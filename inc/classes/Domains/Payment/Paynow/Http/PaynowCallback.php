<?php
/**
 * PayNow Webhook（payment_result）幕後通知接收（NotifyURL）
 *
 * 比照 PayuniUniEmbed\Http\PayuniUniEmbedCallback（ApiBase + SingletonTrait + always HTTP 200），
 * 但驗簽改 HMAC-SHA256（對 raw body）、反查主鍵改 PaymentIntentId（PayNow 體系 1 無對稱加密）。
 *
 * 端點：POST /wp-json/power-checkout/paynow/notify
 *   —— 必須與 PaynowGateway::get_webhook_url() 帶入 create_payment_intent 的 webhookUrl 完全一致。
 *
 * 協定鐵律（金流安全核心）：
 *  - 交易結果以 Webhook（server-to-server）為準（source of truth）。
 *  - 驗證鏈：取 raw body（$request->get_body()，**不** re-encode）→ 取 Header
 *    X-Payment-Center-Hmac-Sha256 → WebhookVerifier::verify（HMAC-SHA256 timing-safe，key=PrivateKey）
 *    → json_decode → 取 PaymentIntentId → PaynowMetaKeys::get_order_by_payment_intent_id 反查訂單
 *    → 冪等（已 processing skip）→ StatusManager（金額防竄改 + 幣別守衛 + 狀態分流）。
 *  - 所有失敗分支（provider 未啟用 / 缺憑證 / 驗簽失敗 / 畸形 JSON / 缺 PaymentIntentId /
 *    查無訂單 / 任何 \Throwable）一律回 HTTP 200，避免 PayNow 重送風暴；不更新狀態時 log，
 *    不外露內部錯誤。
 *
 * ⚠️ 與 PayuniUniEmbedCallback 差異：
 *  - 驗簽對象為 raw body（HMAC-SHA256），非 Form POST 欄位 + AES-256-GCM 解密。
 *  - 反查主鍵為 PaymentIntentId（_pc_paynow_payment_intent_id），非 MerTradeNo。
 *  - 不複用 PayuniCrypto（體系 1 無對稱加密）；驗簽走 WebhookVerifier。
 *
 * @see .claude/skills/paynow/references/payment-rest-api.md §10 Webhook payload
 * @see .claude/skills/paynow/references/php-examples.md §2 PaynowWebhookVerifier
 * @see specs/features/payment/paynow-callback.feature
 * @see \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedCallback 範本對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Http;

use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\WebhookVerifier;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** PayNow Webhook（payment_result）幕後通知 callback */
final class PaynowCallback extends ApiBase {
	use SingletonTrait;

	/** @var string PayNow Provider ID */
	private const PROVIDER_ID = 'paynow';

	/** @var string Webhook 簽章 Header 名稱（HMAC-SHA256 hex 大寫） */
	private const SIGNATURE_HEADER = 'X-Payment-Center-Hmac-Sha256';

	/** @var string Namespace power-checkout/paynow（與 ECPay / PAYUNi / 藍新區隔） */
	protected $namespace = 'power-checkout/paynow';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'notify',
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
	 * 付款結果背景通知（Webhook payment_result，source of truth）
	 *
	 * 所有失敗分支（含 \Throwable）一律回 HTTP 200，避免 PayNow 重送風暴。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（HTTP 200）
	 */
	public function post_notify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			// ⚠️ 取 raw body（驗簽對象）；絕不用 get_json_params / get_body_params re-encode。
			$raw_body  = (string) $request->get_body();
			$signature = (string) $request->get_header( self::SIGNATURE_HEADER );

