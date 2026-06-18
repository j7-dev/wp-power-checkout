<?php
/**
 * InvoiceApiService 正規化錯誤 REST 映射測試（einvoice 導入第五階段，步驟 9）
 *
 * 驗證 5 個 REST 端點（issue / cancel / allowance / allowance-cancel / query）在 provider
 * 回傳正規化 \WP_Error（面 B）時，將其映射為：
 *   - HTTP status = ErrorCode::to_http_status()（VALIDATION→422、NOT_FOUND→404、
 *     NUMBER_EXHAUSTED→409、AUTH→401、NETWORK→502、PROVIDER→502、UNKNOWN→500）。
 *   - body data 含 error_code（正規化 code）/ raw_code（provider 原始碼）/ message（可讀訊息）。
 * 成功（array）維持既有 200 回應、原樣透傳，不變。
 *
 * 面 A（既有，保留不退化）：訂單不存在 / provider 不存在 / 型別不符 → callback 仍 throw \Exception
 *   （WP ApiBase try() 在線上會包成 HTTP 500；單元層直接斷言 expectException）。
 *
 * 錯誤注入：以真實 ezPay provider 啟用 + InvoiceApiClient::$mock_error_override 注入「Status 非 SUCCESS」
 * 的外層回應，逐一觸發 business 錯誤碼 → 對應正規化 code（沿用 EzpayInvoiceErrorMapTest 的 mock 機制）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter InvoiceRestErrorMappingTest --no-coverage'
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\InvoiceApiService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * InvoiceApiService 正規化錯誤 REST 映射測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 * @group error
 * @group edge
 */
final class InvoiceRestErrorMappingTest extends TestCase {

