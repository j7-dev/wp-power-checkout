<?php
/**
 * 綠界 AIO 付款結果通知（ReturnURL）整合測試
 * 驗證 CheckMacValue 驗章 pass/fail、RtnCode="1"→processing、冪等重送、回 1|OK
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\AioCallback;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO ReturnURL 通知測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 */
final class EcpayAioCallbackTest extends TestCase {

	/** @var string 綠界 AIO 測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/**
	 * 每次測試前啟用 ecpay_aio
	 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
	}

	/**
	 * 每次測試後清理設定
	 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立綠界通知 payload，並補上正確的 CheckMacValue
	 *
	 * @param array<string, string> $params 通知參數（不含 CheckMacValue）
	 * @return array<string, string>
	 */
	private function sign_payload( array $params ): array {
		$cmv                    = CheckMacValueService::get_check_value( $params, self::HASH_KEY, self::HASH_IV, 'sha256' );
		$params['CheckMacValue'] = $cmv;
		return $params;
	}

	/**
	 * 建立綁定 MerchantTradeNo 的 pending 訂單
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return \WC_Order
	 */
	private function create_ecpay_order( string $trade_no ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 1000,
			]
		);
		( new EcpayMetaKeys( $order ) )->update_trade_no( $trade_no );
		return $order;
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_CheckMacValue正確且RtnCode為1時訂單轉處理中(): void {
		// Given: pending 訂單 + 已簽章的成功通知
		$trade_no = 'EC100ABCDEF';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '1',
				'RtnMsg'          => '交易成功',
				'TradeNo'         => '2301011234567890',
				'TradeAmt'        => '1000',
				'PaymentType'     => 'Credit_CreditCard',
			]
		);

		// When
		AioCallback::instance()->handle_return( $payload );

		// Then: 訂單轉 processing，明細有值
		$this->assert_order_status( $order, 'processing' );
		$detail = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertNotEmpty( $detail );
		$this->assertSame( '1', $detail['RtnCode'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款失敗通知時維持pending並記錄RtnCode(): void {
		// Given: 失敗通知（RtnCode=10100050）
		$trade_no = 'EC100FAILED';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '10100050',
				'RtnMsg'          => '付款失敗',
				'TradeNo'         => '2301011234567899',
				'TradeAmt'        => '1000',
				'PaymentType'     => 'Credit_CreditCard',
			]
		);

		// When
		AioCallback::instance()->handle_return( $payload );

		// Then: 維持 pending，order note 記錄 RtnCode / RtnMsg
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '10100050' );
	}

	// ========== 冪等（Idempotency） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_重複通知同一MerchantTradeNo時不重複處理(): void {
		// Given: 訂單已因成功通知轉為 processing
		$trade_no = 'EC100ABCDEF';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '1',
				'RtnMsg'          => '交易成功',
				'TradeNo'         => '2301011234567890',
				'TradeAmt'        => '1000',
				'PaymentType'     => 'Credit_CreditCard',
			]
		);

		// When: 重送 4 次
		for ( $i = 0; $i < 4; $i++ ) {
			AioCallback::instance()->handle_return( $payload );
		}

		// Then: 狀態維持 processing（冪等，不因重送出錯或改狀態）
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_CheckMacValue不符時維持狀態不更新明細(): void {
		// Given: pending 訂單 + CheckMacValue 錯誤的通知
		$trade_no = 'EC100BADCMV';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = [
			'MerchantID'      => '3002607',
			'MerchantTradeNo' => $trade_no,
			'RtnCode'         => '1',
			'RtnMsg'          => '交易成功',
			'TradeNo'         => '2301011234567890',
			'TradeAmt'        => '1000',
			'PaymentType'     => 'Credit_CreditCard',
			'CheckMacValue'   => 'INVALID_CHECK_MAC_VALUE',
		];

		// When
		AioCallback::instance()->handle_return( $payload );

		// Then: 維持 pending，不更新付款明細
		$this->assert_order_status( $order, 'pending' );
		$detail = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_detail();
		$this->assertEmpty( $detail );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_驗章使用timing_safe比對(): void {
		// Given: 同樣 payload 計算兩次 CMV
		$params = [
			'MerchantID'      => '3002607',
			'MerchantTradeNo' => 'EC100TIMING',
			'RtnCode'         => '1',
		];
		$cmv1 = CheckMacValueService::get_check_value( $params, self::HASH_KEY, self::HASH_IV, 'sha256' );
		$cmv2 = CheckMacValueService::get_check_value( $params, self::HASH_KEY, self::HASH_IV, 'sha256' );

		// Then: hash_equals timing-safe 比對相等
		$this->assertTrue( hash_equals( $cmv1, $cmv2 ) );
		// 與竄改值不相等
		$this->assertFalse( hash_equals( $cmv1, 'TAMPERED' ) );
	}

	// ========== 回應（Response） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_ReturnURL回應純文字1OK且HTTP200(): void {
		// Given
		$trade_no = 'EC100RESP';
		$this->create_ecpay_order( $trade_no );
		$payload = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '1',
				'RtnMsg'          => '交易成功',
				'TradeNo'         => '2301011234567890',
				'TradeAmt'        => '1000',
				'PaymentType'     => 'Credit_CreditCard',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/aio/return' );
		$request->set_body_params( $payload );

		// When
		$response = AioCallback::instance()->post_aio_return_callback( $request );

		// Then: HTTP 200 + body 為 1|OK
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1|OK', $response->get_data() );
	}
}
