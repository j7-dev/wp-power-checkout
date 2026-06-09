<?php
/**
 * 退款自動開折讓 hook 整合測試（B6）
 *
 * 涵蓋 Invoice\ProviderRegister::maybe_issue_allowance_on_refund()：
 *  - 部分退款 + 已開發票 + provider 支援折讓 + 設定開關開 → 自動開折讓
 *  - 設定開關預設關 → 不開折讓
 *  - 全額退款 → 不開折讓（走作廢發票既有邏輯）
 *  - 未開發票 → 不開折讓
 *  - provider 不支援折讓 → 不開折讓（不報錯）
 *  - 任何 \Throwable 不破壞退款主流程
 *
 * 在 API_MODE=mock 下執行（Amego Requester 折讓走固定 fixture）。
 *
 * @see .claude/skills/amego-invoice/references/api-reference.md §開立折讓 /json/g0401
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\ProviderRegister;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 退款自動開折讓 hook 測試類別
 *
 * @group integration
 * @group invoice
 * @group allowance
 * @group refund
 */
final class RefundAllowanceHookTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
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
	 * 建立一筆已開立發票、已啟用 Amego 的訂單
	 *
	 * @param array<string, mixed> $extra_settings provider 設定
	 * @return \WC_Order
	 */
	private function create_issued_order( array $extra_settings = [] ): \WC_Order {
		$this->enable_provider(
			AmegoProvider::ID,
			\wp_parse_args(
				$extra_settings,
				[ 'mode' => 'test' ]
			)
		);
		// 將 provider 放入容器，使 ProviderUtils::get_provider() 可取得
		ProviderUtils::$container[ AmegoProvider::ID ] = AmegoProvider::instance();

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
		$order->save();

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

	/**
	 * 建立部分退款並觸發 hook
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 退款金額
	 * @return void
	 */
	private function refund_order( \WC_Order $order, float $amount ): void {
		$refund    = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => $amount,
			]
		);
		$refund_id = ( $refund instanceof \WC_Order_Refund ) ? $refund->get_id() : 0;

		// 直接呼叫 hook callback（wc_create_refund 已觸發 woocommerce_order_refunded，
		// 但測試環境 hook 註冊時機不保證，故顯式呼叫以驗證邏輯）
		ProviderRegister::maybe_issue_allowance_on_refund( $order->get_id(), $refund_id );
	}

	// ========== 設定開關 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_設定預設關閉_auto_allowance_on_refund(): void {
		$this->enable_provider( AmegoProvider::ID, [ 'mode' => 'test' ] );
		$settings = AmegoProvider::get_settings();
		$this->assertArrayHasKey( 'auto_allowance_on_refund', $settings );
		$this->assertSame( 'no', $settings['auto_allowance_on_refund'] );
	}

	// ========== 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_開關開啟_部分退款_自動開折讓(): void {
		// Given: 已開發票、開關開啟
		$order = $this->create_issued_order( [ 'auto_allowance_on_refund' => 'yes' ] );

		// When: 部分退款 50
		$this->refund_order( $order, 50.0 );

		// Then: 已開折讓
		$fresh          = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$allowance_data = $fresh->get_allowance_data();
		$this->assertNotEmpty( $allowance_data );
		$this->assertSame( 50, (int) ( $allowance_data['allowance_amount'] ?? 0 ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_開關關閉_部分退款_不開折讓(): void {
		// Given: 已開發票、開關關閉（預設）
		$order = $this->create_issued_order( [ 'auto_allowance_on_refund' => 'no' ] );

		// When: 部分退款 50
		$this->refund_order( $order, 50.0 );

		// Then: 未開折讓
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data() );
	}

	// ========== 全額退款不開折讓 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_全額退款_不開折讓(): void {
		// Given: 已開發票、開關開啟
		$order = $this->create_issued_order( [ 'auto_allowance_on_refund' => 'yes' ] );

		// When: 全額退款 100（= 訂單總額）
		$this->refund_order( $order, 100.0 );

		// Then: 不開折讓（全退走作廢發票，不走折讓）
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data() );
	}

	// ========== 未開發票 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_未開發票_不開折讓(): void {
		// Given: 啟用 provider 但訂單未開發票、開關開啟
		$this->enable_provider(
			AmegoProvider::ID,
			[
				'mode'                     => 'test',
				'auto_allowance_on_refund' => 'yes',
			]
			);
		ProviderUtils::$container[ AmegoProvider::ID ] = AmegoProvider::instance();

		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
			);
		$order->set_total( 100 );
		$order->save();

		// When: 部分退款
		$this->refund_order( $order, 50.0 );

		// Then: 未開折讓
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data() );
	}

	// ========== 錯誤處理：不破壞退款主流程 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_provider不存在_不報錯(): void {
		// Given: 一筆有 provider_id 但 provider 未在容器中的訂單
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
			);
		$order->set_total( 100 );
		$order->save();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AG00000001' ] );
		$meta_keys->update_provider_id( 'nonexistent_provider' );

		// When / Then: 不應拋出例外
		ProviderRegister::maybe_issue_allowance_on_refund( $order->get_id(), 0 );
		$this->assertTrue( true );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_訂單不存在_不報錯(): void {
		// When / Then: 不存在的訂單 ID 不應拋出例外
		ProviderRegister::maybe_issue_allowance_on_refund( 999999999, 0 );
		$this->assertTrue( true );
	}
}
