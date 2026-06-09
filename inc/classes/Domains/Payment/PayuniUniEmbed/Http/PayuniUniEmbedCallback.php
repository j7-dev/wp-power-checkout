<?php
/**
 * PAYUNi UNi Embed V3 幕後通知接收（NotifyURL）
 *
 * 比照 Payuni\Http\PayuniCallback（UPP 導轉式）採 ApiBase + SingletonTrait，
 * 同 namespace power-checkout/payuni，但端點為 uni-embed/notify（與 UPP 的 upp/notify 區隔）。
 *
 * 端點：POST /wp-json/power-checkout/payuni/uni-embed/notify
 *   —— 必須與 MerchantTradeClient::get_notify_url() 帶入 merchant_trade 的 NotifyURL 完全一致。
 *
 * 協定鐵律（金流安全核心，與 UPP 一致）：
 *  - 交易結果以 NotifyURL（server-to-server）為準。
 *  - 驗章鏈：外層 Status=SUCCESS → MerID timing-safe 比對 → EncryptInfo / HashInfo 存在
 *    → HashInfo 長度守衛（SHA256 = 64 hex）→ HashInfo timing-safe 驗章（hash_equals）
 *    → AES-256-GCM 解密（AuthTag 驗證）→ 以 MerTradeNo 反查訂單（_pc_payuni_uni_trade_no，PCE）
 *    → 冪等（已 processing skip）→ StatusManager。
 *  - 所有失敗分支（外層 Status 非 SUCCESS / MerID 不符 / 缺 EncryptInfo / HashInfo 長度異常 /
 *    驗章失敗 / 解密失敗 / 查無訂單 / 任何 \Throwable）一律回 HTTP 200，避免 PAYUNi 重送風暴；
 *    不更新狀態時 log warning，不外露內部錯誤。
 *
 * ⚠️ UNi Embed 與 UPP 的隔離鐵律：
 *  - 反查主鍵為 _pc_payuni_uni_trade_no（PCE 前綴），絕不撈 UPP 的 _pc_payuni_trade_no（PCU 前綴）。
 *  - 加解密複用 Payuni\Shared\Helpers\PayuniCrypto（AES-256-GCM，與 UPP 同源；不開第 3 份副本）。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §NotifyURL 回打格式
 * @see specs/features/payment/payuni-uni-embed-callback.feature
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\Http\PayuniCallback UPP 對照（最重要藍本）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * PAYUNi UNi Embed V3 NotifyURL 幕後通知 callback
 */
