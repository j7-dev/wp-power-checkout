<?php
/**
 * PayNow（立吉富，REST API 體系 1）REST API client
 *
 * 四合一 client：對應 UNi Embed 的 TokenGetClient + MerchantTradeClient + UniDoActionClient +
 * UniQueryTradeClient 四個 client 的合併（PayNow 體系 1 為標準 REST，無對稱加密 / 三層握手，
 * 故得以單一 client 統包 PaymentIntent / Refund 全部端點）。
 *
 * Cycle 2：create_payment_intent / retrieve_payment_intent；
 * Cycle 4：refund（POST /payment-intents/:id/refunds）/ retrieve_refund（GET /refunds/:uuid）補上。
 *
 * 認證：Authorization: Bearer {PrivateKey}（payment-rest-api.md §1）。
 *   ⚠️ Bearer 一律用 PrivateKey（後端機密），非 PublicKey。
 *
 * 退款 / 補查 mock filter（測試以 named filter 攔截 HTTP，避免打真實 PayNow）：
 *   - `paynow_mock_refund_response`                  → refund() 回傳此外層 envelope（內取 result）
 *   - `paynow_mock_retrieve_refund_response`         → retrieve_refund() 回傳此外層 envelope
 *   - `paynow_mock_retrieve_payment_intent_response` → retrieve_payment_intent() 回傳此外層 envelope
 *   - `paynow_mock_refund_exception` (bool true)     → refund() 拋 RuntimeException
 *   - `paynow_mock_retrieve_exception` (bool true)   → retrieve_* 拋 RuntimeException
 *   未掛 filter 時一律走真實 request()（Cycle 2 既有 pre_http_request mock 不受影響）。
 *
 * 環境（concepts.md §10）：
 *   - sandbox → https://sandboxapi.paynow.com.tw
 *   - prod    → https://api.paynow.com.tw
 *   依建構子 $is_sandbox 切換（SettingsDTO mode='test' → true）。
 *
 * 統一回應守衛（request()，對齊 php-examples.md §1 turnkey；守衛順序為資安關鍵）：
 *   1) wp_remote_request → WP_Error → throw RuntimeException（連線層失敗）
 *   2) body 非合法 JSON（json_decode 非陣列）→ throw RuntimeException（含空字串 / HTML 錯誤頁）
 *   3) 外層 type≠'success' 且 status≠200 → throw RuntimeException（業務層失敗）
 *   4) 通過 → 回完整 $data（呼叫端取 $data['result']）
 *
 * @see specs/open-issue/paynow-implementation-plan.md §Phase 06 步驟 13
 * @see .claude/skills/paynow/references/php-examples.md §1 PaynowRestClient（turnkey）
 * @see .claude/skills/paynow/references/payment-rest-api.md §4 PaymentIntent §5 Refund
 * @see \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient request 結構對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Http;

/**
 * PayNow REST API client（PaymentIntent + Refund）
 *
 * 認證：Authorization: Bearer {PrivateKey}。
 */
final class PaynowRestClient {

	/** @var string production 主機（concepts.md §10） */
	private const PROD_BASE = 'https://api.paynow.com.tw';

	/** @var string sandbox 主機（concepts.md §10） */
	private const SANDBOX_BASE = 'https://sandboxapi.paynow.com.tw';

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 30;

	/** @var string 退款回應 mock filter（測試攔截 refund HTTP） */
	public const FILTER_MOCK_REFUND = 'paynow_mock_refund_response';

	/** @var string 退款查詢回應 mock filter（測試攔截 retrieve_refund HTTP） */
	public const FILTER_MOCK_RETRIEVE_REFUND = 'paynow_mock_retrieve_refund_response';

	/** @var string 補查付款意圖回應 mock filter（測試攔截 retrieve_payment_intent HTTP） */
	public const FILTER_MOCK_RETRIEVE_INTENT = 'paynow_mock_retrieve_payment_intent_response';

	/** @var string 退款例外 mock filter（測試令 refund 拋例外） */
	public const FILTER_MOCK_REFUND_EXCEPTION = 'paynow_mock_refund_exception';

	/** @var string 補查 / 退款查詢例外 mock filter（測試令 retrieve_* 拋例外） */
	public const FILTER_MOCK_RETRIEVE_EXCEPTION = 'paynow_mock_retrieve_exception';

	/**
	 * Constructor
	 *
	 * @param string $private_key PayNow PrivateKey（後端 Bearer Token 金鑰，不可公開）
	 * @param bool   $is_sandbox  是否使用 sandbox 主機（SettingsDTO mode='test' → true）
	 */
	public function __construct(
		private readonly string $private_key,
		private readonly bool $is_sandbox = true,
	) {}

