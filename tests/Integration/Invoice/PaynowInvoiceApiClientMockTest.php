<?php
/**
 * PayNow 電子發票 InvoiceApiClient MOCK 模式整合測試（B-Cycle 1）
 *
 * 驗證 API_MODE=mock 下，PayNow 發票 client 不對外發真實網路請求，
 * 而是回固定 fixture。測試同時涵蓋 Response DTO（IssueResponse / AllowanceResponse / QueryResponse）
 * 的解析行為，以及 Bearer JWT-Token header 組裝正確性。
 *
 * RED 條件：以下全部類別於 B-Cycle 1 Green 前尚不存在：
 *   - J7\PowerCheckout\Domains\Invoice\Paynow\Http\InvoiceApiClient
 *   - J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\IssueResponse
 *   - J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceResponse
 *   - J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryResponse
 *   - J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceParams
 *   - J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryParams
 *
 * PayNow 發票 API 與 ezPay 關鍵差異：
 *   - 認證：Authorization: Bearer {jwt_token}（純 Bearer，無對稱加密信封）
 *   - 外層回應：{ status, type, message, result, request_id }，type=success 判斷（非 Status=SUCCESS）
 *   - query 走 GET（query string：InvoiceNumber / OrderNo / Limit / Page），其他走 POST JSON
 *   - 無 CheckCode 驗證（PayNow 發票 API 無對稱簽章，Bearer Token 即為全部認證）
 *
 * @see .claude/skills/paynow/references/invoice-api.md
 * @see specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §B-Cycle 1
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\PaynowInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PaynowInvoiceApiClient MOCK 模式測試類別
 *
 * 執行指令：
 *   API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/ --filter PaynowInvoiceApiClientMock
 *
 * @group integration
 * @group invoice
 * @group paynow
 */
final class PaynowInvoiceApiClientMockTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求記錄（驗證 mock 模式下完全沒有外呼）
	 *
	 * @var array<int, array{url: string, args: array<string, mixed>}>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 paynow_invoice，攔截所有對外 HTTP 請求
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests = [];
		\update_option( 'woocommerce_currency', 'TWD' );

		$this->enable_provider(
			PaynowInvoiceSettingsDTO::ID,
			[
				'mode'      => 'dev',
				'jwt_token' => 'test_jwt_token_for_mock',
			]
		);

		// 重置 singleton 快取，確保載入剛寫入的設定
		$reflection = new \ReflectionClass( PaynowInvoiceSettingsDTO::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		// 攔截：任何對外請求都記錄並短路（若 mock 分流正確，這裡完全不會被觸發）
		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * 每次測試後：移除攔截、清理設定、重置 singleton
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		\delete_option( ProviderUtils::get_option_name( PaynowInvoiceSettingsDTO::ID ) );

		// 重置 singleton 快取
		$reflection = new \ReflectionClass( PaynowInvoiceSettingsDTO::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		parent::tear_down();
	}

	/**
	 * pre_http_request 攔截器：記錄 URL + args 並短路
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt 短路值
	 * @param array<string, mixed>                 $args    請求參數（含 headers）
	 * @param string                               $url     請求 URL
	 * @return \WP_Error
	 */
	public function intercept_http( $preempt, array $args, string $url ): \WP_Error {
		$this->http_requests[] = [
			'url'  => $url,
			'args' => $args,
		];
		return new \WP_Error( 'http_blocked', '測試環境禁止對外請求' );
	}

	/**
	 * 建立一筆有商品的訂單，可選擇寫入已開立發票 meta
	 *
	 * @param array<string, mixed>|null $issued_data 若提供則寫入 issued_data meta（測試作廢/折讓/查詢用）
	 * @return \WC_Order
	 */
	private function create_order_with_items( ?array $issued_data = null ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1050,
			]
		);

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品 A' );
		$product->set_regular_price( '1050' );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_total( 1050 );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();

		if ( null !== $issued_data ) {
			( new MetaKeys( $order ) )->update_issued_data( $issued_data );
		}

		return $order;
	}

	// ========== Smoke 測試 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_smoke_mock模式下issue完整流程回固定值且不外呼(): void {
		// Given: 有商品的訂單 + 啟用 paynow_invoice
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		// When: 開立發票
		$response = $client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then: 回非 null + type=success + invoice_number 非空 + 完全無外呼
		$this->assertNotNull( $response, 'MOCK 模式下 issue 不應回 null' );
		$this->assertInstanceOf( IssueResponse::class, $response );
		$this->assertTrue( $response->is_success(), 'MOCK 回應 type 應為 success' );
		$this->assertNotEmpty( $response->invoice_number, 'MOCK 應回固定發票號碼' );
		$this->assertSame( [], $this->http_requests, 'MOCK 模式不應對外發任何 HTTP 請求' );
	}

	// ========== Happy Path 測試：Bearer header 與請求組裝 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue請求帶Authorization_Bearer_header(): void {
		// Given: 訂單 + 攔截 HTTP（移除 mock filter，改為觀察真實請求建構）
		// 注意：此測試驗證「非 mock 模式」下的 header 組裝。
		// 先移除 mock 攔截，讓 is_mock()=false 路徑建構請求，但 pre_http_request 仍攔截真實外呼。

		// 暫時清空 API_MODE 讓 is_mock() 回 false
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		// When: 呼叫 issue（會被 pre_http_request 攔截，不會真正外呼）
		// 此測試的目的是驗證 header 組裝，captured_args 記錄請求 args
		$client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then: 攔截到的請求 args 含 Authorization: Bearer {jwt_token}
		$this->assertNotEmpty( $this->http_requests, '非 mock 模式下應嘗試發出 HTTP 請求' );

		$request_args = $this->http_requests[0]['args'] ?? [];
		$headers      = $request_args['headers'] ?? [];

		$this->assertArrayHasKey( 'Authorization', $headers, '請求必須含 Authorization header' );
		$this->assertStringStartsWith(
			'Bearer ',
			(string) $headers['Authorization'],
			'Authorization header 必須為 Bearer 格式'
		);
		$this->assertStringContainsString(
			'test_jwt_token_for_mock',
			(string) $headers['Authorization'],
			'Authorization header 必須含設定的 jwt_token'
		);

		// 恢復環境變數
		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue請求body含必要欄位(): void {
		// Given: 非 mock 模式觀察請求 body
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );
		$client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then: 請求 body 含 PayNow issue 必要欄位
		$this->assertNotEmpty( $this->http_requests );
		$request_args = $this->http_requests[0]['args'] ?? [];
		$body_raw     = $request_args['body'] ?? '';

		/** @var array<string, mixed>|null $body */
		$body = \is_string( $body_raw )
		? \json_decode( $body_raw, true )
		: ( \is_array( $body_raw ) ? $body_raw : null );

		$this->assertNotNull( $body, '請求 body 應為有效 JSON' );
		$this->assertArrayHasKey( 'order_no', $body, 'issue body 必須含 order_no' );
		$this->assertArrayHasKey( 'total_amount', $body, 'issue body 必須含 total_amount' );
		$this->assertArrayHasKey( 'tax_amount', $body, 'issue body 必須含 tax_amount' );
		$this->assertArrayHasKey( 'carrier_type', $body, 'issue body 必須含 carrier_type' );
		$this->assertArrayHasKey( 'buyer', $body, 'issue body 必須含 buyer 物件' );
		$this->assertStringStartsWith( 'PCN', (string) ( $body['order_no'] ?? '' ), 'order_no 前綴應為 PCN' );

		// 恢復環境變數
		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_cancel請求body含invoice_number(): void {
		// Given: 非 mock 模式觀察 cancel 請求 body
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		$order  = $this->create_order_with_items(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10',
			]
		);
		$client = new InvoiceApiClient( $order );
		$client->cancel();

		// Then: 請求 body 含 invoice_number
		$this->assertNotEmpty( $this->http_requests );
		$request_args = $this->http_requests[0]['args'] ?? [];
		$body_raw     = $request_args['body'] ?? '';

		/** @var array<string, mixed>|null $body */
		$body = \is_string( $body_raw )
		? \json_decode( $body_raw, true )
		: ( \is_array( $body_raw ) ? $body_raw : null );

		$this->assertNotNull( $body, 'cancel 請求 body 應為有效 JSON' );
		$this->assertArrayHasKey( 'invoice_number', $body, 'cancel body 必須含 invoice_number' );
		$this->assertSame( 'AB12345678', (string) ( $body['invoice_number'] ?? '' ) );

		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_query走GET方法(): void {
		// Given: 非 mock 模式觀察 query 使用 GET
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		$order        = $this->create_order_with_items(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10',
			]
		);
		$client       = new InvoiceApiClient( $order );
		$query_params = QueryParams::from_issued_data(
			[
				'invoice_number' => 'AB12345678',
			]
		);
		$client->query( $query_params );

		// Then: 請求方法為 GET，URL 含 query string
		$this->assertNotEmpty( $this->http_requests );
		$request_url  = $this->http_requests[0]['url'] ?? '';
		$request_args = $this->http_requests[0]['args'] ?? [];
		$method       = \strtoupper( (string) ( $request_args['method'] ?? 'GET' ) );

		$this->assertSame( 'GET', $method, 'query 必須走 GET 方法' );
		$this->assertStringContainsString( '/api/invoices', (string) $request_url, 'query URL 應含 /api/invoices' );

		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}
	}

	// ========== Happy Path 測試：MOCK 模式 fixture 解析 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下issue回IssueResponse且invoice_number非空(): void {
		// Given
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		// When
		$response = $client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then
		$this->assertNotNull( $response );
		$this->assertInstanceOf( IssueResponse::class, $response );
		$this->assertTrue( $response->is_success() );
		$this->assertNotEmpty( $response->invoice_number, 'MOCK fixture 應含固定發票號碼' );
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下issue成功後寫入issued_data含invoice_number(): void {
		// Given
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		// When
		$client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then: issued_data meta 已寫入，含 invoice_number
		$meta_keys = new MetaKeys( $order );
		$issued    = $meta_keys->get_issued_data();
		$this->assertIsArray( $issued, 'issued_data 應為陣列' );
		$this->assertNotEmpty( $issued );
		$this->assertArrayHasKey( 'invoice_number', $issued, 'issued_data 應含 invoice_number' );
		$this->assertNotEmpty( $issued['invoice_number'] );
		$this->assertSame( PaynowInvoiceSettingsDTO::ID, $meta_keys->get_provider_id(), 'provider_id 應寫入 paynow_invoice' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下cancel回IssueResponse且type為success(): void {
		// Given: 先有 issued_data
		$order  = $this->create_order_with_items(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10 10:00:00',
			]
		);
		$client = new InvoiceApiClient( $order );

		// When
		$response = $client->cancel();

		// Then
		$this->assertNotNull( $response, 'MOCK cancel 不應回 null' );
		$this->assertInstanceOf( IssueResponse::class, $response );
		$this->assertTrue( $response->is_success(), 'MOCK cancel type 應為 success' );
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下allowance回AllowanceResponse且allowance_number非空(): void {
		// Given: 先有 issued_data（開立折讓需要先有發票）
		$order  = $this->create_order_with_items(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10 10:00:00',
			]
		);
		$client = new InvoiceApiClient( $order );
		$params = AllowanceParams::from_issued_data(
			[ 'invoice_number' => 'AB12345678' ],
			300
		);

		// When
		$response = $client->allowance( $params );

		// Then
		$this->assertNotNull( $response, 'MOCK allowance 不應回 null' );
		$this->assertInstanceOf( AllowanceResponse::class, $response );
		$this->assertTrue( $response->is_success(), 'MOCK allowance type 應為 success' );
		$this->assertNotEmpty( $response->allowance_number, 'MOCK fixture 應含固定折讓號碼' );
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下invalid_allowance回AllowanceResponse(): void {
		// Given: 先有折讓 data
		$order          = $this->create_order_with_items(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10 10:00:00',
				'allowance_data' => [
					'allowance_number' => 'A20260610000001',
					'allowance_date'   => '2026-06-10 11:00:00',
				],
			]
		);
		$client         = new InvoiceApiClient( $order );
		$allowance_data = [ 'allowance_number' => 'A20260610000001' ];

		// When
		$response = $client->invalid_allowance( $allowance_data );

		// Then
		$this->assertNotNull( $response, 'MOCK invalid_allowance 不應回 null' );
		$this->assertInstanceOf( AllowanceResponse::class, $response );
		$this->assertTrue( $response->is_success(), 'MOCK invalid_allowance type 應為 success' );
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下query回QueryResponse且invoice_number非空(): void {
		// Given
		$order        = $this->create_order_with_items(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10 10:00:00',
			]
		);
		$client       = new InvoiceApiClient( $order );
		$query_params = QueryParams::from_issued_data( [ 'invoice_number' => 'AB12345678' ] );

		// When
		$response = $client->query( $query_params );

		// Then
		$this->assertNotNull( $response, 'MOCK query 不應回 null' );
		$this->assertInstanceOf( QueryResponse::class, $response );
		$this->assertNotEmpty( $response->invoice_number, 'MOCK query fixture 應含固定發票號碼' );
		$this->assertSame( [], $this->http_requests );
	}

	// ========== Happy Path 測試：IssueResponse DTO ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_IssueResponse_type_success時is_success回true(): void {
		// Given: PayNow 外層回應 type=success + result 含 invoice_number
		$result   = [
			'invoice_number' => 'AB12345678',
			'invoice_date'   => '2026-06-10T10:00:00',
			'order_no'       => 'PCN100',
			'total_amount'   => 1050,
		];
		$response = new IssueResponse( $result );

		// Then
		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'AB12345678', $response->invoice_number );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_IssueResponse_缺invoice_number時is_success回false(): void {
		// Given: result 缺 invoice_number（開立失敗）
		$response = new IssueResponse( [] );

		// Then
		$this->assertFalse( $response->is_success() );
		$this->assertSame( '', $response->invoice_number );
	}

	// ========== Happy Path 測試：AllowanceResponse DTO ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_AllowanceResponse_含allowance_number時is_success回true(): void {
		// Given: PayNow allowance result 含 allowance_number
		$result   = [
			'allowance_number' => 'A20260610000001',
			'invoice_number'   => 'AB12345678',
			'allowance_date'   => '2026-06-10T11:00:00',
			'allowance_amount' => 300,
			'remain_amount'    => 750,
		];
		$response = new AllowanceResponse( $result );

		// Then
		$this->assertTrue( $response->is_success() );
		$this->assertSame( 'A20260610000001', $response->allowance_number );
		$this->assertSame( 'AB12345678', $response->invoice_number );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_AllowanceResponse_缺allowance_number時is_success回false(): void {
		// Given: 空 result
		$response = new AllowanceResponse( [] );

		// Then
		$this->assertFalse( $response->is_success() );
		$this->assertSame( '', $response->allowance_number );
	}

	// ========== Happy Path 測試：QueryResponse DTO ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_QueryResponse_含invoice_number且to_array回正確結構(): void {
		// Given: PayNow query result
		$result   = [
			'invoice_number' => 'AB12345678',
			'invoice_status' => 'issued',
			'total_amount'   => 1050,
			'invoice_date'   => '2026-06-10T10:00:00',
			'order_no'       => 'PCN100',
		];
		$response = new QueryResponse( $result );

		// Then
		$this->assertSame( 'AB12345678', $response->invoice_number );
		$arr = $response->to_array();
		$this->assertArrayHasKey( 'invoice_number', $arr );
		$this->assertArrayHasKey( 'invoice_status', $arr );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_QueryResponse_缺欄位時以空字串補(): void {
		// Given: 空陣列
		$response = new QueryResponse( [] );

		// Then: 所有欄位以空字串/0補
		$this->assertSame( '', $response->invoice_number );
		$this->assertSame( '', $response->invoice_status );
	}

	// ========== Happy Path 測試：QueryParams DTO ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_QueryParams_from_issued_data組出正確query欄位(): void {
		// Given
		$issued_data = [
			'invoice_number' => 'AB12345678',
			'order_no'       => 'PCN100',
		];

		// When
		$params = QueryParams::from_issued_data( $issued_data );
		$arr    = $params->to_array();

		// Then
		$this->assertArrayHasKey( 'InvoiceNumber', $arr, 'QueryParams 應含 InvoiceNumber（GET query string key）' );
		$this->assertSame( 'AB12345678', (string) ( $arr['InvoiceNumber'] ?? '' ) );
	}

	// ========== Happy Path 測試：AllowanceParams DTO ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_AllowanceParams_from_issued_data組出折讓欄位(): void {
		// Given
		$issued_data = [
			'invoice_number' => 'AB12345678',
		];

		// When
		$params = AllowanceParams::from_issued_data( $issued_data, 300 );
		$arr    = $params->to_array();

		// Then
		$this->assertArrayHasKey( 'invoice_number', $arr, 'AllowanceParams 應含 invoice_number' );
		$this->assertSame( 'AB12345678', (string) ( $arr['invoice_number'] ?? '' ) );
	}

	// ========== Happy Path 測試：API URL 路由 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_dev模式下issue請求打invoiceapi_dev網域(): void {
		// Given: mode=dev（已在 set_up 設定）
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );
		$client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then: 請求 URL 含 invoiceapi-dev
		$this->assertNotEmpty( $this->http_requests );
		$url = (string) ( $this->http_requests[0]['url'] ?? '' );
		$this->assertStringContainsString(
			'invoiceapi-dev.paynow.com.tw',
			$url,
			'dev 模式下 URL 應含 invoiceapi-dev.paynow.com.tw'
		);
		$this->assertStringContainsString( '/api/invoices/issue', $url );

		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_prod模式下issue請求打invoiceapi_prod網域(): void {
		// Given: 切換為 prod 模式
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		// 重設 singleton 為 prod 模式
		$reflection = new \ReflectionClass( PaynowInvoiceSettingsDTO::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		\delete_option( ProviderUtils::get_option_name( PaynowInvoiceSettingsDTO::ID ) );
		$this->enable_provider(
			PaynowInvoiceSettingsDTO::ID,
			[
				'mode'      => 'prod',
				'jwt_token' => 'prod_jwt_token',
			]
		);

		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );
		$client->issue( PaynowInvoiceSettingsDTO::ID );

		// Then: URL 含 invoiceapi-prod
		$this->assertNotEmpty( $this->http_requests );
		$url = (string) ( $this->http_requests[0]['url'] ?? '' );
		$this->assertStringContainsString(
			'invoiceapi-prod.paynow.com.tw',
			$url,
			'prod 模式下 URL 應含 invoiceapi-prod.paynow.com.tw'
		);

		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}
	}

	// ========== Happy Path 測試：外層回應 type=success 判斷 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_decode_result_type_success時回result陣列(): void {
		// Given: PayNow 標準外層回應（type=success）
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		$outer_response = [
			'status'     => 200,
			'type'       => 'success',
			'message'    => '',
			'result'     => [
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10T10:00:00',
				'order_no'       => 'PCN100',
				'total_amount'   => 1050,
			],
			'request_id' => 'test-uuid-0001',
		];

		// When: 透過反射呼叫 decode_result（驗證解析邏輯）
		$reflection = new \ReflectionClass( InvoiceApiClient::class );
		$method     = $reflection->getMethod( 'decode_result' );
		$method->setAccessible( true );

		/** @var array<string, mixed> $result */
		$result = $method->invoke( $client, $outer_response );

		// Then: 回 result 陣列，含 invoice_number
		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', (string) ( $result['invoice_number'] ?? '' ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_decode_result_type非success時拋例外(): void {
		// Given: PayNow 外層回應 type=error（開立失敗）
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		$outer_response = [
			'status'     => 400,
			'type'       => 'error',
			'message'    => '發票開立失敗：金額格式錯誤',
			'result'     => null,
			'request_id' => 'test-uuid-0002',
		];

		// When + Then: decode_result 應拋出例外
		$reflection = new \ReflectionClass( InvoiceApiClient::class );
		$method     = $reflection->getMethod( 'decode_result' );
		$method->setAccessible( true );

		$this->expectException( \RuntimeException::class );
		$method->invoke( $client, $outer_response );
	}
}
