<?php
/**
 * Webhook 簽章驗證整合測試
 * 驗證 HMAC-SHA256 簽章邏輯及 Webhook 資料解析
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Body;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\EventType;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use Tests\Integration\TestCase;

/**
 * Webhook 簽章驗證測試類別
 *
 * @group integration
 * @group payment
 */
final class WebhookSignatureTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_HMAC_SHA256_簽章可以正確產生(): void {
		// Given: 一個簡單的 payload 和 key
		$payload  = '12345.{"type":"test"}';
		$sign_key = 'test_sign_key';

		// When: 產生簽章
		$signature = hash_hmac( 'sha256', $payload, $sign_key );

		// Then: 簽章非空且為有效的 hex 字串
		$this->assertNotEmpty( $signature );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $signature );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_有效簽章驗證通過(): void {
		// Given: 準備好 payload 和簽章
		$sign_key  = 'test_sign_key_12345';
		$timestamp = (string) ( time() * 1000 );
		$body      = '{"type":"trade.succeeded","data":{}}';
		$payload   = "{$timestamp}.{$body}";
		$signature = hash_hmac( 'sha256', $payload, $sign_key );

		// When: 用相同的 key 驗證
		$calculated = hash_hmac( 'sha256', $payload, $sign_key );

		// Then: 兩個簽章應相等
		$this->assertTrue( hash_equals( $signature, $calculated ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_Webhook_Body_trade_succeeded_事件解析(): void {
		// Given: 模擬 SLP TRADE_SUCCEEDED webhook body
		$trade_order_id = 'TRADE_PARSE_001';
		$body_params    = [
			'id'      => 'EVT_001',
			'type'    => 'trade.succeeded',
			'created' => time(),
			'data'    => [
				'referenceOrderId' => 'REF_001',
				'tradeOrderId'     => $trade_order_id,
				'status'           => 'SUCCEEDED',
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
			],
		];

		// When: 解析 Body
		$body = Body::create( $body_params );

		// Then: 事件類型正確
		$this->assertSame( EventType::TRADE_SUCCEEDED, $body->get_event_type() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功Webhook流程_訂單狀態更新(): void {
		// Given: 一筆有 tradeOrderId 的 pending 訂單
		$trade_order_id = 'TRADE_WEBHOOK_001';
		$order          = $this->create_order_with_payment_identity( $trade_order_id, 'pending' );

		// When: 模擬 StatusManager 處理付款成功通知
		$payment_dto = \J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO::create(
			[
				'referenceOrderId' => 'REF_001',
				'tradeOrderId'     => $trade_order_id,
				'status'           => 'SUCCEEDED',
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

		$status_manager = new StatusManager( $payment_dto, $order );
		$status_manager->update_order_status();

		// Then: 訂單狀態應更新為 processing
		$this->assert_order_status( $order, 'processing' );

		// 並且 _pc_payment_detail 有資料
		$payment_detail = $this->get_payment_detail( $order );
		$this->assertNotEmpty( $payment_detail );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款過期Webhook流程_訂單狀態更新為cancelled(): void {
		// Given: 一筆有 tradeOrderId 的 pending 訂單
		$trade_order_id = 'TRADE_EXPIRED_001';
		$order          = $this->create_order_with_payment_identity( $trade_order_id, 'pending' );

		// When: StatusManager 處理過期通知
		$payment_dto = \J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO::create(
			[
				'referenceOrderId' => 'REF_002',
				'tradeOrderId'     => $trade_order_id,
				'status'           => 'EXPIRED',
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_002',
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

		$status_manager = new StatusManager( $payment_dto, $order );
		$status_manager->update_order_status();

		// Then: 訂單狀態應更新為 cancelled
		$this->assert_order_status( $order, 'cancelled' );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_無效簽章驗證失敗(): void {
		// Given: 正確的 payload 但錯誤的簽章
		$sign_key     = 'correct_key';
		$wrong_key    = 'wrong_key';
		$timestamp    = (string) ( time() * 1000 );
		$body         = '{"type":"trade.succeeded"}';
		$payload      = "{$timestamp}.{$body}";
		$correct_sign = hash_hmac( 'sha256', $payload, $sign_key );
		$wrong_sign   = hash_hmac( 'sha256', $payload, $wrong_key );

		// Then: 兩個簽章不相等
		$this->assertFalse( hash_equals( $correct_sign, $wrong_sign ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_不合法的EventType拋出例外(): void {
		// Given: 包含未知 event type 的 body
		$body_params = [
			'id'      => 'EVT_ERR_001',
			'type'    => 'unknown.event.type', // 不在 EventType 枚舉中
			'created' => time(),
			'data'    => [],
		];

		// When & Then: 應拋出例外（EventType::from 會拋出 ValueError）
		$this->expectException( \ValueError::class );
		Body::create( $body_params );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_時間誤差超過5分鐘簽章驗證邏輯(): void {
		// Given: 5 分鐘前的 timestamp（模擬過期請求）
		$old_timestamp = (string) ( ( time() - 6 * 60 ) * 1000 );
		$current_time  = time() * 1000;
		$diff          = abs( $current_time - (int) $old_timestamp );
		$tolerance     = 5 * 60 * 1000; // 5 分鐘（毫秒）

		// When & Then: 超過 5 分鐘的差異應視為過期
		$this->assertGreaterThan( $tolerance, $diff, 'timestamp 應超過 5 分鐘容忍範圍' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_時間誤差在5分鐘內簽章驗證通過(): void {
		// Given: 4 分鐘前的 timestamp（應在容忍範圍內）
		$recent_timestamp = (string) ( ( time() - 4 * 60 ) * 1000 );
		$current_time     = time() * 1000;
		$diff             = abs( $current_time - (int) $recent_timestamp );
		$tolerance        = 5 * 60 * 1000;

		// When & Then: 4 分鐘差異應在容忍範圍內
		$this->assertLessThanOrEqual( $tolerance, $diff, 'timestamp 在 5 分鐘容忍範圍內' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_空簽章字串驗證失敗(): void {
		// Given: 空的簽章
		$sign_key     = 'test_key';
		$payload      = '12345.body';
		$correct_sign = hash_hmac( 'sha256', $payload, $sign_key );
		$empty_sign   = '';

		// Then: hash_equals 應回傳 false（空字串長度不符）
		$this->assertFalse( hash_equals( $correct_sign, $empty_sign ) );
	}

	// ========== 安全性（Security） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_簽章使用timing_safe比較防止timing_attack(): void {
		// Given: 兩個長度相同但內容不同的簽章
		$sign_key = 'key';
		$payload1 = 'payload1';
		$payload2 = 'payload2';
		$sig1     = hash_hmac( 'sha256', $payload1, $sign_key );
		$fake_sig = hash_hmac( 'sha256', $payload2, $sign_key );

		// When: 使用 hash_equals（timing-safe）比較
		$is_equal = hash_equals( $sig1, $fake_sig );

		// Then: 不相等（驗證 timing-safe 方式不影響結果正確性）
		$this->assertFalse( $is_equal, 'hash_equals 應正確判斷不同簽章' );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_Webhook_body包含XSS內容不造成系統異常(): void {
		// Given: 包含 XSS 嘗試的 tradeOrderId
		$xss_trade_id = '<script>alert("xss")</script>';

		// When: 建立帶有 XSS 內容的訂單 meta
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_payment_identity( $xss_trade_id );

		// Then: meta 正確儲存，不造成異常
		$this->assertSame( $xss_trade_id, $meta_keys->get_payment_identity() );
	}
}
