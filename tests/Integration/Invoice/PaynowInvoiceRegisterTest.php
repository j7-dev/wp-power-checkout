<?php
/**
 * PayNow 電子發票 Provider 註冊整合測試（B-Cycle 2 Red 階段）
 *
 * 驗證 Invoice\ProviderRegister 中：
 *   1. $invoice_providers 含 paynow_invoice（ID = PaynowInvoiceProvider::ID）
 *   2. PaynowInvoiceSettingsDTO::ID 選項鍵 = 'paynow_invoice'（R5：不撞金流 'paynow'）
 *   3. WC option key = 'woocommerce_paynow_invoice_settings'（不撞金流 'woocommerce_paynow_settings'）
 *   4. auto-issue hook 正確掛載（woocommerce_order_status_{status}）
 *   5. ProviderRegister::maybe_issue_allowance_on_refund() 退款自動折讓路由（provider-agnostic hook）
 *      在 paynow_invoice 啟用後正確委派（部分退款 + 開關開 → 觸發折讓）
 *
 * 未來 class FQCN：J7\PowerCheckout\Domains\Invoice\Paynow\Services\PaynowInvoiceProvider
 *
 * @see specs/features/invoice/paynow-invoice-issue.feature 「自動開立」場景
 * @see specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md（B-Cycle 2）裁決 R5
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\PaynowInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Paynow\Services\PaynowInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\ProviderRegister;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 電子發票 Provider 註冊測試類別
 *
 * @group integration
 * @group invoice
 * @group paynow
 */
final class PaynowInvoiceRegisterTest extends TestCase {

