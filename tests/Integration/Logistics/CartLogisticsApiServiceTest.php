<?php
/**
 * 購物車（cart / session）級物流選店 REST 端點整合測試
 *
 * 對應 cart 級選店端點：
 *   POST power-checkout/v1/logistics/store-selection（無 order_id）
 *   → provider::get_cart_store_selection() → data.redirect_target（RWD 選店頁 HTML）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     env API_MODE=mock vendor/bin/phpunit --filter CartLogisticsApiServiceTest 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\CartLogisticsApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * cart 級選店端點測試類別
 *
 * @group integration
 * @group logistics
 */
final class CartLogisticsApiServiceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->boot_wc_session();
		EcpayLogisticsSettingsDTO::reset();
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			[
				'mode'             => 'test',
				'account_type'     => 'b2c',
				'enabled_methods'  => [ 'FAMI', 'UNIMART', 'HILIFE', 'HOME' ],
				'sender_name'      => '測試寄件人',
				'sender_zip_code'  => '100',
				'sender_address'   => '台北市中正區測試路1號',
				'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
				'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
			]
		);
		EcpayLogisticsSettingsDTO::reset();
		// 把 provider 放入容器（resolve_provider 依賴）
		ProviderUtils::$container[ EcpayLogisticsProvider::ID ] = EcpayLogisticsProvider::instance();
	}

	public function tear_down(): void {
		CartLogisticsSession::clear();
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 初始化 WC session（測試環境預設不啟動 session）
	 *
	 * @return void
	 */
	private function boot_wc_session(): void {
		$wc = \WC();
		if (!isset( $wc->session ) || !$wc->session instanceof \WC_Session) {
			$wc->initialize_session();
		}
		$wc->session->set_customer_session_cookie( true );
	}

	/**
	 * 建立 cart 級選店請求
	 *
	 * @param array<string, mixed> $params 請求參數
	 * @return \WP_REST_Request
	 */
	private function make_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/store-selection' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	// ========== happy ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_cart選店_MOCK模式成功回傳redirect_target_HTML(): void {
		$request  = $this->make_request(
			[
				'sub_type'         => 'FAMI',
				'payment_scenario' => 'online',
			]
			);
		$response = CartLogisticsApiService::instance()->post_logistics_store_selection_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertArrayHasKey( 'redirect_target', $data['data'] );
		$this->assertStringContainsStringIgnoringCase( 'ecpay', (string) $data['data']['redirect_target'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_cart選店_成功後session產生選店權杖(): void {
		$request = $this->make_request( [ 'sub_type' => 'UNIMART' ] );
		CartLogisticsApiService::instance()->post_logistics_store_selection_callback( $request );

		// 選店發起後 session 應有可反查的權杖（透過 store_by_token 驗證）
		$ok = CartLogisticsSession::store_by_token(
			(string) \WC()->session->get( 'pc_logistics_selection_token' ),
			[
				'store_id' => 'Z1',
				'temp_id'  => '1',
				'sub_type' => 'UNIMART',
			]
		);
		$this->assertTrue( $ok, '選店發起後應可用 session 內權杖寫入門市' );
	}

	// ========== error ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_cart選店_未啟用子類型回400(): void {
		$request  = $this->make_request( [ 'sub_type' => 'INVALID_SUBTYPE' ] );
		$response = CartLogisticsApiService::instance()->post_logistics_store_selection_callback( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}

	// ========== security：permission（顧客導向，非 admin） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_cart選店_有效nonce通過權限檢查(): void {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/store-selection' );
		$request->set_header( 'X-WP-Nonce', \wp_create_nonce( 'wp_rest' ) );

		$this->assertTrue(
			CartLogisticsApiService::verify_rest_nonce( $request ),
			'有效 wp_rest nonce 應通過權限檢查（顧客導向端點）'
		);
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_cart選店_缺nonce或偽造nonce被拒(): void {
		$no_nonce = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/store-selection' );
		$this->assertFalse(
			CartLogisticsApiService::verify_rest_nonce( $no_nonce ),
			'缺 nonce 應被拒'
		);

		$forged = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/store-selection' );
		$forged->set_header( 'X-WP-Nonce', 'forged_nonce_xxxxx' );
		$this->assertFalse(
			CartLogisticsApiService::verify_rest_nonce( $forged ),
			'偽造 nonce 應被拒'
		);
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_cart選店_provider未啟用回403(): void {
		// 停用 provider
		$this->disable_provider( EcpayLogisticsProvider::ID );
		ProviderUtils::$container = [];
		EcpayLogisticsSettingsDTO::reset();

		$request  = $this->make_request( [ 'sub_type' => 'FAMI' ] );
		$response = CartLogisticsApiService::instance()->post_logistics_store_selection_callback( $request );

		$this->assertSame( 403, $response->get_status() );
	}
}
