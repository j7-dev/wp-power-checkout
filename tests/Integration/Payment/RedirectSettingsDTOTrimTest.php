<?php
/**
 * RedirectSettingsDTO 讀取時自動 trim 整合測試
 * 對應 Issue #16，covers specs/features/settings/trim-key-whitespace.feature 規則 2
 *
 * 測試重點：
 *  1. wp_options 中既有「帶空白」的金鑰，DTO 實例化後屬性是乾淨的
 *  2. 讀取時 trim 不會主動寫回 wp_options（無副作用）
 *  3. 多種不可見字元都會被 trim
 *  4. signKey 在 mb_convert_encoding 之後仍會被 trim（雙保險）
 *  5. 中間不可見字元保留
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group payment
 * @group trim
 */
final class RedirectSettingsDTOTrimTest extends TestCase {

	/**
	 * 每次測試後清理 SLP 設定
	 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );
		parent::tear_down();
	}

	/**
	 * 直接寫入 wp_options（繞過 ProviderUtils::update_option），模擬升級前殘留資料
	 *
	 * @param array<string, mixed> $value 設定資料
	 */
	private function seed_legacy_option( array $value ): void {
		\update_option( ProviderUtils::get_option_name( RedirectGateway::ID ), $value );
	}

	// ========== 既有資料無感修復 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_既有資料中帶前後空白的apiKey讀取後屬性已乾淨(): void {
		// Given: 直接寫入 wp_options 模擬升級前殘留
		$this->seed_legacy_option(
			[
				'mode'   => 'prod',
				'apiKey' => '  sk_live_legacy  ',
			]
		);

		// When：透過 DTO::instance() 讀取
		$dto = RedirectSettingsDTO::instance();

		// Then：apiKey 已 trim
		$this->assertSame( 'sk_live_legacy', $dto->apiKey );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_既有資料中所有金鑰類欄位都會被trim(): void {
		$this->seed_legacy_option(
			[
				'mode'       => 'prod',
				'platformId' => '  platform_legacy  ',
				'merchantId' => 'merchant_legacy ',
				'apiKey'     => '  sk_live_legacy ',
				'clientKey'  => "\tpk_live_legacy\n",
				'apiUrl'     => ' https://api.shoplinepayments.com ',
			]
		);

		$dto = RedirectSettingsDTO::instance();

		$this->assertSame( 'platform_legacy', $dto->platformId );
		$this->assertSame( 'merchant_legacy', $dto->merchantId );
		$this->assertSame( 'sk_live_legacy', $dto->apiKey );
		$this->assertSame( 'pk_live_legacy', $dto->clientKey );
		$this->assertSame( 'https://api.shoplinepayments.com', $dto->apiUrl );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_全形空白與零寬字元在DTO讀取時也會被trim(): void {
		$this->seed_legacy_option(
			[
				'mode'   => 'prod',
				'apiKey' => "\u{3000}\u{200B}sk_live_xyz\u{FEFF}",
			]
		);

		$dto = RedirectSettingsDTO::instance();
		$this->assertSame( 'sk_live_xyz', $dto->apiKey );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_signKey在mb_convert_encoding後仍會被trim(): void {
		// signKey 在 after_init 會經過 mb_convert_encoding
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'signKey' => "  sign_secret_legacy\u{200B}",
			]
		);

		$dto = RedirectSettingsDTO::instance();
		$this->assertSame( 'sign_secret_legacy', $dto->signKey );
	}

	// ========== 不寫回資料庫 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_讀取時的trim不會主動寫回wp_options(): void {
		$dirty = '  sk_live_legacy  ';
		$this->seed_legacy_option(
			[
				'mode'   => 'prod',
				'apiKey' => $dirty,
			]
		);

		// When：透過 DTO 讀取設定
		RedirectSettingsDTO::instance();

		// Then：原始 wp_options 仍為帶空白的值（沒有被覆寫）
		$raw = \get_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );
		$this->assertIsArray( $raw );
		$this->assertSame( $dirty, $raw['apiKey'] );
	}

	// ========== 邊界 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_DTO讀取時保留欄位中間空白(): void {
		$this->seed_legacy_option(
			[
				'mode'   => 'prod',
				'apiKey' => 'sk_live abc 123',
			]
		);

		$dto = RedirectSettingsDTO::instance();
		$this->assertSame( 'sk_live abc 123', $dto->apiKey );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_DTO讀取時純空白欄位變成空字串(): void {
		$this->seed_legacy_option(
			[
				'mode'   => 'prod',
				'apiKey' => '    ',
			]
		);

		$dto = RedirectSettingsDTO::instance();
		$this->assertSame( '', $dto->apiKey );
	}
}
