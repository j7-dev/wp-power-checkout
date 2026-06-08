<?php
/**
 * ezPay 電子發票折讓（Allowance）整合測試
 *
 * 涵蓋：
 *  - EzpayInvoiceProvider::issue_allowance()
 *  - EzpayInvoiceProvider::invalid_allowance()
 *  - 折讓成功寫入 _pc_allowance_data（含 allowance_no，非 allowance_number）
 *  - 金額驗證：折讓金額 > 0 且 ≤ 原發票金額
 *  - CheckCode 驗證失敗 → 不寫 allowance_data
 *  - 作廢折讓清除 allowance_data
 *  - 冪等：已有折讓資料時不重打 API
 *
 * 注意：測試在 API_MODE=mock 下執行，不打真 API。
 * 折讓 API 出處：ezpay-invoice skill references/api-reference.md §allowance_issue + allowanceInvalid
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EzpayInvoiceProvider 折讓測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 * @group allowance
 */
final class EzpayAllowanceTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例 + 啟用 ezpay（測試模式）
	 */
	public function set_up(): void {
		parent::set_up();
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
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
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
	 * 建立一筆已開立發票的訂單
	 *
	 * @param array<string, mixed> $issued_data 已開立發票 meta
	 * @return \WC_Order
	 */
	private function create_issued_order( array $issued_data = [] ): \WC_Order {
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
		$meta_keys->update_issued_data(
			\wp_parse_args(
				$issued_data,
				[
					'invoice_number'   => 'EV00000001',
					'invoice_trans_no' => 'EZT0000001',
					'random_num'       => '1234',
					'invoice_date'     => '2026-01-15 10:00:00',
				]
			)
		);
		$meta_keys->update_provider_id( EzpayInvoiceProvider::ID );

		return $order;
	}

	// ========== 快樂路徑：開立折讓 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue_allowance_部分折讓成功寫入allowance_no(): void {
		// Given: 一筆已開立發票的訂單
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		// When: 對 50 元開折讓單
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回傳含折讓單號（allowance_no，非 allowance_number），allowance meta 已寫入
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['allowance_no'] ?? '' );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();
		$this->assertNotEmpty( $allowance_data );
		$this->assertSame( 50, (int) ( $allowance_data['allowance_amount'] ?? 0 ) );
		$this->assertArrayHasKey( 'allowance_no', $allowance_data, 'ezPay 折讓 meta 必須含 allowance_no' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue_allowance_全額折讓成功(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$result = $provider->issue_allowance( $order, 100.0 );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['allowance_no'] ?? '' );
	}

	// ========== 冪等 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue_allowance_已開折讓時冪等回傳已存在資料(): void {
		// Given: 已有折讓資料的訂單
		$order     = $this->create_issued_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_allowance_data(
			[
				'allowance_no'     => 'EZALL_EXISTING',
				'allowance_amount' => 30,
			]
		);

		$provider = EzpayInvoiceProvider::instance();

		// When: 再次開折讓
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回傳已存在折讓單號（冪等，不重打）
		$this->assertSame( 'EZALL_EXISTING', $result['allowance_no'] ?? '' );
	}

	// ========== 金額驗證 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_error_issue_allowance_金額為零回傳空陣列(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$result = $provider->issue_allowance( $order, 0.0 );
		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_issue_allowance_金額超過原發票回傳空陣列(): void {
		// Given: 原發票 100 元
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		// When: 折讓 200 元（超過）
		$result = $provider->issue_allowance( $order, 200.0 );

		// Then: 拒絕
		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_issue_allowance_未開立發票回傳空陣列(): void {
		// Given: 一筆沒有開立發票的訂單
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order->set_total( 100 );
		$order->save();

		$provider = EzpayInvoiceProvider::instance();

		// When
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then
		$this->assertSame( [], $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_issue_allowance_CheckCode驗證失敗時不寫allowance_data(): void {
		// Given: 一筆已開立發票的訂單（MOCK 會回 CheckCode 失敗的 fixture）
		// 透過設定錯誤的 hash_key / hash_iv 觸發 CheckCode 驗證失敗
		\delete_option( ProviderUtils::get_option_name( EzpayInvoiceProvider::ID ) );
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => 'MS12345678',
				'hash_key'    => 'WRONG_KEY_INTENTIONALLY_INVALID', // 錯誤 KEY → CheckCode 驗證失敗
				'hash_iv'     => '1234567891234567',
			]
		);

		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回空陣列，allowance_data 未寫入
		$this->assertSame( [], $result );
		$meta_keys = new MetaKeys( $order );
		$this->assertEmpty( $meta_keys->get_allowance_data() );
	}

	// ========== 作廢折讓 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_invalid_allowance_作廢折讓成功並清除allowance_data(): void {
		// Given: 一筆已開折讓的訂單
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();
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
	public function test_error_invalid_allowance_無折讓資料回傳空陣列(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		// When: 沒有折讓資料就作廢
		$result = $provider->invalid_allowance( $order );

		// Then
		$this->assertSame( [], $result );
	}
}
