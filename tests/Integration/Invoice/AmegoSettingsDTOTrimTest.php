<?php
/**
 * AmegoSettingsDTO 讀取時自動 trim 整合測試
 * 對應 Issue #16，covers specs/features/settings/trim-key-whitespace.feature 規則 2
 *
 * 測試重點：
 *  1. wp_options 中既有「帶空白」的 invoice / app_key，DTO 屬性是乾淨的
 *  2. 讀取時 trim 不會主動寫回 wp_options
 *  3. 因 AmegoSettingsDTO 採 static instance cache，每次測試需 reset
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group invoice
 * @group trim
 */
final class AmegoSettingsDTOTrimTest extends TestCase {

	/**
	 * 每次測試前重置 static instance cache 並清理 wp_options
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_amego_instance();
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		$this->reset_amego_instance();
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 AmegoSettingsDTO 的 static $instance
	 */
	private function reset_amego_instance(): void {
		$ref  = new \ReflectionClass( AmegoSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 直接寫入 wp_options（繞過 ProviderUtils::update_option），模擬升級前殘留資料
	 *
	 * @param array<string, mixed> $value 設定資料
	 */
	private function seed_legacy_option( array $value ): void {
		\update_option( ProviderUtils::get_option_name( AmegoProvider::ID ), $value );
	}

	// ========== 既有資料無感修復 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_既有資料中Amego_app_key讀取後屬性已乾淨(): void {
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'app_key' => ' amego_legacy_key ',
			]
		);

		$dto = AmegoSettingsDTO::instance();
		$this->assertSame( 'amego_legacy_key', $dto->app_key );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_既有資料中Amego_invoice統編讀取後屬性已乾淨(): void {
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'invoice' => ' 12345678 ',
			]
		);

		$dto = AmegoSettingsDTO::instance();
		$this->assertSame( '12345678', $dto->invoice );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_全形空白與零寬字元在Amego_DTO讀取時也會被trim(): void {
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'app_key' => "\u{3000}\u{200B}amego_xyz\u{FEFF}",
				'invoice' => "\u{00A0}87654321\u{200D}",
			]
		);

		$dto = AmegoSettingsDTO::instance();
		$this->assertSame( 'amego_xyz', $dto->app_key );
		$this->assertSame( '87654321', $dto->invoice );
	}

	// ========== 不寫回資料庫 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_Amego_DTO讀取時的trim不會主動寫回wp_options(): void {
		$dirty = ' amego_legacy_key ';
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'app_key' => $dirty,
			]
		);

		AmegoSettingsDTO::instance();

		$raw = \get_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		$this->assertIsArray( $raw );
		$this->assertSame( $dirty, $raw['app_key'] );
	}

	// ========== 邊界 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_Amego_DTO讀取時保留欄位中間空白(): void {
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'app_key' => 'amego key 123',
			]
		);

		$dto = AmegoSettingsDTO::instance();
		$this->assertSame( 'amego key 123', $dto->app_key );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_Amego_DTO讀取時純空白欄位變成空字串(): void {
		$this->seed_legacy_option(
			[
				'mode'    => 'prod',
				'app_key' => '    ',
			]
		);

		$dto = AmegoSettingsDTO::instance();
		$this->assertSame( '', $dto->app_key );
	}
}
