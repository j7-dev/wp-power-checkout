<?php
/**
 * ProviderUtils 付款金流設定整合測試
 * 驗證金流 Provider 的啟用/停用/設定讀寫
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * ProviderUtils 付款金流測試類別
 *
 * @group integration
 * @group payment
 */
final class ProviderUtilsPaymentTest extends TestCase {

	/**
	 * 測試用的 Provider ID
	 *
	 * @var string
	 */
	private const PROVIDER_ID = RedirectGateway::ID;

	/**
	 * 每次測試後清理設定
	 */
	public function tear_down(): void {
		// 清除測試期間寫入的 WC 選項
		delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_option_name_回傳正確格式(): void {
		$option_name = ProviderUtils::get_option_name( self::PROVIDER_ID );
		$this->assertSame( 'woocommerce_shopline_payment_redirect_settings', $option_name );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用Provider後is_enabled回傳true(): void {
		// Given & When: 啟用 SLP provider
		$this->enable_provider( self::PROVIDER_ID );

		// Then: is_enabled 回傳 true
		$this->assert_provider_enabled( self::PROVIDER_ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_停用Provider後is_enabled回傳false(): void {
		// Given: 先啟用
		$this->enable_provider( self::PROVIDER_ID );

		// When: 停用
		$this->disable_provider( self::PROVIDER_ID );

		// Then: is_enabled 回傳 false
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_toggle切換啟用狀態_從停用到啟用(): void {
		// Given: 預設停用狀態
		$this->disable_provider( self::PROVIDER_ID );

		// When: toggle
		ProviderUtils::toggle( self::PROVIDER_ID );

		// Then: 變為啟用
		$this->assert_provider_enabled( self::PROVIDER_ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_toggle切換啟用狀態_從啟用到停用(): void {
		// Given: 先啟用
		$this->enable_provider( self::PROVIDER_ID );

		// When: toggle
		ProviderUtils::toggle( self::PROVIDER_ID );

		// Then: 變為停用
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_update_option_用字串key更新單一設定(): void {
		// Given: 初始化設定
		$this->enable_provider( self::PROVIDER_ID );

		// When: 更新 min_amount
		ProviderUtils::update_option( self::PROVIDER_ID, 'min_amount', '100' );

		// Then: 讀取正確
		$this->assertSame( '100', ProviderUtils::get_option( self::PROVIDER_ID, 'min_amount' ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_update_option_用陣列批次更新設定(): void {
		// Given: 空設定
		delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );

		// When: 批次更新
		ProviderUtils::update_option(
			self::PROVIDER_ID,
			[
				'min_amount' => '5',
				'max_amount' => '50000',
				'enabled'    => 'yes',
			]
		);

		// Then: 各欄位讀取正確
		$this->assertSame( '5', ProviderUtils::get_option( self::PROVIDER_ID, 'min_amount' ) );
		$this->assertSame( '50000', ProviderUtils::get_option( self::PROVIDER_ID, 'max_amount' ) );
		$this->assertTrue( ProviderUtils::is_enabled( self::PROVIDER_ID ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_option_不帶key時回傳整個設定陣列(): void {
		// Given: 設定已存在
		$this->enable_provider( self::PROVIDER_ID, [ 'min_amount' => '5' ] );

		// When: 取得整個設定
		$all_settings = ProviderUtils::get_option( self::PROVIDER_ID );

		// Then: 回傳陣列且包含期望的欄位
		$this->assertIsArray( $all_settings );
		$this->assertArrayHasKey( 'enabled', $all_settings );
		$this->assertArrayHasKey( 'min_amount', $all_settings );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_讀取不存在的Provider設定回傳null(): void {
		// Given: Provider 無任何設定
		delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );

		// When: 讀取不存在的 key
		$value = ProviderUtils::get_option( self::PROVIDER_ID, 'nonexistent_key' );

		// Then: 回傳 null
		$this->assertNull( $value );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_Provider無設定時is_enabled預設回傳false(): void {
		// Given: Provider 無任何設定
		delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );

		// When & Then: 預設應為停用
		$this->assert_provider_disabled( self::PROVIDER_ID );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_get_provider_不存在的id回傳null(): void {
		// When: 取得不存在的 provider
		$result = ProviderUtils::get_provider( 'nonexistent_provider_id' );

		// Then: 回傳 null
		$this->assertNull( $result );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_update_option_陣列設定會合併而非覆蓋(): void {
		// Given: 先設定兩個欄位
		ProviderUtils::update_option(
			self::PROVIDER_ID,
			[
				'key_a' => 'value_a',
				'key_b' => 'value_b',
			]
			);

		// When: 只更新其中一個欄位
		ProviderUtils::update_option( self::PROVIDER_ID, 'key_a', 'new_value_a' );

		// Then: key_b 應仍然存在（不被覆蓋）
		$this->assertSame( 'new_value_a', ProviderUtils::get_option( self::PROVIDER_ID, 'key_a' ) );
		$this->assertSame( 'value_b', ProviderUtils::get_option( self::PROVIDER_ID, 'key_b' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_get_options_回傳整個設定陣列時不存在key時預設空陣列(): void {
		// Given: Provider 無設定
		delete_option( ProviderUtils::get_option_name( self::PROVIDER_ID ) );

		// When: 取得全部設定
		$all = ProviderUtils::get_option( self::PROVIDER_ID );

		// Then: 回傳空陣列
		$this->assertSame( [], $all );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_has_providers_所有id都不在容器時回傳false(): void {
		// Given: 清空容器
		ProviderUtils::$container = [];

		// When & Then
		$this->assertFalse( ProviderUtils::has_providers( [ self::PROVIDER_ID ] ) );
	}
}
