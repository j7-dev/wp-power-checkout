<?php
/**
 * ProviderUtils 設定管理整合測試
 * 驗證 Provider 設定的完整 CRUD 生命週期
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * ProviderUtils 設定管理測試類別
 *
 * @group integration
 * @group settings
 */
final class ProviderUtilsSettingsTest extends TestCase {

	/**
	 * 所有受測 Provider IDs
	 *
	 * @var array<string>
	 */
	private array $test_provider_ids = [
		RedirectGateway::ID,
		AmegoProvider::ID,
	];

	/**
	 * 每次測試後清理所有測試 Provider 設定
	 */
	public function tear_down(): void {
		foreach ( $this->test_provider_ids as $id ) {
			delete_option( ProviderUtils::get_option_name( $id ) );
		}
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_option_name_格式正確(): void {
		$this->assertSame(
			'woocommerce_shopline_payment_redirect_settings',
			ProviderUtils::get_option_name( RedirectGateway::ID )
		);
		$this->assertSame(
			'woocommerce_amego_settings',
			ProviderUtils::get_option_name( AmegoProvider::ID )
		);
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_切換付款Provider啟用狀態(): void {
		// Given: SLP 初始為停用
		$this->disable_provider( RedirectGateway::ID );
		$this->assert_provider_disabled( RedirectGateway::ID );

		// When: toggle
		ProviderUtils::toggle( RedirectGateway::ID );

		// Then: 啟用
		$this->assert_provider_enabled( RedirectGateway::ID );

		// When: 再次 toggle
		ProviderUtils::toggle( RedirectGateway::ID );

		// Then: 回到停用
		$this->assert_provider_disabled( RedirectGateway::ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_切換發票Provider啟用狀態(): void {
		// Given: Amego 初始為停用
		$this->disable_provider( AmegoProvider::ID );

		// When: 啟用
		ProviderUtils::toggle( AmegoProvider::ID );

		// Then: 啟用
		$this->assert_provider_enabled( AmegoProvider::ID );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_更新SLP設定並讀取(): void {
		// Given: SLP 啟用並寫入完整設定
		$slp_settings = [
			'enabled'    => 'yes',
			'platformId' => 'platform_test_001',
			'merchantId' => 'merchant_test_001',
			'apiKey'     => 'api_key_test',
			'clientKey'  => 'client_key_test',
			'signKey'    => 'sign_key_test',
			'min_amount' => '5',
			'max_amount' => '50000',
		];

		// When: 批次更新設定
		ProviderUtils::update_option( RedirectGateway::ID, $slp_settings );

		// Then: 各欄位可以正確讀取
		$this->assertSame( 'platform_test_001', ProviderUtils::get_option( RedirectGateway::ID, 'platformId' ) );
		$this->assertSame( 'merchant_test_001', ProviderUtils::get_option( RedirectGateway::ID, 'merchantId' ) );
		$this->assertTrue( ProviderUtils::is_enabled( RedirectGateway::ID ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_更新Amego設定並讀取(): void {
		// Given: Amego 設定
		$amego_settings = [
			'enabled'                    => 'yes',
			'api_key'                    => 'amego_api_key_test',
			'auto_issue_order_statuses'  => [ 'wc-processing' ],
			'auto_cancel_order_statuses' => [ 'wc-cancelled' ],
		];

		// When: 批次更新
		ProviderUtils::update_option( AmegoProvider::ID, $amego_settings );

		// Then: 讀取正確
		$this->assertSame( 'amego_api_key_test', ProviderUtils::get_option( AmegoProvider::ID, 'api_key' ) );
		$auto_issue = ProviderUtils::get_option( AmegoProvider::ID, 'auto_issue_order_statuses' );
		$this->assertIsArray( $auto_issue );
		$this->assertContains( 'wc-processing', $auto_issue );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_get_providers_空id陣列回傳空陣列(): void {
		// When: 傳入空陣列
		$providers = ProviderUtils::get_providers( [] );

		// Then: 回傳空陣列
		$this->assertSame( [], $providers );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_has_providers_空id陣列回傳false(): void {
		// When & Then
		$this->assertFalse( ProviderUtils::has_providers( [] ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_所有Provider停用時has_providers回傳false(): void {
		// Given: 清空容器
		ProviderUtils::$container = [];

		// When & Then: 無任何已啟用 Provider
		$this->assertFalse(
			ProviderUtils::has_providers( [ RedirectGateway::ID, AmegoProvider::ID ] )
		);
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_設定值為特殊字元Unicode正常儲存(): void {
		// Given: 設定包含 Unicode 和特殊字元
		$unicode_value = '測試商家 🛒 ABC-123 <>&"\'';
		ProviderUtils::update_option( RedirectGateway::ID, 'title', $unicode_value );

		// When & Then: 可以正確讀取
		$result = ProviderUtils::get_option( RedirectGateway::ID, 'title' );
		$this->assertSame( $unicode_value, $result );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_設定值為空字串正常儲存(): void {
		// Given: 先設定一個值
		ProviderUtils::update_option( RedirectGateway::ID, 'signKey', 'original_key' );

		// When: 更新為空字串
		ProviderUtils::update_option( RedirectGateway::ID, 'signKey', '' );

		// Then: 讀取空字串
		$result = ProviderUtils::get_option( RedirectGateway::ID, 'signKey' );
		$this->assertSame( '', $result );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_同時存在多個Provider設定不互相干擾(): void {
		// Given: 分別設定兩個 provider
		ProviderUtils::update_option( RedirectGateway::ID, 'enabled', 'yes' );
		ProviderUtils::update_option( AmegoProvider::ID, 'enabled', 'no' );

		// When & Then: 兩個 provider 的設定應互相獨立
		$this->assertTrue( ProviderUtils::is_enabled( RedirectGateway::ID ) );
		$this->assertFalse( ProviderUtils::is_enabled( AmegoProvider::ID ) );
	}

	// ========== 安全性（Security） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_API_key包含SQL_injection不造成異常(): void {
		// Given: 含有 SQL injection 嘗試的 API key
		$sql_injection_key = "' OR 1=1; DROP TABLE wp_options; --";

		// When: 儲存
		ProviderUtils::update_option( AmegoProvider::ID, 'api_key', $sql_injection_key );

		// Then: 可以正確讀取，資料庫未被破壞
		$result = ProviderUtils::get_option( AmegoProvider::ID, 'api_key' );
		$this->assertSame( $sql_injection_key, $result );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_signKey包含特殊字元正常儲存(): void {
		// Given: 含有各種特殊字元的 signKey（模擬真實 HMAC 密鑰）
		$complex_key = 'A1b2!@#$%^&*()_+-=[]{}|;:,.<>?/~`\'"';

		// When: 儲存
		ProviderUtils::update_option( RedirectGateway::ID, 'signKey', $complex_key );

		// Then: 可以正確讀取
		$result = ProviderUtils::get_option( RedirectGateway::ID, 'signKey' );
		$this->assertSame( $complex_key, $result );
	}
}
