<?php
/**
 * Logistics\ProviderRegister 對 PAYUNi 的整合測試
 *
 * 驗證 PAYUNi provider 與綠界並存註冊：
 *   - 啟用 payuni_logistics → register_hooks() 後進 ProviderUtils::$container。
 *   - 未啟用 → 不進容器。
 *   - woocommerce_shipping_methods filter 同時含綠界與 PAYUNi 運送方式。
 *   - get_registered_provider_dtos() 同時回傳兩 provider 的 BaseSettingsDTO。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PayuniLogisticsRegisterTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi ProviderRegister 測試類別
 *
 * @group integration
 * @group logistics
 * @group payuni
 */
final class PayuniLogisticsRegisterTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		PayuniLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );
	}

	public function tear_down(): void {
		\putenv( 'API_MODE=mock' );
		PayuniLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PayuniLogisticsProvider::ID ) );
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_is_enabled讀woocommerce_payuni_logistics_settings(): void {
		$this->assertSame(
			'woocommerce_payuni_logistics_settings',
			ProviderUtils::get_option_name( PayuniLogisticsProvider::ID )
		);
	}

	// ========== 啟用 → 進容器 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用payuni_logistics後register_hooks進入容器(): void {
		$this->enable_provider( PayuniLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];

		ProviderRegister::register_hooks();

		$this->assertArrayHasKey( PayuniLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertInstanceOf(
			PayuniLogisticsProvider::class,
			ProviderUtils::get_provider( PayuniLogisticsProvider::ID )
		);
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_未啟用payuni_logistics時不進容器(): void {
		$this->disable_provider( PayuniLogisticsProvider::ID );
		ProviderUtils::$container = [];

		ProviderRegister::register_hooks();

		$this->assertArrayNotHasKey( PayuniLogisticsProvider::ID, ProviderUtils::$container );
	}

	// ========== 兩 provider 並存 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_綠界與PAYUNi並存啟用時皆進容器(): void {
		$this->enable_provider( EcpayLogisticsProvider::ID, [ 'mode' => 'test' ] );
		$this->enable_provider( PayuniLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];

		ProviderRegister::register_hooks();

		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertArrayHasKey( PayuniLogisticsProvider::ID, ProviderUtils::$container );
	}

	// ========== shipping method filter ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用後shipping_methods_filter含PAYUNi運送方式(): void {
		$this->enable_provider( PayuniLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderRegister::register_hooks();

		$methods = \apply_filters( 'woocommerce_shipping_methods', [] );

		$this->assertIsArray( $methods );
		$found = false;
		foreach ( $methods as $method ) {
			if ( is_string( $method ) && str_contains( $method, 'PayuniLogisticsShipping' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'woocommerce_shipping_methods 應含 PAYUNi 物流 WC_Shipping_Method' );
	}

	// ========== get_registered_provider_dtos ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_registered_provider_dtos同時含兩provider(): void {
		$dtos = ProviderRegister::get_registered_provider_dtos();

		$this->assertIsArray( $dtos );
		$this->assertArrayHasKey( PayuniLogisticsProvider::ID, $dtos );
		$this->assertInstanceOf( BaseSettingsDTO::class, $dtos[ PayuniLogisticsProvider::ID ] );
		$this->assertSame( PayuniLogisticsProvider::ID, $dtos[ PayuniLogisticsProvider::ID ]->id );
		// 綠界仍在（兩 provider 並存）
		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, $dtos );
	}
}
