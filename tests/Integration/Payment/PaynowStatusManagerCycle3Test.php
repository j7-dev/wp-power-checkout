<?php
/**
 * PayNow StatusManager Cycle 3 測試（TDD Red 階段）
 *
 * 測試目標：J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager（完整化）
 *
 * Cycle 1 已存在（PaynowStatusManagerTest.php）：
 *   - StatusManager 可實例化
 *   - Amount 不符 / 為 0 → pending
 *
 * 本檔（Cycle 3）補完：
 *   - 即時付款成功完整路徑（Status=Success → processing + payment_detail 寫入）
 *   - 離線 ATM 待繳（PaymentType=ATM，Status 非 Success → payment_info + pending）
 *   - 離線超商代碼待繳（PaymentType=ConvenienceStore → payment_info + pending）
 *   - 離線繳費完成（第二次 Webhook Status=Success → processing）
 *   - 付款失敗（Status=Failed → pending + order note）
 *   - 冪等：已 processing → skip
 *   - 未知 Status → pending + order note
 *
 * ⚠️ 幣別守衛：store 預設幣別為 USD；PayNow 只接 TWD。
 *   成功路徑測試訂單必須 set_currency('TWD') + save()。
 *
 * 設計依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 17
 *   - specs/features/payment/paynow-callback.feature（5 scenario）
 *   - specs/features/payment/paynow-payment-info.feature（離線付款）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowStatusManagerCycle3Test"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use Tests\Integration\TestCase;

/**
 * PayNow StatusManager Cycle 3 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowStatusManagerCycle3Test extends TestCase {

	// ========== 測試常數 ==========

	private const PAYMENT_INTENT_ID = 'pp_cycle3_test_1234567890abcdef';

	// =====================================================================
	// 輔助方法
	// =====================================================================

	/**
	 * 建立 PayNow 測試訂單（寫入 PaymentIntentId + 設定 TWD 幣別）
	 *
	 * ⚠️ 幣別守衛：PayNow 金額比對以 TWD 整數 ceil 為準；
	 *   store 預設幣別 USD，不設 TWD 則成功路徑金額比對永遠失敗。
	 *
	 * @param string $intent_id
	 * @param int    $total
	 * @param string $status WC 訂單初始狀態
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

		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( $intent_id );

		return $order;
	}

	/**
	 * 建立即時付款成功的 Webhook payload（Status=Success + CreditCard）
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
			'PaymentNo'       => 'PCTEST001',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => 'TXN0000000001',
			'Amount'          => (string) $amount,
			'Currency'        => 'TWD',
			'PaymentType'     => $payment_type,
		];
	}

	/**
	 * 建立離線待繳 payload（Status=Pending 或 WaitForPayment）
	 *
	 * @param string $intent_id
	 * @param int    $amount
	 * @param string $payment_type ATM | ConvenienceStore
	 * @return array<string, mixed>
	 */
	private function make_pending_payload(
		string $intent_id,
		int $amount = 1000,
		string $payment_type = 'ATM'
	): array {
		return [
			'ConnectId'       => '26c06b86-1324-48b6-8017-29e4efa649e6',
			'RequestId'       => '09020f76-1405-4db2-b30a-ba30de629c05',
			'Status'          => 'WaitForPayment',
			'OrderNo'         => 'PCTEST002',
			'PaymentNo'       => 'VIRTUALACCOUNT001',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => '',
			'Amount'          => (string) $amount,
			'Currency'        => 'TWD',
			'PaymentType'     => $payment_type,
			'Meta'            => [
				'BankCode'       => '013',
				'VirtualAccount' => '9876543210987654',
				'ExpireDate'     => '2026/12/31 23:59:59',
			],
		];
	}

	/**
	 * 建立付款失敗 payload（Status=Failed）
	 *
	 * @param string $intent_id
	 * @return array<string, mixed>
	 */
	private function make_failed_payload( string $intent_id ): array {
		return [
			'ConnectId'       => '26c06b86-1324-48b6-8017-29e4efa649e6',
			'RequestId'       => '09020f76-1405-4db2-b30a-ba30de629c05',
			'Status'          => 'Failed',
			'OrderNo'         => 'PCTEST003',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => '',
			'Amount'          => '1000',
			'Currency'        => 'TWD',
			'PaymentType'     => 'CreditCard',
		];
	}

	// =====================================================================
	// Happy Path：即時付款成功（Status=Success + CreditCard）
	// =====================================================================

	/**
	 * 即時付款成功（Status=Success + 金額相符）→ 訂單轉 processing
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_即時付款成功_訂單轉processing(): void {
		$order   = $this->create_paynow_order( self::PAYMENT_INTENT_ID, 1000 );
		$payload = $this->make_success_payload( self::PAYMENT_INTENT_ID, 1000 );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 即時付款成功 → 寫入 _pc_paynow_payment_detail（含 TransactionNo）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_即時付款成功_寫入payment_detail(): void {
		$order   = $this->create_paynow_order( self::PAYMENT_INTENT_ID, 1000 );
		$payload = $this->make_success_payload( self::PAYMENT_INTENT_ID, 1000 );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new PaynowMetaKeys( $order );
		$detail    = $meta_keys->get_payment_detail();

		$this->assertNotEmpty( $detail, '_pc_paynow_payment_detail 應有資料' );
	}

	/**
	 * 即時付款成功 → payment_complete() 使用 TransactionNo
	 *
	 * payment_complete() 會設定 transaction_id；驗證 WC order transaction_id 已寫入
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_即時付款成功_transaction_id已寫入(): void {
		$order   = $this->create_paynow_order( self::PAYMENT_INTENT_ID, 1000 );
		$payload = $this->make_success_payload( self::PAYMENT_INTENT_ID, 1000, 'CreditCard' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assertNotEmpty(
			$order->get_transaction_id(),
			'payment_complete() 後 transaction_id 應不為空'
		);
	}

	/**
	 * LINE Pay 即時付款成功（Status=Success + PaymentType=LINEPayOnline）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_LINEPay即時付款成功_訂單轉processing(): void {
		$order   = $this->create_paynow_order( self::PAYMENT_INTENT_ID . '_linepay', 2000 );
		$payload = $this->make_success_payload( self::PAYMENT_INTENT_ID . '_linepay', 2000, 'LINEPayOnline' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Happy Path：離線付款 ATM（兩階段）
	// =====================================================================

	/**
	 * ATM 待繳 Webhook（Status=WaitForPayment + PaymentType=ATM）→ 寫入 _pc_paynow_payment_info + pending
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM待繳_寫入payment_info並維持pending(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_atm';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_pending_payload( $intent_id, 1000, 'ATM' );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new PaynowMetaKeys( $order );

		// 維持 pending
		$this->assert_order_status( $order, 'pending' );

		// 寫入 payment_info（虛擬帳號資料）
		$payment_info = $meta_keys->get_payment_info();
		$this->assertNotEmpty( $payment_info, '_pc_paynow_payment_info 應有虛擬帳號資料' );
	}

	/**
	 * ATM 繳費完成（第二次 Webhook Status=Success）→ 轉 processing
	 *
	 * 離線付款兩階段流程：
	 *   1. WaitForPayment → payment_info + pending（上一個測試）
	 *   2. Success        → payment_complete → processing（本測試）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM繳費完成_轉processing(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_atm_paid';
		$order     = $this->create_paynow_order( $intent_id, 1000 );

		// 模擬先已收到 WaitForPayment（payment_info 已寫，訂單 pending）
		$pending_payload = $this->make_pending_payload( $intent_id, 1000, 'ATM' );
		$manager         = new StatusManager( $pending_payload, $order );
		$manager->update_order_status();

		// 重新載入訂單（確認仍 pending）
		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );

		// 第二次 Webhook：Status=Success（繳費完成）
		$success_payload = $this->make_success_payload( $intent_id, 1000, 'ATM' );
		$manager2        = new StatusManager( $success_payload, $order );
		$manager2->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Happy Path：離線付款 ConvenienceStore（超商代碼）
	// =====================================================================

	/**
	 * 超商代碼待繳（Status=WaitForPayment + PaymentType=ConvenienceStore）→ payment_info + pending
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_超商代碼待繳_寫入payment_info並維持pending(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_cvs';
		$order     = $this->create_paynow_order( $intent_id, 1500 );
		$payload   = $this->make_pending_payload( $intent_id, 1500, 'ConvenienceStore' );
		// 超商代碼的 Meta 格式
		$payload['Meta'] = [
			'CvsPaymentNo' => 'T123456789012',
			'Store'        => 'FAMILY',
			'ExpireDate'   => '2026/12/31 23:59:59',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order     = \wc_get_order( $order->get_id() );
		$meta_keys = new PaynowMetaKeys( $order );

		$this->assert_order_status( $order, 'pending' );
		$payment_info = $meta_keys->get_payment_info();
		$this->assertNotEmpty( $payment_info, '_pc_paynow_payment_info 應有超商代碼資料' );
	}

	/**
	 * 超商代碼繳費完成（第二次 Webhook Status=Success）→ 轉 processing
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_超商代碼繳費完成_轉processing(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_cvs_paid';
		$order     = $this->create_paynow_order( $intent_id, 1500 );

		// 第一次：WaitForPayment
		$pending_payload         = $this->make_pending_payload( $intent_id, 1500, 'ConvenienceStore' );
		$pending_payload['Meta'] = [
			'CvsPaymentNo' => 'T123456789012',
			'Store'        => 'FAMILY',
			'ExpireDate'   => '2026/12/31 23:59:59',
		];
		( new StatusManager( $pending_payload, $order ) )->update_order_status();

		// 第二次：Success
		$order           = \wc_get_order( $order->get_id() );
		$success_payload = $this->make_success_payload( $intent_id, 1500, 'ConvenienceStore' );
		( new StatusManager( $success_payload, $order ) )->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Error / Edge：付款失敗
	// =====================================================================

	/**
	 * Status=Failed → 訂單維持 pending + 寫入 order note
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_付款失敗_維持pending並寫order_note(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_failed';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_failed_payload( $intent_id );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, 'Failed' );
	}

	/**
	 * 未知 Status（如 'Unknown'）→ 維持 pending
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_未知Status_維持pending(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_unknown';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = [
			'Status'          => 'Unknown',
			'PaymentIntentId' => $intent_id,
			'Amount'          => '1000',
			'PaymentType'     => 'CreditCard',
			'TransactionNo'   => '',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Status 空字串 → 維持 pending
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_Status空字串_維持pending(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_empty_status';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = [
			'Status'          => '',
			'PaymentIntentId' => $intent_id,
			'Amount'          => '1000',
			'PaymentType'     => 'CreditCard',
			'TransactionNo'   => '',
		];

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Edge：冪等防重複
	// =====================================================================

	/**
	 * 已 processing 的訂單收到重複 Success Webhook → 不重複呼叫 payment_complete
	 *
	 * 驗證方式：確認訂單仍 processing（狀態未改變）且沒有例外
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_冪等_已processing訂單重複通知_不重複處理(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_idempotent';
		$order     = $this->create_paynow_order( $intent_id, 1000, 'processing' );
		$payload   = $this->make_success_payload( $intent_id, 1000 );

		// 已是 processing，重複通知
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status(); // 不應丟例外

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 連續兩次 Success Webhook → 第二次靜默冪等，狀態仍 processing
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_冪等_連續兩次成功Webhook(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_double_success';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );

		// 第一次
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 重新載入後第二次
		$order    = \wc_get_order( $order->get_id() );
		$manager2 = new StatusManager( $payload, $order );
		$manager2->update_order_status(); // 不應丟例外或重複 payment_complete

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'processing' );
	}

	// =====================================================================
	// Edge：幣別守衛（store 預設 USD，PayNow 只接 TWD）
	// =====================================================================

	/**
	 * 訂單幣別非 TWD（USD）→ 金額比對失敗，維持 pending
	 *
	 * ⚠️ PayNow 只接 TWD；PayNow Webhook Amount 為 TWD 整數。
	 *   若訂單幣別為 USD，get_total() 返回 USD 金額，ceil 後與 TWD Amount 不符，
	 *   StatusManager 的金額守衛應能攔截。
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_幣別非TWD_金額守衛攔截維持pending(): void {
		// 刻意不設 TWD，訂單幣別為 USD（store 預設）
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'paynow',
				'total'          => 1000,
			]
		);
		// 不設定 TWD currency → 訂單幣別為 USD
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( self::PAYMENT_INTENT_ID . '_usd' );

		$payload = $this->make_success_payload( self::PAYMENT_INTENT_ID . '_usd', 1000 );
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// USD 訂單金額 $1000 USD ≠ TWD 1000（或 ceil 結果不同）
		// 此測試依賴幣別 guard 或 amount guard 攔截
		// Red 階段：若 Cycle 3 有幣別守衛，此測試驗證其行為
		$order = \wc_get_order( $order->get_id() );
		// 合理行為：維持 pending（currency guard 或 amount guard 攔截）
		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Edge：金額邊界（補充 Cycle 1 沒有覆蓋的場景）
	// =====================================================================

	/**
	 * Amount 為浮點數字串（如 '999.99'）→ 非純整數 → ctype_digit 失敗 → pending
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount浮點數字串_金額守衛攔截(): void {
		$intent_id         = self::PAYMENT_INTENT_ID . '_float';
		$order             = $this->create_paynow_order( $intent_id, 1000 );
		$payload           = $this->make_success_payload( $intent_id, 1000 );
		$payload['Amount'] = '999.99'; // 浮點數，非純整數

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Amount 為負數字串（'-1000'）→ ctype_digit 失敗（負號非 digit）→ pending
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount負數字串_金額守衛攔截(): void {
		$intent_id         = self::PAYMENT_INTENT_ID . '_negative';
		$order             = $this->create_paynow_order( $intent_id, 1000 );
		$payload           = $this->make_success_payload( $intent_id, 1000 );
		$payload['Amount'] = '-1000'; // 負數

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Amount 欄位缺失（payload 無 Amount key）→ pending
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount欄位缺失_金額守衛攔截(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_noamount';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1000 );
		unset( $payload['Amount'] ); // 移除 Amount

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Amount 與訂單金額不符（多 1 元）→ 金額守衛攔截
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount多一元_金額守衛攔截(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_overpay';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 1001 ); // 多 1 元

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * Amount 少 1 元 → 金額守衛攔截
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount少一元_金額守衛攔截(): void {
		$intent_id = self::PAYMENT_INTENT_ID . '_underpay';
		$order     = $this->create_paynow_order( $intent_id, 1000 );
		$payload   = $this->make_success_payload( $intent_id, 999 ); // 少 1 元

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	// =====================================================================
	// Security：不信任前端 / payload 內容
	// =====================================================================

	/**
	 * payload Status=Success 但 Amount=0 → pending（成功交易金額不可能為 0）
	 *
	 * Cycle 1 已有此測試，此處作 Cycle 3 回歸確認
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_Amount為零且Status為Success_被金額守衛攔截(): void {
		$intent_id         = self::PAYMENT_INTENT_ID . '_zero_success';
		$order             = $this->create_paynow_order( $intent_id, 1000 );
		$payload           = $this->make_success_payload( $intent_id, 1000 );
		$payload['Amount'] = '0'; // Amount=0

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * payload Status 大小寫不一致（'success' 小寫）→ 不被視為成功 → pending
	 *
	 * PayNow 規格：Status='Success'（首字大寫），小寫視為不符
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_Status小寫success_不視為成功(): void {
		$intent_id         = self::PAYMENT_INTENT_ID . '_lowercase';
		$order             = $this->create_paynow_order( $intent_id, 1000 );
		$payload           = $this->make_success_payload( $intent_id, 1000 );
		$payload['Status'] = 'success'; // 小寫

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * payload Status='SUCCESS'（全大寫）→ 不被視為成功 → pending
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_Status全大寫SUCCESS_不視為成功(): void {
		$intent_id         = self::PAYMENT_INTENT_ID . '_uppercase';
		$order             = $this->create_paynow_order( $intent_id, 1000 );
		$payload           = $this->make_success_payload( $intent_id, 1000 );
		$payload['Status'] = 'SUCCESS'; // 全大寫

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		$order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $order, 'pending' );
	}
}
