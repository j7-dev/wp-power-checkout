<?php
/**
 * PayNow 物流 ApiClient 整合測試（TDD Red 階段 — A-Cycle 1）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsApiClient
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 1
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 1 步驟 7
 *   - woomp class-paynow-shipping-request.php（create_order / renew_order / cancel_order / query_order / print_label）
 *
 * 涵蓋範疇（mock 模式）：
 *   - add_order：JsonOrder 欄位存在且為 base64（TripleDES 加密後 base64 的結果）
 *   - add_order：POST 方法、URL 含 Add_Order 路徑
 *   - renew_order：JsonOrder 欄位存在（含 LogisticNumber）、URL 含 ReNewOrder 路徑
 *   - cancel_order：HTTP DELETE 方法、URL 含 CancelOrder 路徑、帶 LogisticNumber + sno + PassCode
 *   - query_order：GET 方法、URL 含 Get_Order_Info + LogisticNumber + sno 查詢字串
 *   - print_label：依 service_id 分派（Seven → Order711；Tcat → PrintBlackCatLabel）
 *   - mock 模式（API_MODE=mock）回 fixture 不打真實 API
 *   - fixture 回應解析（Status=S 成功 / Status=F 失敗）
 *
 * Mock 手法：
 *   HTTP 以 WordPress pre_http_request filter 攔截（API_MODE=mock 不打真實 PayNow 物流 API）
 *   tearDown 移除所有已掛 filter，確保測試隔離
 *
 * ⚠️ 幣別踩雷：涉及 get_total()，須顯式設定 TWD。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowLogisticsApiClientTest tests/Integration/Logistics/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 物流 ApiClient 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsApiClientTest extends TestCase {

	/** mock filter 識別標記 */
	private const FILTER_PRE_HTTP = 'pre_http_request';

	/** 測試用商家帳號 */
	private const TEST_USER_ACCOUNT = 'TEST_LOGISTICS_ACCT';

	/** 測試用 apicode */
	private const TEST_APICODE = 'TEST_LOGISTICS_CODE';

	/** 測試用物流單號 */
	private const TEST_LOGISTIC_NUMBER = 'LN123456789';

	/** test 模式 API base URL */
	private const TEST_API_BASE = 'https://testlogistic.paynow.com.tw';

	/** 已掛的 filter callable，tearDown 時移除 */
	private array $registered_filters = [];

	/** 每次測試前設定環境與 provider 設定 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );
		\update_option( 'woocommerce_currency', 'TWD' );

		// 設定 PayNow 物流 provider（啟用 + test 模式）
		ProviderUtils::update_option(
			'paynow_logistics',
			[
				'enabled'      => 'yes',
				'mode'         => 'test',
				'user_account' => self::TEST_USER_ACCOUNT,
				'apicode'      => self::TEST_APICODE,
				'sender_name'  => '測試寄件人',
				'sender_phone' => '0912345678',
			]
		);
		PaynowLogisticsSettingsDTO::reset();
	}

	/** 每次測試後清理 filter 與環境 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );

		foreach ( $this->registered_filters as [ $tag, $callback, $priority ] ) {
			\remove_filter( $tag, $callback, $priority );
		}
		$this->registered_filters = [];

		\delete_option( ProviderUtils::get_option_name( 'paynow_logistics' ) );
		PaynowLogisticsSettingsDTO::reset();

		parent::tear_down();
	}

	// ========== Helper：建立測試訂單 ==========

	/**
	 * 建立帶物流 meta 的測試訂單
	 *
	 * @param string $service_id 物流服務代碼
	 * @param float  $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_logistics_order(
		string $service_id = '01',
		float $total = 1000.0
	): \WC_Order {
		$order = $this->create_wc_order( [ 'total' => $total ] );

		$order->update_meta_data( PaynowLogisticsMetaKeys::SERVICE_ID, $service_id );
		$order->update_meta_data( PaynowLogisticsMetaKeys::ORDER_NO, 'PCN' . $order->get_id() );
		$order->update_meta_data( PaynowLogisticsMetaKeys::STORE_ID, 'STORE_001' );
		$order->update_meta_data( PaynowLogisticsMetaKeys::STORE_NAME, '7-11 測試門市' );
		$order->update_meta_data( PaynowLogisticsMetaKeys::STORE_ADDR, '台北市大安區' );
		$order->set_payment_method( 'paynow' );
		$order->set_shipping_last_name( '李' );
		$order->set_shipping_first_name( '四' );
		$order->set_billing_email( 'receiver@example.com' );
		$order->save();

		return $order;
	}

	/**
	 * 建立已有物流單號的測試訂單（用於 renew / cancel / query）
	 *
	 * @param string $service_id 物流服務代碼
	 * @return \WC_Order
	 */
	private function create_order_with_ref( string $service_id = '01' ): \WC_Order {
		$order = $this->create_logistics_order( $service_id );
		$order->update_meta_data( PaynowLogisticsMetaKeys::REF, self::TEST_LOGISTIC_NUMBER );
		$order->update_meta_data( PaynowLogisticsMetaKeys::SNO, '1' );
		$order->save();
		return $order;
	}

	// ========== Helper：mock HTTP ==========

	/**
	 * 掛 pre_http_request filter 回傳模擬回應，並捕捉請求資訊
	 *
	 * @param string        $body     回應 body
	 * @param callable|null $capture  捕捉 ($parsed_args, $url) 的回呼
	 * @return void
	 */
	private function mock_http(
		string $body,
		?callable $capture = null
	): void {
		$callback = function ( $false, $parsed_args, $url ) use ( $body, $capture ) {
			if ( $capture ) {
				$capture( $parsed_args, $url );
			}
			return [
				'headers'  => [ 'content-type' => 'text/html' ],
				'body'     => $body,
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];
	}

	/**
	 * 回傳 PayNow 物流 API 成功回應 JSON
	 *
	 * @param array<string, mixed> $extra 額外欄位（合併到回應）
	 * @return string JSON 字串
	 */
	private function make_success_response( array $extra = [] ): string {
		$default = [
			'Status'         => 'S',
			'LogisticNumber' => self::TEST_LOGISTIC_NUMBER,
			'orderno'        => 'PCN100',
			'paymentno'      => 'PMT001',
			'validationno'   => 'VAL001',
			'ReturnMsg'      => '建立成功',
		];
		return \wp_json_encode( \array_merge( $default, $extra ) );
	}

	/**
	 * 回傳 PayNow 物流 API 失敗回應 JSON
	 *
	 * @return string JSON 字串
	 */
	private function make_failure_response(): string {
		return \wp_json_encode(
			[
				'Status'   => 'F',
				'ErrorMsg' => '金額超過上限',
			]
		);
	}

	// ========== Happy Path：mock 模式不打真實 API ==========

	/**
	 * mock 模式（API_MODE=mock）add_order 回 fixture 不打真實 API
	 *
	 * @test
	 * @group happy
	 */
	public function test_mock模式add_order回fixture不打真實API(): void {
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );

		$result = $client->add_order();

		// mock 模式應直接回 fixture，不需 HTTP 攔截就能運作
		$this->assertIsArray( $result, 'mock 模式 add_order 應回傳陣列 fixture' );
		$this->assertArrayHasKey( 'Status', $result, 'fixture 應含 Status 欄位' );
	}

	/**
	 * mock 模式 renew_order 回 fixture
	 *
	 * @test
	 * @group happy
	 */
	public function test_mock模式renew_order回fixture(): void {
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );

		$result = $client->renew_order();

		$this->assertIsArray( $result, 'mock 模式 renew_order 應回傳陣列 fixture' );
		$this->assertArrayHasKey( 'Status', $result, 'fixture 應含 Status 欄位' );
	}

	/**
	 * mock 模式 cancel_order 回 fixture
	 *
	 * @test
	 * @group happy
	 */
	public function test_mock模式cancel_order回fixture(): void {
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );

		$result = $client->cancel_order();

		$this->assertIsString( $result, 'mock 模式 cancel_order 應回傳字串 fixture' );
		// cancel 回應為純文字（含 'S' 代表成功）
		$this->assertNotSame( '', $result, 'cancel_order fixture 不得為空字串' );
	}

	/**
	 * mock 模式 query_order 回 fixture
	 *
	 * @test
	 * @group happy
	 */
	public function test_mock模式query_order回fixture(): void {
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );

		$result = $client->query_order();

		$this->assertIsArray( $result, 'mock 模式 query_order 應回傳陣列 fixture' );
		$this->assertArrayHasKey( 'Status', $result, 'query_order fixture 應含 Status 欄位' );
	}

	/**
	 * mock 模式 print_label（Seven）回 fixture
	 *
	 * @test
	 * @group happy
	 */
	public function test_mock模式print_label_Seven回fixture(): void {
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_order_with_ref( service_id: '01' );
		$client = new LogisticsApiClient( $order );

		$result = $client->print_label();

		$this->assertNotNull( $result, 'mock 模式 print_label（Seven）不得回 null' );
	}

	/**
	 * mock 模式 print_label（Tcat）回 fixture（黑貓宅配）
	 *
	 * @test
	 * @group happy
	 */
	public function test_mock模式print_label_Tcat回fixture(): void {
		\putenv( 'API_MODE=mock' );
		$order  = $this->create_order_with_ref( service_id: '06' );
		$client = new LogisticsApiClient( $order );

		$result = $client->print_label();

		$this->assertNotNull( $result, 'mock 模式 print_label（Tcat）不得回 null' );
	}

	// ========== Happy Path：非 mock 模式 — 請求組裝驗證 ==========

	/**
	 * add_order 請求 body 含 JsonOrder 欄位（base64 字串）
	 *
	 * JsonOrder = base64( TripleDES::encrypt_order_json( json_encode($args) ) )
	 * 依 woomp build_encrypted_args()：base64 編碼 TripleDES 密文
	 *
	 * @test
	 * @group happy
	 */
	public function test_add_order請求body含JsonOrder的base64字串(): void {
		\putenv( 'API_MODE=live' ); // 非 mock，觸發真實 HTTP（以 filter 攔截）

		$captured_args = null;
		$this->mock_http(
			$this->make_success_response(),
			function ( array $parsed_args, string $url ) use ( &$captured_args ): void {
				$captured_args = $parsed_args;
			}
		);

		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );
		$client->add_order();

		$this->assertNotNull( $captured_args, '應攔截到 HTTP 請求' );

		// 驗 JsonOrder 存在於 body
		$body = $captured_args['body'] ?? [];
		$this->assertArrayHasKey( 'JsonOrder', $body, 'add_order request body 應含 JsonOrder 欄位' );

		// JsonOrder 應為有效 base64 字串（非空）
		$json_order = $body['JsonOrder'];
		$this->assertNotSame( '', $json_order, 'JsonOrder 不得為空字串' );
		$decoded = \base64_decode( $json_order, true );
		$this->assertNotFalse( $decoded, 'JsonOrder 應為有效 base64 字串' );
	}

	/**
	 * add_order 使用 POST 方法，URL 含 Add_Order 路徑
	 *
	 * 依 woomp：POST /api/Orderapi/Add_Order
	 *
	 * @test
	 * @group happy
	 */
	public function test_add_order使用POST方法且URL含Add_Order路徑(): void {
		\putenv( 'API_MODE=live' );

		$captured_method = null;
		$captured_url    = null;

		$this->mock_http(
			$this->make_success_response(),
			function ( array $parsed_args, string $url ) use ( &$captured_method, &$captured_url ): void {
				$captured_method = $parsed_args['method'] ?? 'POST';
				$captured_url    = $url;
			}
		);

		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );
		$client->add_order();

		$this->assertNotNull( $captured_url, '應攔截到 HTTP 請求' );
		$this->assertSame( 'POST', \strtoupper( $captured_method ?? '' ), 'add_order 應使用 POST 方法' );
		$this->assertStringContainsString( 'Add_Order', $captured_url, 'add_order URL 應含 Add_Order' );
	}

	/**
	 * add_order URL 使用 test 模式的 testlogistic.paynow.com.tw
	 *
	 * @test
	 * @group happy
	 */
	public function test_add_order在test模式使用testlogistic網域(): void {
		\putenv( 'API_MODE=live' );

		$captured_url = null;
		$this->mock_http(
			$this->make_success_response(),
			function ( array $parsed_args, string $url ) use ( &$captured_url ): void {
				$captured_url = $url;
			}
		);

		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );
		$client->add_order();

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString(
			'testlogistic.paynow.com.tw',
			$captured_url,
			'test 模式應使用 testlogistic.paynow.com.tw'
		);
	}

	/**
	 * renew_order 請求 body 含 JsonOrder 且 URL 含 ReNewOrder 路徑
	 *
	 * 依 woomp renew_order()：POST /api/Orderapi/ReNewOrder，body 含 JsonOrder
	 * renew args 含 LogisticNumber（與 add_order 不同）
	 *
	 * @test
	 * @group happy
	 */
	public function test_renew_order請求body含JsonOrder且URL含ReNewOrder(): void {
		\putenv( 'API_MODE=live' );

		$captured_args = null;
		$captured_url  = null;
		$this->mock_http(
			$this->make_success_response( [ 'OrderNo' => 'RENEW_ORDER_001' ] ),
			function ( array $parsed_args, string $url ) use ( &$captured_args, &$captured_url ): void {
				$captured_args = $parsed_args;
				$captured_url  = $url;
			}
		);

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$client->renew_order();

		$this->assertNotNull( $captured_args, '應攔截到 HTTP 請求' );
		$this->assertNotNull( $captured_url, '應捕捉到 URL' );

		$body = $captured_args['body'] ?? [];
		$this->assertArrayHasKey( 'JsonOrder', $body, 'renew_order 請求 body 應含 JsonOrder' );
		$this->assertStringContainsString( 'ReNewOrder', $captured_url, 'renew_order URL 應含 ReNewOrder' );
	}

	/**
	 * cancel_order 使用 HTTP DELETE 方法
	 *
	 * 依 woomp cancel_order()：wp_remote_request with method='DELETE'
	 *
	 * @test
	 * @group happy
	 */
	public function test_cancel_order使用DELETE方法(): void {
		\putenv( 'API_MODE=live' );

		$captured_method = null;
		$this->mock_http(
			'S|取消成功',
			function ( array $parsed_args, string $url ) use ( &$captured_method ): void {
				$captured_method = $parsed_args['method'] ?? null;
			}
		);

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$client->cancel_order();

		$this->assertSame( 'DELETE', \strtoupper( $captured_method ?? '' ), 'cancel_order 應使用 DELETE 方法' );
	}

	/**
	 * cancel_order URL 含 CancelOrder 路徑
	 *
	 * @test
	 * @group happy
	 */
	public function test_cancel_order_URL含CancelOrder路徑(): void {
		\putenv( 'API_MODE=live' );

		$captured_url = null;
		$this->mock_http(
			'S|取消成功',
			function ( array $parsed_args, string $url ) use ( &$captured_url ): void {
				$captured_url = $url;
			}
		);

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$client->cancel_order();

		$this->assertNotNull( $captured_url );
		$this->assertStringContainsString( 'CancelOrder', $captured_url, 'cancel_order URL 應含 CancelOrder' );
	}

	/**
	 * cancel_order request body 含 LogisticNumber / sno / PassCode
	 *
	 * 依 woomp cancel_order()：body 含 LogisticNumber / sno='1' / PassCode
	 *
	 * @test
	 * @group happy
	 */
	public function test_cancel_order請求body含LogisticNumber與PassCode(): void {
		\putenv( 'API_MODE=live' );

		$captured_body = null;
		$this->mock_http(
			'S|取消成功',
			function ( array $parsed_args, string $url ) use ( &$captured_body ): void {
				$captured_body = $parsed_args['body'] ?? [];
			}
		);

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$client->cancel_order();

		$this->assertNotNull( $captured_body );
		$this->assertArrayHasKey( 'LogisticNumber', $captured_body, 'cancel 請求 body 應含 LogisticNumber' );
		$this->assertArrayHasKey( 'sno', $captured_body, 'cancel 請求 body 應含 sno' );
		$this->assertArrayHasKey( 'PassCode', $captured_body, 'cancel 請求 body 應含 PassCode' );
		$this->assertSame( self::TEST_LOGISTIC_NUMBER, $captured_body['LogisticNumber'], 'LogisticNumber 應為物流單號' );
		$this->assertSame( '1', $captured_body['sno'], 'sno 預設應為 1' );
	}

	/**
	 * query_order 使用 GET 方法，URL 含 Get_Order_Info 與 LogisticNumber query string
	 *
	 * 依 woomp query_order()：GET /api/Orderapi/Get_Order_Info?LogisticNumber=...&sno=1
	 *
	 * @test
	 * @group happy
	 */
	public function test_query_order使用GET且URL含LogisticNumber查詢字串(): void {
		\putenv( 'API_MODE=live' );

		$captured_method = null;
		$captured_url    = null;
		$this->mock_http(
			\wp_json_encode(
				[
					'LogisticNumber'     => self::TEST_LOGISTIC_NUMBER,
					'Status'             => '0',
					'Delivery_Status'    => '處理中',
					'PayNowLogisticCode' => '0001',
				]
			),
			function ( array $parsed_args, string $url ) use ( &$captured_method, &$captured_url ): void {
				$captured_method = $parsed_args['method'] ?? 'GET';
				$captured_url    = $url;
			}
		);

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$client->query_order();

		$this->assertNotNull( $captured_url );
		$this->assertSame( 'GET', \strtoupper( $captured_method ?? '' ), 'query_order 應使用 GET 方法' );
		$this->assertStringContainsString( 'Get_Order_Info', $captured_url, 'query_order URL 應含 Get_Order_Info' );
		$this->assertStringContainsString(
			'LogisticNumber=' . self::TEST_LOGISTIC_NUMBER,
			$captured_url,
			'query_order URL 應含 LogisticNumber 查詢字串'
		);
		$this->assertStringContainsString( 'sno=1', $captured_url, 'query_order URL 應含 sno=1' );
	}

	/**
	 * print_label（Seven）URL 含 Order711 路徑
	 *
	 * 依 woomp print_label：Seven → /api/Order711?orderNumberStr=...&user_account=...
	 *
	 * @test
	 * @group happy
	 */
	public function test_print_label_Seven的URL含Order711(): void {
		\putenv( 'API_MODE=live' );

		$captured_url = null;
		$this->mock_http(
			'S|https://example.com/label/123.pdf',
			function ( array $parsed_args, string $url ) use ( &$captured_url ): void {
				$captured_url = $url;
			}
		);

		$order  = $this->create_order_with_ref( service_id: '01' );
		$client = new LogisticsApiClient( $order );
		$client->print_label();

		$this->assertNotNull( $captured_url, '應攔截到 HTTP 請求' );
		$this->assertStringContainsString( 'Order711', $captured_url, 'Seven 列印 URL 應含 Order711' );
	}

	/**
	 * print_label（Tcat）URL 含 PrintBlackCatLabel 路徑
	 *
	 * 依 woomp print_label：TCAT → /Member/Order/PrintBlackCatLabel
	 *
	 * @test
	 * @group happy
	 */
	public function test_print_label_Tcat的URL含PrintBlackCatLabel(): void {
		\putenv( 'API_MODE=live' );

		$captured_url = null;
		$this->mock_http(
			'%PDF-1.4 mock pdf content',
			function ( array $parsed_args, string $url ) use ( &$captured_url ): void {
				$captured_url = $url;
			}
		);

		$order  = $this->create_order_with_ref( service_id: '06' );
		$client = new LogisticsApiClient( $order );
		$client->print_label();

		$this->assertNotNull( $captured_url, '應攔截到 HTTP 請求' );
		$this->assertStringContainsString( 'PrintBlackCatLabel', $captured_url, 'Tcat 列印 URL 應含 PrintBlackCatLabel' );
	}

	// ========== Happy Path：fixture 回應解析 ==========

	/**
	 * add_order fixture 回應 Status=S 時解析為成功陣列（含 LogisticNumber）
	 *
	 * @test
	 * @group happy
	 */
	public function test_add_order_fixture_Status_S解析為含LogisticNumber的陣列(): void {
		\putenv( 'API_MODE=live' );

		$this->mock_http(
			$this->make_success_response(
				[
					'Status'         => 'S',
					'LogisticNumber' => 'LN_FIXTURE_001',
					'paymentno'      => 'PMT_001',
					'validationno'   => 'VAL_001',
				]
			)
		);

		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );
		$result = $client->add_order();

		$this->assertIsArray( $result, 'add_order 應回傳陣列' );
		$this->assertSame( 'S', $result['Status'], 'Status 應為 S' );
		$this->assertArrayHasKey( 'LogisticNumber', $result, 'result 應含 LogisticNumber' );
	}

	/**
	 * add_order fixture 回應 Status=F 時解析包含 ErrorMsg
	 *
	 * @test
	 * @group happy
	 */
	public function test_add_order_fixture_Status_F解析含ErrorMsg(): void {
		\putenv( 'API_MODE=live' );

		$this->mock_http( $this->make_failure_response() );

		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );
		$result = $client->add_order();

		$this->assertIsArray( $result, 'add_order 應回傳陣列（即使失敗）' );
		$this->assertSame( 'F', $result['Status'], 'Status 應為 F' );
		$this->assertArrayHasKey( 'ErrorMsg', $result, 'Status=F 時應含 ErrorMsg' );
	}

	/**
	 * query_order fixture 回應解析包含貨態欄位
	 *
	 * @test
	 * @group happy
	 */
	public function test_query_order_fixture解析包含貨態欄位(): void {
		\putenv( 'API_MODE=live' );

		$this->mock_http(
			\wp_json_encode(
				[
					'LogisticNumber'     => self::TEST_LOGISTIC_NUMBER,
					'sno'                => '1',
					'Status'             => '0',
					'Delivery_Status'    => '包裹已到達指定門市',
					'PayNowLogisticCode' => '5000',
					'paymentno'          => 'PMT_001',
					'validationno'       => 'VAL_001',
				]
			)
		);

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$result = $client->query_order();

		$this->assertIsArray( $result, 'query_order 應回傳陣列' );
		$this->assertArrayHasKey( 'LogisticNumber', $result, 'query result 應含 LogisticNumber' );
		$this->assertArrayHasKey( 'Status', $result, 'query result 應含 Status' );
		$this->assertArrayHasKey( 'Delivery_Status', $result, 'query result 應含 Delivery_Status' );
		$this->assertArrayHasKey( 'PayNowLogisticCode', $result, 'query result 應含 PayNowLogisticCode' );
	}

	/**
	 * cancel_order fixture 回應 'S' 開頭代表成功
	 *
	 * 依 woomp cancel 邏輯：strpos(resp, 'S') !== false → 成功
	 *
	 * @test
	 * @group happy
	 */
	public function test_cancel_order_fixture含S代表成功(): void {
		\putenv( 'API_MODE=live' );

		$this->mock_http( 'S|取消物流單成功' );

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$result = $client->cancel_order();

		$this->assertStringContainsString( 'S', $result, 'cancel_order 成功回應應含 S' );
	}

	// ========== Integration：mock 模式 add_order fixture Status 判讀 ==========

	/**
	 * mock 模式 add_order fixture 應含 Status 欄位（可判斷 S/F）
	 *
	 * @test
	 * @group integration
	 */
	public function test_mock模式add_order_fixture含Status欄位(): void {
		\putenv( 'API_MODE=mock' );

		$order  = $this->create_logistics_order();
		$client = new LogisticsApiClient( $order );
		$result = $client->add_order();

		$this->assertIsArray( $result, 'mock add_order 應回傳陣列' );
		$this->assertArrayHasKey( 'Status', $result, 'mock fixture 應含 Status 欄位' );
		// mock 模式應預設為成功（Status='S'）
		$this->assertSame( 'S', $result['Status'], 'mock fixture Status 應為 S' );
	}

	/**
	 * mock 模式 query_order fixture 應含貨態欄位（Delivery_Status / PayNowLogisticCode）
	 *
	 * @test
	 * @group integration
	 */
	public function test_mock模式query_order_fixture含貨態欄位(): void {
		\putenv( 'API_MODE=mock' );

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$result = $client->query_order();

		$this->assertIsArray( $result, 'mock query_order 應回傳陣列' );
		$this->assertArrayHasKey( 'Delivery_Status', $result, 'mock query fixture 應含 Delivery_Status' );
		$this->assertArrayHasKey( 'PayNowLogisticCode', $result, 'mock query fixture 應含 PayNowLogisticCode' );
	}

	/**
	 * mock 模式 cancel_order fixture 回應含 S（代表成功）
	 *
	 * @test
	 * @group integration
	 */
	public function test_mock模式cancel_order_fixture含S(): void {
		\putenv( 'API_MODE=mock' );

		$order  = $this->create_order_with_ref();
		$client = new LogisticsApiClient( $order );
		$result = $client->cancel_order();

		$this->assertIsString( $result, 'mock cancel_order 應回傳字串' );
		$this->assertStringContainsString( 'S', $result, 'mock cancel_order fixture 應含 S' );
	}
}
