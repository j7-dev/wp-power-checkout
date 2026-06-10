<?php
/**
 * PayNow Callback 整合測試（TDD Red 階段 — Cycle 3）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowCallback
 *
 * 測試依據：
 *   - specs/features/payment/paynow-callback.feature（5 scenarios）
 *   - specs/features/payment/paynow-payment-info.feature（離線付款）
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 18（PaynowCallback）
 *   - .claude/skills/paynow/references/payment-rest-api.md §10 Webhook payload
 *
 * PaynowCallback 設計規格：
 *   - 繼承 ApiBase；namespace = 'power-checkout/paynow'；endpoint = 'notify'
 *   - 方法：post_notify_callback(WP_REST_Request $request): WP_REST_Response
 *   - 驗簽：raw body + Header 'X-Payment-Center-Hmac-Sha256' → WebhookVerifier
 *   - 反查主鍵：PaymentIntentId → PaynowMetaKeys::get_order_by_payment_intent_id()
 *   - 所有路徑（含 \Throwable）回傳 HTTP 200
 *
 * 分發機制：
 *   $request->get_body()   → raw JSON（驗簽用）
 *   $request->get_header() → 'X-Payment-Center-Hmac-Sha256'（簽章）
 *
 * 幣別守衛：
 *   ⚠️ store 預設幣別 USD；成功路徑訂單必須 set_currency('TWD') + save()
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowCallbackTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowCallback;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow Callback 整合測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowCallbackTest extends TestCase {

	// ========== 測試常數 ==========

	/** 測試用 PrivateKey（HMAC 驗簽 key） */
	private const PRIVATE_KEY = 'test_private_key_paynow_callback_xyz987';

	/** 測試用 PublicKey（前端 SDK 初始化，此測試不使用） */
	private const PUBLIC_KEY = 'pk_test_callback_dummy';

	// =====================================================================
	// 生命週期：setUp / tearDown
	// =====================================================================

	/**
	 * 每個測試前：注入 PayNow 設定（enabled + private_key）
	 *
	 * @return void
	 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => self::PUBLIC_KEY,
				'private_key' => self::PRIVATE_KEY,
			]
		);
	}

	/**
	 * 每個測試後：清除 PayNow 設定（TestCase::PROVIDER_OPTION_IDS 未含 paynow，需手動清）
	 *
	 * @return void
	 */
	public function tear_down(): void {
		parent::tear_down();
		\delete_option( ProviderUtils::get_option_name( 'paynow' ) );
	}

	// =====================================================================
	// 輔助方法
	// =====================================================================

	/**
	 * 建立 PayNow 測試訂單（TWD 幣別 + PaymentIntentId）
	 *
	 * @param string $intent_id
	 * @param int    $total
	 * @param string $status
	 * @return \WC_Order
	 */
	private function create_paynow_order(
		string $intent_id,
		int $total = 1000,
		string $status = 'pending'
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => 'paynow',
				'total'          => $total,
			]
		);
		$order->set_currency( 'TWD' );
		$order->save();

		( new PaynowMetaKeys( $order ) )->update_payment_intent_id( $intent_id );

		return $order;
	}

	/**
	 * 建立 raw JSON body（模擬 PayNow Webhook 發出的原始 JSON）
	 *
	 * @param array<string, mixed> $payload
	 * @return string
	 */
	private function build_raw_json( array $payload ): string {
		return (string) \wp_json_encode( $payload );
	}

	/**
	 * 計算 HMAC-SHA256 簽章（strtoupper）
	 *
	 * @param string $raw_body
	 * @param string $private_key
	 * @return string
	 */
	private function build_signature( string $raw_body, string $private_key = self::PRIVATE_KEY ): string {
		return \strtoupper( \hash_hmac( 'sha256', $raw_body, $private_key ) );
	}

	/**
	 * 分發 Notify 請求至 PaynowCallback
	 *
	 * 使用 set_body() 傳入 raw JSON（非 set_body_params），
	 * 模擬 PayNow Webhook 的真實請求格式。
	 *
	 * @param string $raw_body  raw JSON（驗簽對象）
	 * @param string $signature HMAC-SHA256 簽章
	 * @return \WP_REST_Response
	 */
	private function dispatch_notify( string $raw_body, string $signature ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/paynow/notify' );
		$request->set_body( $raw_body );
		$request->set_header( 'X-Payment-Center-Hmac-Sha256', $signature );

		return PaynowCallback::instance()->post_notify_callback( $request );
	}

	/**
	 * 建立標準成功 payload
	 *
	 * @param string $intent_id
	 * @param int    $amount
	 * @param string $payment_type
	 * @return array<string, mixed>
	 */
	private function make_success_payload(
		string $intent_id,
		int $amount = 1000,
		string $payment_type = 'CreditCard'
	): array {
		return [
			'ConnectId'       => '26c06b86-1324-48b6-8017-29e4efa649e6',
			'RequestId'       => '09020f76-1405-4db2-b30a-ba30de629c05',
			'Status'          => 'Success',
			'OrderNo'         => 'PCTEST001',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => 'TXN_CALLBACK_001',
			'Amount'          => (string) $amount,
			'Currency'        => 'TWD',
			'PaymentType'     => $payment_type,
		];
	}

	// =====================================================================
	// 冒煙測試：PaynowCallback 類別可實例化 + 端點 OK
	// =====================================================================

	/**
	 * PaynowCallback::instance() 可取得實例（Singleton）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowCallback可實例化(): void {
		$callback = PaynowCallback::instance();
		$this->assertInstanceOf( PaynowCallback::class, $callback );
	}

	/**
	 * post_notify_callback 方法存在
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_post_notify_callback方法存在(): void {
		$callback = PaynowCallback::instance();
		$this->assertTrue( \method_exists( $callback, 'post_notify_callback' ) );
	}

	// =====================================================================
	// Happy Path（Scenario 1）：付款成功（Status=Success）→ 轉處理中 + HTTP 200
	// =====================================================================

	/**
	 * 付款成功 Webhook → 訂單轉 processing + 回 HTTP 200
	 *
	 * paynow-callback.feature Scenario 1：付款成功
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_付款成功_訂單轉processing且回200(): void {
		$intent_id = 'pp_callback_happy_001';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 付款成功 Webhook → 寫入 _pc_paynow_payment_detail
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_付款成功_寫入payment_detail(): void {
		$intent_id = 'pp_callback_detail_002';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$this->dispatch_notify( $raw_body, $signature );

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new PaynowMetaKeys( $order );
		$detail    = $meta_keys->get_payment_detail();

		$this->assertNotEmpty( $detail, '付款成功後應寫入 _pc_paynow_payment_detail' );
	}

	// =====================================================================
	// Happy Path（Scenario 2）：付款失敗（Status=Failed）→ 維持 pending + HTTP 200
	// =====================================================================

	/**
	 * 付款失敗 Webhook → 訂單維持 pending + 回 HTTP 200
	 *
	 * paynow-callback.feature Scenario 2：付款失敗
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_付款失敗_維持pending且回200(): void {
		$intent_id = 'pp_callback_failed_003';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = [
			'Status'          => 'Failed',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => '',
			'Amount'          => '1000',
			'Currency'        => 'TWD',
			'PaymentType'     => 'CreditCard',
		];
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Security（Scenario 3）：HMAC 驗證失敗 → 回 200 不更新訂單
	// =====================================================================

	/**
	 * HMAC 驗簽失敗（簽章錯誤）→ 訂單不更新 + 仍回 HTTP 200
	 *
	 * paynow-callback.feature Scenario 3：HMAC 驗證失敗
	 *
	 * ⚠️ 所有路徑均回 200，避免 PayNow 判定 endpoint 不可達而重試
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_HMAC驗簽失敗_訂單不更新且仍回200(): void {
		$intent_id = 'pp_callback_hmac_fail_004';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );
		$bad_sig   = 'DEADBEEF00000000000000000000000000000000000000000000000000000000';

		$response = $this->dispatch_notify( $raw_body, $bad_sig );

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' ); // 不應被更新
	}

	/**
	 * HMAC 驗簽失敗（空簽章）→ 訂單不更新 + 仍回 HTTP 200
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_空簽章_訂單不更新且仍回200(): void {
		$intent_id = 'pp_callback_empty_sig_005';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );

		$response = $this->dispatch_notify( $raw_body, '' ); // 空簽章

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * HMAC 驗簽失敗（body 被竄改）→ 訂單不更新 + 仍回 HTTP 200
	 *
	 * 攻擊情境：攻擊者竄改 body（修改 Amount），使用原始簽章無效
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_body竄改_驗簽失敗不更新且回200(): void {
		$intent_id     = 'pp_callback_tamper_006';
		$order         = $this->create_paynow_order( $intent_id, 1000 );
		$original      = $this->make_success_payload( $intent_id, 1000 );
		$original_json = $this->build_raw_json( $original );
		$valid_sig     = $this->build_signature( $original_json ); // 正確簽章

		// 竄改 body（修改 Amount 為 1，但使用原始簽章）
		$tampered_payload           = $original;
		$tampered_payload['Amount'] = '1';
		$tampered_json              = $this->build_raw_json( $tampered_payload );

		// 竄改後的 body + 原始簽章（不符）
		$response = $this->dispatch_notify( $tampered_json, $valid_sig );

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * 使用錯誤 PrivateKey 的簽章 → 驗簽失敗 → 訂單不更新 + 仍回 HTTP 200
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_錯誤PrivateKey簽章_驗簽失敗且回200(): void {
		$intent_id = 'pp_callback_wrong_key_007';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );

		// 用錯誤的 key 計算簽章
		$wrong_sig = $this->build_signature( $raw_body, 'wrong_private_key_from_another_merchant' );

		$response = $this->dispatch_notify( $raw_body, $wrong_sig );

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Error（Scenario 4）：金額不符 → 回 200 不更新
	// =====================================================================

	/**
	 * 金額與本地訂單不符 → 訂單不更新 + 仍回 HTTP 200
	 *
	 * paynow-callback.feature Scenario 4：金額與本地不符
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_金額不符_訂單不更新且仍回200(): void {
		$intent_id = 'pp_callback_amount_mismatch_008';
		$order     = $this->create_paynow_order( $intent_id, 1000 );

		// Webhook Amount=9999，訂單應收 1000 → 不符
		$payload   = $this->make_success_payload( $intent_id, 9999 );
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Edge（Scenario 5）：重複通知冪等 → 回 200 不重複處理
	// =====================================================================

	/**
	 * 重複 Webhook 通知（訂單已 processing）→ 靜默冪等 + 仍回 HTTP 200
	 *
	 * paynow-callback.feature Scenario 5：重複通知冪等
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_重複通知_冪等不重複處理且回200(): void {
		$intent_id = 'pp_callback_idempotent_009';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		// 第一次
		$response1 = $this->dispatch_notify( $raw_body, $signature );
		$this->assertSame( 200, $response1->get_status() );

		// 第二次（重複）
		$response2 = $this->dispatch_notify( $raw_body, $signature );
		$this->assertSame( 200, $response2->get_status() );

		// 訂單仍 processing（不重複呼叫 payment_complete）
		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Happy Path：離線 ATM 待繳 Webhook → payment_info + pending + 回 200
	// =====================================================================

	/**
	 * ATM 待繳 Webhook（Status=WaitForPayment）→ 寫入 payment_info + pending + 回 200
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM待繳Webhook_寫入payment_info並回200(): void {
		$intent_id = 'pp_callback_atm_010';
		$order     = $this->create_paynow_order( $intent_id, 2000 );
		$payload   = [
			'Status'          => 'WaitForPayment',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => '',
			'Amount'          => '2000',
			'Currency'        => 'TWD',
			'PaymentType'     => 'ATM',
			'Meta'            => [
				'BankCode'       => '013',
				'VirtualAccount' => '9876543210987654',
				'ExpireDate'     => '2026/12/31 23:59:59',
			],
		];
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new PaynowMetaKeys( $order );

		$this->assert_order_status( $order, 'pending' );
		$this->assertNotEmpty(
			$meta_keys->get_payment_info(),
			'ATM 待繳應寫入 _pc_paynow_payment_info'
		);
	}

	/**
	 * 超商代碼待繳 Webhook（Status=WaitForPayment + PaymentType=ConvenienceStore）→ payment_info + pending + 200
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_超商代碼待繳Webhook_寫入payment_info並回200(): void {
		$intent_id = 'pp_callback_cvs_011';
		$order     = $this->create_paynow_order( $intent_id, 1500 );
		$payload   = [
			'Status'          => 'WaitForPayment',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => '',
			'Amount'          => '1500',
			'Currency'        => 'TWD',
			'PaymentType'     => 'ConvenienceStore',
			'Meta'            => [
				'CvsPaymentNo' => 'T123456789012',
				'Store'        => 'FAMILY',
				'ExpireDate'   => '2026/12/31 23:59:59',
			],
		];
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new PaynowMetaKeys( $order );

		$this->assert_order_status( $order, 'pending' );
		$this->assertNotEmpty(
			$meta_keys->get_payment_info(),
			'超商待繳應寫入 _pc_paynow_payment_info'
		);
	}

	// =====================================================================
	// Error：找不到訂單（PaymentIntentId 無對應訂單）→ 仍回 200
	// =====================================================================

	/**
	 * PaymentIntentId 找不到對應訂單 → 不丟例外 + 仍回 HTTP 200
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentIntentId找不到訂單_仍回200(): void {
		$nonexistent_intent = 'pp_nonexistent_xxxxxxxxxxxxxyyyyyyy';
		$payload            = $this->make_success_payload( $nonexistent_intent, 1000 );
		$raw_body           = $this->build_raw_json( $payload );
		$signature          = $this->build_signature( $raw_body );

		// 沒有建立對應訂單
		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * payload 無 PaymentIntentId → 仍回 HTTP 200
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_payload缺少PaymentIntentId_仍回200(): void {
		$payload = [
			'Status'        => 'Success',
			'TransactionNo' => 'TXN001',
			'Amount'        => '1000',
			'PaymentType'   => 'CreditCard',
			// 故意無 PaymentIntentId
		];
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );
	}

	// =====================================================================
	// Edge：Throwable 防禦（任何意外例外仍回 200）
	// =====================================================================

	/**
	 * 畸形 JSON body（非合法 JSON）→ 仍回 HTTP 200（\Throwable 防禦）
	 *
	 * ⚠️ Callback 所有路徑（含解析例外）均回 200
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_畸形JSON_Throwable防禦仍回200(): void {
		$raw_body = '{invalid json :::';
		// 計算合法簽章（即使 body 畸形）
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 空 body → 仍回 HTTP 200
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_空body_仍回200(): void {
		$raw_body  = '';
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * 空 body + 空簽章 → 仍回 HTTP 200（最惡意輸入）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_空body空簽章_仍回200(): void {
		$response = $this->dispatch_notify( '', '' );

		$this->assertSame( 200, $response->get_status() );
	}

	// =====================================================================
	// Edge：超大 payload（超長欄位）→ 仍回 200
	// =====================================================================

	/**
	 * 超長 PaymentIntentId（256 字元）→ 仍回 HTTP 200（不丟例外）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_超長PaymentIntentId_仍回200(): void {
		$long_id  = 'pp_' . \str_repeat( 'x', 253 );
		$payload  = $this->make_success_payload( $long_id, 1000 );
		$raw_body = $this->build_raw_json( $payload );
		$sig      = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $sig );

		$this->assertSame( 200, $response->get_status() );
	}

	// =====================================================================
	// Edge：provider 未啟用時 → 仍回 200
	// =====================================================================

	/**
	 * paynow provider 未啟用（enabled=no）→ 回 200（不處理）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_provider未啟用_仍回200(): void {
		// 關閉 paynow
		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'no',
				'mode'        => 'test',
				'public_key'  => self::PUBLIC_KEY,
				'private_key' => self::PRIVATE_KEY,
			]
		);

		$intent_id = 'pp_callback_disabled_012';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = $this->build_raw_json( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );
	}
}