	/**
	 * 每次測試前：啟用 ezpay（正確金鑰）、放入容器、清空錯誤注入
	 */
	public function set_up(): void {
		parent::set_up();

		InvoiceApiClient::$mock_error_override = null;

		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => 'MS12345678',
				'hash_key'    => 'abcdefghijklmnopqrstuvwxyzabcdef',
				'hash_iv'     => '1234567891234567',
			]
		);
		// REST service 透過 ProviderUtils 取 provider.
		ProviderUtils::$container[ EzpayInvoiceProvider::ID ] = EzpayInvoiceProvider::instance();
	}

	/**
	 * 每次測試後：清空錯誤注入與設定
	 */
	public function tear_down(): void {
		InvoiceApiClient::$mock_error_override = null;
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EzpayInvoiceProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 EzpaySettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( EzpaySettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 建立一筆有商品、可通過 dispatch 驗證的 B2C 訂單（未開立發票），provider = ezpay
	 *
	 * @param array<string, mixed> $issue_params 結帳填寫的發票資訊
	 * @return \WC_Order
	 */
	private function create_order( array $issue_params = [] ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
		);

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '100' );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_total( 100 );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();

		$params = \array_merge( [ 'provider' => 'ezpay' ], $issue_params );
		( new MetaKeys( $order ) )->update_issue_params( $params );
		( new MetaKeys( $order ) )->update_provider_id( EzpayInvoiceProvider::ID );

		return $order;
	}

	/**
	 * 建立一筆已開立發票的 ezPay 訂單（供 cancel / allowance / query 路徑）
	 *
	 * @return \WC_Order
	 */
	private function create_issued_order(): \WC_Order {
		$order     = $this->create_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number'   => 'EV00000001',
				'invoice_trans_no' => 'EZT0000001',
				'random_num'       => '1234',
				'invoice_date'     => '2026-01-15 10:00:00',
			]
		);
		$meta_keys->update_provider_id( EzpayInvoiceProvider::ID );
		return $order;
	}

	/**
	 * 對 issue 端點發出請求並取回回應
	 *
	 * issue 端點以「請求 body」作為發票參數來源（post_issue_with_id_callback 會 update_issue_params(body)），
	 * 故發票資訊須由 body 帶入（會覆寫 create_order 預設寫入的 meta）。
	 *
	 * @param \WC_Order            $order      訂單
	 * @param array<string, mixed> $body_extra 額外的 body 發票參數（如 companyId）
	 * @return \WP_REST_Response
	 */
	private function call_issue( \WC_Order $order, array $body_extra = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/issue/{$order->get_id()}" );
		$request->set_body_params( \array_merge( [ 'provider' => 'ezpay' ], $body_extra ) );
		// 'id' 須最後設定，確保在 get_params() 合併中勝出（單元層無路由解析 URL param）.
		$request->set_param( 'id', (string) $order->get_id() );
		return InvoiceApiService::instance()->post_issue_with_id_callback( $request );
	}

	// ========================================================================
	// 面 B：provider 回正規化 WP_Error → REST 映射 HTTP + body 欄位
	// ========================================================================

	/**
	 * AUTH（KEY10002）→ HTTP 401 + body error_code=AUTH + raw_code=KEY10002 + message 非空
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_issue_AUTH映射為401且body帶error_code與raw_code(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '資料解密錯誤',
		];

		$response = $this->call_issue( $order );

		$this->assertSame( 401, $response->get_status(), 'AUTH 須映射為 HTTP 401' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::AUTH->value, $data['error_code'] ?? null );
		$this->assertSame( 'KEY10002', $data['raw_code'] ?? null );
		$this->assertNotEmpty( $data['message'] ?? '', 'body 須含可讀 message 供前端顯示' );
	}

	/**
	 * VALIDATION（dispatch 級統編 checksum 不合法）→ HTTP 422 + error_code=VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_issue_VALIDATION映射為422(): void {
		// companyId 12345678 財政部 checksum 不過 → issue 第一步回 VALIDATION（不打 API）.
		// 發票參數由 body 帶入（issue 端點以 body 為發票資訊來源）.
		$order = $this->create_order();

		$response = $this->call_issue(
			$order,
			[
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '12345678',
			]
		);

		$this->assertSame( 422, $response->get_status(), 'VALIDATION 須映射為 HTTP 422' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::VALIDATION->value, $data['error_code'] ?? null );
		$this->assertNotEmpty( $data['message'] ?? '' );
	}

	/**
	 * NOT_FOUND（INV20006 查無發票）→ HTTP 404 + error_code=NOT_FOUND + raw_code
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_issue_NOT_FOUND映射為404(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'INV20006',
			'Message' => '查無發票資料',
		];

		$response = $this->call_issue( $order );

		$this->assertSame( 404, $response->get_status(), 'NOT_FOUND 須映射為 HTTP 404' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::NOT_FOUND->value, $data['error_code'] ?? null );
		$this->assertSame( 'INV20006', $data['raw_code'] ?? null );
	}

	/**
	 * NUMBER_EXHAUSTED（INV90006 字軌用罄）→ HTTP 409 + error_code=NUMBER_EXHAUSTED + raw_code
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_issue_NUMBER_EXHAUSTED映射為409(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'INV90006',
			'Message' => '可開立張數已用罄',
		];

		$response = $this->call_issue( $order );

		$this->assertSame( 409, $response->get_status(), 'NUMBER_EXHAUSTED 須映射為 HTTP 409' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::NUMBER_EXHAUSTED->value, $data['error_code'] ?? null );
		$this->assertSame( 'INV90006', $data['raw_code'] ?? null );
	}

	/**
	 * NETWORK（NOR10001 網路連線異常）→ HTTP 502 + error_code=NETWORK
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_issue_NETWORK映射為502(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'NOR10001',
			'Message' => '網路連線異常',
		];

		$response = $this->call_issue( $order );

		$this->assertSame( 502, $response->get_status(), 'NETWORK 須映射為 HTTP 502' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::NETWORK->value, $data['error_code'] ?? null );
	}

	/**
	 * PROVIDER（未涵蓋業務碼 LIB99999）→ HTTP 502 + error_code=PROVIDER + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_issue_PROVIDER映射為502且保留raw_code(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'LIB99999',
			'Message' => '未知錯誤',
		];

		$response = $this->call_issue( $order );

		$this->assertSame( 502, $response->get_status(), 'PROVIDER 須映射為 HTTP 502' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::PROVIDER->value, $data['error_code'] ?? null );
		$this->assertSame( 'LIB99999', $data['raw_code'] ?? null, '未涵蓋碼仍須保留 raw_code 供 debug' );
	}

	// ========================================================================
	// cancel / allowance / allowance-cancel / query 端點映射
	// ========================================================================

	/**
	 * cancel：CONFLICT（已開折讓擋作廢 LIB10007）→ HTTP 409 + error_code=CONFLICT + raw_code
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_cancel_CONFLICT映射為409(): void {
		$order = $this->create_issued_order();
		// 寫入折讓資料 → provider 前置攔截回 CONFLICT（不打 API）.
		( new MetaKeys( $order ) )->update_allowance_data(
			[
				'allowance_no'     => 'EA00000001',
				'allowance_amount' => 50,
			]
		);

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/cancel/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		$response = InvoiceApiService::instance()->post_cancel_with_id_callback( $request );

		$this->assertSame( 409, $response->get_status(), 'CONFLICT 須映射為 HTTP 409' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::CONFLICT->value, $data['error_code'] ?? null );
		$this->assertSame( 'LIB10007', $data['raw_code'] ?? null );
	}

	/**
	 * allowance：金額不合法 → VALIDATION → HTTP 422（取代 phase 4 暫時的 200 透傳）
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_allowance_VALIDATION映射為422(): void {
		$order   = $this->create_issued_order();
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/allowance/{$order->get_id()}" );
		$request->set_body_params( [ 'amount' => 0 ] );
		$request->set_param( 'id', (string) $order->get_id() );

		$response = InvoiceApiService::instance()->post_allowance_with_id_callback( $request );

		$this->assertSame( 422, $response->get_status(), '折讓金額不合法 VALIDATION 須映射為 HTTP 422' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::VALIDATION->value, $data['error_code'] ?? null );
	}

	/**
	 * allowance-cancel：無折讓資料 → NOT_FOUND → HTTP 404
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_allowance_cancel_NOT_FOUND映射為404(): void {
		// 已開立發票但無折讓資料 → invalid_allowance 回 NOT_FOUND.
		$order   = $this->create_issued_order();
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/allowance-cancel/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		$response = InvoiceApiService::instance()->post_allowance_cancel_with_id_callback( $request );

		$this->assertSame( 404, $response->get_status(), '無折讓資料 NOT_FOUND 須映射為 HTTP 404' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::NOT_FOUND->value, $data['error_code'] ?? null );
	}

	/**
	 * query：未開立發票 → NOT_FOUND → HTTP 404（GET 端點）
	 *
	 * @test
	 * @group error
	 */
	public function test_rest_query_NOT_FOUND映射為404(): void {
		// 未開立發票（無 issued_data）→ query_invoice 回 NOT_FOUND.
		$order   = $this->create_order();
		$request = new \WP_REST_Request( 'GET', "/power-checkout/v1/invoices/query/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		$response = InvoiceApiService::instance()->get_query_with_id_callback( $request );

		$this->assertSame( 404, $response->get_status(), 'query 未開立 NOT_FOUND 須映射為 HTTP 404' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( ErrorCode::NOT_FOUND->value, $data['error_code'] ?? null );
	}

	// ========================================================================
	// 成功（array）→ 維持 200 原樣透傳，不變
	// ========================================================================

	/**
	 * issue 成功 → HTTP 200 + 原樣透傳 provider array（含 invoice_number），非錯誤 body
	 *
	 * @test
	 * @group happy
	 */
	public function test_rest_issue_成功維持200且原樣透傳array(): void {
		$order = $this->create_order();

		$response = $this->call_issue(
			$order,
			[
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);

		$this->assertSame( 200, $response->get_status(), '成功須維持 HTTP 200' );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertFalse( \is_wp_error( $data ) );
		$this->assertNotEmpty( $data['invoice_number'] ?? '', '成功 body 須原樣透傳 provider array' );
		$this->assertArrayNotHasKey( 'error_code', $data, '成功回應不應帶 error_code' );
	}

	/**
	 * cancel 成功 → HTTP 200 + 原樣透傳作廢 array
	 *
	 * @test
	 * @group happy
	 */
	public function test_rest_cancel_成功維持200(): void {
		$order   = $this->create_issued_order();
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/cancel/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		$response = InvoiceApiService::instance()->post_cancel_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayNotHasKey( 'error_code', $data );
		$this->assertNotEmpty( $data );
	}

	// ========================================================================
	// 面 A（既有，保留不退化）：訂單 / provider 找不到 → 仍 throw（線上 500）
	// ========================================================================

	/**
	 * issue：訂單不存在 → callback 仍 throw \Exception（面 A 不退化）
	 *
	 * @test
	 * @group error
	 */
	public function test_面A_issue_訂單不存在仍拋例外(): void {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/invoices/issue/9999999' );
		$request->set_param( 'id', '9999999' );
		$request->set_body_params( [ 'provider' => 'ezpay' ] );

		$this->expectException( \Exception::class );
		InvoiceApiService::instance()->post_issue_with_id_callback( $request );
	}

	/**
	 * cancel：provider_id 指向未啟用 / 不存在 provider → callback 仍 throw（面 A 不退化）
	 *
	 * @test
	 * @group error
	 */
	public function test_面A_cancel_provider不存在仍拋例外(): void {
		$order     = $this->create_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_provider_id( 'nonexistent_provider' );

		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/invoices/cancel/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		$this->expectException( \Exception::class );
		InvoiceApiService::instance()->post_cancel_with_id_callback( $request );
	}

	/**
	 * query：provider 不支援查詢 → callback 仍 throw（面 A 不退化）
	 *
	 * @test
	 * @group error
	 */
	public function test_面A_query_provider不支援查詢仍拋例外(): void {
		$order     = $this->create_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'EV00000001' ] );
		$meta_keys->update_provider_id( 'nonexistent_provider' );

		$request = new \WP_REST_Request( 'GET', "/power-checkout/v1/invoices/query/{$order->get_id()}" );
		$request->set_param( 'id', (string) $order->get_id() );

		$this->expectException( \Exception::class );
		InvoiceApiService::instance()->get_query_with_id_callback( $request );
	}
}