	/** @return string base_url（依 is_sandbox 切換 sandbox / prod 主機） */
	private function base_url(): string {
		return $this->is_sandbox ? self::SANDBOX_BASE : self::PROD_BASE;
	}

	/**
	 * 建立付款意圖 — POST /api/v1/payment-intents
	 *
	 * 成功回傳 result 內層（含 id（pp_xxx）/ secret（pp_xxx_st_xxx）/ status（draft）/ amount）。
	 * currency 未指定則補 TWD（PayNow 體系 1 僅支援 TWD）。
	 *
	 * @param array<string, mixed> $params 請求 body（amount/currency/description/webhookUrl/resultUrl/allowedPaymentMethods/allowInstallments/expireDays 等）
	 * @return array<string, mixed> result 內層（id / secret / status / amount）
	 * @throws \RuntimeException 連線失敗 / 非 JSON / type≠success
	 */
	public function create_payment_intent( array $params ): array {
		$params['currency'] ??= 'TWD';
		$res                  = $this->request( 'POST', '/api/v1/payment-intents', $params );

		$result = $res['result'] ?? null;
		return \is_array( $result ) ? $result : [];
	}

	/**
	 * 查詢付款意圖 — GET /api/v1/payment-intents/:id
	 *
	 * 優先 honor mock filter（測試 / 補單）：
	 *   - paynow_mock_retrieve_exception=true → 拋 RuntimeException（模擬連線失敗）
	 *   - paynow_mock_retrieve_payment_intent_response 回 array → 套回應守衛後回 result。
	 * 未掛 filter 時走真實 request()（Cycle 2 既有 pre_http_request mock 不受影響）。
	 *
	 * @param string $id PaymentIntentId（pp_xxx）
	 * @return array<string, mixed> result 內層（含 status，補單 / 退款查詢用）
	 * @throws \RuntimeException 連線失敗 / 非 JSON / type≠success
	 */
	public function retrieve_payment_intent( string $id ): array {
		$mocked = $this->resolve_mock(
			self::FILTER_MOCK_RETRIEVE_INTENT,
			self::FILTER_MOCK_RETRIEVE_EXCEPTION
		);
		if ( null !== $mocked ) {
			return $mocked;
		}

		$res = $this->request( 'GET', "/api/v1/payment-intents/{$id}" );

		$result = $res['result'] ?? null;
		return \is_array( $result ) ? $result : [];
	}

	/**
	 * 退款開立 — POST /api/v1/payment-intents/:id/refunds
	 *
	 * ATM 退款需於 $params 帶 bankCode / bankBranchCode / bankAccount（由呼叫端 RefundParams 守衛）。
	 * 退款狀態 result.type：success / failed / rejected（RejectReason）/ processing / validation_error。
	 *
	 * 優先 honor mock filter（測試）：
	 *   - paynow_mock_refund_exception=true → 拋 RuntimeException（模擬退款 API 失敗）。
	 *   - paynow_mock_refund_response 回 array → 套回應守衛後回 result。
	 * 未掛 filter 時走真實 request()。
	 *
	 * @param string               $payment_intent_id PaymentIntentId（pp_xxx）
	 * @param array<string, mixed> $params            退款 body（amount / reason / [bank 三欄]）
	 * @return array<string, mixed> result 內層（id / type / amount / [RejectReason]）
	 * @throws \RuntimeException 連線失敗 / 非 JSON / type≠success
	 */
	public function refund( string $payment_intent_id, array $params ): array {
		$mocked = $this->resolve_mock(
			self::FILTER_MOCK_REFUND,
			self::FILTER_MOCK_REFUND_EXCEPTION
		);
		if ( null !== $mocked ) {
			return $mocked;
		}

		$res = $this->request( 'POST', "/api/v1/payment-intents/{$payment_intent_id}/refunds", $params );

		$result = $res['result'] ?? null;
		return \is_array( $result ) ? $result : [];
	}

	/**
	 * 退款查詢 — GET /api/v1/refunds/:uuid
	 *
	 * 優先 honor mock filter（測試）：
	 *   - paynow_mock_retrieve_exception=true → 拋 RuntimeException。
	 *   - paynow_mock_retrieve_refund_response 回 array → 套回應守衛後回 result。
	 * 未掛 filter 時走真實 request()。
	 *
	 * @param string $uuid 退款 uuid（refund id）
	 * @return array<string, mixed> result 內層（id / type / amount）
	 * @throws \RuntimeException 連線失敗 / 非 JSON / type≠success
	 */
	public function retrieve_refund( string $uuid ): array {
		$mocked = $this->resolve_mock(
			self::FILTER_MOCK_RETRIEVE_REFUND,
			self::FILTER_MOCK_RETRIEVE_EXCEPTION
		);
		if ( null !== $mocked ) {
			return $mocked;
		}

		$res = $this->request( 'GET', "/api/v1/refunds/{$uuid}" );

		$result = $res['result'] ?? null;
		return \is_array( $result ) ? $result : [];
	}

