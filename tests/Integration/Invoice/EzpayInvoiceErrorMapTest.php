<?php
/**
 * ezPay 正規化錯誤模型映射測試（einvoice 導入第四階段-a，參考模板）
 *
 * 驗證 EzpayInvoiceProvider 將「失敗」從塌縮回 [] 演進為回正規化 \WP_Error：
 *   - ezPay 原始錯誤碼 → 正規化 ErrorCode（LIB10007→CONFLICT、KEY10002→AUTH、INV20006→NOT_FOUND、
 *     INV90006→NUMBER_EXHAUSTED、未涵蓋碼→PROVIDER、NOR10001→NETWORK…），且 raw_code 保留供 debug。
 *   - CheckCode 驗章不符 → SIGNATURE。
 *   - issue() 第一步 dispatch 驗證失敗 → VALIDATION，且第三方未被呼叫。
 *   - provider 內部拋 \Throwable → UNKNOWN，不向 WC hook 拋。
 *   - 成功仍回 array（冪等 / 正常開立）。
 *
 * 錯誤注入：API_MODE=mock 下，透過 InvoiceApiClient::$mock_error_override 注入「Status 非 SUCCESS」
 * 的外層回應，觸發 business 錯誤路徑（參考既有 EzpayInvoiceApiClientMockTest 的 mock 機制擴充）。
 * CheckCode 失敗則沿用既有手法：設定錯誤金鑰使官方 fixture 的 CheckCode 驗章不過。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter EzpayInvoiceErrorMapTest --no-coverage'
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * ezPay 正規化錯誤模型映射測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 * @group error
 */
final class EzpayInvoiceErrorMapTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求 URL（驗證「第三方未被呼叫」）
	 *
	 * @var array<int, string>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 ezpay（正確金鑰）、攔截對外 HTTP、清空錯誤注入
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests                   = [];
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

		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * 每次測試後：移除攔截、清空錯誤注入與設定
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
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
	 * 重設設定為「錯誤金鑰」（觸發 CheckCode 驗章失敗）
	 */
	private function enable_with_wrong_key(): void {
		\delete_option( ProviderUtils::get_option_name( EzpayInvoiceProvider::ID ) );
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => 'MS12345678',
				'hash_key'    => 'WRONG_KEY_INTENTIONALLY_INVALID',
				'hash_iv'     => '1234567891234567',
			]
		);
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
				'invoice_number'   => 'EV00000001',
				'invoice_trans_no' => 'EZT0000001',
				'random_num'       => '1234',
				'invoice_date'     => '2026-01-15 10:00:00',
			]
		);
		$meta_keys->update_provider_id( EzpayInvoiceProvider::ID );
		return $order;
	}

	// ========================================================================
	// ezPay 原始錯誤碼 → 正規化 code（business 路徑，經 mock_error_override 注入）
	// ========================================================================

	/**
	 * LIB10007（已開折讓無法作廢）→ CONFLICT + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_LIB10007_映射為CONFLICT且保留raw_code(): void {
		$order = $this->create_issued_order();
		// 作廢端點注入 LIB10007（透過 client，繞過 provider 前置的 allowance 擋）.
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'LIB10007',
			'Message' => '無法作廢',
		];

		$result = EzpayInvoiceProvider::instance()->cancel( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		$this->assertSame( 'LIB10007', NormalizedError::get_raw_code( $result ) );
		// 作廢失敗不清除 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), '作廢失敗時 issued_data 不應被清除' );
	}

	/**
	 * KEY10002（資料解密 / 金鑰錯）→ AUTH + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_KEY10002_映射為AUTH且保留raw_code(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '資料解密錯誤',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::AUTH, NormalizedError::get_code( $result ) );
		$this->assertSame( 'KEY10002', NormalizedError::get_raw_code( $result ) );
		// 失敗未寫入 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '開立失敗不應寫入 issued_data' );
	}

	/**
	 * INV20006（查無發票）→ NOT_FOUND
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_INV20006_映射為NOT_FOUND(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'INV20006',
			'Message' => '查無發票資料',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
		$this->assertSame( 'INV20006', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * INV90006（可開立張數用罄 = 字軌號碼用完）→ NUMBER_EXHAUSTED
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_INV90006_映射為NUMBER_EXHAUSTED(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'INV90006',
			'Message' => '可開立張數已用罄',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NUMBER_EXHAUSTED, NormalizedError::get_code( $result ) );
		$this->assertSame( 'INV90006', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * INV10012（金額 / 課稅別驗證錯誤）→ VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_INV10012_映射為VALIDATION(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'INV10012',
			'Message' => '發票金額、課稅別驗證錯誤',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$this->assertSame( 'INV10012', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * NOR10001（網路連線異常）→ NETWORK
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_NOR10001_映射為NETWORK(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'NOR10001',
			'Message' => '網路連線異常',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NETWORK, NormalizedError::get_code( $result ) );
	}

	/**
	 * 映射表未涵蓋的業務碼（如 LIB99999）→ PROVIDER（fallthrough），且 raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_未涵蓋碼LIB99999_fallthrough為PROVIDER(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'LIB99999',
			'Message' => '未知錯誤',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ) );
		$this->assertSame( 'LIB99999', NormalizedError::get_raw_code( $result ), '未涵蓋碼仍須保留 raw_code 供 debug' );
	}

	// ========================================================================
	// 驗章失敗 → SIGNATURE（設定錯誤金鑰使官方 fixture CheckCode 不過）
	// ========================================================================

	/**
	 * 開立發票 CheckCode 與本地計算不符 → SIGNATURE，且不寫 issued_data
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_CheckCode不符_映射為SIGNATURE且不寫issued_data(): void {
		$this->enable_with_wrong_key();
		$order = $this->create_order();

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::SIGNATURE, NormalizedError::get_code( $result ) );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), 'SIGNATURE 失敗不應寫入 issued_data' );
	}

	// ========================================================================
	// 統一驗證層（issue 第一步）→ VALIDATION，且第三方未被呼叫
	// ========================================================================

	/**
	 * 同時帶載具與捐贈碼（互斥違反）→ VALIDATION，且 client 從未被呼叫（mock_error_override 即使設定也不觸發）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_載具捐贈互斥_回VALIDATION且第三方未被呼叫(): void {
		// 同時帶載具（barcode）與捐贈碼 —— dispatch 驗證互斥規則違反.
		$order = $this->create_order(
			[
				'provider'    => 'ezpay',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => '/ABC1234',
				'donateCode'  => '7788',
			]
		);

		// 若驗證層先攔截，client 不會被建構 → 即使設了錯誤注入也不應觸發成功 / 業務分支.
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '不應到達此處',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

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
				'provider'    => 'ezpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '12345678',
			]
		);

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data() );
	}

	// ========================================================================
	// never-throw：provider 內部 \Throwable → UNKNOWN，不向外拋
	// ========================================================================

	/**
	 * client 解析回應丟出非預期結構（Result 非陣列且非 JSON）→ provider 回 PROVIDER / UNKNOWN，不向 WC hook 拋
	 *
	 * 註：decode 種類（JSON / 結構解析失敗）依模板映射 PROVIDER；此處驗證「不拋例外」鐵律。
	 *
	 * @test
	 * @group error
	 */
	public function test_error_never_throw_client解析失敗時不向外拋(): void {
		$order = $this->create_order();
		// 注入 SUCCESS 但 Result 為不可 json_decode 的字串 → decode_result 內 json_decode 丟 → kind=decode.
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'SUCCESS',
			'Message' => 'OK',
			'Result'  => '{not-valid-json',
		];

		$thrown = null;
		try {
			$result = EzpayInvoiceProvider::instance()->issue( $order );
		} catch ( \Throwable $e ) {
			$thrown = $e;
			$result = null;
		}

		$this->assertNull( $thrown, 'provider 公開方法絕不可向外拋例外（never-throw 鐵律）' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ), '回傳須為正規化錯誤' );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ), 'decode 解析失敗依模板映射 PROVIDER' );
	}

	// ========================================================================
	// 正規化錯誤型別契約：失敗回 WP_Error 帶 code / provider；type guard 可辨識
	// ========================================================================

	/**
	 * 業務失敗回傳須通過 is_normalized_error 型別守衛，且 error_data 帶 provider='ezpay'
	 *
	 * @test
	 * @group error
	 */
	public function test_error_失敗回傳為正規化錯誤帶provider(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '資料解密錯誤',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ) );
		$this->assertSame( 'ezpay', $result->get_error_data()['provider'] ?? null );
		$this->assertNotNull( NormalizedError::get_code( $result ) );
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
				'provider'    => 'ezpay',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$result = EzpayInvoiceProvider::instance()->issue( $order );

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
			'Status'  => 'KEY10002',
			'Message' => '不應到達此處',
		];

		$result = EzpayInvoiceProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 'EV00000001', $result['invoice_number'] ?? '' );
	}
}
