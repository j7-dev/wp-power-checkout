<?php
/**
 * StatusManager 整合測試
 * 驗證 Shopline Payment Webhook 收到付款狀態後，訂單狀態是否正確更新
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook\Order as WebhookOrder;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook\Payment as WebhookPayment;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use Tests\Integration\TestCase;

/**
 * StatusManager 測試類別
 *
 * @group integration
 * @group payment
 */
final class StatusManagerTest extends TestCase {

	/**
	 * 建立最小可用的 PaymentDTO（依照 PaymentDTO::$require_properties 及子 DTO 必填欄位）
	 *
	 * @param string $status       ResponseStatus 枚舉值
	 * @param string $trade_order_id tradeOrderId
	 * @return PaymentDTO
	 */
	private function make_payment_dto( string $status, string $trade_order_id = 'TRADE_001' ): PaymentDTO {
		return PaymentDTO::create(
			[
				'referenceOrderId' => 'REF_001',
				'tradeOrderId'     => $trade_order_id,
				'status'           => $status,
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_001',
					'createTime'       => time(),
					'amount'           => [
						'value'    => 10000,
						'currency' => 'TWD',
					],
					'customer'         => [
						'referenceCustomerId' => 'CUSTOMER_001',
						'customerId'          => 'SLP_CUSTOMER_001',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'CreditCard',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => 10000,
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
	public function test_冒煙_StatusManager_可以被實例化(): void {
		$order   = $this->create_wc_order();
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );

		$this->assertInstanceOf( StatusManager::class, $manager );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時訂單狀態變更為processing(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到付款 SUCCEEDED webhook
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態變更為 processing
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時付款詳情儲存至meta(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到付款 SUCCEEDED webhook
		$dto     = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_DETAIL_001' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 付款詳情儲存至 _pc_payment_detail
		$payment_detail = $this->get_payment_detail( $order );
		$this->assertNotEmpty( $payment_detail, '付款詳情不應為空' );
		$this->assertArrayHasKey( 'tradeOrderId', $payment_detail );
		$this->assertSame( 'TRADE_DETAIL_001', $payment_detail['tradeOrderId'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時order_note被新增(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到付款 SUCCEEDED webhook
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單備忘錄中有付款狀態記錄（包含「成功」字樣）
		$this->assert_order_note_contains( $order, '成功' );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_付款逾期時訂單狀態變更為cancelled(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到付款 EXPIRED webhook
		$dto     = $this->make_payment_dto( 'EXPIRED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態變更為 cancelled
		$this->assert_order_status( $order, 'cancelled' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_付款失敗時訂單狀態保持pending(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到 FAILED webhook
		$dto     = $this->make_payment_dto( 'FAILED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態保持 pending（未知狀態 fallback 到 pending）
		$this->assert_order_status( $order, 'pending' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_未知狀態時訂單狀態fallback為pending(): void {
		// Given: 一筆 pending 訂單
		$order = $this->create_wc_order( [ 'status' => 'pending' ] );

		// When: 收到未知狀態的 webhook（不在 ResponseStatus 枚舉中）
		$dto     = $this->make_payment_dto( 'PROCESSING' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態保持 pending（match default 分支）
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_同一訂單收到兩次SUCCEEDED_webhook(): void {
		// Given: 一筆 processing 訂單（已付款）
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		// When: 再次收到 SUCCEEDED webhook（重複通知）
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態仍為 processing（不應因重複而產生異常）
		$this->assert_order_status( $order, 'processing' );
	}
}
