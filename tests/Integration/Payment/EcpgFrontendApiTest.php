<?php
/**
 * 綠界站內付 2.0（ECPG）前端 SDK create-payment REST 端點整合測試
 *
 * 驗證：
 *  - order_key 正確 → create_payment 被觸發，回 three_d_url / need_3ds（MOCK 模式預設帶 ThreeDURL）。
 *  - order_key 錯誤 / 空 → 403 拒絕（防越權），不觸發 create_payment。
 *  - 訂單不存在 → 404。
 *  - 付款方式非 ecpay_ecpg → 400。
 *  - pay_token 空 → 400。
 *  - 缺 MerchantTradeNo（未走 GetTokenbyTrade）→ 400 並寫 order note。
 *
 * 真 API 呼叫以 API_MODE=mock 攔截（不打綠界）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgFrontendApi;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界站內付 2.0 create-payment 端點測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group ecpg
 */
final class EcpgFrontendApiTest extends TestCase {

	/** @var string 綠界 ECPG 線上金流測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 線上金流測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** 每次測試前啟用 ecpay_ecpg + MOCK 模式 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
		EcpgSettingsDTO::reset();
		putenv( 'API_MODE=mock' );
	}

	/** 每次測試後清理設定 + 還原 API_MODE */
	public function tear_down(): void {
		putenv( 'API_MODE' );
		delete_option( ProviderUtils::get_option_name( EcpgGateway::ID ) );
		EcpgSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * 建立已取得交易 token（含 MerchantTradeNo）的 ecpay_ecpg 訂單
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return \WC_Order
	 */
	private function create_ecpg_order( string $trade_no = 'EG200ABCDEF' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);
		( new EcpayMetaKeys( $order ) )->update_trade_no( $trade_no );
		return $order;
	}

	/**
	 * 組裝 create-payment REST 請求
	 *
	 * @param int    $order_id  訂單 ID
	 * @param string $order_key 訂單 key
	 * @param string $pay_token PayToken
	 * @return \WP_REST_Request
	 */
	private function build_request( int $order_id, string $order_key, string $pay_token ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/ecpg/create-payment' );
		$request->set_body_params(
			[
				'order_id'  => $order_id,
				'order_key' => $order_key,
				'pay_token' => $pay_token,
			]
		);
		return $request;
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_create_payment端點URL正確(): void {
		$this->assertStringContainsString(
			'power-checkout/ecpay/ecpg/create-payment',
			EcpgFrontendApi::get_create_payment_url()
		);
	}

	// ========== 快樂路徑（order_key 正確） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_order_key正確時觸發create_payment並回ThreeDURL(): void {
		// Given: 已取得 token 的訂單，order_key 正確
		$order   = $this->create_ecpg_order();
		$request = $this->build_request( $order->get_id(), $order->get_order_key(), 'mock_pay_token' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 200 success，MOCK 模式預設帶 ThreeDURL → need_3ds=true
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertTrue( $data['data']['need_3ds'] );
		$this->assertStringContainsString( '3DVerify', $data['data']['three_d_url'] );
	}

	// ========== 越權防護（order_key 錯誤 / 空） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_order_key錯誤時回403拒絕(): void {
		// Given: order_key 不符
		$order   = $this->create_ecpg_order();
		$request = $this->build_request( $order->get_id(), 'wc_order_WRONG_KEY', 'mock_pay_token' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 403 拒絕
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_order_key為空時回403拒絕(): void {
		// Given: order_key 空字串
		$order   = $this->create_ecpg_order();
		$request = $this->build_request( $order->get_id(), '', 'mock_pay_token' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 403 拒絕
		$this->assertSame( 403, $response->get_status() );
	}

	// ========== 訂單 / 付款方式 / 參數驗證 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_訂單不存在時回404(): void {
		// Given: 不存在的訂單 ID
		$request = $this->build_request( 999999999, 'wc_order_anything', 'mock_pay_token' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 404
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_付款方式非ecpg時回400(): void {
		// Given: 付款方式非 ecpay_ecpg，order_key 正確
		$order   = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'other_gateway',
				'total'          => 1000,
			]
		);
		$request = $this->build_request( $order->get_id(), $order->get_order_key(), 'mock_pay_token' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 400
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_pay_token為空時回400(): void {
		// Given: pay_token 空
		$order   = $this->create_ecpg_order();
		$request = $this->build_request( $order->get_id(), $order->get_order_key(), '' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 400
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_缺MerchantTradeNo時回400並寫order_note(): void {
		// Given: 未走過 GetTokenbyTrade（無 MerchantTradeNo），order_key 正確
		$order   = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);
		$request = $this->build_request( $order->get_id(), $order->get_order_key(), 'mock_pay_token' );

		// When
		$response = EcpgFrontendApi::instance()->post_ecpg_create_payment_callback( $request );

		// Then: 400（流程異常）
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}
}