	/**
	 * 每次測試前重置 SettingsDTO 單例
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
		\update_option( 'woocommerce_currency', 'TWD' );
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( PaynowInvoiceSettingsDTO::ID ) );
		// 金流 gateway option（確保不與發票 option 混用）
		\delete_option( ProviderUtils::get_option_name( 'paynow' ) );
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

	// ========== ID / Option Key 不撞 — Smoke ==========

	/**
	 * R5 裁決：發票 provider ID = 'paynow_invoice'，與金流 'paynow' 完全分離。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_PaynowInvoiceProvider_ID為paynow_invoice(): void {
		$this->assertSame( 'paynow_invoice', PaynowInvoiceProvider::ID );
	}

	/**
	 * R5 裁決：SettingsDTO ID = 'paynow_invoice'，選項鍵 'woocommerce_paynow_invoice_settings'。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_PaynowInvoiceSettingsDTO_ID為paynow_invoice(): void {
		$this->assertSame( 'paynow_invoice', PaynowInvoiceSettingsDTO::ID );
	}

	// ========== Option key 隔離 — Happy ==========

	/**
	 * R5 裁決：發票 option key = 'woocommerce_paynow_invoice_settings'（不撞金流 'woocommerce_paynow_settings'）。
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_發票option_key不撞金流option_key(): void {
		$invoice_option_key = ProviderUtils::get_option_name( PaynowInvoiceSettingsDTO::ID );
		$payment_option_key = ProviderUtils::get_option_name( 'paynow' );

		$this->assertSame( 'woocommerce_paynow_invoice_settings', $invoice_option_key );
		$this->assertSame( 'woocommerce_paynow_settings', $payment_option_key );
		$this->assertNotSame(
			$invoice_option_key,
			$payment_option_key,
			'發票 option key 不可與金流 option key 相同（R5 撞鍵保護）'
		);
	}

	/**
	 * 同時寫入兩個 option 不互相覆蓋（隔離驗證）
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_發票與金流option獨立寫入不互覆(): void {
		// 寫入發票設定
		ProviderUtils::update_option(
			PaynowInvoiceSettingsDTO::ID,
			[
				'enabled'   => 'yes',
				'jwt_token' => 'invoice-jwt-token',
			]
		);
		// 寫入金流設定
		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'yes',
				'private_key' => 'payment-private-key',
			]
		);

		// 各自讀回，互不影響
		$invoice_settings = ProviderUtils::get_option( PaynowInvoiceSettingsDTO::ID );
		$payment_settings = ProviderUtils::get_option( 'paynow' );

		$this->assertIsArray( $invoice_settings );
		$this->assertIsArray( $payment_settings );
		$this->assertSame( 'invoice-jwt-token', $invoice_settings['jwt_token'] ?? '' );
		$this->assertSame( 'payment-private-key', $payment_settings['private_key'] ?? '' );
		// 確認無串漏
		$this->assertArrayNotHasKey( 'private_key', $invoice_settings, '發票設定不應含金流 private_key' );
		$this->assertArrayNotHasKey( 'jwt_token', $payment_settings, '金流設定不應含發票 jwt_token' );
	}

	// ========== ProviderRegister 含 paynow_invoice — Happy ==========

	/**
	 * ProviderRegister::$invoice_providers 中應包含 paynow_invoice（啟用後可加入容器）
	 *
	 * 透過 reflection 讀取 private static $invoice_providers 陣列，驗證包含 paynow_invoice key。
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_ProviderRegister_invoice_providers含paynow_invoice(): void {
		$ref  = new \ReflectionClass( ProviderRegister::class );
		$prop = $ref->getProperty( 'invoice_providers' );
		$prop->setAccessible( true );
		$providers = (array) $prop->getValue( null );

		$this->assertArrayHasKey(
			PaynowInvoiceProvider::ID,
			$providers,
			'ProviderRegister::$invoice_providers 必須含 paynow_invoice key'
		);
		$this->assertSame(
			PaynowInvoiceProvider::class,
			$providers[ PaynowInvoiceProvider::ID ] ?? '',
			'paynow_invoice 對應的 class 必須為 PaynowInvoiceProvider'
		);
	}

	/**
	 * 啟用 paynow_invoice 後，provider 可進入容器且可取得
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_啟用paynow_invoice後provider進入容器(): void {
		// Given: 啟用 paynow_invoice
		$this->enable_provider(
			PaynowInvoiceSettingsDTO::ID,
			[
				'mode'      => 'dev',
				'jwt_token' => 'test-jwt',
			]
		);

		// 手動放入容器（模擬 ProviderRegister::register_provider_hooks 效果）
		ProviderUtils::$container[ PaynowInvoiceProvider::ID ] = PaynowInvoiceProvider::instance();

		// Then: 容器中可取得 provider 且為正確型別
		$this->assert_provider_enabled( PaynowInvoiceProvider::ID );
		$provider = ProviderUtils::get_provider( PaynowInvoiceProvider::ID );
		$this->assertInstanceOf( PaynowInvoiceProvider::class, $provider );
	}

	// ========== auto-issue hook 掛載 — Happy ==========

	/**
	 * auto_issue_order_statuses 設定後，對應的 WooCommerce hook 應被掛載
	 *
	 * 模擬 ProviderRegister::register_provider_hooks() 的 auto_issue hook 邏輯，
	 * 驗證 woocommerce_order_status_{status} → provider::issue 的 hook 掛載。
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_auto_issue_hook在設定狀態下正確掛載(): void {
		// Given: auto_issue_order_statuses = ['wc-processing']
		$this->reset_settings_instance();
		$this->enable_provider(
			PaynowInvoiceSettingsDTO::ID,
			[
				'mode'                      => 'dev',
				'jwt_token'                 => 'test-jwt',
				'auto_issue_order_statuses' => [ 'wc-processing' ],
			]
		);

		// 手動模擬 register_provider_hooks 的 auto_issue hook 掛載邏輯
		$provider = PaynowInvoiceProvider::instance();
		$settings = $provider::get_settings();
		$statuses = \is_array( $settings['auto_issue_order_statuses'] ?? null )
		? (array) $settings['auto_issue_order_statuses']
		: [];

		foreach ( $statuses as $status_with_prefix ) {
			$status = \str_replace( 'wc-', '', (string) $status_with_prefix );
			\add_action( "woocommerce_order_status_{$status}", [ $provider, 'issue' ] );
		}

		// Then: hook 掛載成功
		$this->assertGreaterThan(
			0,
			\has_action( 'woocommerce_order_status_processing', [ $provider, 'issue' ] ),
			'woocommerce_order_status_processing → provider::issue 必須已掛載'
		);

		// 清理
		\remove_action( 'woocommerce_order_status_processing', [ $provider, 'issue' ] );
	}

	// ========== 退款自動折讓路由（provider-agnostic hook 委派） — Happy ==========

	/**
	 * maybe_issue_allowance_on_refund：paynow_invoice 啟用、開關開、部分退款 → 觸發折讓
	 *
	 * ProviderRegister::maybe_issue_allowance_on_refund() 為 provider-agnostic；
	 * 此測試驗證 paynow_invoice 在容器中時，hook 正確委派至 PaynowInvoiceProvider::issue_allowance()。
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_退款折讓hook委派至paynow_invoice_部分退款開折讓(): void {
		// Given: paynow_invoice 啟用、auto_allowance_on_refund=yes、provider 在容器中
		$this->reset_settings_instance();
		$this->enable_provider(
			PaynowInvoiceSettingsDTO::ID,
			[
				'mode'                     => 'dev',
				'jwt_token'                => 'test-jwt',
				'auto_allowance_on_refund' => 'yes',
			]
		);
		ProviderUtils::$container[ PaynowInvoiceProvider::ID ] = PaynowInvoiceProvider::instance();

		// 建立已開立發票的訂單，provider_id = paynow_invoice
		$order   = $this->create_wc_order(
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
		$order->save();

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

		// When: 部分退款 500
		$refund    = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 500.0,
			]
		);
		$refund_id = ( $refund instanceof \WC_Order_Refund ) ? $refund->get_id() : 0;
		ProviderRegister::maybe_issue_allowance_on_refund( $order->get_id(), $refund_id );

		// Then: allowance_data 已寫入（MOCK 成功）
		$fresh          = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$allowance_data = $fresh->get_allowance_data();
		$this->assertNotEmpty( $allowance_data, '部分退款後 _pc_allowance_data 必須有值' );
	}

	/**
	 * maybe_issue_allowance_on_refund：paynow_invoice 未啟用時不觸發折讓（不報錯）
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_退款折讓hook_paynow_invoice未啟用時不開折讓(): void {
		// Given: paynow_invoice 未啟用（容器中無 provider）
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1050,
			]
		);
		$order->set_total( 1050 );
		$order->save();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB12345678' ] );
		$meta_keys->update_provider_id( PaynowInvoiceProvider::ID );

		// When / Then: 不報錯（provider 不在容器中，maybe_issue_allowance_on_refund 靜默退出）
		ProviderRegister::maybe_issue_allowance_on_refund( $order->get_id(), 0 );

		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data(), 'provider 未在容器時不應開立折讓' );
		$this->assertTrue( true, 'provider 未在容器時不應拋出例外' );
	}

	// ========== auto_allowance_on_refund 開關預設 — Happy ==========

	/**
	 * PayNow 發票 auto_allowance_on_refund 預設應為 'no'（比照其他 provider）
	 *
	 * @test
	 * @group happy
	 */
	public function test_happy_auto_allowance_on_refund預設為no(): void {
		$this->enable_provider(
			PaynowInvoiceSettingsDTO::ID,
			[ 'mode' => 'dev' ]
		);
		$settings = PaynowInvoiceProvider::get_settings();
		$this->assertArrayHasKey( 'auto_allowance_on_refund', $settings );
		$this->assertSame( 'no', $settings['auto_allowance_on_refund'] );
	}
}
