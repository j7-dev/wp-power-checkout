<?php
/**
 * PayNow 立吉富發票正規化錯誤模型映射測試（einvoice 導入第四階段-d，照 ezPay 模板）
 *
 * 驗證 PaynowInvoiceProvider 將「失敗」從塌縮回 [] 演進為回正規化 \WP_Error：
 *   - PayNow 原始錯誤（外層 type + message）→ 正規化 ErrorCode：
 *       type=validation_error → VALIDATION；
 *       message 含 JWT/token/認證 → AUTH（PayNow 純 Bearer，無對稱簽章）；
 *       message 含 重複/已開立 → CONFLICT；message 含 查無/不存在 → NOT_FOUND；
 *       message 含 字軌/用罄 → NUMBER_EXHAUSTED；未涵蓋（rejected/failed 無關鍵字）→ PROVIDER。
 *     且 raw_code（外層 type）保留供 debug。
 *   - 連線失敗（wp_remote_* 回 WP_Error）→ NETWORK（client kind=network 分流）。
 *   - issue() 第一步 dispatch 驗證失敗（載具捐贈互斥 / 統編 checksum）→ VALIDATION，且第三方未被呼叫。
 *   - provider 內部不拋 \Throwable（never-throw）；無 last_error_detail 時 fallback → PROVIDER。
 *   - 成功仍回 array（冪等 / 正常開立）。
 *
 * 錯誤注入：API_MODE=mock 下，透過 InvoiceApiClient::$mock_error_override 注入「外層 type 非 success」
 * 的回應（鍵：type / message / result），觸發 business 錯誤路徑（參考既有 PaynowInvoiceApiClientMockTest
 * 的 mock 機制擴充）。NETWORK 則以 pre_http_request 注入 WP_Error（非 mock 路徑）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter PaynowInvoiceErrorMapTest --no-coverage'
 *
 * @see specs/open-issue/einvoice-adoption-implementation-plan.md §第四階段 步驟8（PayNow）
 * @see .claude/skills/paynow/references/error-codes.md §10 體系 3 REST 狀態碼
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\PaynowInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Paynow\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Paynow\Services\PaynowInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 發票正規化錯誤模型映射測試類別
 *
 * ⚠️ @group 放 class docblock（PHPUnit 只讀 class 緊鄰 docblock；放 file docblock 會被 --group 漏收）。
 * group 對齊既有 PayNow 發票測試（paynow），並掛 error 進入白名單。
 *
 * @group integration
 * @group invoice
 * @group paynow
 * @group error
 */
final class PaynowInvoiceErrorMapTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求 URL（驗證「第三方未被呼叫」）
	 *
	 * @var array<int, string>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 paynow_invoice（dev 模式）、攔截對外 HTTP、清空錯誤注入
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests                   = [];
		InvoiceApiClient::$mock_error_override = null;

		// 金額涉及 TWD 幣別，顯式設定避免踩雷.
		\update_option( 'woocommerce_currency', 'TWD' );

		$this->reset_settings_instance();
		$this->enable_provider(
			PaynowInvoiceProvider::ID,
			[
				'mode'      => 'dev',
				'jwt_token' => 'test-jwt-token-paynow-invoice',
			]
		);

		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * 每次測試後：移除攔截、清空錯誤注入與設定
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		InvoiceApiClient::$mock_error_override = null;
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( PaynowInvoiceProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 PaynowInvoiceSettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( PaynowInvoiceSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * pre_http_request 攔截器：記錄 URL 並短路（mock 分流正確時完全不會被觸發）
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt 短路值
	 * @param array<string, mixed>                 $args    請求參數
	 * @param string                               $url     請求 URL
	 * @return \WP_Error
	 */
	public function intercept_http( $preempt, $args, $url ): \WP_Error {
		$this->http_requests[] = $url;
		return new \WP_Error( 'http_blocked', '測試環境禁止對外請求' );
	}

	/**
	 * 建立一筆有商品、可通過 dispatch 驗證的 B2C 訂單（未開立發票）
	 *
	 * @param array<string, mixed> $issue_params 結帳填寫的發票資訊
	 * @return \WC_Order
	 */
	private function create_order( array $issue_params = [] ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1050,
			]
		);

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '1050' );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_total( 1050 );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();

		if ( $issue_params ) {
			( new MetaKeys( $order ) )->update_issue_params( $issue_params );
		}

		return $order;
	}

	/**
	 * 建立一筆已開立發票的訂單（供 cancel / allowance 路徑）
	 *
	 * @return \WC_Order
	 */
	private function create_issued_order(): \WC_Order {
		$order     = $this->create_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-06-10T00:00:00',
				'order_no'       => 'PCN' . $order->get_id(),
				'total_amount'   => 1050,
			]
		);
		$meta_keys->update_provider_id( PaynowInvoiceProvider::ID );
		return $order;
	}

	// ========================================================================
	// PayNow 原始錯誤（外層 type + message）→ 正規化 code（business 路徑，經 mock_error_override 注入）
	// ========================================================================

	/**
	 * 外層 type=validation_error → VALIDATION + raw_code 保留（type 即 raw_code）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_validation_error型別_映射為VALIDATION且保留raw_code(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'validation_error',
			'message' => '發票金額格式錯誤',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$this->assertSame( 'validation_error', NormalizedError::get_raw_code( $result ), 'raw_code 應為外層 type' );
		// 失敗未寫入 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '開立失敗不應寫入 issued_data' );
	}

	/**
	 * message 含 JWT / token / 認證 關鍵字 → AUTH（PayNow 純 Bearer JWT，認證類走 AUTH）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_JWT認證訊息_映射為AUTH(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => 'JWT token 認證失敗，請確認商家金鑰',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::AUTH, NormalizedError::get_code( $result ), 'JWT/認證訊息應映射 AUTH' );
		$this->assertSame( 'rejected', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * message 含 重複 / 已開立 關鍵字 → CONFLICT + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_重複開立訊息_映射為CONFLICT(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '此訂單發票已開立，無法重複開立',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ), '重複/已開立訊息應映射 CONFLICT' );
		$this->assertSame( 'rejected', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * message 含 查無 / 不存在 關鍵字 → NOT_FOUND
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_查無發票訊息_映射為NOT_FOUND(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'failed',
			'message' => '查無此發票資料',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ), '查無訊息應映射 NOT_FOUND' );
	}

	/**
	 * message 含 字軌 / 用罄 關鍵字 → NUMBER_EXHAUSTED
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_字軌用罄訊息_映射為NUMBER_EXHAUSTED(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'failed',
			'message' => '發票字軌號碼已用罄，請新增字軌',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NUMBER_EXHAUSTED, NormalizedError::get_code( $result ), '字軌用罄應映射 NUMBER_EXHAUSTED' );
	}

	/**
	 * 未涵蓋（type=rejected / failed 但 message 無可分類關鍵字）→ PROVIDER（fallthrough），且 raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_未涵蓋訊息_fallthrough為PROVIDER且保留raw_code(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '系統忙碌中，請稍後再試',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ), '未涵蓋訊息應 fallthrough 為 PROVIDER' );
		$this->assertSame( 'rejected', NormalizedError::get_raw_code( $result ), '未涵蓋仍須保留 raw_code 供 debug' );
	}

	/**
	 * 折讓端點注入 CONFLICT（已折讓）→ issue_allowance 回 CONFLICT（business 路徑經 client 注入）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_折讓重複_映射為CONFLICT(): void {
		$order                                 = $this->create_issued_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '該發票已開立折讓，無法重複折讓',
		];

		$result = PaynowInvoiceProvider::instance()->issue_allowance( $order, 300.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		// 折讓失敗不寫 allowance_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data(), '折讓失敗不應寫入 allowance_data' );
	}

	// ========================================================================
	// 連線失敗 → NETWORK（client kind=network；以非 mock 路徑 + pre_http_request WP_Error 觸發）
	// ========================================================================

	/**
	 * 對外連線失敗（wp_remote_* 回 WP_Error）→ NETWORK，且不寫 issued_data
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_連線失敗_映射為NETWORK(): void {
		// 切換為非 mock 模式，讓 client 走 wp_remote_post → 被 pre_http_request 攔成 WP_Error（kind=network）.
		$original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=sandbox_test_only' );

		$order = $this->create_order();

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		// 還原環境變數.
		if ( false === $original_api_mode ) {
			\putenv( 'API_MODE' );
		} else {
			\putenv( "API_MODE={$original_api_mode}" );
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NETWORK, NormalizedError::get_code( $result ), '連線失敗應映射 NETWORK' );
		$this->assertNotEmpty( $this->http_requests, '非 mock 模式應嘗試對外請求（被攔截）' );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '連線失敗不應寫入 issued_data' );
	}

	// ========================================================================
	// 統一驗證層（issue 第一步）→ VALIDATION，且第三方未被呼叫
	// ========================================================================

	/**
	 * 同時帶載具與捐贈碼（互斥違反）→ VALIDATION，且 client 從未被呼叫（即使設了錯誤注入也不觸發）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_載具捐贈互斥_回VALIDATION且第三方未被呼叫(): void {
		// 同時帶載具（barcode）與捐贈碼 —— dispatch 驗證互斥規則違反.
		$order = $this->create_order(
			[
				'provider'    => 'paynow_invoice',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => '/ABC1234',
				'donateCode'  => '919',
			]
		);

		// 若驗證層先攔截，client 不會被建構 → 即使設了錯誤注入也不應觸發成功 / 業務分支.
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '不應到達此處',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ), '互斥違反應回 VALIDATION' );
		// 驗證層攔截 → 不寫 issued_data（client 從未被呼叫）.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '驗證層攔截時第三方未被呼叫，不應有 issued_data' );
		// 本地 mock 不外呼，但即便如此 http_requests 也應為空（雙重保險）.
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * B2B 統編 checksum 不合法 → VALIDATION（issue 第一步攔截）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_統編checksum不合法_回VALIDATION(): void {
		// 12345678 非合法統編（財政部 checksum 不過）.
		$order = $this->create_order(
			[
				'provider'    => 'paynow_invoice',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '12345678',
			]
		);

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data() );
	}

	// ========================================================================
	// never-throw + PROVIDER fallback：type=success 但 result 缺號 → client 回 null（無明細）→ PROVIDER，不拋
	// ========================================================================

	/**
	 * 注入 type=success 但 result 缺 invoice_number → IssueResponse 非成功 → client 回 null（無 last_error_detail）
	 *   → provider fallback 為 PROVIDER，且絕不向外拋例外（never-throw 鐵律）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_never_throw_成功型別但result缺號_fallback為PROVIDER且不拋(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'success',
			'message' => 'OK',
			'result'  => [ 'foo' => 'bar' ], // 缺 invoice_number → IssueResponse::is_success() = false.
		];

		$thrown = null;
		try {
			$result = PaynowInvoiceProvider::instance()->issue( $order );
		} catch ( \Throwable $e ) {
			$thrown = $e;
			$result = null;
		}

		$this->assertNull( $thrown, 'provider 公開方法絕不可向外拋例外（never-throw 鐵律）' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ), '回傳須為正規化錯誤' );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ), '無錯誤明細時 fallback 為 PROVIDER' );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '失敗不應寫入 issued_data' );
	}

	// ========================================================================
	// 正規化錯誤型別契約：失敗回 WP_Error 帶 code / provider；type guard 可辨識
	// ========================================================================

	/**
	 * 業務失敗回傳須通過 is_normalized_error 型別守衛，且 error_data 帶 provider='paynow_invoice'
	 *
	 * @test
	 * @group error
	 */
	public function test_error_失敗回傳為正規化錯誤帶provider(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => 'JWT token 認證失敗',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ) );
		$this->assertSame( 'paynow_invoice', $result->get_error_data()['provider'] ?? null );
		$this->assertNotNull( NormalizedError::get_code( $result ) );
	}

	// ========================================================================
	// 前置狀態 → NOT_FOUND（未開立 / 無折讓）
	// ========================================================================

	/**
	 * 未開立發票時開立折讓 → NOT_FOUND，且第三方未被呼叫
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_allowance_未開立_映射為NOT_FOUND且不外呼(): void {
		$order = $this->create_order();
		// 即使注入錯誤，前置 NOT_FOUND 在 client 前攔截 → client 不會被呼叫.
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '不應到達此處',
		];

		$result = PaynowInvoiceProvider::instance()->issue_allowance( $order, 300.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
		$this->assertSame( [], $this->http_requests, '未開立時不應對外發任何 HTTP 請求' );
	}

	/**
	 * 無折讓資料時作廢折讓 → NOT_FOUND
	 *
	 * @test
	 * @group error
	 */
	public function test_error_invalid_allowance_無折讓資料_映射為NOT_FOUND(): void {
		$order = $this->create_issued_order();

		$result = PaynowInvoiceProvider::instance()->invalid_allowance( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}

	/**
	 * 未開立發票時查詢 → NOT_FOUND（唯讀）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_query_invoice_未開立_映射為NOT_FOUND(): void {
		$order = $this->create_order();

		$result = PaynowInvoiceProvider::instance()->query_invoice( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}

	// ========================================================================
	// cancel 狀態前置 → CONFLICT（已開折讓擋作廢，不清 issued_data）
	// ========================================================================

	/**
	 * 已開折讓時作廢發票 → CONFLICT，且不清除 issued_data、第三方未被呼叫
	 *
	 * @test
	 * @group error
	 */
	public function test_error_cancel_已開折讓_映射為CONFLICT且保留issued_data(): void {
		$order     = $this->create_issued_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_allowance_data(
			[
				'allowance_number' => 'A20260610000001',
				'allowance_amount' => 300,
				'remain_amount'    => 750,
			]
		);

		// 即使注入錯誤，CONFLICT 在 client 前攔截.
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '不應到達此處',
		];

		$result = PaynowInvoiceProvider::instance()->cancel( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		// 作廢失敗不清除 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), 'CONFLICT 時 issued_data 不應被清除' );
		$this->assertSame( [], $this->http_requests, 'CONFLICT 前置攔截，第三方未被呼叫' );
	}

	// ========================================================================
	// 成功仍回 array（冪等 / 正常開立）—— 契約不退化
	// ========================================================================

	/**
	 * 正常開立（無錯誤注入）仍回 array（含 invoice_number），非 WP_Error
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_正常開立仍回array(): void {
		$order  = $this->create_order(
			[
				'provider'    => 'paynow_invoice',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertFalse( \is_wp_error( $result ) );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );
	}

	/**
	 * 冪等：已開立直接回 array，不驗證、不打 API（即使設了錯誤注入也不觸發）
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_已開立冪等回array不受錯誤注入影響(): void {
		$order = $this->create_issued_order();
		// 即使注入錯誤，冪等短路在最前面 → 不會走到 client.
		InvoiceApiClient::$mock_error_override = [
			'type'    => 'rejected',
			'message' => '不應到達此處',
		];

		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['invoice_number'] ?? '' );
	}

	/**
	 * B2B 統編（合法 checksum 04595257）正常開立仍回 array（驗證 B2B happy 不被新 checksum 擋）
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_B2B合法統編04595257_正常開立回array(): void {
		$order  = $this->create_order(
			[
				'provider'    => 'paynow_invoice',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '04595257',
			]
		);
		$result = PaynowInvoiceProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertFalse( \is_wp_error( $result ) );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );
	}
}
