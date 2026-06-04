<?php
/**
 * 綠界 AIO 取號通知（PaymentInfoURL，ATM/CVS/BARCODE）整合測試
 * 驗證 ATM RtnCode="2" 取號不轉狀態、CVS "10100073"、驗章失敗不寫入
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
 * 綠界 AIO PaymentInfoURL 取號通知測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 */
final class EcpayAioPaymentInfoTest extends TestCase {

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
		$cmv                     = CheckMacValueService::get_check_value( $params, self::HASH_KEY, self::HASH_IV, 'sha256' );
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
	public function test_ATM取號成功時保存繳費資訊且維持pending(): void {
		// Given: ATM 取號通知（RtnCode=2）
		$trade_no = 'EC100ABCDEF';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '2',
				'RtnMsg'          => '取號成功',
				'PaymentType'     => 'ATM_TAISHIN',
				'BankCode'        => '812',
				'vAccount'        => '9103522175887271',
				'ExpireDate'      => '2026/06/10',
				'TradeNo'         => '2301011234567890',
			]
		);

		// When
		AioCallback::instance()->handle_payment_info( $payload );

		// Then: 維持 pending，繳費資訊已保存
		$this->assert_order_status( $order, 'pending' );
		$info = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertSame( '812', $info['BankCode'] );
		$this->assertSame( '9103522175887271', $info['vAccount'] );
		$this->assertSame( '2026/06/10', $info['ExpireDate'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_CVS超商代碼取號成功時保存繳費資訊且維持pending(): void {
		// Given: CVS 取號通知（RtnCode=10100073）
		$trade_no = 'EC100CVS001';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '10100073',
				'RtnMsg'          => '取號成功',
				'PaymentType'     => 'CVS_CVS',
				'PaymentNo'       => 'LLL22247310',
				'ExpireDate'      => '2026/06/10 23:59:59',
				'TradeNo'         => '2301011234567891',
			]
		);

		// When
		AioCallback::instance()->handle_payment_info( $payload );

		// Then: 維持 pending，繳費代碼已保存
		$this->assert_order_status( $order, 'pending' );
		$info = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertSame( 'LLL22247310', $info['PaymentNo'] );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_CheckMacValue不符時不寫入取號資訊且維持pending(): void {
		// Given: CheckMacValue 錯誤的取號通知
		$trade_no = 'EC100BADINFO';
		$order    = $this->create_ecpay_order( $trade_no );
		$payload  = [
			'MerchantID'      => '3002607',
			'MerchantTradeNo' => $trade_no,
			'RtnCode'         => '2',
			'RtnMsg'          => '取號成功',
			'PaymentType'     => 'ATM_TAISHIN',
			'BankCode'        => '812',
			'vAccount'        => '9103522175887271',
			'ExpireDate'      => '2026/06/10',
			'CheckMacValue'   => 'INVALID_CHECK_MAC_VALUE',
		];

		// When
		AioCallback::instance()->handle_payment_info( $payload );

		// Then: 維持 pending，未寫入取號資訊
		$this->assert_order_status( $order, 'pending' );
		$info = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_payment_info();
		$this->assertEmpty( $info );
	}

	// ========== 回應（Response） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_PaymentInfoURL回應純文字1OK且HTTP200(): void {
		// Given
		$trade_no = 'EC100INFORESP';
		$this->create_ecpay_order( $trade_no );
		$payload = $this->sign_payload(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => $trade_no,
				'RtnCode'         => '2',
				'RtnMsg'          => '取號成功',
				'PaymentType'     => 'ATM_TAISHIN',
				'BankCode'        => '812',
				'vAccount'        => '9103522175887271',
				'ExpireDate'      => '2026/06/10',
			]
		);

		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/aio/payment-info' );
		$request->set_body_params( $payload );

		// When
		$response = AioCallback::instance()->post_aio_payment_info_callback( $request );

		// Then: HTTP 200 + body 為 1|OK
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1|OK', $response->get_data() );
	}
}
