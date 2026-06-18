<?php
/**
 * 綠界電子發票折讓（Allowance）整合測試（A3）
 *
 * 涵蓋：
 *  - EApi 新增 B2C/B2B 折讓 + 作廢折讓 4 個 case 與 helper
 *  - EcpayInvoiceProvider::issue_allowance() / invalid_allowance()（B2C/B2B）
 *  - 折讓 meta 寫入（_pc_allowance_data）
 *  - 冪等：同一 RelateNumber/已開折讓不重打
 *  - 金額驗證：折讓金額 > 0 且 ≤ 原發票金額
 *  - 作廢折讓
 *
 * 測試在 API_MODE=mock 下執行，InvoiceApiClient 折讓走固定 fixture，不打真 API。
 *
 * 折讓 API 出處：
 *  - B2C：guides/04-invoice-b2c.md §折讓（/B2CInvoice/Allowance、/B2CInvoice/AllowanceInvalid）
 *  - B2B：guides/05-invoice-b2b.md §折讓（存證模式 /B2BInvoice/Allowance、/B2BInvoice/AllowanceInvalid）
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EcpayInvoiceProvider 折讓測試類別
 *
 * @group integration
 * @group invoice
 * @group ecpay
 * @group allowance
 */
final class EcpayAllowanceTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例 + 啟用 ecpay（測試模式）
	 */
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
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
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
	 * 建立一筆已開立發票的訂單
	 *
	 * @param array<string, mixed> $issue_params 結帳發票資訊（決定 B2C / B2B）
	 * @param array<string, mixed> $issued_data  已開立發票 meta
	 * @return \WC_Order
	 */
	private function create_issued_order( array $issue_params = [], array $issued_data = [] ): \WC_Order {
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

		$meta_keys = new MetaKeys( $order );
		if ( $issue_params ) {
			$meta_keys->update_issue_params( $issue_params );
		}
		$meta_keys->update_issued_data(
			\wp_parse_args(
				$issued_data,
				[
					'invoice_number' => 'AB12345678',
					'invoice_date'   => '2026-01-15 10:00:00',
					'random_number'  => '1234',
				]
			)
		);
		$meta_keys->update_provider_id( EcpayInvoiceProvider::ID );

		return $order;
	}

	// ========== EApi enum ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_EApi_折讓case端點正確(): void {
		$this->assertSame( '/B2CInvoice/Allowance', EApi::B2C_ALLOWANCE->value );
		$this->assertSame( '/B2CInvoice/AllowanceInvalid', EApi::B2C_ALLOWANCE_INVALID->value );
		$this->assertSame( '/B2BInvoice/Allowance', EApi::B2B_ALLOWANCE->value );
		$this->assertSame( '/B2BInvoice/AllowanceInvalid', EApi::B2B_ALLOWANCE_INVALID->value );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_EApi_折讓case的is_allowance為true(): void {
		$this->assertTrue( EApi::B2C_ALLOWANCE->is_allowance() );
		$this->assertTrue( EApi::B2B_ALLOWANCE->is_allowance() );
		$this->assertFalse( EApi::B2C_ISSUE->is_allowance() );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_EApi_B2B折讓需帶RqID(): void {
		$this->assertTrue( EApi::B2B_ALLOWANCE->is_b2b() );
		$this->assertTrue( EApi::B2B_ALLOWANCE_INVALID->is_b2b() );
		$this->assertSame( '1.0.0', EApi::B2B_ALLOWANCE->revision() );
		$this->assertSame( '3.0.0', EApi::B2C_ALLOWANCE->revision() );
	}

	// ========== 快樂路徑：開立折讓 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_allowance_B2C部分折讓成功寫入allowance_data(): void {
		// Given: 一筆已開立 B2C 雲端發票的訂單
		$order    = $this->create_issued_order(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$provider = EcpayInvoiceProvider::instance();

		// When: 對 50 元開折讓單
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回傳含折讓單號，allowance meta 已寫入
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['allowance_number'] ?? '' );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();
		$this->assertNotEmpty( $allowance_data );
		$this->assertSame( 50, (int) ( $allowance_data['allowance_amount'] ?? 0 ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_allowance_B2B公司統編折讓成功(): void {
		// Given: 一筆已開立 B2B 發票的訂單（04595257 通過財政部 UBN checksum）
		$order    = $this->create_issued_order(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '04595257',
			]
		);
		$provider = EcpayInvoiceProvider::instance();

		// When
		$result = $provider->issue_allowance( $order, 100.0 );

		// Then
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['allowance_number'] ?? '' );
	}

	// ========== 冪等 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_allowance_已開折讓時冪等回傳已存在資料(): void {
		// Given: 已有折讓資料的訂單
		$order     = $this->create_issued_order( [ 'provider' => 'ecpay' ] );
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_allowance_data(
			[
				'allowance_number' => 'EXISTING001',
				'allowance_amount' => 30,
			]
		);

		$provider = EcpayInvoiceProvider::instance();

		// When: 再次開折讓
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回傳已存在折讓單號（冪等，不重打）
		$this->assertSame( 'EXISTING001', $result['allowance_number'] ?? '' );
	}

	// ========== 金額驗證 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_issue_allowance_金額為零回傳VALIDATION錯誤(): void {
		$order    = $this->create_issued_order( [ 'provider' => 'ecpay' ] );
		$provider = EcpayInvoiceProvider::instance();

		// 契約演進：金額不合法不再塌縮回 []，改回正規化 VALIDATION WP_Error.
		$result = $provider->issue_allowance( $order, 0.0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_issue_allowance_金額超過原發票回傳空陣列(): void {
		// Given: 原發票 100 元
		$order    = $this->create_issued_order( [ 'provider' => 'ecpay' ] );
		$provider = EcpayInvoiceProvider::instance();

		// When: 折讓 200 元（超過）
		$result = $provider->issue_allowance( $order, 200.0 );

		// Then: 拒絕（契約演進：超額回正規化 VALIDATION WP_Error，非 []）
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_issue_allowance_未開立發票回傳NOT_FOUND錯誤(): void {
		// Given: 一筆沒有開立發票的訂單
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order->set_total( 100 );
		$order->save();

		$provider = EcpayInvoiceProvider::instance();

		// When
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 契約演進：前置未開立發票回正規化 NOT_FOUND WP_Error，非 []
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}

	// ========== 作廢折讓 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_invalid_allowance_作廢折讓成功並清除allowance_data(): void {
		// Given: 一筆已開折讓的訂單
		$order    = $this->create_issued_order( [ 'provider' => 'ecpay' ] );
		$provider = EcpayInvoiceProvider::instance();
		$provider->issue_allowance( $order, 50.0 );

		// When: 作廢折讓
		$result = $provider->invalid_allowance( $order );

		// Then: 回成功，allowance_data 已清除
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_invalid_allowance_無折讓資料回傳NOT_FOUND錯誤(): void {
		$order    = $this->create_issued_order( [ 'provider' => 'ecpay' ] );
		$provider = EcpayInvoiceProvider::instance();

		// When: 沒有折讓資料就作廢
		$result = $provider->invalid_allowance( $order );

		// Then: 契約演進：無折讓資料回正規化 NOT_FOUND WP_Error，非 []
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}
}
