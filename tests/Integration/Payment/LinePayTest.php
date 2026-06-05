<?php
/**
 * LINE Pay 整合測試
 * 驗證 LINE Pay 付款方式的完整行為：退款規則、Webhook 狀態更新、設定預設值
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook\Order as WebhookOrder;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook\Payment as WebhookPayment;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Body;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Payment as WebhookPaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\PaymentMethod;
use Tests\Integration\TestCase;

/**
 * LINE Pay 測試類別
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinePayTest extends TestCase {

	/**
	 * 建立 LINE Pay 的 PaymentDTO
	 *
	 * @param string $status       ResponseStatus 枚舉值
	 * @param string $trade_order_id tradeOrderId
	 * @return PaymentDTO
	 */
	private function make_linepay_payment_dto( string $status, string $trade_order_id = 'LINEPAY_TRADE_001' ): PaymentDTO {
		return PaymentDTO::create(
			[
				'referenceOrderId' => 'REF_LINEPAY_001',
				'tradeOrderId'     => $trade_order_id,
				'status'           => $status,
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_LINEPAY_001',
					'createTime'       => time(),
					'amount'           => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'CUSTOMER_LINEPAY_001',
						'customerId'          => 'SLP_CUSTOMER_LINEPAY_001',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'LinePay',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
				],
			]
		);
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_PaymentMethod_LINEPAY_存在(): void {
		$method = PaymentMethod::from( 'LinePay' );
		$this->assertSame( PaymentMethod::LINEPAY, $method );
		$this->assertSame( 'LINE Pay', $method->label() );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_LinePay_PaymentDTO_可以被建立(): void {
		$dto = $this->make_linepay_payment_dto( 'SUCCEEDED' );
		$this->assertInstanceOf( PaymentDTO::class, $dto );
		$this->assertSame( 'LinePay', $dto->payment->paymentMethod );
	}

	// ========== 快樂路徑：Webhook 狀態更新 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_付款成功_訂單狀態變更為processing(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到 LINE Pay 付款 SUCCEEDED webhook
		$dto     = $this->make_linepay_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態變更為 processing
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_付款成功_付款詳情meta包含LinePay(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到 LINE Pay 付款 SUCCEEDED webhook
		$dto     = $this->make_linepay_payment_dto( 'SUCCEEDED', 'LINEPAY_DETAIL_001' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 付款詳情儲存至 meta，且 paymentMethod 為 LinePay
		$payment_detail = $this->get_payment_detail( $order );
		$this->assertNotEmpty( $payment_detail, '付款詳情不應為空' );
		$this->assertArrayHasKey( 'payment', $payment_detail );
		$this->assertSame( 'LinePay', $payment_detail['payment']['paymentMethod'] ?? '' );
		$this->assertSame( 'LINEPAY_DETAIL_001', $payment_detail['tradeOrderId'] );
	}

	// ========== 快樂路徑：退款規則 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_全額退款_can_refund回傳true(): void {
		// Given: 總額 1000 的訂單
		$order = $this->create_wc_order( [ 'total' => '1000' ] );

		// When: LINE Pay 全額退款
		$result = PaymentMethod::LINEPAY->can_refund( $order, 1000.0 );

		// Then: 回傳 true
		$this->assertTrue( $result );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_部分退款_can_refund回傳true(): void {
		// Given: 總額 1000 的訂單
		$order = $this->create_wc_order( [ 'total' => '1000' ] );

		// When: LINE Pay 部分退款 500
		$result = PaymentMethod::LINEPAY->can_refund( $order, 500.0 );

		// Then: 回傳 true（LINE Pay 支援部分退款）
		$this->assertTrue( $result );
	}

	// ========== 快樂路徑：設定預設值 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_在預設allowPaymentMethodList中(): void {
		// Given: 全新的 RedirectSettingsDTO（無已儲存設定）
		$dto = new RedirectSettingsDTO( [] );

		// Then: 預設 allowPaymentMethodList 包含 LinePay
		$this->assertContains( 'LinePay', $dto->allowPaymentMethodList );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_不在paymentMethodOptions中(): void {
		// Given: 全新的 RedirectSettingsDTO
		$dto = new RedirectSettingsDTO( [] );

		// Then: paymentMethodOptions 不包含 LinePay（LINE Pay 不需要額外設定）
		$this->assertArrayNotHasKey( 'LinePay', $dto->paymentMethodOptions );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_LinePay_付款失敗_訂單狀態保持pending(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到 LINE Pay 付款 FAILED webhook
		$dto     = $this->make_linepay_payment_dto( 'FAILED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態保持 pending
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_LinePay_付款逾期_訂單狀態變更為cancelled(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到 LINE Pay 付款 EXPIRED webhook
		$dto     = $this->make_linepay_payment_dto( 'EXPIRED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態變更為 cancelled
		$this->assert_order_status( $order, 'cancelled' );
	}

	// ========== 快樂路徑：Webhook Body DTO 解析 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_trade_succeeded_Body_DTO_正確路由到Payment_DTO(): void {
		// Given: 一個 LINE Pay trade.succeeded 的 webhook body
		$body_data = [
			'id'      => 'EVT_LINEPAY_BODY_TEST_001',
			'type'    => 'trade.succeeded',
			'created' => time(),
			'data'    => [
				'referenceOrderId' => 'REF_BODY_001',
				'tradeOrderId'     => 'LINEPAY_BODY_001',
				'status'           => 'SUCCEEDED',
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_BODY_001',
					'createTime'       => time(),
					'amount'           => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'C1',
						'customerId'          => 'SC1',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'LinePay',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
				],
			],
		];

		// When: 建立 Body DTO
		$body = Body::create( $body_data );

		// Then: data 應為 WebhookPaymentDTO 且 paymentMethod 為 LinePay
		$this->assertInstanceOf( WebhookPaymentDTO::class, $body->data );
		$this->assertSame( 'trade.succeeded', $body->type );
		/** @var WebhookPaymentDTO $payment_dto */
		$payment_dto = $body->data;
		$this->assertSame( 'LinePay', $payment_dto->payment->paymentMethod );
		$this->assertSame( 'LINEPAY_BODY_001', $payment_dto->tradeOrderId );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_trade_failed_Body_DTO_正確路由到Payment_DTO(): void {
		// Given: 一個 LINE Pay trade.failed 的 webhook body
		$body_data = [
			'id'      => 'EVT_LINEPAY_FAIL_BODY_001',
			'type'    => 'trade.failed',
			'created' => time(),
			'data'    => [
				'referenceOrderId' => 'REF_FAIL_001',
				'tradeOrderId'     => 'LINEPAY_FAIL_001',
				'status'           => 'FAILED',
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_FAIL_001',
					'createTime'       => time(),
					'amount'           => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'C1',
						'customerId'          => 'SC1',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'LinePay',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => 0,
						'currency' => 'TWD',
					],
				],
			],
		];

		// When: 建立 Body DTO
		$body = Body::create( $body_data );

		// Then: data 應為 WebhookPaymentDTO 且 status 為 FAILED
		$this->assertInstanceOf( WebhookPaymentDTO::class, $body->data );
		$this->assertSame( 'trade.failed', $body->type );
		/** @var WebhookPaymentDTO $payment_dto */
		$payment_dto = $body->data;
		$this->assertSame( 'FAILED', $payment_dto->status );
	}

	// ========== 快樂路徑：Order Note 與 HTML 輸出 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_付款成功_Order_Note包含LINE_Pay(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到 LINE Pay SUCCEEDED webhook
		$dto     = $this->make_linepay_payment_dto( 'SUCCEEDED', 'LINEPAY_NOTE_001' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單備忘錄應包含 LINE Pay 字樣
		$this->assert_order_note_contains( $order, 'LINE Pay' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_to_human_html_包含LINE_Pay標籤(): void {
		// Given: 一個 LINE Pay 成功付款的 PaymentDTO
		$dto = $this->make_linepay_payment_dto( 'SUCCEEDED' );

		// When: 取得 human-readable HTML
		$html = $dto->to_human_html();

		// Then: HTML 應包含 LINE Pay 標籤
		$this->assertStringContainsString( 'LINE Pay', $html );
		$this->assertStringContainsString( '付款狀態', $html );
		$this->assertStringContainsString( '付款方式', $html );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_get_payment_method_回傳LINEPAY枚舉(): void {
		// Given: 一個 LINE Pay PaymentDTO
		$dto = $this->make_linepay_payment_dto( 'SUCCEEDED' );

		// When: 取得 payment method 枚舉
		$method = $dto->get_payment_method();

		// Then: 應為 PaymentMethod::LINEPAY
		$this->assertSame( PaymentMethod::LINEPAY, $method );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_is_successed_or_failed_成功狀態回傳true(): void {
		// Given: 一個 SUCCEEDED 狀態的 LINE Pay webhook DTO
		$dto = WebhookPaymentDTO::create(
			[
				'referenceOrderId' => 'REF_001',
				'tradeOrderId'     => 'TRADE_001',
				'status'           => 'SUCCEEDED',
				'order'            => [
					'merchantId'       => 'M1',
					'referenceOrderId' => 'REF_001',
					'createTime'       => time(),
					'amount'           => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'C1',
						'customerId'          => 'SC1',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'LinePay',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
				],
			]
			);

		// Then: is_successed_or_failed 應回傳 true
		$this->assertTrue( $dto->is_successed_or_failed() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LinePay_is_successed_or_failed_失敗狀態回傳true(): void {
		// Given: 一個 FAILED 狀態的 LINE Pay webhook DTO
		$dto = WebhookPaymentDTO::create(
			[
				'referenceOrderId' => 'REF_002',
				'tradeOrderId'     => 'TRADE_002',
				'status'           => 'FAILED',
				'order'            => [
					'merchantId'       => 'M1',
					'referenceOrderId' => 'REF_002',
					'createTime'       => time(),
					'amount'           => [
						'value'    => 100000,
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'C1',
						'customerId'          => 'SC1',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'LinePay',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => 0,
						'currency' => 'TWD',
					],
				],
			]
			);

		// Then: is_successed_or_failed 應回傳 true
		$this->assertTrue( $dto->is_successed_or_failed() );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_LinePay_重複SUCCEEDED_webhook_訂單狀態不變(): void {
		// Given: 一筆已處理中的訂單（已付款）
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		// When: 再次收到 LINE Pay SUCCEEDED webhook（重複通知）
		$dto     = $this->make_linepay_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態仍為 processing
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 對比測試（確認退款規則差異） ==========

	/**
	 * @test
	 * @group contrast
	 */
	public function test_對比_VirtualAccount_退款_回傳WP_Error(): void {
		$order  = $this->create_wc_order( [ 'total' => '1000' ] );
		$result = PaymentMethod::VIRTUALACCOUNT->can_refund( $order, 1000.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * @test
	 * @group contrast
	 */
	public function test_對比_ChaileaseBNPL_部分退款_回傳WP_Error(): void {
		$order  = $this->create_wc_order( [ 'total' => '1000' ] );
		$result = PaymentMethod::CHAILEASEBNPL->can_refund( $order, 500.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * @test
	 * @group contrast
	 */
	public function test_對比_ChaileaseBNPL_全額退款_回傳true(): void {
		$order  = $this->create_wc_order( [ 'total' => '1000' ] );
		$result = PaymentMethod::CHAILEASEBNPL->can_refund( $order, 1000.0 );

		$this->assertTrue( $result );
	}
}
