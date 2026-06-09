<?php
/**
 * PAYUNi UPP Provider Register 整合測試（TDD Red 階段）
 *
 * 測試目標：
 *   J7\PowerCheckout\Domains\Payment\ProviderRegister（已存在）
 *   J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway（尚未存在 → Red）
 *
 * 規格依據：
 *   - provider-guide.rule.md §Adding a New Payment Provider
 *   - inc/classes/Domains/Payment/ProviderRegister.php §$gateway_services
 *   - 既有 ProviderUtilsPaymentTest 風格
 *
 * 驗證三件事：
 *   1. ProviderRegister::$gateway_services 包含 'payuni_upp' key（靜態屬性反射）
 *   2. payuni_upp 啟用後，woocommerce_payment_gateways filter 回傳陣列中含 PayuniUppGateway
 *   3. ProviderUtils::get_option_name('payuni_upp') = 'woocommerce_payuni_upp_settings'
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP Provider Register 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniProviderRegisterTest extends TestCase {

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( PayuniSettingsDTO::ID ) );
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	// ========== 冒煙（Smoke）: Provider ID 規範 ==========

	/**
	 * ProviderUtils::get_option_name('payuni_upp') 回傳 'woocommerce_payuni_upp_settings'
	 * 確認 option name 命名慣例對齊既有 ecpay_aio / newebpay_mpg
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_ProviderID_option_name命名正確(): void {
		$expected = 'woocommerce_payuni_upp_settings';
		$actual   = ProviderUtils::get_option_name( PayuniSettingsDTO::ID );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * PayuniSettingsDTO::ID 等於 'payuni_upp'
	 * 確認 Provider ID 已拍板（不得更名）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_PayuniSettingsDTO_ID常數等於payuni_upp(): void {
		$this->assertSame( 'payuni_upp', PayuniSettingsDTO::ID );
	}

	// ========== ProviderRegister::$gateway_services 包含 payuni_upp ==========

	/**
	 * ProviderRegister::$gateway_services 包含 'payuni_upp' key
	 * 依 provider-guide.rule.md §Adding a New Payment Provider Step 5
	 * 「Register in Payment\ProviderRegister::$gateway_services」
	 *
	 * 反射讀取 private static 屬性（測試框架允許反射讀取靜態私有屬性）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_ProviderRegister_gateway_services包含payuni_upp(): void {
		$reflection = new \ReflectionClass( ProviderRegister::class );
		$property   = $reflection->getProperty( 'gateway_services' );
		$property->setAccessible( true );

		/** @var array<string, string> $services */
		$services = $property->getValue();

		$this->assertArrayHasKey(
			'payuni_upp',
			$services,
			'ProviderRegister::$gateway_services 未包含 payuni_upp。' .
			'需在 ProviderRegister::$gateway_services 中加入 PayuniUppGateway::ID => PayuniUppGateway::class'
		);
	}

	/**
	 * ProviderRegister::$gateway_services['payuni_upp'] 指向 PayuniUppGateway::class
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_ProviderRegister_payuni_upp_指向PayuniUppGateway(): void {
		$reflection = new \ReflectionClass( ProviderRegister::class );
		$property   = $reflection->getProperty( 'gateway_services' );
		$property->setAccessible( true );

		/** @var array<string, string> $services */
		$services = $property->getValue();

		$this->assertSame(
			PayuniUppGateway::class,
			$services['payuni_upp'] ?? '',
			'ProviderRegister::$gateway_services[\'payuni_upp\'] 應指向 PayuniUppGateway::class'
		);
	}

	// ========== woocommerce_payment_gateways filter ==========

	/**
	 * payuni_upp 啟用時，apply_filters('woocommerce_payment_gateways') 回傳陣列中含 PayuniUppGateway::class
	 * 模擬 WooCommerce 取得所有金流列表的流程
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_woocommerce_payment_gateways_filter含PayuniUppGateway(): void {
		// 先套用 ProviderRegister 的 filter
		// ProviderRegister::register_hooks() 已在 Bootstrap 呼叫，
		// 此處直接以 apply_filters 取得 gateways
		$gateways = \apply_filters( 'woocommerce_payment_gateways', [] );

		$this->assertContains(
			PayuniUppGateway::class,
			$gateways,
			'woocommerce_payment_gateways filter 回傳結果不含 PayuniUppGateway::class。' .
			'需在 ProviderRegister::add_method() 加入 PayuniUppGateway。'
		);
	}

	// ========== ProviderUtils 啟用 / 停用流程 ==========

	/**
	 * payuni_upp 啟用後 is_enabled 回傳 true
	 * 對齊既有 ProviderUtilsPaymentTest 的設計
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_啟用payuni_upp後is_enabled回傳true(): void {
		$this->enable_provider( PayuniSettingsDTO::ID );

		$this->assert_provider_enabled( PayuniSettingsDTO::ID );
	}

	/**
	 * payuni_upp 停用後 is_enabled 回傳 false
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_停用payuni_upp後is_enabled回傳false(): void {
		$this->enable_provider( PayuniSettingsDTO::ID );
		$this->disable_provider( PayuniSettingsDTO::ID );

		$this->assert_provider_disabled( PayuniSettingsDTO::ID );
	}

	/**
	 * payuni_upp 無設定時預設停用
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payuni_upp_無設定時預設停用(): void {
		\delete_option( ProviderUtils::get_option_name( PayuniSettingsDTO::ID ) );

		$this->assert_provider_disabled( PayuniSettingsDTO::ID );
	}
}
