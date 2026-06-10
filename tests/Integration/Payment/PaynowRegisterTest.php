<?php
/**
 * PayNow Provider Register 整合測試（TDD Red 階段）
 *
 * 測試目標（部分尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\ProviderRegister（已存在）
 *   J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway（尚未存在 → Red）
 *   J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO（尚未存在 → Red）
 *
 * 驗證三件事：
 *   1. ProviderRegister::$gateway_services 包含 'paynow' key
 *   2. paynow 啟用後，woocommerce_payment_gateways filter 回傳陣列中含 PaynowGateway
 *   3. ProviderUtils::get_option_name('paynow') = 'woocommerce_paynow_settings'
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 12（register）
 *   - provider-guide.rule.md §Adding a New Payment Provider Step 5
 *   - 風格對齊既有 PayuniUniEmbedRegisterTest.php
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowRegisterTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow Provider Register 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowRegisterTest extends TestCase {

	private const PROVIDER_ID = 'paynow';
	private const OPTION_NAME = 'woocommerce_paynow_settings';

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( self::OPTION_NAME );
		if ( \method_exists( PaynowSettingsDTO::class, 'reset' ) ) {
			PaynowSettingsDTO::reset();
		}
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke）：Provider ID 規範 ==========

	/**
	 * PaynowSettingsDTO::ID 等於 'paynow'
	 * 確認 Provider ID 已拍板（不得更名）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaynowSettingsDTO_ID常數等於paynow(): void {
		$this->assertSame( 'paynow', PaynowSettingsDTO::ID );
	}

	/**
	 * ProviderUtils::get_option_name('paynow') 回傳正確選項名稱
	 * 對齊既有命名慣例 woocommerce_{id}_settings
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_ProviderID_option_name命名正確(): void {
		$expected = self::OPTION_NAME;
		$actual   = ProviderUtils::get_option_name( self::PROVIDER_ID );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * paynow 選項名稱與 payuni_upp 不同
	 * 確保不同 gateway 的設定完全隔離
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_option_name與payuni_upp不同(): void {
		$paynow_option = ProviderUtils::get_option_name( self::PROVIDER_ID );
		$upp_option    = ProviderUtils::get_option_name( 'payuni_upp' );

		$this->assertNotSame( $paynow_option, $upp_option );
	}

	/**
	 * paynow 選項名稱與 payuni_uni_embed 不同
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_option_name與payuni_uni_embed不同(): void {
		$paynow_option    = ProviderUtils::get_option_name( self::PROVIDER_ID );
		$uni_embed_option = ProviderUtils::get_option_name( 'payuni_uni_embed' );

		$this->assertNotSame( $paynow_option, $uni_embed_option );
	}

	// ========== ProviderRegister::$gateway_services 包含 paynow ==========

	/**
	 * ProviderRegister::$gateway_services 包含 'paynow' key
	 * 依 provider-guide.rule.md Step 5：Register in Payment\ProviderRegister::$gateway_services
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_ProviderRegister_gateway_services包含paynow(): void {
		$reflection = new \ReflectionClass( ProviderRegister::class );
		$property   = $reflection->getProperty( 'gateway_services' );
		$property->setAccessible( true );

		/** @var array<string, string> $services */
		$services = $property->getValue();

		$this->assertArrayHasKey(
			'paynow',
			$services,
			'ProviderRegister::$gateway_services 未包含 paynow。' .
			'需在 ProviderRegister::$gateway_services 中加入 PaynowGateway::ID => PaynowGateway::class'
		);
	}

	/**
	 * ProviderRegister::$gateway_services['paynow'] 指向 PaynowGateway::class
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_ProviderRegister_paynow指向PaynowGateway(): void {
		$reflection = new \ReflectionClass( ProviderRegister::class );
		$property   = $reflection->getProperty( 'gateway_services' );
		$property->setAccessible( true );

		/** @var array<string, string> $services */
		$services = $property->getValue();

		$this->assertSame(
			PaynowGateway::class,
			$services['paynow'] ?? '',
			'ProviderRegister::$gateway_services[\'paynow\'] 應指向 PaynowGateway::class'
		);
	}

	// ========== woocommerce_payment_gateways filter ==========

	/**
	 * paynow gateway 出現在 woocommerce_payment_gateways filter 輸出
	 * 依 paynow-implementation-plan §步驟 12：gateway 必須透過此 filter 注入
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_woocommerce_payment_gateways_filter含PaynowGateway(): void {
		$gateways = \apply_filters( 'woocommerce_payment_gateways', [] );

		$this->assertContains(
			PaynowGateway::class,
			$gateways,
			'woocommerce_payment_gateways filter 未含 PaynowGateway::class。' .
			'需在 ProviderRegister::add_method() 加入 PaynowGateway。'
		);
	}

	// ========== ProviderUtils 啟用 / 停用流程 ==========

	/**
	 * paynow 啟用後 is_enabled 回傳 true
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_啟用paynow後is_enabled回傳true(): void {
		$this->enable_provider( self::PROVIDER_ID );
		$this->assert_provider_enabled( self::PROVIDER_ID );
	}

	/**
	 * paynow 停用後 is_enabled 回傳 false
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_停用paynow後is_enabled回傳false(): void {
		$this->enable_provider( self::PROVIDER_ID );
		$this->disable_provider( self::PROVIDER_ID );
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * paynow 無設定時預設停用
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_paynow_無設定時預設停用(): void {
		\delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * paynow 與 payuni_upp 啟停用彼此獨立（不互相干擾）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_paynow與payuni_upp啟停用獨立(): void {
		// 啟用 UPP，停用 paynow
		$this->enable_provider( 'payuni_upp' );
		$this->assert_provider_enabled( 'payuni_upp' );
		$this->assert_provider_disabled( self::PROVIDER_ID );

		// 啟用 paynow
		$this->enable_provider( self::PROVIDER_ID );
		$this->assert_provider_enabled( self::PROVIDER_ID );
		// UPP 仍應維持啟用
		$this->assert_provider_enabled( 'payuni_upp' );
	}
}