			$this->handle_notify( $raw_body, $signature );
		} catch ( \Throwable $e ) {
			Plugin::logger( 'PayNow Webhook 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}

		return self::ok_response();
	}

	// endregion

	// region 處理邏輯（可獨立測試）

	/**
	 * 處理付款結果通知（HMAC 驗簽 + 反查 + 冪等 + StatusManager）
	 *
	 * 步驟：
	 *  1. provider 未啟用 → log + return（不處理）。
	 *  2. 缺 PrivateKey（憑證未設）→ log + return。
	 *  3. HMAC-SHA256 timing-safe 驗簽（對 raw body）→ 失敗 log + return（不 decode、不更新）。
	 *  4. json_decode raw body → 非陣列 → log + return（畸形 JSON）。
	 *  5. 取 PaymentIntentId → 空 → log + return。
	 *  6. 以 PaymentIntentId 反查訂單 → 查無 → log + return。
	 *  7. 冪等：已 processing → skip。
	 *  8. StatusManager::update_order_status()（金額防竄改 + 幣別守衛 + 狀態分流）。
	 *
	 * @param string $raw_body  原始 request body（驗簽對象 + decode 來源）
	 * @param string $signature Header X-Payment-Center-Hmac-Sha256 的值
	 * @return void
	 */
	public function handle_notify( string $raw_body, string $signature ): void {
		// 1. provider 未啟用 → 不處理（仍由呼叫端回 200）
		if ( ! ProviderUtils::is_enabled( self::PROVIDER_ID ) ) {
			Plugin::logger( 'PayNow Webhook：provider 未啟用，略過處理', 'warning' );
			return;
		}

		$settings = PaynowSettingsDTO::instance();

		// 2. 缺 PrivateKey（驗簽金鑰）→ 不處理
		if ( '' === $settings->private_key ) {
			Plugin::logger( 'PayNow Webhook 處理失敗：缺少 PrivateKey', 'warning' );
			return;
		}

		// 3. HMAC-SHA256 timing-safe 驗簽（對 raw body；竄改 body / 竄改 sig / 空 sig / 錯誤 key 皆失敗）
		$verifier = new WebhookVerifier( $settings->private_key );
		if ( ! $verifier->verify( $raw_body, $signature ) ) {
			Plugin::logger( 'PayNow Webhook 驗簽失敗', 'warning' );
			return;
		}

		// 4. 驗簽通過才 decode（畸形 JSON → 非陣列 → 拒絕）
		$payload = \json_decode( $raw_body, true );
		if ( ! \is_array( $payload ) ) {
			Plugin::logger( 'PayNow Webhook payload 非合法 JSON', 'warning' );
			return;
		}

		// 5. 取 PaymentIntentId（反查主鍵）
		$payment_intent_id = (string) ( $payload['PaymentIntentId'] ?? '' );
		if ( '' === $payment_intent_id ) {
			Plugin::logger( 'PayNow Webhook 缺少 PaymentIntentId', 'warning' );
			return;
		}

		// 6. 以 PaymentIntentId 反查訂單
		$order = PaynowMetaKeys::get_order_by_payment_intent_id( $payment_intent_id );
		if ( ! $order instanceof \WC_Order ) {
			Plugin::logger(
				'PayNow Webhook 查無訂單',
				'warning',
				[ 'payment_intent_id' => $payment_intent_id ]
			);
			return;
		}

		// 7. 冪等：已 processing → skip（PayNow 重送通知不重複處理）
		if ( $order->has_status( OrderStatus::PROCESSING->value ) ) {
			return;
		}

		// 7-1. 終態守衛（資安 F-1）：訂單已為終態（refunded / cancelled / completed）→
		// early-return，不再委派 StatusManager（避免合法 Webhook 重放讓終態訂單復活為 processing）。
		// ⚠️ StatusManager::handle_success 亦有相同守衛（雙重防禦）；此處早退僅省去無謂處理。
		if ( $order->has_status(
			[
				OrderStatus::REFUNDED->value,
				OrderStatus::CANCELLED->value,
				OrderStatus::COMPLETED->value,
			]
		) ) {
			Plugin::logger(
				'PayNow Webhook：訂單已為終態，拒絕重放更新（疑似重送 / 重放）',
				'warning',
				[
					'order_id' => $order->get_id(),
					'status'   => $order->get_status(),
				]
			);
			return;
		}

		// 8. 委派 StatusManager（內含幣別守衛 / 金額防竄改 / Status 分流 / 離線付款）
		/** @var array<string, mixed> $payload */
		( new StatusManager( $payload, $order ) )->update_order_status();
	}

	// endregion

	// region URL helpers

	/** @return string Webhook URL（付款結果背景通知，source of truth；僅限 80/443 port） */
	public static function get_notify_url(): string {
		return \site_url( 'wp-json/power-checkout/paynow/notify', 'https' );
	}

	// endregion

	/**
	 * HTTP 200 回應（PayNow 只要收到 200 即不重送；無需特定 body 字串）
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok_response(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'code' => 'success' ], 200 );
	}
}