	/**
	 * 解析退款 / 補查 mock filter（測試以 named filter 攔截 HTTP）
	 *
	 * 守衛順序與真實 request() 一致（資安關鍵）：
	 *   1) exception filter 為 true → 立即 throw（模擬連線層失敗）。
	 *   2) response filter 回 array 且 type≠success 且 status≠200 → throw（業務層失敗）。
	 *   3) response filter 回 array 通過 → 回 result 內層。
	 *   4) 皆未掛 filter → 回 null（呼叫端走真實 request()）。
	 *
	 * @param non-empty-string $response_filter  回應 mock filter tag（class const，恆非空）
	 * @param non-empty-string $exception_filter 例外 mock filter tag（class const，恆非空）
	 * @return array<string, mixed>|null mock result（命中 filter）；未掛 filter 回 null
	 * @throws \RuntimeException Exception filter 為 true，或 mock envelope 業務層失敗時
	 */
	private function resolve_mock( string $response_filter, string $exception_filter ): ?array {
		// 1. 例外 mock（模擬連線層失敗）
		if ( true === \apply_filters( $exception_filter, false ) ) {
			throw new \RuntimeException( 'PayNow mock 連線失敗（測試）' );
		}

		// 2. 回應 mock（外層 envelope：{ status, type, message, result }）
		$mock = \apply_filters( $response_filter, null );
		if ( ! \is_array( $mock ) ) {
			return null; // 未掛 filter → 呼叫端走真實 request()
		}

		// 3. 套用與 request() 一致的業務層守衛（type≠success 且 status≠200 → throw）
		if ( 'success' !== ( $mock['type'] ?? '' ) && 200 !== (int) ( $mock['status'] ?? 0 ) ) {
			throw new \RuntimeException( 'PayNow API 錯誤（mock）：' . (string) ( $mock['message'] ?? '' ) );
		}

		$result = $mock['result'] ?? null;
		return \is_array( $result ) ? $result : [];
	}

	/**
	 * 共用請求；PayNow 外層回應固定 { status, type, message, result, requestId, paginate }
	 *
	 * ⚠️ 回應守衛順序（資安關鍵，對齊 php-examples.md §1 turnkey）：
	 *   1) WP_Error（連線層）→ throw
	 *   2) 非 JSON（含空字串 / HTML 錯誤頁）→ throw
	 *   3) type≠'success' 且 status≠200（業務層失敗）→ throw
	 *   4) 通過 → 回完整 $data
	 *
	 * @param 'GET'|'POST'              $method HTTP 方法
	 * @param string                    $path   端點路徑（含前導 /）
	 * @param array<string, mixed>|null $body   請求 body（GET 時為 null）
	 * @return array<string, mixed> 外層回應（含 result）
	 * @throws \RuntimeException 連線失敗 / 非 JSON / type≠success
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		$args = [
			'method'   => $method,
			'timeout'  => self::TIMEOUT,
			'blocking' => true,
			'headers'  => [
				'Authorization' => 'Bearer ' . $this->private_key,
				'Accept'        => 'application/json',
			],
		];

		if ( null !== $body ) {
			$encoded = \wp_json_encode( $body );
			if ( false === $encoded ) {
				throw new \RuntimeException( 'PayNow 請求參數 JSON 編碼失敗' );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $encoded;
		}

		// 統一以 wp_remote_request（pre_http_request filter 可攔截，測試 mock 用）
		$response = \wp_remote_request( $this->base_url() . $path, $args );

		// 守衛 1：連線層失敗（WP_Error）
		if ( \is_wp_error( $response ) ) {
			throw new \RuntimeException( 'PayNow 連線失敗：' . $response->get_error_message() );
		}

		$raw  = \wp_remote_retrieve_body( $response );
		$data = \json_decode( $raw, true );

		// 守衛 2：回應非合法 JSON（含空字串 / HTML 錯誤頁）
		if ( ! \is_array( $data ) ) {
			throw new \RuntimeException( 'PayNow 回應非 JSON：' . $raw );
		}

		// 守衛 3：業務層失敗（type≠success 且 status≠200，雙重條件）
		if ( 'success' !== ( $data['type'] ?? '' ) && 200 !== (int) ( $data['status'] ?? 0 ) ) {
			throw new \RuntimeException( 'PayNow API 錯誤：' . (string) ( $data['message'] ?? $raw ) );
		}

		/** @var array<string, mixed> $data */
		return $data;
	}
}
