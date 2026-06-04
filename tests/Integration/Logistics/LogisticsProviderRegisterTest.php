<?php
/**
 * Logistics\ProviderRegister 整合測試（階段四 — Red Gate）
 *
 * 鏡像 Invoice\ProviderRegister 行為：
 *   - 啟用 ecpay_logistics → register_hooks() 後進 ProviderUtils::$container。
 *   - 未啟用 → 不進容器。
 *   - is_enabled 讀 woocommerce_ecpay_logistics_settings。
 *   - get_registered_provider_dtos() 回傳 BaseSettingsDTO 陣列。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter LogisticsProviderRegisterTest" 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * Logistics\ProviderRegister 測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsProviderRegisterTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		EcpayLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );
	}

	public function tear_down(): void {
		\putenv( 'API_MODE' );
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_is_enabled讀woocommerce_ecpay_logistics_settings(): void {
		$this->assertSame(
			'woocommerce_ecpay_logistics_settings',
			ProviderUtils::get_option_name( EcpayLogisticsProvider::ID )
		);
	}

	// ========== 啟用 → 進容器 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用ecpay_logistics後register_hooks進入ProviderUtils容器(): void {
		// Given: 啟用 provider
		$this->enable_provider( EcpayLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];

		// When
		ProviderRegister::register_hooks();

		// Then: 已進容器
		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertInstanceOf(
			EcpayLogisticsProvider::class,
			ProviderUtils::get_provider( EcpayLogisticsProvider::ID )
		);
	}

	// ========== 未啟用 → 不進容器 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_未啟用ecpay_logistics時register_hooks不進容器(): void {
		// Given: 停用 provider
		$this->disable_provider( EcpayLogisticsProvider::ID );
		ProviderUtils::$container = [];

		// When
		ProviderRegister::register_hooks();

		// Then: 未進容器
		$this->assertArrayNotHasKey( EcpayLogisticsProvider::ID, ProviderUtils::$container );
	}

	// ========== get_registered_provider_dtos ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_registered_provider_dtos回傳BaseSettingsDTO陣列(): void {
		// When
		$dtos = ProviderRegister::get_registered_provider_dtos();

		// Then
		$this->assertIsArray( $dtos );
		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, $dtos );
		$this->assertInstanceOf( BaseSettingsDTO::class, $dtos[ EcpayLogisticsProvider::ID ] );
		$this->assertSame( EcpayLogisticsProvider::ID, $dtos[ EcpayLogisticsProvider::ID ]->id );
	}

	// ========== shipping method filter ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用後woocommerce_shipping_methods_filter加入物流運送方式(): void {
		// Given: 啟用 provider
		$this->enable_provider( EcpayLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderRegister::register_hooks();

		// When: 透過 filter 取得運送方式
		$methods = \apply_filters( 'woocommerce_shipping_methods', [] );

		// Then: 含綠界物流 WC_Shipping_Method
		$this->assertIsArray( $methods );
		$found = false;
		foreach ( $methods as $method ) {
			if ( is_string( $method ) && str_contains( $method, 'EcpayLogisticsShipping' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'woocommerce_shipping_methods 應含綠界物流 WC_Shipping_Method' );
	}
}
