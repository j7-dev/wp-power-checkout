<?php
/**
 * PayNow RestClient 整合測試（TDD Red 階段 — Cycle 2）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowRestClient
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §Phase 06 步驟 13
 *   - .claude/skills/paynow/references/php-examples.md §1 PaynowRestClient
 *   - .claude/skills/paynow/references/payment-rest-api.md §4 PaymentIntent §5 Refund
 *
 * 涵蓋範疇（Cycle 2）：
 *   - create_payment_intent 成功解析 result（result.id=pp_xxx, result.secret, result.status）
 *   - API 回非 success type → throw RuntimeException
 *   - wp_remote_request 回 WP_Error → throw RuntimeException
 *   - 回應非 JSON → throw RuntimeException
 *   - request Header Authorization: Bearer {PrivateKey} 正確
 *   - retrieve_payment_intent 成功解析 result
 *
 * Mock 手法：
 *   HTTP 以 WordPress pre_http_request filter 攔截（API_MODE=mock 不打真實 PayNow）
 *   tearDown 移除所有已掛 filter，確保測試隔離
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowRestClientTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowRestClient;
use Tests\Integration\TestCase;

/**
 * PayNow RestClient 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowRestClientTest extends TestCase {

	/** mock filter 識別標記（用於 tearDown 清理） */
	private const FILTER_PRE_HTTP = 'pre_http_request';

	/** 測試用 PrivateKey */
	private const TEST_PRIVATE_KEY = 'sk_test_dummy_private_key_paynow';

	/** 沙箱 API base */
	private const SANDBOX_BASE = 'https://sandboxapi.paynow.com.tw';

	/** 已掛的 filter callable，tearDown 時移除 */
	private array $registered_filters = [];

	/** 每次測試前設定環境 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );
	}

	/** 每次測試後清理 filter 並復原環境 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );

		foreach ( $this->registered_filters as [ $tag, $callback, $priority ] ) {
			\remove_filter( $tag, $callback, $priority );
		}
		$this->registered_filters = [];

		parent::tear_down();
	}

	// ========== Helper：mock HTTP ==========

	/**
	 * 掛 pre_http_request filter 回傳 mock HTTP 成功回應
	 *
	 * @param array<string, mixed> $result_data  放入外層 result 的內容
	 * @param string               $type         外層 type（'success' 為正常）
	 * @param int                  $status        外層 status
	 * @param callable|null        $capture       回呼用來記錄請求（可選）
	 * @return void
	 */
	private function mock_http_success(
		array $result_data,
		string $type = 'success',
		int $status = 200,
		?callable $capture = null
	): void {
		$body = \wp_json_encode(
			[
				'status'    => $status,
				'type'      => $type,
				'message'   => '',
				'result'    => $result_data,
				'requestId' => '09020f76-1405-4db2-b30a-ba30de629c05',
				'paginate'  => null,
			]
		);

		$callback = function ( $false, $parsed_args, $url ) use ( $body, &$capture ) {
			if ( $capture ) {
				$capture( $parsed_args, $url );
			}
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
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
	 * 掛 pre_http_request filter 回傳指定 raw body
	 *
	 * @param string $raw_body HTTP response body
	 * @return void
	 */
	private function mock_http_raw_body( string $raw_body ): void {
		$callback = function () use ( $raw_body ) {
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => $raw_body,
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
	 * 掛 pre_http_request filter 回傳 WP_Error
	 *
	 * @return void
	 */
	private function mock_http_wp_error(): void {
		$callback = function () {
			return new \WP_Error( 'http_request_failed', '模擬網路連線失敗（mock）' );
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];
	}

	// ========== Happy Path ==========

	/**
	 * create_payment_intent 成功時回傳 result 陣列，含 id（pp_xxx）與 secret
	 *
	 * 依 payment-rest-api.md §4.1：API 回 { status:200, type:"success", result:{ id, secret, status:"draft", ... } }
	 * 依 php-examples.md §1：create_payment_intent 回傳 $res['result']
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_create_payment_intent_成功時回傳含id與secret的result陣列(): void {
		$expected_id     = 'pp_1a304818ced44e5cbeab6107400da3c4';
		$expected_secret = 'pp_1a304818ced44e5cbeab6107400da3c4_st_04895990e31b4cefbd59d494ae420392';

		$this->mock_http_success(
			[
				'id'     => $expected_id,
				'secret' => $expected_secret,
				'status' => 'draft',
				'amount' => 1000,
			]
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$result = $client->create_payment_intent(
			[
				'amount'      => 1000,
				'currency'    => 'TWD',
				'description' => '測試訂單 #100',
				'webhookUrl'  => 'https://example.com/wp-json/power-checkout/paynow/notify',
				'resultUrl'   => 'https://example.com/order-received/100',
			]
		);

		$this->assertIsArray( $result, 'create_payment_intent 應回傳陣列' );
		$this->assertArrayHasKey( 'id', $result, 'result 應包含 id 欄位' );
		$this->assertArrayHasKey( 'secret', $result, 'result 應包含 secret 欄位' );
		$this->assertSame( $expected_id, $result['id'], 'result.id 應等於 pp_1a304818...' );
		$this->assertSame( $expected_secret, $result['secret'], 'result.secret 應等於 pp_..._st_...' );
	}

	/**
	 * create_payment_intent 成功時 result.status 為 "draft"
	 *
	 * 依 payment-rest-api.md §4.1 Response：建立後狀態為 draft
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_create_payment_intent_成功時result_status為draft(): void {
		$this->mock_http_success(
			[
				'id'     => 'pp_test123',
				'secret' => 'pp_test123_st_abc456',
				'status' => 'draft',
				'amount' => 500,
			]
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$result = $client->create_payment_intent( [ 'amount' => 500 ] );

		$this->assertSame( 'draft', $result['status'], 'PaymentIntent 建立後 status 應為 draft' );
	}

	/**
	 * retrieve_payment_intent 成功時回傳 result 陣列
	 *
	 * 依 payment-rest-api.md §4.2：GET /api/v1/payment-intents/:id 回 PaymentIntent 詳細
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_retrieve_payment_intent_成功時回傳result陣列(): void {
		$intent_id = 'pp_abcdef1234567890';

		$this->mock_http_success(
			[
				'id'     => $intent_id,
				'secret' => $intent_id . '_st_xyz',
				'status' => 'success',
				'amount' => 1000,
			]
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$result = $client->retrieve_payment_intent( $intent_id );

		$this->assertIsArray( $result, 'retrieve_payment_intent 應回傳陣列' );
		$this->assertSame( $intent_id, $result['id'], 'result.id 應等於查詢的 intent_id' );
	}

	/**
	 * request 使用 sandbox 環境時 URL 使用 sandboxapi.paynow.com.tw
	 *
	 * 依 php-examples.md §1：SANDBOX_BASE = 'https://sandboxapi.paynow.com.tw'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_沙箱模式時URL使用sandboxapi主機(): void {
		$captured_url = '';

		$this->mock_http_success(
			[
				'id'     => 'pp_test',
				'secret' => 'pp_test_st',
				'status' => 'draft',
			],
			'success',
			200,
			function ( array $parsed_args, string $url ) use ( &$captured_url ): void {
				$captured_url = $url;
			}
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$client->create_payment_intent( [ 'amount' => 100 ] );

		$this->assertStringContainsString(
			'sandboxapi.paynow.com.tw',
			$captured_url,
			'沙箱模式應使用 sandboxapi.paynow.com.tw'
		);
	}

	/**
	 * request Header Authorization 使用 Bearer + PrivateKey
	 *
	 * 依 payment-rest-api.md §1：所有 REST 請求 Authorization: Bearer {PrivateKey}
	 * 依 php-examples.md §1：$args['headers']['Authorization'] = 'Bearer ' . $this->private_key
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_request_header_Authorization使用Bearer加PrivateKey(): void {
		$captured_args = null;

		$this->mock_http_success(
			[
				'id'     => 'pp_test',
				'secret' => 'pp_test_st',
				'status' => 'draft',
			],
			'success',
			200,
			function ( array $parsed_args ) use ( &$captured_args ): void {
				$captured_args = $parsed_args;
			}
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$client->create_payment_intent( [ 'amount' => 100 ] );

		$this->assertNotNull( $captured_args, '應攔截到 HTTP 請求' );
		$this->assertIsArray( $captured_args['headers'] ?? null, 'headers 應為陣列' );

		$auth_header = $captured_args['headers']['Authorization'] ?? '';
		$this->assertSame(
			'Bearer ' . self::TEST_PRIVATE_KEY,
			$auth_header,
			'Authorization header 必須為 Bearer {PrivateKey}'
		);
	}

	/**
	 * POST 請求 Content-Type 為 application/json
	 *
	 * 依 payment-rest-api.md §1：POST 時 Content-Type: application/json
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_POST請求Content_Type為application_json(): void {
		$captured_args = null;

		$this->mock_http_success(
			[
				'id'     => 'pp_test',
				'secret' => 'pp_test_st',
				'status' => 'draft',
			],
			'success',
			200,
			function ( array $parsed_args ) use ( &$captured_args ): void {
				$captured_args = $parsed_args;
			}
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$client->create_payment_intent( [ 'amount' => 100 ] );

		$this->assertNotNull( $captured_args, '應攔截到 HTTP 請求' );
		$content_type = $captured_args['headers']['Content-Type'] ?? '';
		$this->assertSame(
			'application/json',
			$content_type,
			'POST 請求 Content-Type 應為 application/json'
		);
	}

	// ========== Error Path ==========

	/**
	 * API 回應外層 type 非 "success" 時拋出 RuntimeException
	 *
	 * 依 php-examples.md §1：if ( ( $data['type'] ?? '' ) !== 'success' ... ) → throw RuntimeException
	 * 依 paynow-implementation-plan.md §步驟 13：API 非 success → throw
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_API回應type非success時拋出RuntimeException(): void {
		$this->mock_http_success(
			[],           // result 無意義
			'error',      // type = 'error'，非 'success'
			400
		);

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$this->expectException( \RuntimeException::class );

		$client->create_payment_intent( [ 'amount' => 100 ] );
	}

	/**
	 * wp_remote_request 回 WP_Error 時拋出 RuntimeException
	 *
	 * 依 php-examples.md §1：if ( is_wp_error( $response ) ) → throw RuntimeException
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_wp_remote_回WP_Error時拋出RuntimeException(): void {
		$this->mock_http_wp_error();

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$this->expectException( \RuntimeException::class );

		$client->create_payment_intent( [ 'amount' => 100 ] );
	}

	/**
	 * wp_remote_request 回非 JSON 字串時拋出 RuntimeException
	 *
	 * 依 php-examples.md §1：if ( ! is_array( $data ) ) → throw RuntimeException
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_回應非JSON時拋出RuntimeException(): void {
		$this->mock_http_raw_body( '<html>Internal Server Error</html>' );

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$this->expectException( \RuntimeException::class );

		$client->create_payment_intent( [ 'amount' => 100 ] );
	}

	/**
	 * API 回應 type=success 但 status 非 200 時拋出 RuntimeException
	 *
	 * 依 php-examples.md §1：type !== 'success' && status !== 200 → throw
	 * 若 type 非 success 且 status 非 200，應拋出（雙重條件）
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_API回應status非200且type非success時拋出(): void {
		$body = \wp_json_encode(
			[
				'status'  => 422,
				'type'    => 'validation_error',
				'message' => '請求參數驗證失敗',
				'result'  => null,
			]
		);

		$this->mock_http_raw_body( $body );

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$this->expectException( \RuntimeException::class );

		$client->create_payment_intent( [ 'amount' => 100 ] );
	}

	/**
	 * WP_Error 拋出的 RuntimeException 訊息包含「連線失敗」相關訊息
	 *
	 * 依 php-examples.md §1：throw new RuntimeException('PayNow 連線失敗：' . ...)
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_WP_Error例外訊息包含連線失敗描述(): void {
		$this->mock_http_wp_error();

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		try {
			$client->create_payment_intent( [ 'amount' => 100 ] );
			$this->fail( '應拋出 RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString(
				'PayNow',
				$e->getMessage(),
				'例外訊息應包含 PayNow 識別字串'
			);
		}
	}

	/**
	 * 回應為空字串（非 JSON）時拋出 RuntimeException
	 *
	 * 邊緣案例：API 正常回 200 但 body 為空字串
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_回應為空字串時拋出RuntimeException(): void {
		$this->mock_http_raw_body( '' );

		$client = new PaynowRestClient(
			private_key: self::TEST_PRIVATE_KEY,
			is_sandbox: true,
		);

		$this->expectException( \RuntimeException::class );

		$client->create_payment_intent( [ 'amount' => 100 ] );
	}
}
