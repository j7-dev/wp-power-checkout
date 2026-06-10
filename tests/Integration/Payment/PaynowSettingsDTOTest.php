<?php
/**
 * PayNow PaynowSettingsDTO 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 9
 *   - .claude/skills/paynow/references/concepts.md §3 金鑰體系（PublicKey / PrivateKey）
 *   - .claude/skills/paynow/references/concepts.md §10 環境網域速查
 *
 * 欄位：public_key / private_key / mode / allowed_payment_methods / allow_installments / expire_days
 * mode=test → sandbox host；mode=prod → 正式 host
 * 憑證不寫死 prod（存 woocommerce_paynow_settings）
 * trim_invisible_deep 清憑證空白（含全形空白 / 零寬字元）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowSettingsDTOTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PaynowSettingsDTO 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowSettingsDTOTest extends TestCase {

	private const SANDBOX_HOST = 'https://sandboxapi.paynow.com.tw';
	private const PROD_HOST    = 'https://api.paynow.com.tw';
	private const PROVIDER_ID  = 'paynow';
	private const OPTION_NAME  = 'woocommerce_paynow_settings';

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\delete_option( self::OPTION_NAME );
		if ( \method_exists( PaynowSettingsDTO::class, 'reset' ) ) {
			PaynowSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 寫入 wp_options 模擬現有設定
	 *
	 * @param array<string, mixed> $value
	 */
	private function seed_option( array $value ): void {
		\update_option( self::OPTION_NAME, $value );
		if ( \method_exists( PaynowSettingsDTO::class, 'reset' ) ) {
			PaynowSettingsDTO::reset();
		}
	}

	// ========== 冒煙測試（Happy） ==========

	/**
	 * PaynowSettingsDTO 可被實例化
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_PaynowSettingsDTO可被實例化(): void {
		$dto = PaynowSettingsDTO::instance();
		$this->assertInstanceOf( PaynowSettingsDTO::class, $dto );
	}

	/**
	 * PaynowSettingsDTO::ID 常數等於 'paynow'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ID常數等於paynow(): void {
		$this->assertSame( 'paynow', PaynowSettingsDTO::ID );
	}

	// ========== mode → host 切換（Happy） ==========

	/**
	 * mode=test 時 API base_url 包含 sandbox 主機
	 * 依 concepts.md §10：sandbox = sandboxapi.paynow.com.tw
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_test模式使用sandbox主機(): void {
		$this->seed_option( [ 'mode' => 'test' ] );

		$dto = PaynowSettingsDTO::instance();

		$this->assertStringStartsWith( self::SANDBOX_HOST, $dto->base_url );
	}

	/**
	 * mode=prod 時 API base_url 使用正式主機
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_prod模式使用正式主機(): void {
		$this->seed_option(
			[
				'mode'        => 'prod',
				'public_key'  => 'pk_live_test',
				'private_key' => 'pk_private_test',
			]
		);

		$dto = PaynowSettingsDTO::instance();

		$this->assertStringStartsWith( self::PROD_HOST, $dto->base_url );
		$this->assertStringNotContainsString( 'sandbox', $dto->base_url );
	}

	// ========== 憑證不寫死（Security） ==========

	/**
	 * prod 模式憑證存 WC option，不寫死 production key
	 * 測試：prod 模式設定的 public_key / private_key 可被 DTO 讀取
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_prod憑證存option不寫死(): void {
		$this->seed_option(
			[
				'mode'        => 'prod',
				'public_key'  => 'pk_live_real_key_abc123',
				'private_key' => 'sk_live_real_key_xyz456',
			]
		);

		$dto = PaynowSettingsDTO::instance();

		$this->assertSame( 'pk_live_real_key_abc123', $dto->public_key );
		$this->assertSame( 'sk_live_real_key_xyz456', $dto->private_key );
	}

	// ========== trim 清憑證空白（Happy） ==========

	/**
	 * public_key 前後空白被 trim
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_trim_public_key前後空白被移除(): void {
		$this->seed_option(
			[
				'mode'       => 'test',
				'public_key' => '  pk_test_abc123  ',
			]
		);

		$dto = PaynowSettingsDTO::instance();
		$this->assertSame( 'pk_test_abc123', $dto->public_key );
	}

	/**
	 * private_key 前後空白被 trim
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_trim_private_key前後空白被移除(): void {
		$this->seed_option(
			[
				'mode'        => 'test',
				'private_key' => "\t sk_test_xyz789 \n",
			]
		);

		$dto = PaynowSettingsDTO::instance();
		$this->assertSame( 'sk_test_xyz789', $dto->private_key );
	}

	/**
	 * 全形空白與零寬字元被 trim_invisible_deep 移除
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_trim_全形空白與零寬字元被移除(): void {
		$this->seed_option(
			[
				'mode'        => 'test',
				'public_key'  => "\u{3000}pk_test_abc\u{200B}",
				'private_key' => "\u{FEFF}sk_test_xyz\u{3000}",
			]
		);

		$dto = PaynowSettingsDTO::instance();
		$this->assertSame( 'pk_test_abc', $dto->public_key );
		$this->assertSame( 'sk_test_xyz', $dto->private_key );
	}

	/**
	 * Trim 不應寫回 wp_options（無副作用）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_trim_讀取時不寫回wp_options(): void {
		$dirty_key = '  pk_test_abc123  ';
		$this->seed_option(
			[
				'mode'       => 'test',
				'public_key' => $dirty_key,
			]
		);

		PaynowSettingsDTO::instance();

		$raw = \get_option( self::OPTION_NAME );
		$this->assertIsArray( $raw );
		$this->assertSame( $dirty_key, $raw['public_key'] );
	}

	// ========== mode validate（Happy） ==========

	/**
	 * ProviderUtils::get_option_name('paynow') 回傳正確選項名稱
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_option_name命名正確(): void {
		$this->assertSame(
			self::OPTION_NAME,
			ProviderUtils::get_option_name( self::PROVIDER_ID )
		);
	}
}
