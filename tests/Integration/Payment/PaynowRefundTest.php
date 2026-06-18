<?php
/**
 * PayNow（立吉富）退款整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/paynow-refund.feature
 *
 * 驗證：
 *  - 信用卡（PaymentType=CreditCard）退款走 REST POST /api/v1/payment-intents/:id/refunds，
 *    成功時寫入 _pc_paynow_refund_detail + order note。
 *  - ATM 退款必填 bankCode / bankBranchCode / bankAccount；缺三欄時拒絕送出。
 *  - 超商代碼（ConvenienceStore）/ LINE Pay / ApplePay 等不支援 API 退款 →
 *    WP_Error('refund_unsupported')（人工退款路徑）。
 *  - 退款被拒絕（status=rejected）時記錄 order note（含 RejectReason），不標記為已退款。
 *  - 金額守衛：≤0 / 超額 → false 或 WP_Error，不呼叫 API。
 *  - 判定依 _pc_paynow_payment_detail 的 PaymentType（後端 source of truth，非前端傳入）。
 *  - 非本 gateway 訂單（payment_method != 'paynow）靜默略過。
 *  - wpdb ROLLBACK on 退款 API 失敗（比照 PayuniUniEmbed 模式）。
 *
 * TDD 紅燈：
 *  PaynowGateway::process_gateway_refund / handle_payment_gateway_refund 靜態方法尚未存在；
 *  PaynowRestClient::refund / retrieve_refund 方法尚未實作（Cycle 4）。
 *
 * Mock 手法：
 *  外部 HTTP 一律透過 WP filter `paynow_mock_refund_response` mock；
 *  例外模擬：`paynow_mock_refund_exception` filter 使 RestClient::refund 拋例外。
 *  tearDown 移除所有 filter。
 *
 * PayNow REST API 參考（payment-rest-api.md §5）：
 *  端點 POST /api/v1/payment-intents/:id/refunds；
 *  退款 result.type：success / failed / rejected（RejectReason） / processing / validation_error；
 *  ATM 退款必填 bankCode + bankBranchCode + bankAccount。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *       --filter PaynowRefund --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowTradeNo;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 退款測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowRefundTest extends TestCase {

	/** @var string PayNow 測試用 PrivateKey（mock 模式不打真實 API） */
	private const PRIVATE_KEY = 'test_private_key_paynow_refund_001';

	/** @var string PayNow 測試用 PublicKey */
	private const PUBLIC_KEY = 'test_public_key_paynow_refund_001';

	/**
	 * 每次測試前啟用 paynow（test 模式），設定 mock 退款成功回應（信用卡）
	 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			PaynowSettingsDTO::ID,
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => self::PUBLIC_KEY,
				'private_key' => self::PRIVATE_KEY,
			]
		);

		// 預設 MOCK：RestClient::refund 回 type=success（信用卡退款成功）
		\add_filter(
			'paynow_mock_refund_response',
			static function ( mixed $default ): mixed {
				return [
					'status'  => 200,
					'type'    => 'success',
					'message' => '退款成功',
					'result'  => [
						'id'     => 'rf_test_credit_001',
						'type'   => 'success',
						'amount' => 1000,
					],
				];
			}
		);
	}

	/**
	 * 每次測試後清理 filter 與 option
	 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\remove_all_filters( 'paynow_mock_refund_response' );
		\remove_all_filters( 'paynow_mock_refund_exception' );
		\delete_option( ProviderUtils::get_option_name( PaynowSettingsDTO::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立已付款的 PayNow 訂單（含 PaymentIntentId + payment_detail，TWD）
	 *
	 * @param string $payment_intent_id PayNow PaymentIntentId（pp_xxx）
	 * @param string $payment_type      PaymentType（e.g. 'CreditCard'、'ATM'、'ConvenienceStore'）
	 * @param float  $total             訂單金額
	 * @return \WC_Order
	 */
	private function create_paid_paynow_order(
		string $payment_intent_id = 'pp_test_credit_001',
		string $payment_type = 'CreditCard',
		float $total = 1000.0
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PaynowGateway::ID,
				'total'          => $total,
			]
		);

		// ⚠️ PayNow 僅支援 TWD；store 預設幣別 USD 需強制覆寫
		$order->set_currency( 'TWD' );
		$order->save();

		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_trade_no( PaynowTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_intent_id( $payment_intent_id );
		$meta_keys->update_payment_detail(
			[
				'PaymentIntentId' => $payment_intent_id,
				'PaymentType'     => $payment_type,
				'Amount'          => (int) $total,
				'Status'          => 'success',
			]
		);

		return $order;
	}

	/**
	 * 建立 gateway 標記的 WC refund（refunded_payment=true）
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 退款金額
	 * @param string    $reason 退款原因
	 * @return \WC_Order_Refund
	 */
	private function create_gateway_refund(
		\WC_Order $order,
		float $amount,
		string $reason = '顧客取消訂單'
	): \WC_Order_Refund {
		$refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => $amount,
				'reason'   => $reason,
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$refund->set_refunded_payment( true ); // 標記為「經 gateway 退款」
		$refund->save();
		return $refund;
	}

	// ========== Smoke ==========

	/**
	 * PaynowGateway 可被實例化且 ID 正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway可被實例化且ID正確(): void {
		$gateway = new PaynowGateway();
		$this->assertInstanceOf( PaynowGateway::class, $gateway );
		$this->assertSame( 'paynow', $gateway->id );
	}

	/**
	 * PaynowGateway 有靜態方法 handle_payment_gateway_refund（Cycle 4 待實作）
	 *
	 * 紅燈原因：handle_payment_gateway_refund 靜態方法 Cycle 4 才新增
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway有handle_payment_gateway_refund靜態方法(): void {
		$this->assertTrue(
			\method_exists( PaynowGateway::class, 'handle_payment_gateway_refund' ),
			'PaynowGateway::handle_payment_gateway_refund 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	// ========== Happy Path：信用卡退款成功 ==========

	/**
	 * 信用卡全額退款成功：呼叫 REST refunds，寫入 _pc_paynow_refund_detail + order note
	 *
	 * 規格依據：paynow-refund.feature 場景：信用卡訂單全額退款成功
	 * API：POST /api/v1/payment-intents/pp_test100/refunds
	 *
	 * 紅燈原因：handle_payment_gateway_refund 尚未實作 + RestClient::refund 尚未存在
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_信用卡全額退款成功寫入refund_detail並記錄order_note(): void {
		// Given: PayNow 信用卡訂單（PaymentType=CreditCard），已付款 1000 元
		$order  = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_credit_full_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$refund = $this->create_gateway_refund( $order, 1000.0, '顧客取消訂單' );

		// When: 觸發退款 handler
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 退款成功 → 寫入 _pc_paynow_refund_detail + order note 含退款成功訊息
		$fresh_order = \wc_get_order( $order->get_id() );
		$meta_keys   = new PaynowMetaKeys( $fresh_order );
		$refund_data = $meta_keys->get_refund_detail();

		$this->assertNotEmpty( $refund_data, '_pc_paynow_refund_detail 應有寫入退款資料' );
		$this->assert_order_note_contains( $fresh_order, '退款' );

		// refund 物件未被刪除（成功不刪 refund）
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $refund->get_id() ) );
	}

	/**
	 * 信用卡部分退款成功：退款金額來自 WC refund 物件（非前端 $amount 參數）
	 *
	 * 規格依據：paynow-refund.feature 規則：退款金額不得超過訂單可退款餘額
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_信用卡部分退款成功金額來自WC_refund物件(): void {
		// Given: 訂單 1000 元，建立 300 元退款
		$order  = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_credit_partial_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$refund = $this->create_gateway_refund( $order, 300.0, '部分退款' );

		// When
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: note 含 300（依 refund 物件金額）；退款成功
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '退款' );
		$this->assert_order_note_contains( $fresh_order, '300' );
	}

	// ========== Error：ATM 缺銀行資料拒絕送出 ==========

	/**
	 * ATM 退款缺 bankCode / bankBranchCode / bankAccount → 拒絕送出 + order note 提示必填
	 *
	 * 規格依據：paynow-refund.feature 場景：ATM 訂單退款必填銀行帳號
	 * 實作計劃：specs/open-issue/paynow-implementation-plan.md 流程 5
	 *
	 * 紅燈原因：process_refund ATM 分支 + 必填守衛 Cycle 4 才實作
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM退款缺銀行資料時拒絕送出並記錄order_note(): void {
		// Given: PayNow ATM 訂單（PaymentType=ATM），已付款 1500 元
		$order = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_atm_001',
			payment_type: 'ATM',
			total: 1500.0
		);
		// 建立退款但不帶銀行資料（模擬管理員未填必填欄位）
		$refund = $this->create_gateway_refund( $order, 1500.0, 'ATM 退款測試' );

		// When: handle（沒有帶 bankCode / bankBranchCode / bankAccount）
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 後端拒絕送出 + order note 含銀行必填提示；_pc_paynow_refund_detail 不應有成功資料
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '銀行' );

		$meta_keys   = new PaynowMetaKeys( $fresh_order );
		$refund_data = $meta_keys->get_refund_detail();
		// 不應含 type=success（拒絕送出，未呼叫 API）
		$this->assertNotSame( 'success', $refund_data['type'] ?? '', 'ATM 缺銀行資料不應有成功退款資料' );
	}

	/**
	 * 超商代碼訂單不支援 API 退款 → WP_Error('refund_unsupported')
	 *
	 * 規格依據：paynow-refund.feature 場景：超商代碼訂單不支援 API 退款
	 *
	 * 紅燈原因：process_refund 超商分流 Cycle 4 才實作
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_超商代碼訂單退款回WP_Error_refund_unsupported(): void {
		// Given: PayNow 超商代碼訂單（PaymentType=ConvenienceStore）
		$order   = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_cvs_001',
			payment_type: 'ConvenienceStore',
			total: 800.0
		);
		$gateway = new PaynowGateway();

		// When: 嘗試發起 API 退款
		$result = $gateway->process_refund( $order->get_id(), 800.0, '超商代碼退款測試' );

		// Then: 回傳正規化 UNSUPPORTED \WP_Error（取代舊 refund_unsupported）
		$this->assertTrue( \is_wp_error( $result ), '超商代碼訂單退款應回 WP_Error，實際回：' . \gettype( $result ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			ErrorCode::UNSUPPORTED->value,
			$result->get_error_code(),
			'超商代碼退款 WP_Error 代碼應為正規化 UNSUPPORTED'
		);
	}

	/**
	 * LINE Pay 訂單不支援 API 退款 → WP_Error('refund_unsupported')
	 *
	 * 規格依據：paynow-refund.feature 規則：不支援 API 退款的付款方式回傳 WP_Error
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_LINEPay訂單退款回WP_Error_refund_unsupported(): void {
		// Given: PayNow LINE Pay 訂單（PaymentType=LINEPayOnline）
		$order   = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_linepay_001',
			payment_type: 'LINEPayOnline',
			total: 500.0
		);
		$gateway = new PaynowGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 500.0, 'LINE Pay 退款測試' );

		// Then
		$this->assertTrue( \is_wp_error( $result ), 'LINE Pay 訂單退款應回 WP_Error' );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * ApplePay 訂單不支援 API 退款 → WP_Error('refund_unsupported')
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_ApplePay訂單退款回WP_Error_refund_unsupported(): void {
		// Given: PayNow Apple Pay 訂單
		$order   = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_applepay_001',
			payment_type: 'ApplePay',
			total: 600.0
		);
		$gateway = new PaynowGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 600.0, 'Apple Pay 退款測試' );

		// Then
		$this->assertTrue( \is_wp_error( $result ), 'Apple Pay 訂單退款應回 WP_Error' );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * 退款被拒絕（status=rejected）時記錄 order note 含 RejectReason，不標記已退款
	 *
	 * 規格依據：paynow-refund.feature 場景：退款被拒絕時記錄原因不標記已退款
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_退款被拒絕時記錄RejectReason並不標記已退款(): void {
		// Given: 改 mock 回 rejected（含 RejectReason）
		\remove_all_filters( 'paynow_mock_refund_response' );
		\add_filter(
			'paynow_mock_refund_response',
			static function ( mixed $default ): mixed {
				return [
					'status'  => 200,
					'type'    => 'rejected',
					'message' => '退款被拒絕',
					'result'  => [
						'id'           => 'rf_test_rejected_001',
						'type'         => 'rejected',
						'RejectReason' => '超過退款期限',
					],
				];
			}
		);

		$order     = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_rejected_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$refund    = $this->create_gateway_refund( $order, 1000.0, '測試退款被拒' );
		$refund_id = $refund->get_id();

		// When
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: order note 含「拒絕」/「RejectReason」/「超過退款期限」；退款不標記成功
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '拒絕' );
		// refund 被刪除（rejected 視同失敗，ROLLBACK 刪 refund）
		$this->assertFalse( \wc_get_order( $refund_id ), '退款被拒絕時 refund 應被刪除' );
	}

	/**
	 * 退款 API 失敗（例外）→ wpdb ROLLBACK + 刪除 refund + 記錄失敗 order note
	 *
	 * 規格依據：paynow-refund.feature 規則：退款失敗時記錄 order note
	 * 比照 PayuniUniEmbedRefundTest::test_close失敗時ROLLBACK刪除refund並記錄失敗order_note
	 *
	 * 紅燈原因：handle_payment_gateway_refund ROLLBACK 邏輯 Cycle 4 才實作
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_退款API失敗時ROLLBACK刪除refund並記錄失敗order_note(): void {
		// Given: RestClient::refund 拋例外（模擬 API 失敗）
		\remove_all_filters( 'paynow_mock_refund_response' );
		\add_filter(
			'paynow_mock_refund_exception',
			static function (): bool {
				return true; // 觸發例外
			}
		);

		$order     = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_fail_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$refund    = $this->create_gateway_refund( $order, 1000.0, '退款失敗測試' );
		$refund_id = $refund->get_id();

		// When: 退款 API 失敗
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 失敗 note + refund 被刪除（ROLLBACK）
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( \wc_get_order( $refund_id ), '退款 API 失敗時 refund 應被刪除' );
	}

	/**
	 * 非本 gateway 訂單不由本 gateway 處理退款（靜默略過）
	 *
	 * 規格依據：paynow-refund.feature 規則：前置（狀態）—訂單付款方式必須為 paynow
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_非本gateway訂單不處理退款靜默略過(): void {
		// Given: SLP 訂單（payment_method != 'paynow'）
		$order  = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000.0,
			]
		);
		$refund = $this->create_gateway_refund( $order, 1000.0, '非本 gateway 測試' );

		// When: PayNow gateway 嘗試處理非本 gateway 訂單的退款
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: refund 仍存在（未被刪除），無退款成功 note
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $refund->get_id() ) );
		$notes = \wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( '退款成功', $note->content, '非本 gateway 不應觸發退款成功 note' );
		}
	}

	// ========== Edge：金額守衛 ==========

	/**
	 * 零金額退款（$amount=0）→ process_refund 回 false 或 WP_Error，不呼叫 API
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_零金額退款不呼叫API回false或WP_Error(): void {
		// Given: PayNow 信用卡訂單
		$order   = $this->create_paid_paynow_order( total: 1000.0 );
		$gateway = new PaynowGateway();

		// When: 嘗試退款 0 元
		$result = $gateway->process_refund( $order->get_id(), 0.0, '零金額測試' );

		// Then: 不允許，且錯誤碼不是 'refund_not_implemented'（未實作的降級）
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'零金額退款應回 WP_Error 或 false，實際回：' . \gettype( $result )
		);
		if ( \is_wp_error( $result ) ) {
			$this->assertNotSame( 'refund_not_implemented', $result->get_error_code() );
		}
	}

	/**
	 * 退款金額超過訂單金額時 process_refund 回 WP_Error 或 false
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_退款金額超過訂單金額時回WP_Error或false(): void {
		// Given: 訂單金額 1000，嘗試退款 2000（超額）
		$order   = $this->create_paid_paynow_order( total: 1000.0 );
		$gateway = new PaynowGateway();

		// When: 超額退款
		$result = $gateway->process_refund( $order->get_id(), 2000.0, '超額退款測試' );

		// Then: 不允許
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'超額退款應回 WP_Error 或 false'
		);
	}

	/**
	 * 負數金額退款 → process_refund 回 WP_Error 或 false
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_負數金額退款回WP_Error或false(): void {
		// Given: PayNow 訂單
		$order   = $this->create_paid_paynow_order( total: 1000.0 );
		$gateway = new PaynowGateway();

		// When: 負數退款
		$result = $gateway->process_refund( $order->get_id(), -100.0, '負數金額測試' );

		// Then: 不允許
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'負數退款應回 WP_Error 或 false'
		);
	}

	/**
	 * 無 _pc_paynow_payment_intent_id 的訂單 → 無法取得退款端點識別碼，退款失敗
	 *
	 * PayNow 退款端點為 POST /api/v1/payment-intents/:id/refunds，缺 intent_id 無法組 URL
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_缺PaymentIntentId時退款失敗(): void {
		// Given: 訂單沒有 _pc_paynow_payment_intent_id
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PaynowGateway::ID,
				'total'          => 1000.0,
			]
		);
		$order->set_currency( 'TWD' );
		$order->save();
		// 刻意只寫 PaymentType，不寫 PaymentIntentId
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_detail( [ 'PaymentType' => 'CreditCard' ] );

		$refund    = $this->create_gateway_refund( $order, 1000.0, '缺 intent_id 測試' );
		$refund_id = $refund->get_id();

		// When
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 退款失敗 note + refund 被刪除
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( \wc_get_order( $refund_id ), '缺 PaymentIntentId 退款應刪除 refund' );
	}

	// ========== Security：退款路由判定依後端 payment_detail ==========

	/**
	 * 退款路由判定依 _pc_paynow_payment_detail 的 PaymentType（後端），非前端傳入
	 *
	 * 規格依據：paynow-refund.feature 規則：前置（狀態）—退款須帶 reason
	 * 實作計劃：流程 5「判定依 _pc_paynow_payment_detail 的 PaymentType（非前端）」
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_安全_退款路由判定依後端PaymentType非前端傳入(): void {
		// Given: 訂單 payment_detail 為 ConvenienceStore（後端記錄），
		//        即使前端嘗試直接呼叫 process_refund 也應以後端判定為準
		$order   = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_security_001',
			payment_type: 'ConvenienceStore',
			total: 800.0
		);
		$gateway = new PaynowGateway();

		// When: 直接呼叫 process_refund（模擬前端不當請求）
		$result = $gateway->process_refund( $order->get_id(), 800.0, '安全測試退款' );

		// Then: 依後端 PaymentType=ConvenienceStore → WP_Error('refund_unsupported')
		$this->assertTrue(
			\is_wp_error( $result ),
			'後端 PaymentType=ConvenienceStore 應回 WP_Error，不因前端金額傳入而走信用卡退款路徑'
		);
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * 退款金額來自 WC refund 物件（非前端 $amount 參數），防止前端竄改金額
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_安全_退款金額來自WC_refund物件非前端amount參數(): void {
		// Given: refund 物件金額為 300
		$order  = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_security_amount_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$refund = $this->create_gateway_refund( $order, 300.0, '安全金額測試' );

		// When: handle 執行（gateway 應讀 refund 物件金額 300，不信任外部傳入值）
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: note 含 '300'（依 refund 物件金額）；退款成功
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '300' );
	}

	/**
	 * PayNow meta 前綴 _pc_paynow_ 與其他 gateway（如 _pc_payuni_uni_*）完全隔離
	 *
	 * 斷言退款讀取的是 _pc_paynow_payment_detail，不會誤讀其他 gateway 的 meta
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_安全_退款讀取paynow_meta不讀其他gateway_meta(): void {
		// Given: PayNow 訂單，同時在 _pc_payuni_uni_payment_detail 寫入不同的 PaymentType
		$order = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_isolation_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		// 偽造 UNi Embed meta：如果退款誤讀 UNi Embed meta，會看到 PaymentType=ConvenienceStore
		$order->update_meta_data( '_pc_payuni_uni_payment_detail', [ 'PaymentType' => 'ConvenienceStore' ] );
		$order->save_meta_data();

		// When: 讀取 PaynowMetaKeys
		$meta_keys = new PaynowMetaKeys( \wc_get_order( $order->get_id() ) );
		$detail    = $meta_keys->get_payment_detail();

		// Then: 讀到的是 _pc_paynow_payment_detail（CreditCard），不是 UNi Embed 的（ConvenienceStore）
		$this->assertSame(
			'CreditCard',
			$detail['PaymentType'] ?? '',
			'PaynowMetaKeys 應讀取 _pc_paynow_payment_detail，不應讀到 UNi Embed 的 ConvenienceStore'
		);
		$this->assertNotSame(
			'ConvenienceStore',
			$detail['PaymentType'] ?? '',
			'退款絕不應讀取 UNi Embed 的 _pc_payuni_uni_payment_detail'
		);
	}

	/**
	 * 手動退款（無 _refunded_payment 標記）不發 API（安全防禦）
	 *
	 * handle_payment_gateway_refund 需檢查 refund->get_refunded_payment() 為 true
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_安全_手動退款無標記不發API(): void {
		// Given: 建立「手動」退款（refunded_payment=false，預設值）
		$order         = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_manual_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$manual_refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 500.0,
				'reason'   => '純手動退款，不發 API',
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $manual_refund );
		// 注意：不呼叫 set_refunded_payment(true)，維持 false 預設值

		// When: 呼叫 handle
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $manual_refund->get_id() );

		// Then: 不應有退款成功 note；refund 仍存在
		$notes = \wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( '退款成功', $note->content, '手動退款不應觸發 API 退款成功 note' );
		}
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $manual_refund->get_id() ) );
	}

	// ========== Security F-3：信用卡退款 body 不帶空 bank 三欄 ==========

	/**
	 * F-3：信用卡退款時送往 PayNow API 的 request body 不含空 bank 三欄
	 *
	 * 現況漏洞：RefundParams::create(['amount', 'reason']) 的 to_array() 輸出所有 public 屬性，
	 *   含 bankCode='' / bankBranchCode='' / bankAccount=''，空欄位被送進 refund API body，
	 *   導致 PayNow API 收到非預期欄位（validation_error 或付款方式誤判）。
	 *
	 * 加固期望：信用卡退款的 RefundParams::to_array() 不應包含空的 bank 三欄位；
	 *   或者送到 PayNow API 的 request body 中，這三個空欄位不存在（已被過濾）。
	 *
	 * 驗證手法（兩層）：
	 *   1. DTO 層：RefundParams::create(['amount'=>..., 'reason'=>...]) 的 to_array()
	 *      不應輸出空字串的 bankCode / bankBranchCode / bankAccount。
	 *   2. HTTP 層：以 pre_http_request filter 攔截實際送出的 HTTP request body，
	 *      確認 JSON body 不含這三個空欄位。
	 *
	 * Red 狀態：目前 RefundParams::to_array() 會輸出所有 public 屬性（含空 bank 欄位），
	 *   以下測試會失敗（assertArrayNotHasKey 失敗，空欄位確實存在）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_安全F3_信用卡退款RefundParams_to_array不含空bank欄位(): void {
		// Given: 只帶 amount + reason（信用卡退款，不需 bank 欄位）
		$params = \J7\PowerCheckout\Domains\Payment\Paynow\DTOs\RefundParams::create(
			[
				'amount' => 1000,
				'reason' => '信用卡退款測試',
			]
		);

		// When: 轉換為 array（這是送給 PayNow API 的 body 來源）
		$body = $params->to_array();

		// Then: 不應含空的 bank 三欄（空字串欄位不得送進 refund API）
		// 加固期望：這三個欄位應被過濾掉，或其值不為空字串
		$this->assertFalse(
			isset( $body['bankCode'] ) && '' === $body['bankCode'],
			'信用卡退款 RefundParams::to_array() 不得包含空字串的 bankCode（實際包含：' . ( $body['bankCode'] ?? 'N/A' ) . '）'
		);
		$this->assertFalse(
			isset( $body['bankBranchCode'] ) && '' === $body['bankBranchCode'],
			'信用卡退款 RefundParams::to_array() 不得包含空字串的 bankBranchCode'
		);
		$this->assertFalse(
			isset( $body['bankAccount'] ) && '' === $body['bankAccount'],
			'信用卡退款 RefundParams::to_array() 不得包含空字串的 bankAccount'
		);
	}

	/**
	 * F-3：信用卡退款實際送往 PayNow API 的 HTTP request body JSON 不含空 bank 三欄
	 *
	 * 驗證手法：移除 paynow_mock_refund_response filter，改用 pre_http_request 攔截真實 HTTP 呼叫，
	 *   捕獲 request body JSON，確認其中不含 bankCode / bankBranchCode / bankAccount 空欄位。
	 *
	 * Red 狀態：目前 to_array() 輸出空 bank 欄位，body JSON 會含這三個空欄位，測試失敗。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_安全F3_信用卡退款HTTP請求body不含空bank欄位(): void {
		// 移除 configure_dependencies 設置的預設 mock（讓它走到真實 request()→pre_http_request）
		\remove_all_filters( 'paynow_mock_refund_response' );

		// Given: 信用卡訂單
		$order  = $this->create_paid_paynow_order(
			payment_intent_id: 'pp_test_f3_http_body_001',
			payment_type: 'CreditCard',
			total: 1000.0
		);
		$refund = $this->create_gateway_refund( $order, 1000.0, '信用卡退款 F-3 HTTP body 測試' );

		// 以 pre_http_request filter 攔截實際 HTTP 請求，捕獲 request body
		/** @var array<string, mixed>|null $captured_body */
		$captured_body = null;

		\add_filter(
			'pre_http_request',
			static function ( mixed $preempt, array $parsed_args, string $url ) use ( &$captured_body ): mixed {
				// 只攔截打向 PayNow refund 端點的請求
				if ( \str_contains( $url, '/api/v1/payment-intents/' ) && \str_contains( $url, '/refunds' ) ) {
					$body          = $parsed_args['body'] ?? '';
					$captured_body = \is_string( $body ) ? \json_decode( $body, true ) : $body;

					// 回傳模擬成功回應（避免真實打出，格式對齊 PayNowRestClient::resolve_mock 守衛）
					return [
						'body'     => (string) \wp_json_encode(
							[
								'type'    => 'success',
								'status'  => 200,
								'message' => 'mock ok',
								'result'  => [
									'id'     => 'rf_f3_test_001',
									'type'   => 'success',
									'amount' => 1000,
								],
							]
						),
						'response' => [ 'code' => 200 ],
						'headers'  => [],
						'cookies'  => [],
					];
				}
				return $preempt;
			},
			10,
			3
		);

		// When: 觸發退款 handler
		PaynowGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		\remove_all_filters( 'pre_http_request' );

		// Then: 必須有捕獲到 body（確認真的打出了請求）
		$this->assertNotNull(
			$captured_body,
			'未攔截到 PayNow refund HTTP request，可能 pre_http_request filter 未正確掛接'
		);

		if ( \is_array( $captured_body ) ) {
			// 加固期望：body 不應含空字串的 bank 三欄
			$this->assertFalse(
				isset( $captured_body['bankCode'] ) && '' === $captured_body['bankCode'],
				'信用卡退款 HTTP request body 不得包含空字串的 bankCode（實際：' . ( $captured_body['bankCode'] ?? 'N/A' ) . '）'
			);
			$this->assertFalse(
				isset( $captured_body['bankBranchCode'] ) && '' === $captured_body['bankBranchCode'],
				'信用卡退款 HTTP request body 不得包含空字串的 bankBranchCode'
			);
			$this->assertFalse(
				isset( $captured_body['bankAccount'] ) && '' === $captured_body['bankAccount'],
				'信用卡退款 HTTP request body 不得包含空字串的 bankAccount'
			);
		}
	}

	/**
	 * F-3 對照組：ATM 退款時 bank 三欄有值且存在（確保 ATM 仍正常帶 bank）
	 *
	 * 確認加固後的 RefundParams::to_array() 過濾邏輯不會誤刪 ATM 退款的有值 bank 欄位。
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_安全F3_對照組_ATM退款RefundParams_to_array含有值bank欄位(): void {
		// Given: ATM 退款用的 RefundParams（帶 bank 三欄有效值）
		$params = \J7\PowerCheckout\Domains\Payment\Paynow\DTOs\RefundParams::create_for_atm(
			[
				'amount'         => 1500,
				'reason'         => 'ATM 退款測試',
				'bankCode'       => '013',
				'bankBranchCode' => '0001',
				'bankAccount'    => '1234567890',
			]
		);

		// When: 轉換為 array
		$body = $params->to_array();

		// Then: bank 三欄應存在且有值（ATM 退款必填，不應被過濾掉）
		$this->assertArrayHasKey( 'bankCode', $body, 'ATM 退款 body 應含 bankCode' );
		$this->assertArrayHasKey( 'bankBranchCode', $body, 'ATM 退款 body 應含 bankBranchCode' );
		$this->assertArrayHasKey( 'bankAccount', $body, 'ATM 退款 body 應含 bankAccount' );

		$this->assertSame( '013', $body['bankCode'], 'ATM 退款 bankCode 應正確帶入' );
		$this->assertSame( '0001', $body['bankBranchCode'], 'ATM 退款 bankBranchCode 應正確帶入' );
		$this->assertSame( '1234567890', $body['bankAccount'], 'ATM 退款 bankAccount 應正確帶入' );
	}
}
