<?php
/**
 * EcpayInvoiceProvider 整合測試
 *
 * 驗證綠界電子發票 provider 的 B2C/B2B 開立、冪等保護、作廢與 meta 清除。
 *
 * 注意：測試在 API_MODE=mock 下執行（見 composer test），InvoiceApiClient 於 MOCK
 * 模式回固定 fixture，不打真 API；故可驗證完整的 issue → 寫 meta → cancel → 清 meta 流程。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EcpayInvoiceProvider 測試類別
 *
 * @group integration
 * @group invoice
 * @group ecpay
 */
final class EcpayInvoiceProviderTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例 cache + 啟用 ecpay（測試模式）
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
	 * 每次測試後：清理設定與單例 cache
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

		// 直接以 WC CRUD 建立簡單商品（本測試套件未載入 WC_Helper_Product）
		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '100' );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_total( 100 );
		// B2C 規則：CustomerPhone 或 CustomerEmail 至少填一個（綠界 RtnCode=1200024）
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
	public function test_冒煙_EcpayInvoiceProvider_ID常數正確(): void {
		$this->assertSame( 'ecpay', EcpayInvoiceProvider::ID );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_settings_帶預設值時包含測試帳號(): void {
		$settings = EcpayInvoiceProvider::get_settings();
		$this->assertIsArray( $settings );
		$this->assertSame( '2000132', $settings['merchant_id'] ?? '' );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_B2C個人雲端發票成功寫入issued_data與provider_id(): void {
		// Given: 一筆個人雲端發票訂單
		$order    = $this->create_order_with_items(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);
		$provider = EcpayInvoiceProvider::instance();

		// When: 開立發票（MOCK 模式回固定發票號碼）
		$result = $provider->issue( $order );

		// Then: 回傳含發票號碼，且 meta 已寫入
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );

		$meta_keys = new MetaKeys( $order );
		$this->assertNotEmpty( $meta_keys->get_issued_data() );
		$this->assertSame( 'ecpay', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_B2B公司統編發票成功寫入issued_data(): void {
		// Given: 一筆公司統編（B2B）發票訂單
		$order    = $this->create_order_with_items(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'company',
				'companyName' => '測試公司',
				'companyId'   => '87654321',
			]
		);
		$provider = EcpayInvoiceProvider::instance();

		// When: 開立發票
		$result = $provider->issue( $order );

		// Then: meta 已寫入且 provider_id 為 ecpay
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );

		$meta_keys = new MetaKeys( $order );
		$this->assertNotEmpty( $meta_keys->get_issued_data() );
		$this->assertSame( 'ecpay', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_已開立過時冪等回傳已存在資料且不重打API(): void {
		// Given: 一筆已有開立資料的訂單
		$issued_data = [
			'invoice_number' => 'AB12345678',
			'invoice_date'   => '2026-01-15 10:00:00',
		];
		$order       = $this->create_order_with_items();
		( new MetaKeys( $order ) )->update_issued_data( $issued_data );

		$provider = EcpayInvoiceProvider::instance();

		// When: 再次呼叫 issue
		$result = $provider->issue( $order );

		// Then: 直接回傳已存在資料（冪等），未產生新的 MOCK 號碼
		$this->assertSame( 'AB12345678', $result['invoice_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_cancel_作廢後寫入cancelled_data並清除開立資料(): void {
		// Given: 一筆已開立發票的綠界訂單（含開立日期供作廢使用）
		$order     = $this->create_order_with_items( [ 'provider' => 'ecpay' ] );
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number' => 'PC00000001',
				'invoice_date'   => '2026-01-15 10:00:00',
			]
		);
		$meta_keys->update_provider_id( 'ecpay' );

		$provider = EcpayInvoiceProvider::instance();

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
	public function test_cancel_已作廢過時冪等回傳已存在資料(): void {
		// Given: 一筆已有作廢資料的訂單
		$order          = $this->create_wc_order( [ 'status' => 'refunded' ] );
		$meta_keys      = new MetaKeys( $order );
		$cancelled_data = [
			'rtn_msg' => '作廢成功',
			'status'  => 'cancelled',
		];
		$meta_keys->update_cancelled_data( $cancelled_data );

		$provider = EcpayInvoiceProvider::instance();

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
	public function test_get_invoice_number_已開立時回傳發票號碼(): void {
		// Given: 一筆已開立發票的訂單
		$order = $this->create_order_with_items();
		( new MetaKeys( $order ) )->update_issued_data( [ 'invoice_number' => 'XY98765432' ] );

		$provider = EcpayInvoiceProvider::instance();

		// When & Then
		$this->assertSame( 'XY98765432', $provider->get_invoice_number( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_issue_用訂單id而非物件傳入冪等仍正確(): void {
		// Given: 一筆已有開立資料的訂單
		$order = $this->create_order_with_items();
		( new MetaKeys( $order ) )->update_issued_data( [ 'invoice_number' => 'AB12345678' ] );

		$provider = EcpayInvoiceProvider::instance();

		// When: 用訂單 ID（int）傳入
		$result = $provider->issue( $order->get_id() );

		// Then: 冪等保護仍有效
		$this->assertSame( 'AB12345678', $result['invoice_number'] ?? '' );
	}
}
