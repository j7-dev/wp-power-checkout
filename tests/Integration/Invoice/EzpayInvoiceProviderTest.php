<?php
/**
 * EzpayInvoiceProvider 整合測試
 *
 * 驗證 ezPay 電子發票 provider 的 B2C/B2B 開立、冪等保護、作廢與 meta 寫入。
 *
 * meta key 對照（ezPay 與 Ecpay 不同）：
 *  - issued_data 中 invoice_trans_no（非 ecpay 的 invoice_number 對應字段名稱不同）
 *  - issued_data 中 random_num（非 random_number）
 *  - allowance_data 中 allowance_no（非 allowance_number）
 *
 * 注意：測試在 API_MODE=mock 下執行（EzpayInvoiceApiClient MOCK 模式回固定 fixture，
 * 不打真 API）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EzpayInvoiceProvider 測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 */
final class EzpayInvoiceProviderTest extends TestCase {

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
	 * 每次測試後：清理設定與單例 cache
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
	 * 建立一筆有商品的訂單（避免 Items 為空）
	 *
	 * @param array<string, mixed> $issue_params 結帳填寫的發票資訊
	 * @return \WC_Order
	 */
	private function create_order_with_items( array $issue_params = [] ): \WC_Order {
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

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_EzpayInvoiceProvider_ID常數為ezpay(): void {
		$this->assertSame( 'ezpay', EzpayInvoiceProvider::ID );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_settings_帶測試設定包含merchant_id(): void {
		$settings = EzpayInvoiceProvider::get_settings();
		$this->assertIsArray( $settings );
		$this->assertSame( 'MS12345678', $settings['merchant_id'] ?? '' );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue_B2C個人雲端發票成功寫入issued_data與provider_id(): void {
		// Given: 一筆個人雲端發票訂單
		$order = $this->create_order_with_items(
			[
				'provider'    => 'ezpay',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$provider = EzpayInvoiceProvider::instance();

		// When: 開立發票（MOCK 模式回固定發票資料）
		$result = $provider->issue( $order );

		// Then: 回傳含發票資訊，且 meta 已寫入
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );

		$meta_keys = new MetaKeys( $order );
		$issued    = $meta_keys->get_issued_data();
		$this->assertNotEmpty( $issued );
		// ezPay 特有欄位（非 ecpay）
		$this->assertArrayHasKey( 'invoice_trans_no', $issued, 'ezPay issued_data 必須含 invoice_trans_no' );
		$this->assertArrayHasKey( 'random_num', $issued, 'ezPay issued_data 必須含 random_num' );
		$this->assertSame( 'ezpay', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue_B2B公司統編發票成功寫入issued_data(): void {
		// Given: 一筆公司統編（B2B）發票訂單
		$order = $this->create_order_with_items(
			[
				'provider'    => 'ezpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '87654321',
			]
		);
		$provider = EzpayInvoiceProvider::instance();

		// When
		$result = $provider->issue( $order );

		// Then
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );

		$meta_keys = new MetaKeys( $order );
		$this->assertNotEmpty( $meta_keys->get_issued_data() );
		$this->assertSame( 'ezpay', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_issue_已開立過時冪等回傳已存在資料(): void {
		// Given: 一筆已有開立資料的訂單
		$issued_data = [
			'invoice_number'   => 'EV12345678',
			'invoice_trans_no' => 'EZT0000001',
			'random_num'       => '9876',
			'invoice_date'     => '2026-01-15 10:00:00',
		];
		$order = $this->create_order_with_items();
		( new MetaKeys( $order ) )->update_issued_data( $issued_data );

		$provider = EzpayInvoiceProvider::instance();

		// When: 再次呼叫 issue
		$result = $provider->issue( $order );

		// Then: 直接回傳已存在資料（冪等），不重打 API
		$this->assertSame( 'EV12345678', $result['invoice_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_cancel_作廢後寫入cancelled_data並清除開立資料(): void {
		// Given: 一筆已開立發票的訂單
		$order     = $this->create_order_with_items( [ 'provider' => 'ezpay' ] );
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number'   => 'EV00000001',
				'invoice_trans_no' => 'EZT0000001',
				'random_num'       => '1234',
				'invoice_date'     => '2026-01-15 10:00:00',
			]
		);
		$meta_keys->update_provider_id( 'ezpay' );

		$provider = EzpayInvoiceProvider::instance();

		// When: 作廢發票（MOCK 模式回作廢成功）
		$result = $provider->cancel( $order );

		// Then: 作廢資料寫入，開立資料與 provider_id 已清除
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_cancelled_data() );
		$this->assertEmpty( $fresh->get_issued_data() );
		$this->assertSame( '', $fresh->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_cancel_已作廢過時冪等回傳已存在資料(): void {
		// Given: 一筆已有作廢資料的訂單
		$order          = $this->create_wc_order( [ 'status' => 'refunded' ] );
		$meta_keys      = new MetaKeys( $order );
		$cancelled_data = [
			'status'  => 'cancelled',
			'rtn_msg' => '作廢成功',
		];
		$meta_keys->update_cancelled_data( $cancelled_data );

		$provider = EzpayInvoiceProvider::instance();

		// When: 再次呼叫 cancel
		$result = $provider->cancel( $order );

		// Then: 直接回傳已存在資料（冪等）
		$this->assertSame( 'cancelled', $result['status'] ?? '' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_get_invoice_number_已開立時回傳發票號碼(): void {
		$order = $this->create_order_with_items();
		( new MetaKeys( $order ) )->update_issued_data( [ 'invoice_number' => 'EV98765432' ] );

		$provider = EzpayInvoiceProvider::instance();

		$this->assertSame( 'EV98765432', $provider->get_invoice_number( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_issue_未開立發票時get_invoice_number回傳空字串(): void {
		$order    = $this->create_order_with_items();
		$provider = EzpayInvoiceProvider::instance();

		$this->assertSame( '', $provider->get_invoice_number( $order ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_cancel_LIB10007已開折讓時作廢失敗不清除issued_data(): void {
		// Given: 一筆已開立發票且已有折讓的訂單（LIB10007 情境）
		$order     = $this->create_order_with_items();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number'   => 'EV00000001',
				'invoice_trans_no' => 'EZT0000001',
				'random_num'       => '1234',
				'invoice_date'     => '2026-01-15',
			]
		);
		$meta_keys->update_allowance_data(
			[
				'allowance_no'     => 'EZALL00001',
				'allowance_amount' => 50,
			]
		);
		$meta_keys->update_provider_id( 'ezpay' );

		$provider = EzpayInvoiceProvider::instance();

		// When: 嘗試作廢（MOCK 模式應回 LIB10007 失敗）
		$result = $provider->cancel( $order );

		// Then: 回傳空陣列或包含錯誤，issued_data 未清除
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), 'LIB10007 時 issued_data 不應被清除' );
	}
}
