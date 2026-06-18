<?php
/**
 * InvoiceApiService 折讓 REST 端點整合測試（A3）
 *
 * 端點（namespace power-checkout/v1/invoices）：
 *   - POST  allowance/{order_id}          開立折讓（body: amount）
 *   - POST  allowance-cancel/{order_id}   作廢折讓
 *
 * 回應沿用既有 InvoiceApiService 慣例：直接回 provider 結果陣列 + 200。
 * 真 API 以 API_MODE=mock 攔截（不打綠界）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\InvoiceApiService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * InvoiceApiService 折讓端點測試類別
 *
 * @group integration
 * @group invoice
 * @group ecpay
 * @group allowance
 */
final class InvoiceAllowanceApiServiceTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
		$this->enable_provider(
			EcpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => '2000132',
				'hash_key'    => 'ejCk326UnaZWKisg',
				'hash_iv'     => 'q9jcZX8Ib9LM8wYk',
			]
		);
		// API service 透過 ProviderUtils 取 provider
		ProviderUtils::$container[ EcpayInvoiceProvider::ID ] = EcpayInvoiceProvider::instance();
	}

	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EcpayInvoiceProvider::ID ) );
		parent::tear_down();
	}

	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( EcpayInvoiceSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 建立一筆已開立發票的綠界訂單
	 *
	 * @return \WC_Order
	 */
	private function create_issued_order(): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
		);
		$order->set_billing_email( 'buyer@example.com' );
		$order->save();

		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issue_params( [ 'provider' => 'ecpay' ] );
		$meta_keys->update_issued_data(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-01-15 10:00:00',
			]
		);
		$meta_keys->update_provider_id( EcpayInvoiceProvider::ID );

		return $order;
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_開立折讓端點成功回折讓單號(): void {
		// Given
		$order   = $this->create_issued_order();
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/allowance/{$order->get_id()}" );
		$request->set_body_params( [ 'amount' => 50 ] );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = InvoiceApiService::instance()->post_allowance_with_id_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data['allowance_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_作廢折讓端點成功(): void {
		// Given: 先開折讓
		$order = $this->create_issued_order();
		EcpayInvoiceProvider::instance()->issue_allowance( $order, 50.0 );

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/allowance-cancel/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = InvoiceApiService::instance()->post_allowance_cancel_with_id_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_發票查詢端點成功回發票明細(): void {
		// Given
		$order   = $this->create_issued_order();
		$request = new \WP_REST_Request( 'GET', "/power-checkout/v1/invoices/query/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = InvoiceApiService::instance()->get_query_with_id_callback( $request );

		// Then
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertNotEmpty( $data );
		$this->assertArrayHasKey( 'invoice_number', $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_發票查詢端點_provider不支援時拋例外(): void {
		// Given: provider_id 指向不支援查詢的服務
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB12345678' ] );
		$meta_keys->update_provider_id( 'nonexistent_provider' );

		$request = new \WP_REST_Request( 'GET', "/power-checkout/v1/invoices/query/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		// When / Then
		$this->expectException( \Exception::class );
		InvoiceApiService::instance()->get_query_with_id_callback( $request );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_開立折讓端點金額不合法回VALIDATION錯誤(): void {
		// Given
		$order   = $this->create_issued_order();
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/allowance/{$order->get_id()}" );
		$request->set_body_params( [ 'amount' => 0 ] );
		$request->set_param( 'id', (string) $order->get_id() );

		// When
		$response = InvoiceApiService::instance()->post_allowance_with_id_callback( $request );

		// Then: 契約演進（einvoice 第五階段）——REST 層將 VALIDATION WP_Error 映射為 HTTP 422，
		// body 帶 error_code（前端讀 error.response.data.error_code + message）。
		$this->assertSame( 422, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::VALIDATION->value, $data['error_code'] ?? null );
		$this->assertNotEmpty( $data['message'] ?? '' );
	}
}
