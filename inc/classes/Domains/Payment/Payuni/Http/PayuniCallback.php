<?php
/**
 * PAYUNi UPP V2 幕後通知接收（NotifyURL）
 *
 * 比照 NewebpayMpg\Http\MpgCallback / EcpayAIO\Http\AioCallback 採 ApiBase + SingletonTrait，
 * 但用獨立 namespace power-checkout/payuni（避免與綠界 power-checkout/ecpay、藍新 power-checkout/newebpay 混淆）。
 *
 * 端點：POST /wp-json/power-checkout/payuni/upp/notify
 *   —— 必須與 PayuniRequestParams::get_notify_url() / PayuniUppGateway 建單時的 NotifyURL 完全一致。
 *
 * 協定鐵律（金流安全核心）：
 *  - 交易結果以 NotifyURL（server-to-server）為準。
 *  - 驗章鏈：外層 Status=SUCCESS → MerID 比對 → EncryptInfo 存在 → HashInfo timing-safe 驗章
 *    → AES-256-GCM 解密 → 反查訂單 → 冪等 → StatusManager。
 *  - 所有失敗分支（外層 Status 非 SUCCESS / MerID 不符 / 缺 EncryptInfo / 驗章失敗 /
 *    解密失敗 / 查無訂單 / 任何 \Throwable）一律回 HTTP 200，避免 PAYUNi 60 分鐘重送風暴；
 *    不更新狀態時 log warning，不外露內部錯誤。
 *
 * @see .claude/skills/payuni-upp-v2/references/upp-response-params.md §外層欄位 / §驗章流程
 * @see specs/features/payment/payuni-upp-callback.feature
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * PAYUNi UPP V2 NotifyURL 幕後通知 callback
 */
final class PayuniCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 外層成功狀態值 */
	private const STATUS_SUCCESS = 'SUCCESS';

	/** @var string Namespace power-checkout/payuni（與綠界 / 藍新區隔） */
	protected $namespace = 'power-checkout/payuni';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'upp/notify',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callback（一律回 HTTP 200）

	/**
	 * 付款結果背景通知（NotifyURL，source of truth）
	 *
	 * 所有失敗分支（含 \Throwable）一律回 HTTP 200，避免 PAYUNi 重送風暴。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（HTTP 200）
	 */
	public function post_upp_notify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		// PAYUNi 為 Form POST；優先取 body params，退而取全部 params。
		$params = $request->get_body_params();
		if ( ! $params ) {
			$params = $request->get_params();
		}

		try {
			$this->handle_notify( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( 'PAYUNi UPP NotifyURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}

		return self::ok_response();
	}

	// endregion

	// region 處理邏輯（可獨立測試）

	/**
	 * 處理付款結果通知（驗章鏈 + 冪等 + StatusManager）
	 *
	 * 步驟：
	 *  1. 外層 Status 非 SUCCESS → log + return（不更新）。
	 *  2. EncryptInfo / HashInfo 缺失 → log + return。
	 *  3. MerID 比對商店設定 → 不符 log + return（防跨商店污染）。
	 *  4. HashInfo timing-safe 驗章（verify_hash 內含 hash_equals）→ 失敗 log + return。
	 *  5. AES-256-GCM 解密 → 解密失敗 throw（呼叫端 catch 仍回 200）。
	 *  6. 以 MerTradeNo 反查訂單 → 查無 log + return。
	 *  7. 冪等：已 processing → skip。
	 *  8. StatusManager::update_order_status()。
	 *
	 * @param array<string, mixed> $params 外層 Form POST body（含 Status / MerID / EncryptInfo / HashInfo）
	 * @return void
	 * @throws \RuntimeException 解密失敗（由 post_upp_notify_callback catch）
	 */
	public function handle_notify( array $params ): void {
		$outer_status = (string) ( $params['Status'] ?? '' );
		$encrypt_info = (string) ( $params['EncryptInfo'] ?? '' );
		$hash_info    = (string) ( $params['HashInfo'] ?? '' );
		$outer_mer_id = (string) ( $params['MerID'] ?? '' );

		// 1. 外層 Status 非 SUCCESS（PAYUNi 報錯，無 EncryptInfo）→ 直接拒絕
		if ( self::STATUS_SUCCESS !== $outer_status ) {
			Plugin::logger( 'PAYUNi UPP 通知外層 Status 非 SUCCESS', 'warning', [ 'status' => $outer_status ] );
			return;
		}

		// 2. EncryptInfo / HashInfo 缺失 → 拒絕
		if ( '' === $encrypt_info || '' === $hash_info ) {
			Plugin::logger( 'PAYUNi UPP 通知缺少 EncryptInfo / HashInfo', 'warning' );
			return;
		}

		$settings = PayuniSettingsDTO::instance();
		if ( '' === $settings->hash_key || '' === $settings->hash_iv ) {
			Plugin::logger( 'PAYUNi UPP 通知處理失敗：缺少憑證', 'warning' );
			return;
		}

		// 3. MerID 比對商店設定（timing-safe）→ 防跨商店污染
		if ( '' !== $settings->merchant_id && ! \hash_equals( $settings->merchant_id, $outer_mer_id ) ) {
			Plugin::logger( 'PAYUNi UPP 通知 MerID 不符商店設定', 'warning', [ 'mer_id' => $outer_mer_id ] );
			return;
		}

		$crypto = new PayuniCrypto( $settings->hash_key, $settings->hash_iv );

		// 4. HashInfo timing-safe 驗章（空字串 / 長度異常 / 竄改皆回 false）
		if ( ! $crypto->verify_hash( $encrypt_info, $hash_info ) ) {
			Plugin::logger( 'PAYUNi UPP 通知 HashInfo 驗章失敗', 'warning' );
			return;
		}

		// 5. AES-256-GCM 解密（AuthTag 驗證失敗 / 格式錯誤 → throw RuntimeException）
		$inner = $crypto->decrypt( $encrypt_info );

		// 6. 以 MerTradeNo 反查訂單
		$mer_trade_no = (string) ( $inner['MerTradeNo'] ?? '' );
		$order        = PayuniMetaKeys::get_order_by_trade_no( $mer_trade_no );
		if ( ! $order instanceof \WC_Order ) {
			Plugin::logger( 'PAYUNi UPP 通知查無訂單', 'warning', [ 'mer_trade_no' => $mer_trade_no ] );
			return;
		}

		// 7. 冪等：已 processing 則 skip（PAYUNi 重送通知不重複處理）
		if ( $order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		// 8. 委派 StatusManager（內含金額防竄改 / Status / MerID 最後防線）
		( new StatusManager( $inner, $order ) )->update_order_status();
	}

	// endregion

	// region URL helpers

	/** @return string NotifyURL（付款結果背景通知，source of truth；僅限 80/443 port） */
	public static function get_notify_url(): string {
		return \site_url( 'wp-json/power-checkout/payuni/upp/notify', 'https' );
	}

	/** @return string ReturnURL（付款完成前景導回 order-received，UX） */
	public static function get_return_url( \WC_Order $order ): string {
		return $order->get_checkout_order_received_url();
	}

	// endregion

	/**
	 * HTTP 200 回應（PAYUNi 只要收到 200 即不重送；無需特定 body 字串）
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok_response(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'code' => 'success' ], 200 );
	}
}
