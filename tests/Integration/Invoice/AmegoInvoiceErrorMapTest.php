<?php
/**
 * 光貿 Amego 正規化錯誤模型映射測試（einvoice 導入第四階段-c，照 ezPay 模板補齊）
 *
 * Amego 是 4 家中失敗處理最弱的（原本 issue/cancel 用 `?? []` null 合併、無顯式 try/catch），
 * 且缺整合錯誤測試。本檔對齊 EzpayInvoiceErrorMapTest 的覆蓋度，驗證 AmegoProvider 將「失敗」
 * 從塌縮回 [] 演進為回正規化 \WP_Error：
 *   - Amego 數字錯誤碼 → 正規化 ErrorCode（16→SIGNATURE、22→AUTH、3050141→CONFLICT、
 *     3050125→NOT_FOUND、3040111→NUMBER_EXHAUSTED、3040145→VALIDATION、未涵蓋碼→PROVIDER、10→NETWORK…），
 *     且 raw_code 保留供 debug。
 *   - sign 驗章失敗（Amego code=16）→ SIGNATURE。
 *   - issue() 第一步 dispatch 驗證失敗 → VALIDATION，且第三方未被呼叫。
 *   - provider 內部拋 \Throwable → UNKNOWN，不向 WC hook 拋（特別測 Amego 新增的 try/catch）。
 *   - 成功仍回 array（冪等 / 正常開立 / B2B companyId 04595257）。
 *
 * 錯誤注入：API_MODE=mock 下，透過 Requester::$mock_error_override 注入「code 非 0」的外層回應，
 * 觸發 business 錯誤路徑（與 ezPay InvoiceApiClient::$mock_error_override 機制一致）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter AmegoInvoiceErrorMapTest --no-coverage'
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 光貿 Amego 正規化錯誤模型映射測試類別
 *
 * @group integration
 * @group invoice
 * @group amego
 * @group error
 */
final class AmegoInvoiceErrorMapTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求 URL（驗證「第三方未被呼叫」）
	 *
	 * @var array<int, string>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 amego（測試模式 = 官方測試金鑰）、攔截對外 HTTP、清空錯誤注入
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests            = [];
		Requester::$mock_error_override = null;

		$this->reset_settings_instance();
		$this->enable_provider(
			AmegoProvider::ID,
			[
				'mode' => 'test',
			]
		);

		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * 每次測試後：移除攔截、清空錯誤注入與設定
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		Requester::$mock_error_override = null;
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 AmegoSettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( AmegoSettingsDTO::class );
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
				'invoice_number' => 'AG00000001',
				'invoice_time'   => \time(),
				'random_number'  => '1234',
			]
		);
		$meta_keys->update_provider_id( AmegoProvider::ID );
		return $order;
	}

	// ========================================================================
	// Amego 數字錯誤碼 → 正規化 code（business 路徑，經 mock_error_override 注入）
	// ========================================================================

	/**
	 * code=16（簽名驗證錯誤）→ SIGNATURE + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code16_映射為SIGNATURE且保留raw_code(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 16,
			'msg'  => '簽名驗證錯誤',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::SIGNATURE, NormalizedError::get_code( $result ) );
		$this->assertSame( '16', NormalizedError::get_raw_code( $result ) );
		// 失敗未寫入 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '驗章失敗不應寫入 issued_data' );
	}

	/**
	 * code=22（尚未申請 API 串接）→ AUTH + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code22_映射為AUTH且保留raw_code(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 22,
			'msg'  => '尚未申請 API 串接',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::AUTH, NormalizedError::get_code( $result ) );
		$this->assertSame( '22', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * code=12（統編錯誤）→ AUTH
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code12_映射為AUTH(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 12,
			'msg'  => 'invoice 統編錯誤',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::AUTH, NormalizedError::get_code( $result ) );
		$this->assertSame( '12', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * code=10（系統停機維護中）→ NETWORK
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code10_映射為NETWORK(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 10,
			'msg'  => '系統停機維護中',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NETWORK, NormalizedError::get_code( $result ) );
		$this->assertSame( '10', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * code=3040111（字軌不足）→ NUMBER_EXHAUSTED
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code3040111_映射為NUMBER_EXHAUSTED(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 3040111,
			'msg'  => '字軌不足',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NUMBER_EXHAUSTED, NormalizedError::get_code( $result ) );
		$this->assertSame( '3040111', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * code=3040171（OrderId 重複）→ CONFLICT
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code3040171_映射為CONFLICT(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 3040171,
			'msg'  => 'OrderId 重複',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		$this->assertSame( '3040171', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * code=3040145（SalesAmount 格式錯誤，開立欄位驗證）→ VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code3040145_映射為VALIDATION(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 3040145,
			'msg'  => 'SalesAmount 格式錯誤',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$this->assertSame( '3040145', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * 作廢 code=3050141（已開折讓無法作廢發票）→ CONFLICT，且不清除 issued_data
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code3050141_映射為CONFLICT且不清除issued_data(): void {
		$order                          = $this->create_issued_order();
		Requester::$mock_error_override = [
			'code' => 3050141,
			'msg'  => '指定發票號碼 已存在折讓單',
		];

		$result = AmegoProvider::instance()->cancel( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		$this->assertSame( '3050141', NormalizedError::get_raw_code( $result ) );
		// 作廢失敗不清除 issued_data（保留可重試）.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), '作廢失敗時 issued_data 不應被清除' );
	}

	/**
	 * 作廢 code=3050125（指定發票不存在）→ NOT_FOUND
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_code3050125_映射為NOT_FOUND(): void {
		$order                          = $this->create_issued_order();
		Requester::$mock_error_override = [
			'code' => 3050125,
			'msg'  => '指定發票號碼 發票不存在',
		];

		$result = AmegoProvider::instance()->cancel( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
		$this->assertSame( '3050125', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * 映射表未涵蓋的業務碼（如 9999999）→ PROVIDER（fallthrough），且 raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_未涵蓋碼_fallthrough為PROVIDER(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 9999999,
			'msg'  => '未知錯誤',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ) );
		$this->assertSame( '9999999', NormalizedError::get_raw_code( $result ), '未涵蓋碼仍須保留 raw_code 供 debug' );
	}

	// ========================================================================
	// 統一驗證層（issue 第一步）→ VALIDATION，且第三方未被呼叫
	// ========================================================================

	/**
	 * 同時帶載具與捐贈碼（互斥違反）→ VALIDATION，且 client 從未被呼叫
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_載具捐贈互斥_回VALIDATION且第三方未被呼叫(): void {
		// 同時帶載具（barcode）與捐贈碼 —— dispatch 驗證互斥規則違反.
		$order = $this->create_order(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => '/ABC1234',
				'donateCode'  => '7788',
			]
		);

		// 若驗證層先攔截，client 不會被建構 → 即使設了錯誤注入也不應觸發成功 / 業務分支.
		Requester::$mock_error_override = [
			'code' => 16,
			'msg'  => '不應到達此處',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ), '互斥違反應回 VALIDATION' );
		// 驗證層攔截 → 不寫 issued_data（client 從未被呼叫）.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '驗證層攔截時第三方未被呼叫，不應有 issued_data' );
		// 本地 mock 不外呼，但即便如此 http_requests 也應為空（雙重保險）.
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * B2B 統編 checksum 不合法（12345678）→ VALIDATION（issue 第一步攔截）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_統編checksum不合法_回VALIDATION(): void {
		// 12345678 非合法統編（財政部 checksum 不過）.
		$order = $this->create_order(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '12345678',
			]
		);

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data() );
	}

	// ========================================================================
	// never-throw：provider 內部 \Throwable → UNKNOWN，不向外拋（特別測 Amego 新增的 try/catch）
	// ========================================================================

	/**
	 * client 解析回應時拋出非預期例外（注入缺 code 欄位的外層回應）→ provider 回正規化錯誤，不向外拋
	 *
	 * 機制：注入只帶 msg、不帶 code 的外層回應 → Requester::build_response_dto 內
	 * IssueInvoiceResponseDTO 的 typed int $code 未被初始化，呼叫 is_success() 存取時拋
	 * \Error（Typed property must not be accessed before initialization）→ 被 Requester::post 的
	 * catch \Throwable 攔下落地為 kind=decode 錯誤明細 → provider error_from_client 映射 PROVIDER。
	 * 重點驗證：provider 公開方法絕不向 WC hook 拋例外（never-throw 鐵律），且回正規化錯誤。
	 *
	 * @test
	 * @group error
	 */
	public function test_error_never_throw_provider不向外拋且回正規化錯誤(): void {
		$order = $this->create_order();
		// 注入缺 code 欄位的外層回應 → DTO typed $code 未初始化 → is_success() 拋 \Error → kind=decode → PROVIDER.
		Requester::$mock_error_override = [
			'msg' => '缺 code 欄位',
		];

		$thrown = null;
		try {
			$result = AmegoProvider::instance()->issue( $order );
		} catch ( \Throwable $e ) {
			$thrown = $e;
			$result = null;
		}

		$this->assertNull( $thrown, 'provider 公開方法絕不可向外拋例外（never-throw 鐵律）' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ), '回傳須為正規化錯誤' );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ), 'decode 解析失敗依模板映射 PROVIDER' );
	}

	/**
	 * query_invoice 注入解析失敗（data 非陣列且 code 非 0）→ 不向外拋，回正規化錯誤
	 *
	 * @test
	 * @group error
	 */
	public function test_error_never_throw_query失敗時不向外拋(): void {
		$order = $this->create_issued_order();
		// 查詢路徑：注入 code 非 0 → business → map_error.
		Requester::$mock_error_override = [
			'code' => 71,
			'msg'  => '查無資料',
		];

		$thrown = null;
		try {
			$result = AmegoProvider::instance()->query_invoice( $order );
		} catch ( \Throwable $e ) {
			$thrown = $e;
			$result = null;
		}

		$this->assertNull( $thrown, 'query_invoice 絕不可向外拋例外（never-throw 鐵律）' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ), '查無資料 code=71 映射 NOT_FOUND' );
		$this->assertSame( '71', NormalizedError::get_raw_code( $result ) );
	}

	// ========================================================================
	// 正規化錯誤型別契約：失敗回 WP_Error 帶 code / provider；type guard 可辨識
	// ========================================================================

	/**
	 * 業務失敗回傳須通過 is_normalized_error 型別守衛，且 error_data 帶 provider='amego'
	 *
	 * @test
	 * @group error
	 */
	public function test_error_失敗回傳為正規化錯誤帶provider(): void {
		$order                          = $this->create_order();
		Requester::$mock_error_override = [
			'code' => 22,
			'msg'  => '尚未申請 API 串接',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ) );
		$this->assertSame( 'amego', $result->get_error_data()['provider'] ?? null );
		$this->assertNotNull( NormalizedError::get_code( $result ) );
	}

	// ========================================================================
	// 成功仍回 array（冪等 / 正常開立 / B2B）—— 契約不退化
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
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$result = AmegoProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertFalse( \is_wp_error( $result ) );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );
	}

	/**
	 * B2B（companyId 04595257，財政部 checksum 合法）正常開立仍回 array（不被 dispatch 驗證攔截）
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_B2B合法統編04595257正常開立仍回array(): void {
		$order  = $this->create_order(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyName' => '測試股份有限公司',
				'companyId'   => '04595257',
			]
		);
		$result = AmegoProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertFalse( \is_wp_error( $result ), 'B2B 合法統編不應被 dispatch 驗證攔截' );
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
		Requester::$mock_error_override = [
			'code' => 16,
			'msg'  => '不應到達此處',
		];

		$result = AmegoProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 'AG00000001', $result['invoice_number'] ?? '' );
	}
}