final class PayuniUniEmbedCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 外層成功狀態值 */
	private const STATUS_SUCCESS = 'SUCCESS';

	/** @var int HashInfo（SHA256 hex）固定長度，用於進入 timing-safe 比對前的長度守衛 */
	private const HASH_INFO_LENGTH = 64;

	/** @var string Namespace power-checkout/payuni（與 UPP / 綠界 / 藍新區隔，與 FrontendApi 同 domain） */
	protected $namespace = 'power-checkout/payuni';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'uni-embed/notify',
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
	public function post_uni_embed_notify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		// PAYUNi 為 Form POST；優先取 body params，退而取全部 params。
		$params = $request->get_body_params();
		if ( ! $params ) {
			$params = $request->get_params();
		}

		try {
			$this->handle_notify( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( 'PAYUNi UNi Embed NotifyURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
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
	 *  3. 缺商店憑證（HashKey / HashIV）→ log + return。
	 *  4. MerID 比對商店設定（timing-safe）→ 不符 log + return（防跨商店污染）。
	 *  5. HashInfo 長度守衛（SHA256 = 64 hex）→ 不符 log + return（防空字串 / 短字串繞過）。
	 *  6. HashInfo timing-safe 驗章（verify_hash 內含 hash_equals）→ 失敗 log + return。
	 *  7. AES-256-GCM 解密 → 解密失敗 throw（呼叫端 catch 仍回 200）。
	 *  8. 以 MerTradeNo（_pc_payuni_uni_trade_no，PCE）反查訂單 → 查無 log + return。
	 *  9. 冪等：已 processing → skip。
	 *  10. StatusManager::update_order_status()。
	 *
	 * @param array<string, mixed> $params 外層 Form POST body（含 Status / MerID / EncryptInfo / HashInfo）
	 * @return void
	 * @throws \RuntimeException 解密失敗（由 post_uni_embed_notify_callback catch）
	 */
	public function handle_notify( array $params ): void {
		$outer_status = (string) ( $params['Status'] ?? '' );
		$encrypt_info = (string) ( $params['EncryptInfo'] ?? '' );
		$hash_info    = (string) ( $params['HashInfo'] ?? '' );
		$outer_mer_id = (string) ( $params['MerID'] ?? '' );

		// 1. 外層 Status 非 SUCCESS（PAYUNi 報錯，無 EncryptInfo）→ 直接拒絕
		if ( self::STATUS_SUCCESS !== $outer_status ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知外層 Status 非 SUCCESS', 'warning', [ 'status' => $outer_status ] );
			return;
		}

		// 2. EncryptInfo / HashInfo 缺失 → 拒絕
		if ( '' === $encrypt_info || '' === $hash_info ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知缺少 EncryptInfo / HashInfo', 'warning' );
			return;
		}

		$settings = PayuniUniEmbedSettingsDTO::instance();
		if ( '' === $settings->hash_key || '' === $settings->hash_iv ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知處理失敗：缺少憑證', 'warning' );
			return;
		}

		// 3. MerID 比對商店設定（timing-safe）→ 防跨商店污染
		if ( '' !== $settings->merchant_id && ! \hash_equals( $settings->merchant_id, $outer_mer_id ) ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知 MerID 不符商店設定', 'warning', [ 'mer_id' => $outer_mer_id ] );
			return;
		}

		// 4. HashInfo 長度守衛：SHA256 輸出固定 64 字元 hex。
		// 長度不符（空字串 / 'TOOSHORT' 等）代表格式錯誤，在進入 timing-safe 比對前就拒絕
		// （防 hash_equals('', '') 之類的繞過嘗試）。
		if ( self::HASH_INFO_LENGTH !== \strlen( \trim( $hash_info ) ) ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知 HashInfo 長度異常', 'warning' );
			return;
		}

		$crypto = new PayuniCrypto( $settings->hash_key, $settings->hash_iv );

		// 5. HashInfo timing-safe 驗章（竄改 / 不符皆回 false）
		if ( ! $crypto->verify_hash( $encrypt_info, $hash_info ) ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知 HashInfo 驗章失敗', 'warning' );
			return;
		}

		// 6. AES-256-GCM 解密（AuthTag 驗證失敗 / 格式錯誤 → throw RuntimeException）
		$inner = $crypto->decrypt( $encrypt_info );

		// 7. 以 MerTradeNo 反查訂單（_pc_payuni_uni_trade_no，PCE 前綴；絕不撈 UPP 的 PCU）
		$mer_trade_no = (string) ( $inner['MerTradeNo'] ?? '' );
		$order        = PayuniUniEmbedMetaKeys::get_order_by_trade_no( $mer_trade_no );
		if ( ! $order instanceof \WC_Order ) {
			Plugin::logger( 'PAYUNi UNi Embed 通知查無訂單', 'warning', [ 'mer_trade_no' => $mer_trade_no ] );
			return;
		}

		// 8. 冪等：已 processing 則 skip（PAYUNi 重送通知不重複處理）
		if ( $order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		// 9. 委派 StatusManager（內含 Gateway=9 / 金額防竄改 / Status / MerID 最後防線）
		( new StatusManager( $inner, $order ) )->update_order_status();
	}

	// endregion

	// region URL helpers

	/** @return string NotifyURL（付款結果背景通知，source of truth；僅限 80/443 port） */
	public static function get_notify_url(): string {
		return \site_url( 'wp-json/power-checkout/payuni/uni-embed/notify', 'https' );
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
