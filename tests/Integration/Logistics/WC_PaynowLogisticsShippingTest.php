<?php
/**
 * WC_PaynowLogisticsShipping 整合測試（A-Cycle 3 Red Gate）
 *
 * 驗證 PayNow 物流 WC_Shipping_Method（classic checkout）行為：
 *   - per-service 運送方式（每個啟用的 PaynowLogisticService 各產一個 rate）。
 *   - is_chosen() 判斷訂單是否選用本物流方式。
 *   - save_checkout_meta() 寫 _pc_paynow_logistics_service_id（依選定 rate）。
 *   - enabled_methods 為空不產 rate。
 *   - 白名單過濾（非合法 service_id 不寫入 meta）。
 *
 * ⚠️ 此測試類在 WC_PaynowLogisticsShipping 未實作前必須全部失敗（Red Gate）。
 *    失敗原因應為「class not found」或斷言失敗，不應為語法/環境錯誤。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ --filter WC_PaynowLogisticsShippingTest" 2>&1; echo "EXIT_CODE=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\WC_PaynowLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * WC_PaynowLogisticsShipping 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class WC_PaynowLogisticsShippingTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		// 幣別踩雷：涉及金額的測試需顯式設 TWD
		\update_option( 'woocommerce_currency', 'TWD' );
		PaynowLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );
		$this->enable_paynow_logistics();
	}

	public function tear_down(): void {
		\putenv( 'API_MODE=' );
		PaynowLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PaynowLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 paynow_logistics（test 模式，預設啟用 SEVEN / FAMI / HILIFE / TCAT）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 */
	private function enable_paynow_logistics( array $extra = [] ): void {
		$this->enable_provider(
			PaynowLogisticsProvider::ID,
			\array_merge(
				[
					'mode'            => 'test',
					'user_account'    => 'test_account',
					'apicode'         => 'test_apicode',
					'enabled_methods' => [ 'SEVEN', 'FAMI', 'HILIFE', 'TCAT' ],
				],
				$extra
			)
		);
		PaynowLogisticsSettingsDTO::reset();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_WC_PaynowLogisticsShipping繼承WC_Shipping_Method(): void {
		$method = new WC_PaynowLogisticsShipping();
		$this->assertInstanceOf( \WC_Shipping_Method::class, $method );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_METHOD_ID為paynow_logistics(): void {
		$this->assertSame( 'paynow_logistics', WC_PaynowLogisticsShipping::METHOD_ID );
	}

	// ========== per-service 運送方式（每個 enabled service 各一個 rate） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用SEVEN_FAMI_HILIFE_TCAT時calculate_shipping產出4個rate(): void {
		// Given: enabled_methods = SEVEN, FAMI, HILIFE, TCAT
		$method = new WC_PaynowLogisticsShipping();

		// When
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// Then: 應產出 4 個 rate，各對應一個 service
		$this->assertCount( 4, $rates, '應為每個 enabled service 各產一個 rate' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_每個rate的ID含對應service_value後綴(): void {
		// Given: enabled_methods = SEVEN, FAMI, HILIFE, TCAT
		$method = new WC_PaynowLogisticsShipping();
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// rate_id 應含各 PaynowLogisticService value
		$rate_ids = \array_keys( $rates );
		$this->assertNotEmpty(
			\array_filter( $rate_ids, static fn( string $id ): bool => \str_contains( $id, PaynowLogisticService::Seven->value ) ),
			'應有 rate id 含 SEVEN（01）後綴'
		);
		$this->assertNotEmpty(
			\array_filter( $rate_ids, static fn( string $id ): bool => \str_contains( $id, PaynowLogisticService::Fami->value ) ),
			'應有 rate id 含 FAMI（03）後綴'
		);
		$this->assertNotEmpty(
			\array_filter( $rate_ids, static fn( string $id ): bool => \str_contains( $id, PaynowLogisticService::Hilife->value ) ),
			'應有 rate id 含 HILIFE（05）後綴'
		);
		$this->assertNotEmpty(
			\array_filter( $rate_ids, static fn( string $id ): bool => \str_contains( $id, PaynowLogisticService::Tcat->value ) ),
			'應有 rate id 含 TCAT（06）後綴'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_每個rate帶正確service_id_meta(): void {
		// Given: enabled_methods = SEVEN, FAMI
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI' ] ] );
		$method = new WC_PaynowLogisticsShipping();
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// Then: 至少一個 rate 的 meta_data 含 'service_id' 且值為 PaynowLogisticService value
		$has_seven_meta = false;
		$has_fami_meta  = false;
		foreach ( $rates as $rate ) {
			$meta = $rate->get_meta_data();
			if ( isset( $meta['service_id'] ) && $meta['service_id'] === PaynowLogisticService::Seven->value ) {
				$has_seven_meta = true;
			}
			if ( isset( $meta['service_id'] ) && $meta['service_id'] === PaynowLogisticService::Fami->value ) {
				$has_fami_meta = true;
			}
		}
		$this->assertTrue( $has_seven_meta, 'SEVEN rate 應帶 service_id=01 meta' );
		$this->assertTrue( $has_fami_meta, 'FAMI rate 應帶 service_id=03 meta' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_每個rate的label帶對應中文標籤(): void {
		// Given: enabled_methods = SEVEN, TCAT
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'TCAT' ] ] );
		$method = new WC_PaynowLogisticsShipping();
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// 至少有一個 rate label 含 '7-11'，另一個含 '黑貓'
		$labels          = \array_map( static fn( \WC_Shipping_Rate $r ): string => $r->get_label(), $rates );
		$has_seven_label = false;
		$has_tcat_label  = false;
		foreach ( $labels as $label ) {
			if ( \str_contains( $label, '7-11' ) || \str_contains( $label, 'SEVEN' ) || \str_contains( $label, '7' ) ) {
				$has_seven_label = true;
			}
			if ( \str_contains( $label, '黑貓' ) || \str_contains( $label, 'TCAT' ) ) {
				$has_tcat_label = true;
			}
		}
		$this->assertTrue( $has_seven_label, 'SEVEN rate label 應含 7-11 相關文字' );
		$this->assertTrue( $has_tcat_label, 'TCAT rate label 應含黑貓相關文字' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_每個rate沿用設定固定運費(): void {
		// Given: cost = 80，enabled = SEVEN, FAMI
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI' ] ] );
		$method       = new WC_PaynowLogisticsShipping();
		$method->cost = '80';
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// Then: 每個 rate 的運費都是 80
		$this->assertNotEmpty( $rates );
		foreach ( $rates as $rate ) {
			$this->assertSame( 80.0, (float) $rate->get_cost(), 'rate 運費應等於後台設定值 80' );
		}
	}

	// ========== enabled_methods 邊界 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_enabled_methods為空時不產rate(): void {
		// Given: enabled_methods 空
		$this->enable_paynow_logistics( [ 'enabled_methods' => [] ] );
		$method = new WC_PaynowLogisticsShipping();
		$method->calculate_shipping( [] );

		// Then: 不產生任何 rate
		$this->assertEmpty( $method->rates ?? [], 'enabled_methods 為空時不應產生任何 rate' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_get_supported_services僅含啟用的service(): void {
		// Given: enabled_methods = SEVEN, HILIFE
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'HILIFE' ] ] );
		$method    = new WC_PaynowLogisticsShipping();
		$supported = $method->get_supported_services();

		// Then: 僅含 SEVEN, HILIFE 對應的 PaynowLogisticService
		$service_values = \array_map(
			static fn( PaynowLogisticService $s ): string => $s->value,
			$supported
		);
		$this->assertContains( PaynowLogisticService::Seven->value, $service_values );
		$this->assertContains( PaynowLogisticService::Hilife->value, $service_values );
		$this->assertNotContains( PaynowLogisticService::Fami->value, $service_values );
		$this->assertNotContains( PaynowLogisticService::Tcat->value, $service_values );
	}

	// ========== method 註冊於 woocommerce_shipping_methods ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_method註冊於woocommerce_shipping_methods(): void {
		// Given: 啟用 provider 並註冊 hooks
		ProviderRegister::register_hooks();

		// When
		$methods = \apply_filters( 'woocommerce_shipping_methods', [] );

		// Then: 含 WC_PaynowLogisticsShipping
		$this->assertContains( WC_PaynowLogisticsShipping::class, $methods );
	}

	// ========== is_chosen() ==========

	/**
	 * 建立已選用 PayNow 物流運送方式的訂單（含 service_id meta 模擬選定 rate）
	 *
	 * @param string $service_id PaynowLogisticService value（01/03/05/06）
	 * @return \WC_Order
	 */
	private function create_order_with_paynow_shipping( string $service_id = '01' ): \WC_Order {
		$order         = \wc_create_order();
		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_id( WC_PaynowLogisticsShipping::METHOD_ID );
		$shipping_item->set_method_title( 'PayNow 7-11 超商取貨' );
		// 模擬 set_shipping_rate() 搬入的 rate meta（service_id）
		$shipping_item->add_meta_data( 'service_id', $service_id, true );
		$order->add_item( $shipping_item );
		$order->save();
		return $order;
	}

	/**
	 * 建立未選用 PayNow 物流的訂單（選用其他運送方式）
	 *
	 * @return \WC_Order
	 */
	private function create_order_without_paynow_shipping(): \WC_Order {
		$order         = \wc_create_order();
		$shipping_item = new \WC_Order_Item_Shipping();
		$shipping_item->set_method_id( 'other_shipping_method' );
		$shipping_item->set_method_title( '其他運送方式' );
		$order->add_item( $shipping_item );
		$order->save();
		return $order;
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_is_chosen_選用paynow物流時回true(): void {
		// Given: 訂單選用 PayNow 物流
		$order = $this->create_order_with_paynow_shipping( '01' );

		// When / Then
		$this->assertTrue(
			WC_PaynowLogisticsShipping::is_chosen( $order ),
			'選用 PayNow 物流時 is_chosen 應回 true'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_is_chosen_未選用paynow物流時回false(): void {
		// Given: 訂單選用其他運送方式
		$order = $this->create_order_without_paynow_shipping();

		// When / Then
		$this->assertFalse(
			WC_PaynowLogisticsShipping::is_chosen( $order ),
			'未選用 PayNow 物流時 is_chosen 應回 false'
		);
	}

	// ========== save_checkout_meta() 寫 _pc_paynow_logistics_service_id ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_save_checkout_meta_依選定rate的service_id寫入meta(): void {
		// Given: 選定 SEVEN rate（service_id=01）
		$order = $this->create_order_with_paynow_shipping( PaynowLogisticService::Seven->value );

		// When
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, [] );

		// Then: _pc_paynow_logistics_service_id 應為 '01'
		$fresh_order = \wc_get_order( $order->get_id() );
		\assert( $fresh_order instanceof \WC_Order );
		$meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame(
			PaynowLogisticService::Seven->value,
			$meta->get_service_id(),
			'save_checkout_meta 應將選定 rate 的 service_id（01）寫入 _pc_paynow_logistics_service_id'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_save_checkout_meta_選定FAMI_rate寫入03(): void {
		// Given: 選定 FAMI rate（service_id=03）
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI', 'HILIFE', 'TCAT' ] ] );
		$order = $this->create_order_with_paynow_shipping( PaynowLogisticService::Fami->value );

		// When
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, [] );

		// Then
		$fresh_order = \wc_get_order( $order->get_id() );
		\assert( $fresh_order instanceof \WC_Order );
		$meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( PaynowLogisticService::Fami->value, $meta->get_service_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_save_checkout_meta_選定TCAT_rate寫入06(): void {
		// Given: 選定 TCAT rate（service_id=06，黑貓宅配）
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI', 'HILIFE', 'TCAT' ] ] );
		$order = $this->create_order_with_paynow_shipping( PaynowLogisticService::Tcat->value );

		// When
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, [] );

		// Then
		$fresh_order = \wc_get_order( $order->get_id() );
		\assert( $fresh_order instanceof \WC_Order );
		$meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( PaynowLogisticService::Tcat->value, $meta->get_service_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_save_checkout_meta_未選用paynow物流時不寫meta(): void {
		// Given: 訂單選用其他運送方式
		$order = $this->create_order_without_paynow_shipping();

		// When
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, [] );

		// Then: _pc_paynow_logistics_service_id 應為空（未寫入）
		$fresh_order = \wc_get_order( $order->get_id() );
		\assert( $fresh_order instanceof \WC_Order );
		$meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( '', $meta->get_service_id(), '未選用 PayNow 物流時不應寫入 service_id meta' );
	}

	// ========== 白名單安全（非法 service_id 不寫入） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_save_checkout_meta_非法service_id不寫入(): void {
		// Given: rate meta 帶非法值（非 PaynowLogisticService 合法 value）
		$order = $this->create_order_with_paynow_shipping( 'EVIL_INJECTED' );

		// When
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, [] );

		// Then: 非法值不應寫入 meta
		$fresh_order = \wc_get_order( $order->get_id() );
		\assert( $fresh_order instanceof \WC_Order );
		$meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( '', $meta->get_service_id(), '非白名單 service_id 不應被寫入' );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_save_checkout_meta_未啟用的合法service_id不寫入(): void {
		// Given: enabled_methods = SEVEN, FAMI（未含 HILIFE）；選定 rate meta 為 HILIFE（05）
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI' ] ] );
		$order = $this->create_order_with_paynow_shipping( PaynowLogisticService::Hilife->value );

		// When
		WC_PaynowLogisticsShipping::save_checkout_meta( $order, [] );

		// Then: HILIFE 雖是合法 enum，但不在 enabled_methods，不應寫入
		$fresh_order = \wc_get_order( $order->get_id() );
		\assert( $fresh_order instanceof \WC_Order );
		$meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( '', $meta->get_service_id(), '未啟用的 service_id 不應被寫入' );
	}

	// ========== 後台設定預設值 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_cost預設為0(): void {
		$method = new WC_PaynowLogisticsShipping();
		$this->assertSame( '0', $method->cost );
	}
}
