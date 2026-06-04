<?php
/**
 * 綠界全方位物流設定 DTO 整合測試
 *
 * 驗證 account_type（b2c / c2c）切換對應憑證（計畫 R5 核心 — get_active_* accessor），
 * 測試模式套綠界物流公開測試帳號（B2C 2000132 / C2C 2000933），
 * 讀取時自動 trim（鏡像 AmegoSettingsDTOTrim），以及 instance / reset 單例。
 *
 * 對應計畫第一階段步驟 4。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §前置需求 / C2C 操作
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界全方位物流設定 DTO 測試類別
 *
 * @group integration
 * @group logistics
 */
final class EcpayLogisticsSettingsDTOTest extends TestCase {

	/** 每次測試前重置單例並清理 wp_options */
	public function set_up(): void {
		parent::set_up();
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 直接寫入 wp_options（繞過 trim），模擬升級前殘留資料
	 *
	 * @param array<string, mixed> $value 設定資料
	 */
	private function seed_option( array $value ): void {
		\update_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ), $value );
	}

	// ========== account_type 切換對應憑證（R5） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_account_type為b2c時test模式套用B2C公開測試帳號(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'account_type' => 'b2c',
			]
		);

		$dto = EcpayLogisticsSettingsDTO::instance();

		$this->assertSame( '2000132', $dto->get_active_merchant_id() );
		$this->assertSame( '5294y06JbISpM5x9', $dto->get_active_hash_key() );
		$this->assertSame( 'v77hoKGq4kWxNNIS', $dto->get_active_hash_iv() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_account_type為c2c時test模式套用C2C公開測試帳號(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'account_type' => 'c2c',
			]
		);

		$dto = EcpayLogisticsSettingsDTO::instance();

		$this->assertSame( '2000933', $dto->get_active_merchant_id() );
		$this->assertSame( 'XBERn1YOvpM9nfZc', $dto->get_active_hash_key() );
		$this->assertSame( 'h1ONHk4P4yqbl5LK', $dto->get_active_hash_iv() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_account_type切換取得各自填入的prod憑證(): void {
		$this->seed_option(
			[
				'mode'            => 'prod',
				'account_type'    => 'b2c',
				'b2c_merchant_id' => 'B2C_MID',
				'b2c_hash_key'    => 'B2C_KEY',
				'b2c_hash_iv'     => 'B2C_IV',
				'c2c_merchant_id' => 'C2C_MID',
				'c2c_hash_key'    => 'C2C_KEY',
				'c2c_hash_iv'     => 'C2C_IV',
			]
		);

		$dto = EcpayLogisticsSettingsDTO::instance();

		// account_type = b2c → 回傳 B2C 那組
		$this->assertSame( 'B2C_MID', $dto->get_active_merchant_id() );
		$this->assertSame( 'B2C_KEY', $dto->get_active_hash_key() );
		$this->assertSame( 'B2C_IV', $dto->get_active_hash_iv() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_prod模式c2c回傳C2C憑證(): void {
		$this->seed_option(
			[
				'mode'            => 'prod',
				'account_type'    => 'c2c',
				'b2c_merchant_id' => 'B2C_MID',
				'b2c_hash_key'    => 'B2C_KEY',
				'b2c_hash_iv'     => 'B2C_IV',
				'c2c_merchant_id' => 'C2C_MID',
				'c2c_hash_key'    => 'C2C_KEY',
				'c2c_hash_iv'     => 'C2C_IV',
			]
		);

		$dto = EcpayLogisticsSettingsDTO::instance();

		$this->assertSame( 'C2C_MID', $dto->get_active_merchant_id() );
		$this->assertSame( 'C2C_KEY', $dto->get_active_hash_key() );
		$this->assertSame( 'C2C_IV', $dto->get_active_hash_iv() );
	}

	// ========== 讀取時自動 trim（鏡像 AmegoSettingsDTOTrim） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_既有資料中憑證讀取後屬性已trim(): void {
		$this->seed_option(
			[
				'mode'            => 'prod',
				'account_type'    => 'b2c',
				'b2c_merchant_id' => ' 3000001 ',
				'b2c_hash_key'    => " \u{3000}b2c_dirty_key\u{200B} ",
				'b2c_hash_iv'     => "\u{FEFF}b2c_dirty_iv ",
			]
		);

		$dto = EcpayLogisticsSettingsDTO::instance();

		$this->assertSame( '3000001', $dto->get_active_merchant_id() );
		$this->assertSame( 'b2c_dirty_key', $dto->get_active_hash_key() );
		$this->assertSame( 'b2c_dirty_iv', $dto->get_active_hash_iv() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_DTO讀取時的trim不會主動寫回wp_options(): void {
		$dirty = ' 3000001 ';
		$this->seed_option(
			[
				'mode'            => 'prod',
				'account_type'    => 'b2c',
				'b2c_merchant_id' => $dirty,
			]
		);

		EcpayLogisticsSettingsDTO::instance();

		$raw = \get_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		$this->assertIsArray( $raw );
		$this->assertSame( $dirty, $raw['b2c_merchant_id'] );
	}

	// ========== instance / reset 單例 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_instance回傳同一個單例實例(): void {
		$first  = EcpayLogisticsSettingsDTO::instance();
		$second = EcpayLogisticsSettingsDTO::instance();

		$this->assertSame( $first, $second, 'instance() 應回傳同一個單例' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_reset後instance重新讀取最新設定(): void {
		$this->seed_option(
			[
				'mode'         => 'test',
				'account_type' => 'b2c',
			]
		);
		$before = EcpayLogisticsSettingsDTO::instance();
		$this->assertSame( '2000132', $before->get_active_merchant_id() );

		// 變更設定為 c2c 後 reset，應讀到新值
		$this->seed_option(
			[
				'mode'         => 'test',
				'account_type' => 'c2c',
			]
		);
		EcpayLogisticsSettingsDTO::reset();

		$after = EcpayLogisticsSettingsDTO::instance();
		$this->assertNotSame( $before, $after, 'reset 後應為新實例' );
		$this->assertSame( '2000933', $after->get_active_merchant_id() );
	}
}
