<?php
/**
 * PayNow Gateway 骨架整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 11
 *   - specs/open-issue/paynow-execution-plan.md §生命週期
 *   - provider-guide.rule.md §Adding a New Payment Provider
 *
 * Cycle 1 骨架測試範圍：
 *   - 可實例化
 *   - const ID='paynow'
 *   - extends AbstractPaymentGateway
 *   - implements IPaymentProvider
 *   - get_supported_payment_methods() 回傳 7 個合法付款方式（排除 ApplePayDeferred）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowGatewayTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow Gateway 骨架測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowGatewayTest extends TestCase {

	/** 每次測試前設定 paynow 環境 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => 'pk_test_dummy',
				'private_key' => 'sk_test_dummy',
			]
		);
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\delete_option( ProviderUtils::get_option_name( 'paynow' ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PaynowGateway 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway可被實例化(): void {
		$gateway = new PaynowGateway();
		$this->assertInstanceOf( PaynowGateway::class, $gateway );
	}

	/**
	 * PaynowGateway::ID 常數等於 'paynow'
	 * 依 provider-guide.rule.md：每個 gateway 需定義 const ID
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_ID常數等於paynow(): void {
		$this->assertSame( 'paynow', PaynowGateway::ID );
	}

	/**
	 * PaynowGateway extends AbstractPaymentGateway
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_extends_AbstractPaymentGateway(): void {
		$gateway = new PaynowGateway();
		$this->assertInstanceOf( AbstractPaymentGateway::class, $gateway );
	}

	/**
	 * PaynowGateway implements IPaymentProvider
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_implements_IPaymentProvider(): void {
		$gateway = new PaynowGateway();
		$this->assertInstanceOf( IPaymentProvider::class, $gateway );
	}

	/**
	 * get_supported_payment_methods() 回傳 7 個合法付款方式（排除 ApplePayDeferred）
	 * 依 Q1 裁決：CreditCard / CreditCardInstallment / ATM / ConvenienceStore /
	 *            LINEPayOnline / LINEPayOffline / ApplePay
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_get_supported_payment_methods回傳7個方式(): void {
		$gateway = new PaynowGateway();
		$methods = $gateway->get_supported_payment_methods();

		$this->assertCount( 7, $methods, 'get_supported_payment_methods() 應回傳 7 個付款方式（排除 ApplePayDeferred）' );
	}

	/**
	 * get_supported_payment_methods() 不含 ApplePayDeferred
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_supported_methods不含ApplePayDeferred(): void {
		$gateway = new PaynowGateway();
		$methods = $gateway->get_supported_payment_methods();

		$method_values = array_map(
			fn( $m ) => is_string( $m ) ? $m : $m->value,
			$methods
		);

		$this->assertNotContains(
			'ApplePayDeferred',
			$method_values,
			'get_supported_payment_methods() 不應包含 ApplePayDeferred（Q1 排除）'
		);
	}
}
