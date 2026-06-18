<?php
/**
 * 綠界 Ecpay 正規化錯誤模型映射測試（einvoice 導入第四階段-b）
 *
 * 驗證 EcpayInvoiceProvider 將「失敗」從塌縮回 [] 演進為回正規化 \WP_Error：
 *   - RtnCode≠1（內層業務失敗）→ 依 RtnCode + 中文 RtnMsg regex 補強映射：
 *       字軌用罄 → NUMBER_EXHAUSTED、已作廢/已開立/重複 → CONFLICT、財政部失敗 → NETWORK、
 *       統一編號/稅額/載具/捐贈碼格式 → VALIDATION、查無 → NOT_FOUND、金鑰/憑證 → AUTH、
 *       未涵蓋 → PROVIDER（保留 raw_code 供 debug）。
 *   - CheckMacValue / AES 驗章不符（外層 TransCode≠1 驗章類）→ SIGNATURE（client kind 分流）。
 *   - issue() 第一步 dispatch 驗證失敗 → VALIDATION，且第三方未被呼叫。
 *   - provider 內部拋 \Throwable → UNKNOWN，不向 WC hook 拋（never-throw 鐵律）。
 *   - 成功仍回 array（冪等 / 正常開立）。
 *
 * 錯誤注入：API_MODE=mock 下，透過 InvoiceApiClient::$mock_error_override 注入「外層 TransCode≠1」
 * 或「內層 RtnCode≠1 + RtnMsg」回應，觸發 signature / business 錯誤路徑（不對外發 HTTP）。
 *
 * 注意：@group 一律放 class docblock（PHPUnit 只讀 class 緊鄰 docblock；放 file docblock 會被
 * declare/namespace/use 隔開導致 --group 收集不到）。B2B 測試 companyId 用 04595257（過 UBN checksum）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter EcpayInvoiceErrorMapTest --no-coverage'
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 Ecpay 正規化錯誤模型映射測試類別
 *
 * @group integration
 * @group invoice
 * @group ecpay
 * @group error
 */
final class EcpayInvoiceErrorMapTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求 URL（驗證「第三方未被呼叫」）
	 *
	 * @var array<int, string>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 ecpay（官方測試金鑰）、攔截對外 HTTP、清空錯誤注入
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests                   = [];
		InvoiceApiClient::$mock_error_override = null;

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

		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * 每次測試後：移除攔截、清空錯誤注入與設定
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		InvoiceApiClient::$mock_error_override = null;
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EcpayInvoiceProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 EcpayInvoiceSettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( EcpayInvoiceSettingsDTO::class );
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
				'invoice_number' => 'AB12345678',
				'invoice_date'   => '2026-01-15 10:00:00',
				'random_number'  => '1234',
			]
		);
		$meta_keys->update_provider_id( EcpayInvoiceProvider::ID );
		return $order;
	}

	// ========================================================================
	// RtnCode≠1（business 路徑）→ 正規化 code（RtnCode + 中文 RtnMsg regex 補強）
	// ========================================================================

	/**
	 * RelateNumber 重複（RtnCode=10000009 / RtnMsg 含「已開立」）→ CONFLICT + raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_發票已開立_映射為CONFLICT且保留raw_code(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 10000009,
			'rtn_msg'  => '發票已開立，RelateNumber 重複',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		$this->assertSame( '10000009', NormalizedError::get_raw_code( $result ) );
		// 失敗未寫入 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '開立失敗不應寫入 issued_data' );
	}

	/**
	 * 發票已作廢（RtnMsg regex「已作廢」）→ CONFLICT
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_發票已作廢_映射為CONFLICT(): void {
		$order                                 = $this->create_issued_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 2000003,
			'rtn_msg'  => '此發票已作廢，無法重複作廢',
		];

		$result = EcpayInvoiceProvider::instance()->cancel( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::CONFLICT, NormalizedError::get_code( $result ) );
		// 作廢失敗不清除 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), '作廢失敗時 issued_data 不應被清除' );
	}

	/**
	 * 字軌號碼用罄（RtnMsg regex「字軌 … 用罄」）→ NUMBER_EXHAUSTED
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_字軌用罄_映射為NUMBER_EXHAUSTED(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 2000010,
			'rtn_msg'  => '可開立發票之字軌號碼已用罄，請至平台新增字軌',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NUMBER_EXHAUSTED, NormalizedError::get_code( $result ) );
		$this->assertSame( '2000010', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * 財政部上傳失敗（RtnMsg regex「財政部 … 失敗」）→ NETWORK
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_財政部失敗_映射為NETWORK(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 2000099,
			'rtn_msg'  => '連線財政部大平台失敗，請稍後再試',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NETWORK, NormalizedError::get_code( $result ) );
	}

	/**
	 * 統一編號格式錯誤（RtnMsg regex「統一編號 … 錯誤」）→ VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_統一編號格式錯誤_映射為VALIDATION(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 1200022,
			'rtn_msg'  => '統一編號格式錯誤，須為 8 位數字',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$this->assertSame( '1200022', NormalizedError::get_raw_code( $result ) );
	}

	/**
	 * 稅額與金額不符（RtnMsg regex「稅額 … 不符」）→ VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_稅額不符_映射為VALIDATION(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 1200023,
			'rtn_msg'  => '商品銷售金額加總與稅額不符',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	/**
	 * 查無發票（RtnMsg regex「查無」）→ NOT_FOUND
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_查無發票_映射為NOT_FOUND(): void {
		$order                                 = $this->create_issued_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 2000007,
			'rtn_msg'  => '查無此發票資料',
		];

		$result = EcpayInvoiceProvider::instance()->cancel( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}

	/**
	 * 金鑰 / 憑證錯誤（RtnMsg regex「HashKey / HashIV / 憑證 錯誤」）→ AUTH
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_金鑰錯誤_映射為AUTH(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 1200001,
			'rtn_msg'  => 'HashKey 或 HashIV 錯誤，請確認商店設定',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::AUTH, NormalizedError::get_code( $result ) );
	}

	/**
	 * 映射表 / regex 皆未涵蓋的業務碼（中性訊息）→ PROVIDER（fallthrough），且 raw_code 保留
	 *
	 * @test
	 * @group error
	 */
	public function test_error_map_未涵蓋碼_fallthrough為PROVIDER(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 9999999,
			'rtn_msg'  => '未定義的系統回應',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $result ) );
		$this->assertSame( '9999999', NormalizedError::get_raw_code( $result ), '未涵蓋碼仍須保留 raw_code 供 debug' );
	}

	// ========================================================================
	// 驗章失敗 → SIGNATURE（外層 TransCode≠1 驗章類，client kind 分流）
	// ========================================================================

	/**
	 * 外層 TransCode≠1 且 TransMsg 為 CheckMacValue/AES 驗章失敗 → SIGNATURE，且不寫 issued_data
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_CheckMacValue不符_映射為SIGNATURE且不寫issued_data(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'trans_code' => 0,
			'trans_msg'  => 'CheckMacValue verify fail',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::SIGNATURE, NormalizedError::get_code( $result ) );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), 'SIGNATURE 失敗不應寫入 issued_data' );
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
		$order = $this->create_order(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => '/ABC1234',
				'donateCode'  => '7788',
			]
		);

		// 若驗證層先攔截，client 不會被建構 → 即使設了錯誤注入也不應觸發.
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 1200001,
			'rtn_msg'  => '不應到達此處',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ), '互斥違反應回 VALIDATION' );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '驗證層攔截時第三方未被呼叫，不應有 issued_data' );
		$this->assertSame( [], $this->http_requests );
	}

	/**
	 * B2B 統編 checksum 不合法（12345678）→ VALIDATION（issue 第一步攔截）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_統編checksum不合法_回VALIDATION(): void {
		$order = $this->create_order(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '12345678',
			]
		);

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data() );
	}

	/**
	 * B2B 統編合法（04595257）+ 注入業務錯誤 → 通過驗證層後才映射業務碼（驗證層不誤擋合法統編）
	 *
	 * @test
	 * @group error
	 */
	public function test_error_issue_合法統編04595257_通過驗證層後映射業務碼(): void {
		$order = $this->create_order(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '04595257',
			]
		);

		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 1200001,
			'rtn_msg'  => 'HashKey 或 HashIV 錯誤',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		// 合法統編不被驗證層擋下 → 走到 client → 映射為業務碼（AUTH），而非 VALIDATION.
		$this->assertSame( ErrorCode::AUTH, NormalizedError::get_code( $result ), '合法統編應通過驗證層後映射業務碼' );
	}

	// ========================================================================
	// never-throw：client / provider 內部 \Throwable 一律不向外拋，回正規化錯誤
	// ========================================================================

	/**
	 * client 內非預期 \Throwable（decode 種類）→ provider 回 PROVIDER，不向 WC hook 拋（never-throw 鐵律）
	 *
	 * 註：client 的 try/catch 是 client-internal 失敗的 never-throw 邊界（落地 kind=decode → null）；
	 * provider 讀 null → error_from_client → 依模板映射 PROVIDER（與 ezPay 模板 never_throw 測試一致）。
	 * 此處核心斷言為「不拋例外」鐵律 + 回正規化錯誤。
	 *
	 * @test
	 * @group error
	 */
	public function test_error_never_throw_client解析失敗時不向外拋且回PROVIDER(): void {
		$order = $this->create_order();
		// 注入會在 client 內觸發非 EcpayInvoiceApiException 的 \Throwable（force_throw → kind=decode）.
		InvoiceApiClient::$mock_error_override = [ 'force_throw' => true ];

		$thrown = null;
		try {
			$result = EcpayInvoiceProvider::instance()->issue( $order );
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
	 * provider 內部（client 外）\Throwable → UNKNOWN（never-throw 鐵律的 provider 級落點）
	 *
	 * client 的 try/catch 是 client-internal 失敗的邊界（→ null → PROVIDER）；provider 自身 try 區塊內
	 * （client 建構前的 settings / params 組裝等）若拋 \Throwable，則由 provider 的 catch 映射 UNKNOWN。
	 * 該落點由私有 {@see EcpayInvoiceProvider::unknown_error()} 統一封裝；本測試以 reflection 直接驗證其行為：
	 * 回正規化 UNKNOWN WP_Error、帶 provider=ecpay、raw_message 攜帶原始例外訊息。
	 *
	 * @test
	 * @group error
	 */
	public function test_error_never_throw_provider內部例外映射為UNKNOWN(): void {
		$order = $this->create_order();

		$ref = new \ReflectionMethod( EcpayInvoiceProvider::class, 'unknown_error' );
		$ref->setAccessible( true );

		/** @var \WP_Error $result */
		$result = $ref->invoke(
			EcpayInvoiceProvider::instance(),
			'開立發票',
			new \RuntimeException( '模擬 provider 內部未預期例外' ),
			$order,
			true
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ) );
		$this->assertSame( ErrorCode::UNKNOWN, NormalizedError::get_code( $result ) );
		$this->assertSame( 'ecpay', $result->get_error_data()['provider'] ?? null );
		$this->assertSame( '模擬 provider 內部未預期例外', $result->get_error_data()['raw_message'] ?? null );
	}

	// ========================================================================
	// 正規化錯誤型別契約：失敗回 WP_Error 帶 code / provider；type guard 可辨識
	// ========================================================================

	/**
	 * 業務失敗回傳須通過 is_normalized_error 型別守衛，且 error_data 帶 provider='ecpay'
	 *
	 * @test
	 * @group error
	 */
	public function test_error_失敗回傳為正規化錯誤帶provider(): void {
		$order                                 = $this->create_order();
		InvoiceApiClient::$mock_error_override = [
			'rtn_code' => 1200001,
			'rtn_msg'  => 'HashKey 或 HashIV 錯誤',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( NormalizedError::is_normalized_error( $result ) );
		$this->assertSame( 'ecpay', $result->get_error_data()['provider'] ?? null );
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
				'provider'    => 'ecpay',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$result = EcpayInvoiceProvider::instance()->issue( $order );

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
			'rtn_code' => 1200001,
			'rtn_msg'  => '不應到達此處',
		];

		$result = EcpayInvoiceProvider::instance()->issue( $order );

		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['invoice_number'] ?? '' );
	}
}
