<?php
/**
 * PAYUNi UNi Embed V3 Provider Register 整合測試（TDD Red 階段）
 *
 * 測試目標：
 *   J7\PowerCheckout\Domains\Payment\ProviderRegister（已存在）
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway（尚未存在 → Red）
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO（尚未存在 → Red）
 *
 * 驗證三件事：
 *   1. ProviderRegister::$gateway_services 包含 'payuni_uni_embed' key
 *   2. payuni_uni_embed 啟用後，woocommerce_payment_gateways filter 回傳陣列中含 PayuniUniEmbedGateway
 *   3. ProviderUtils::get_option_name('payuni_uni_embed') = 'woocommerce_payuni_uni_embed_settings'
 *
 * 規格依據：
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 05-08（register）
 *   - provider-guide.rule.md §Adding a New Payment Provider Step 5
 *   - 風格對齊既有 PayuniProviderRegisterTest.php
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni_uni_embed"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed Provider Register 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedRegisterTest extends TestCase {

	private const PROVIDER_ID = 'payuni_uni_embed';
	private const OPTION_NAME = 'woocommerce_payuni_uni_embed_settings';

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( self::OPTION_NAME );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke）：Provider ID 規範 ==========

	/**
	 * PayuniUniEmbedSettingsDTO::ID 等於 'payuni_uni_embed'
	 * 確認 Provider ID 已拍板（不得更名）
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_PayuniUniEmbedSettingsDTO_ID常數等於payuni_uni_embed(): void {
		$this->assertSame( 'payuni_uni_embed', PayuniUniEmbedSettingsDTO::ID );
	}

	/**
	 * ProviderUtils::get_option_name('payuni_uni_embed') 回傳正確選項名稱
	 * 對齊既有命名慣例 woocommerce_{id}_settings
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_ProviderID_option_name命名正確(): void {
		$expected = self::OPTION_NAME;
		$actual   = ProviderUtils::get_option_name( self::PROVIDER_ID );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * payuni_uni_embed 選項名稱與 payuni_upp 不同
	 * 確保兩個 gateway 的設定互相隔離
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_option_name與UPP不同(): void {
		$uni_embed_option = ProviderUtils::get_option_name( self::PROVIDER_ID );
		$upp_option       = ProviderUtils::get_option_name( 'payuni_upp' );

		$this->assertNotSame( $uni_embed_option, $upp_option );
	}

	// ========== ProviderRegister::$gateway_services 包含 payuni_uni_embed ==========

	/**
	 * ProviderRegister::$gateway_services 包含 'payuni_uni_embed' key
	 * 依 provider-guide.rule.md Step 5：Register in Payment\ProviderRegister::$gateway_services
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_ProviderRegister_gateway_services包含payuni_uni_embed(): void {
		$reflection = new \ReflectionClass( ProviderRegister::class );
		$property   = $reflection->getProperty( 'gateway_services' );
		$property->setAccessible( true );

		/** @var array<string, string> $services */
		$services = $property->getValue();

		$this->assertArrayHasKey(
			'payuni_uni_embed',
			$services,
			'ProviderRegister::$gateway_services 未包含 payuni_uni_embed。' .
			'需在 ProviderRegister::$gateway_services 中加入 PayuniUniEmbedGateway::ID => PayuniUniEmbedGateway::class'
		);
	}

	/**
	 * ProviderRegister::$gateway_services['payuni_uni_embed'] 指向 PayuniUniEmbedGateway::class
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_ProviderRegister_payuni_uni_embed指向PayuniUniEmbedGateway(): void {
		$reflection = new \ReflectionClass( ProviderRegister::class );
		$property   = $reflection->getProperty( 'gateway_services' );
		$property->setAccessible( true );

		/** @var array<string, string> $services */
		$services = $property->getValue();

		$this->assertSame(
			PayuniUniEmbedGateway::class,
			$services['payuni_uni_embed'] ?? '',
			'ProviderRegister::$gateway_services[\'payuni_uni_embed\'] 應指向 PayuniUniEmbedGateway::class'
		);
	}

	// ========== woocommerce_payment_gateways filter ==========

	/**
	 * payuni_uni_embed 啟用時，apply_filters('woocommerce_payment_gateways') 含 PayuniUniEmbedGateway
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_woocommerce_payment_gateways_filter含PayuniUniEmbedGateway(): void {
		$gateways = \apply_filters( 'woocommerce_payment_gateways', [] );

		$this->assertContains(
			PayuniUniEmbedGateway::class,
			$gateways,
			'woocommerce_payment_gateways filter 未含 PayuniUniEmbedGateway::class。' .
			'需在 ProviderRegister::add_method() 加入 PayuniUniEmbedGateway。'
		);
	}

	// ========== ProviderUtils 啟用 / 停用流程 ==========

	/**
	 * payuni_uni_embed 啟用後 is_enabled 回傳 true
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_啟用payuni_uni_embed後is_enabled回傳true(): void {
		$this->enable_provider( self::PROVIDER_ID );
		$this->assert_provider_enabled( self::PROVIDER_ID );
	}

	/**
	 * payuni_uni_embed 停用後 is_enabled 回傳 false
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_停用payuni_uni_embed後is_enabled回傳false(): void {
		$this->enable_provider( self::PROVIDER_ID );
		$this->disable_provider( self::PROVIDER_ID );
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * payuni_uni_embed 無設定時預設停用
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_payuni_uni_embed_無設定時預設停用(): void {
		\delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * payuni_uni_embed 與 payuni_upp 啟停用彼此獨立（不互相干擾）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_payuni_uni_embed與payuni_upp啟停用獨立(): void {
		// 啟用 UPP，停用 UNi Embed
		$this->enable_provider( 'payuni_upp' );
		$this->assert_provider_enabled( 'payuni_upp' );
		$this->assert_provider_disabled( self::PROVIDER_ID );

		// 啟用 UNi Embed
		$this->enable_provider( self::PROVIDER_ID );
		$this->assert_provider_enabled( self::PROVIDER_ID );
		// UPP 仍應維持啟用
		$this->assert_provider_enabled( 'payuni_upp' );
	}
}
