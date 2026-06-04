<?php
/**
 * WC_EcpayLogisticsShipping 整合測試（階段四 — Red Gate，風險 R7）
 *
 * WC_Shipping_Method 為專案全新領域。本切片只做最小驗證：
 *   - method 註冊於 woocommerce_shipping_methods filter。
 *   - calculate_shipping 回固定運費（T4，後台 cost，預設 0）。
 *   - enabled_methods 過濾出對應的物流子類型運送選項。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter WC_EcpayLogisticsShippingTest" 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\WC_EcpayLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * WC_EcpayLogisticsShipping 測試類別
 *
 * @group integration
 * @group logistics
 */
final class WC_EcpayLogisticsShippingTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		EcpayLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );
		$this->enable_logistics();
	}

	public function tear_down(): void {
		\putenv( 'API_MODE' );
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 ecpay_logistics（B2C，test 模式）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 */
	private function enable_logistics( array $extra = [] ): void {
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			array_merge(
				[
					'mode'            => 'test',
					'account_type'    => 'b2c',
					'enabled_methods' => [ 'FAMI', 'UNIMART' ],
				],
				$extra
			)
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_WC_EcpayLogisticsShipping繼承WC_Shipping_Method(): void {
		$method = new WC_EcpayLogisticsShipping();
		$this->assertInstanceOf( \WC_Shipping_Method::class, $method );
	}

	// ========== method 註冊 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_method註冊於woocommerce_shipping_methods(): void {
		// Given: 啟用 provider 並註冊 hooks
		ProviderRegister::register_hooks();

		// When
		$methods = \apply_filters( 'woocommerce_shipping_methods', [] );

		// Then
		$this->assertContains( WC_EcpayLogisticsShipping::class, $methods );
	}

	// ========== calculate_shipping 固定運費 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_calculate_shipping回固定運費_預設0(): void {
		// Given: 未設定 cost（預設 0）
		$method = new WC_EcpayLogisticsShipping();

		// When: 計算運費（攔截 add_rate）
		$captured = [];
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// Then: 至少有一個 rate，運費為 0
		$this->assertNotEmpty( $rates, 'calculate_shipping 應產生運費 rate' );
		$rate = reset( $rates );
		$this->assertSame( '0', (string) (float) $rate->get_cost() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_calculate_shipping回固定運費_後台設定值60(): void {
		// Given: cost 設為 60
		$method       = new WC_EcpayLogisticsShipping();
		$method->cost = '60';

		// When
		$method->calculate_shipping( [] );
		$rates = $method->rates ?? [];

		// Then
		$this->assertNotEmpty( $rates );
		$rate = reset( $rates );
		$this->assertSame( 60.0, (float) $rate->get_cost() );
	}

	// ========== enabled_methods 過濾 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_enabled_methods過濾出對應運送選項(): void {
		// Given: enabled_methods = FAMI, UNIMART
		$method    = new WC_EcpayLogisticsShipping();
		$supported = $method->get_supported_sub_types();

		// Then: 僅含已啟用子類型
		$this->assertContains( 'FAMI', $supported );
		$this->assertContains( 'UNIMART', $supported );
		$this->assertNotContains( 'HILIFE', $supported );
		$this->assertNotContains( 'HOME', $supported );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_enabled_methods為空時無支援子類型(): void {
		// Given: enabled_methods 空
		$this->enable_logistics( [ 'enabled_methods' => [] ] );

		// When
		$method    = new WC_EcpayLogisticsShipping();
		$supported = $method->get_supported_sub_types();

		// Then
		$this->assertSame( [], $supported );
	}
}
