<?php
/**
 * LogisticsApiService 整合測試（階段四 — Red Gate）
 *
 * 5 個對外 REST 端點（namespace power-checkout/v1）：
 *   - POST  logistics/{id}/store-selection  （選店導轉，回 redirect_target）
 *   - POST  logistics/{id}/create-shipment  （成立物流單，回 logistics_id）
 *   - GET   logistics/{id}                   （查詢物流單）
 *   - POST  logistics/{id}/print             （列印託運單，回 HTML）
 *   - POST  logistics/{id}/cancel            （C2C 取消物流單）
 *
 * 涵蓋 happy path + 前置驗證（provider 未啟用 / 訂單不存在 / 缺前置 meta → 對應 4xx）。
 * 回應一律 {code, message, data}（鏡像 InvoiceApiService）。
 * 真 API 以 API_MODE=mock 攔截（不打綠界）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter LogisticsApiServiceTest" 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * LogisticsApiService 測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsApiServiceTest extends TestCase {

	// ---- 測試公開帳號（B2C / C2C） ----
	private const B2C_MERCHANT_ID = '2000132';
	private const B2C_HASH_KEY    = '5294y06JbISpM5x9';
	private const B2C_HASH_IV     = 'v77hoKGq4kWxNNIS';
	private const C2C_MERCHANT_ID = '2000933';
	private const C2C_HASH_KEY    = 'XBERn1YOvpM9nfZc';
	private const C2C_HASH_IV     = 'h1ONHk4P4yqbl5LK';

	public function set_up(): void {
		parent::set_up();
		EcpayLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );
		$this->enable_logistics_b2c();
		// 放入 ProviderUtils 容器（API service 透過 ProviderUtils 取 provider）
		ProviderUtils::$container[ EcpayLogisticsProvider::ID ] = EcpayLogisticsProvider::instance();
	}

	public function tear_down(): void {
		\putenv( 'API_MODE' );
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 ecpay_logistics（B2C，test 模式）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 */
	private function enable_logistics_b2c( array $extra = [] ): void {
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			array_merge(
				[
					'mode'             => 'test',
					'account_type'     => 'b2c',
					'b2c_merchant_id'  => self::B2C_MERCHANT_ID,
					'b2c_hash_key'     => self::B2C_HASH_KEY,
					'b2c_hash_iv'      => self::B2C_HASH_IV,
					'c2c_merchant_id'  => self::C2C_MERCHANT_ID,
					'c2c_hash_key'     => self::C2C_HASH_KEY,
					'c2c_hash_iv'      => self::C2C_HASH_IV,
					'enabled_methods'  => [ 'FAMI', 'UNIMART', 'HILIFE', 'HOME' ],
					'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
					'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
				],
				$extra
			)
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	/**
	 * 啟用 ecpay_logistics（C2C，test 模式）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 */
	private function enable_logistics_c2c( array $extra = [] ): void {
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			array_merge(
				[
					'mode'             => 'test',
					'account_type'     => 'c2c',
					'b2c_merchant_id'  => self::B2C_MERCHANT_ID,
					'b2c_hash_key'     => self::B2C_HASH_KEY,
					'b2c_hash_iv'      => self::B2C_HASH_IV,
					'c2c_merchant_id'  => self::C2C_MERCHANT_ID,
					'c2c_hash_key'     => self::C2C_HASH_KEY,
					'c2c_hash_iv'      => self::C2C_HASH_IV,
					'enabled_methods'  => [ 'FAMI', 'UNIMART', 'HILIFE' ],
					'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
					'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
				],
				$extra
			)
		);
		EcpayLogisticsSettingsDTO::reset();
		ProviderUtils::$container[ EcpayLogisticsProvider::ID ] = EcpayLogisticsProvider::instance();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_LogisticsApiService_namespace為power_checkout_v1(): void {
		$service = LogisticsApiService::instance();
		$this->assertInstanceOf( LogisticsApiService::class, $service );
	}

	// ========== store-selection（階段 A） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_選店端點_happy回傳redirect_target(): void {
		// Given
		$order   = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/store-selection" );
		$request->set_body_params( [ 'sub_type' => 'FAMI' ] );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_store_selection_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertArrayHasKey( 'redirect_target', $data['data'] );
		$this->assertNotEmpty( $data['data']['redirect_target'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店端點_provider未啟用回403(): void {
		// Given: 停用 provider
		$this->disable_provider( EcpayLogisticsProvider::ID );
		EcpayLogisticsSettingsDTO::reset();
		ProviderUtils::$container = [];

		$order   = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/store-selection" );
		$request->set_body_params( [ 'sub_type' => 'FAMI' ] );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_store_selection_callback( $request );

		// Then
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店端點_訂單不存在回404(): void {
		// Given: 不存在的 order id
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/999999999/store-selection' );
		$request->set_body_params( [ 'sub_type' => 'FAMI' ] );
		$request->set_param( 'id', '999999999' );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_store_selection_callback( $request );

		// Then
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店端點_sub_type未啟用回400(): void {
		// Given: sub_type 不在 enabled_methods
		$order   = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/store-selection" );
		$request->set_body_params( [ 'sub_type' => 'OKMART' ] );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_store_selection_callback( $request );

		// Then
		$this->assertSame( 400, $response->get_status() );
	}

	// ========== create-shipment（階段 B） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單端點_happy回傳logistics_id(): void {
		// Given: 已選店（有 temp_id）
		$order = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_temp_id( '2264' );
		$meta->update_sub_type( 'FAMI' );

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/create-shipment" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertArrayHasKey( 'logistics_id', $data['data'] );
		$this->assertNotEmpty( $data['data']['logistics_id'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_建單端點_無temp_id回403(): void {
		// Given: 未選店
		$order   = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/create-shipment" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $request );

		// Then
		$this->assertSame( 403, $response->get_status() );
	}

	// ========== query（查詢） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_查詢端點_happy回傳物流資訊(): void {
		// Given: 有 ref
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );

		$request = new \WP_REST_Request( 'GET', "/power-checkout/v1/logistics/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->get_logistics_with_id_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertArrayHasKey( 'logistics_id', $data['data'] );
		$this->assertArrayHasKey( 'status', $data['data'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_查詢端點_無ref回403(): void {
		// Given: 無 ref
		$order   = $this->create_wc_order( [ 'status' => 'processing' ] );
		$request = new \WP_REST_Request( 'GET', "/power-checkout/v1/logistics/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->get_logistics_with_id_callback( $request );

		// Then
		$this->assertSame( 403, $response->get_status() );
	}

	// ========== print（列印） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_列印端點_happy回傳HTML(): void {
		// Given: 有 ref 與 sub_type
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_sub_type( 'FAMI' );

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/print" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $request );

		// Then: 回 HTML（字串 body）
		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertIsString( $body );
		$this->assertStringContainsString( '<html', strtolower( $body ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_列印端點_無ref回403(): void {
		// Given: 無 ref
		$order   = $this->create_wc_order( [ 'status' => 'processing' ] );
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/print" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $request );

		// Then
		$this->assertSame( 403, $response->get_status() );
	}

	// ========== cancel（C2C 取消） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_取消端點_C2C_happy成功(): void {
		// Given: C2C，有 ref + cvs_payment_no + cvs_validation_no
		$this->enable_logistics_c2c();
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '9988776655' );
		$meta->update_cvs_payment_no( '12345678' );
		$meta->update_cvs_validation_no( '9999' );

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/cancel" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_cancel_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'success', $response->get_data()['code'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_取消端點_B2C帳號回403(): void {
		// Given: B2C 帳號（預設），有 ref
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_cvs_payment_no( '12345678' );

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/cancel" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_cancel_callback( $request );

		// Then: 取消僅支援 C2C → 403
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_取消端點_訂單不存在回404(): void {
		// Given
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/999999999/cancel' );
		$request->set_param( 'id', '999999999' );

		// When
		$response = LogisticsApiService::instance()->post_logistics_with_id_cancel_callback( $request );

		// Then
		$this->assertSame( 404, $response->get_status() );
	}
}
