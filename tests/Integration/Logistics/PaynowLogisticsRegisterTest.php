<?php
/**
 * PayNow ProviderRegister + LogisticsApiService 註冊層整合測試（A-Cycle 3 Red Gate）
 *
 * 驗證 PayNow 物流 (paynow_logistics) 的三層註冊完整性：
 *
 * 1. ProviderRegister::$logistics_providers 含 PaynowLogisticsProvider::class
 *    → register_hooks() 啟用後 ProviderUtils::$container 有 paynow_logistics。
 * 2. LogisticsApiService::PROVIDER_IDS 含 'paynow_logistics'
 *    → REST 委派解析可找到此 provider（帶 provider 參數時）。
 * 3. is_enabled('paynow_logistics') 時 LogisticsCallback::register_hooks() 被掛
 *    → REST routes power-checkout/paynow/logistics/selection-callback 與
 *       power-checkout/paynow/logistics/status-callback 被註冊。
 * 4. woocommerce_shipping_methods filter 含 WC_PaynowLogisticsShipping。
 * 5. get_registered_provider_dtos() 含 paynow_logistics（三 provider 並存）。
 *
 * ⚠️ 此測試類在實作變更前必須全部失敗（Red Gate）。
 *    LogisticsApiService::PROVIDER_IDS 為 private const，透過反射存取。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ --filter PaynowLogisticsRegisterTest" 2>&1; echo "EXIT_CODE=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback as PaynowLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\WC_PaynowLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow ProviderRegister + LogisticsApiService 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 * @group happy
 */
final class PaynowLogisticsRegisterTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		PaynowLogisticsSettingsDTO::reset();
		\putenv( 'API_MODE=mock' );

		// 測試 bug 修正（最小）：WP_UnitTestCase 不重置全域 $wp_rest_server，導致前一個
		// 「啟用→路由被註冊」測試把 callback 路由殘留在持久 server 中，後續「未啟用→路由不被掛載」
		// 測試讀到殘留路由而誤判。於每個測試前重置 server，使每個測試自乾淨 REST 狀態開始
		// （對齊 WP_Test_REST_Controller_Testcase 慣例）。下次 rest_get_server() 會 lazy 重建並
		// 觸發 rest_api_init，使路由註冊與否如實反映當下 register_hooks() 的掛載結果。
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		\putenv( 'API_MODE=' );
		PaynowLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PaynowLogisticsProvider::ID ) );
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		\delete_option( ProviderUtils::get_option_name( PayuniLogisticsProvider::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_option_key為woocommerce_paynow_logistics_settings(): void {
		$this->assertSame(
			'woocommerce_paynow_logistics_settings',
			ProviderUtils::get_option_name( PaynowLogisticsProvider::ID )
		);
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_PaynowLogisticsProvider_ID為paynow_logistics(): void {
		$this->assertSame( 'paynow_logistics', PaynowLogisticsProvider::ID );
	}

	// ========== ProviderRegister::$logistics_providers 含 PaynowLogisticsProvider ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用paynow_logistics後register_hooks進入ProviderUtils容器(): void {
		// Given: 啟用 provider
		$this->enable_provider( PaynowLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];

		// When
		ProviderRegister::register_hooks();

		// Then: 已進容器
		$this->assertArrayHasKey( PaynowLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertInstanceOf(
			PaynowLogisticsProvider::class,
			ProviderUtils::get_provider( PaynowLogisticsProvider::ID )
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_未啟用paynow_logistics時不進容器(): void {
		// Given: 停用 provider（預設狀態，set_up 已清空 option）
		ProviderUtils::$container = [];

		// When
		ProviderRegister::register_hooks();

		// Then: 未進容器
		$this->assertArrayNotHasKey( PaynowLogisticsProvider::ID, ProviderUtils::$container );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_三provider並存啟用時皆進容器(): void {
		// Given: 三個 provider 全啟用
		$this->enable_provider( EcpayLogisticsProvider::ID, [ 'mode' => 'test' ] );
		$this->enable_provider( PayuniLogisticsProvider::ID, [ 'mode' => 'test' ] );
		$this->enable_provider( PaynowLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];

		// When
		ProviderRegister::register_hooks();

		// Then: 三個都進容器
		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertArrayHasKey( PayuniLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertArrayHasKey( PaynowLogisticsProvider::ID, ProviderUtils::$container );
	}

	// ========== get_registered_provider_dtos 含 paynow_logistics ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_registered_provider_dtos含paynow_logistics(): void {
		// When
		$dtos = ProviderRegister::get_registered_provider_dtos();

		// Then: paynow_logistics 在 dtos 陣列中
		$this->assertIsArray( $dtos );
		$this->assertArrayHasKey( PaynowLogisticsProvider::ID, $dtos );
		$this->assertInstanceOf( BaseSettingsDTO::class, $dtos[ PaynowLogisticsProvider::ID ] );
		$this->assertSame( PaynowLogisticsProvider::ID, $dtos[ PaynowLogisticsProvider::ID ]->id );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_registered_provider_dtos同時含三provider(): void {
		// When
		$dtos = ProviderRegister::get_registered_provider_dtos();

		// Then: 三個 provider 皆在 dtos 陣列中（三方並存）
		$this->assertArrayHasKey( EcpayLogisticsProvider::ID, $dtos );
		$this->assertArrayHasKey( PayuniLogisticsProvider::ID, $dtos );
		$this->assertArrayHasKey( PaynowLogisticsProvider::ID, $dtos );
	}

	// ========== LogisticsApiService::PROVIDER_IDS 含 paynow_logistics ==========

	/**
	 * LogisticsApiService::PROVIDER_IDS 為 private const，透過反射存取。
	 *
	 * @test
	 * @group happy
	 */
	public function test_LogisticsApiService_PROVIDER_IDS含paynow_logistics(): void {
		// Given: 透過反射取得 private const PROVIDER_IDS
		$reflection = new \ReflectionClass( LogisticsApiService::class );
		$constant   = $reflection->getReflectionConstant( 'PROVIDER_IDS' );

		// Then: constant 存在且含 paynow_logistics
		$this->assertNotFalse( $constant, 'LogisticsApiService 應定義 PROVIDER_IDS 常數' );

		/** @var array<int, string> $provider_ids */
		$provider_ids = $constant->getValue();
		$this->assertIsArray( $provider_ids );
		$this->assertContains(
			PaynowLogisticsProvider::ID,
			$provider_ids,
			'LogisticsApiService::PROVIDER_IDS 應含 paynow_logistics'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_LogisticsApiService_PROVIDER_IDS同時含三provider(): void {
		// Given: 透過反射取得 PROVIDER_IDS
		$reflection = new \ReflectionClass( LogisticsApiService::class );
		$constant   = $reflection->getReflectionConstant( 'PROVIDER_IDS' );
		$this->assertNotFalse( $constant );

		/** @var array<int, string> $provider_ids */
		$provider_ids = $constant->getValue();

		// Then: 三個 provider 皆在清單（REST 委派解析全部可用）
		$this->assertContains( 'ecpay_logistics', $provider_ids, 'PROVIDER_IDS 應含 ecpay_logistics' );
		$this->assertContains( 'payuni_logistics', $provider_ids, 'PROVIDER_IDS 應含 payuni_logistics' );
		$this->assertContains( 'paynow_logistics', $provider_ids, 'PROVIDER_IDS 應含 paynow_logistics' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用paynow_logistics後REST委派可解析provider(): void {
		// Given: 啟用 paynow_logistics 並載入容器
		$this->enable_provider( PaynowLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();

		// When: 透過 REST 請求帶 provider=paynow_logistics 參數（模擬委派路徑）
		// 驗證：ProviderUtils::get_provider('paynow_logistics') 能回傳正確實例
		$provider = ProviderUtils::get_provider( PaynowLogisticsProvider::ID );

		// Then: 可解析到 PaynowLogisticsProvider 實例
		$this->assertInstanceOf(
			PaynowLogisticsProvider::class,
			$provider,
			'REST 委派應能解析 paynow_logistics provider'
		);
	}

	// ========== Callback 條件註冊（is_enabled 時 REST routes 被掛載） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_啟用paynow_logistics後callback_REST_routes被註冊(): void {
		// Given: 啟用 provider，確保 WordPress REST 初始化
		$this->enable_provider( PaynowLogisticsProvider::ID, [ 'mode' => 'test' ] );
		ProviderUtils::$container = [];

		// When: register_hooks 觸發 callback 掛載
		ProviderRegister::register_hooks();
		// 確保 REST 路由被真正初始化
		\do_action( 'rest_api_init' );

		// Then: power-checkout/paynow 下的物流 callback 路由應存在
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$has_selection_callback = false;
		$has_status_callback    = false;
		foreach ( \array_keys( $routes ) as $route ) {
			if ( \str_contains( (string) $route, 'paynow' ) && \str_contains( (string) $route, 'selection-callback' ) ) {
				$has_selection_callback = true;
			}
			if ( \str_contains( (string) $route, 'paynow' ) && \str_contains( (string) $route, 'status-callback' ) ) {
				$has_status_callback = true;
			}
		}

		$this->assertTrue( $has_selection_callback, 'paynow 選店回呼 REST route 應被註冊（power-checkout/paynow/logistics/selection-callback）' );
		$this->assertTrue( $has_status_callback, 'paynow 貨態通知 REST route 應被註冊（power-checkout/paynow/logistics/status-callback）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_未啟用paynow_logistics時callback_REST_routes不被掛載(): void {
		// Given: paynow_logistics 未啟用（set_up 已清空 option）
		ProviderUtils::$container = [];

		// When
		ProviderRegister::register_hooks();
		\do_action( 'rest_api_init' );

		// Then: 不應有 paynow 物流 callback 路由
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$has_paynow_logistics_callback = false;
		foreach ( \array_keys( $routes ) as $route ) {
			if ( \str_contains( (string) $route, 'paynow' ) && \str_contains( (string) $route, 'logistics' ) ) {
				$has_paynow_logistics_callback = true;
				break;
			}
		}

		$this->assertFalse(
			$has_paynow_logistics_callback,
			'未啟用 paynow_logistics 時不應註冊物流 callback REST routes'
		);
	}

	// ========== woocommerce_shipping_methods filter 含 WC_PaynowLogisticsShipping ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_shipping_methods_filter含WC_PaynowLogisticsShipping(): void {
		// Given: 不論啟用與否皆應在 filter（類別本身被掛，啟用狀態由 WC_Shipping_Method 自身控制）
		ProviderRegister::register_hooks();

		// When
		$methods = \apply_filters( 'woocommerce_shipping_methods', [] );

		// Then
		$this->assertIsArray( $methods );
		$found = false;
		foreach ( $methods as $method ) {
			if ( \is_string( $method ) && \str_contains( $method, 'PaynowLogisticsShipping' ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'woocommerce_shipping_methods 應含 WC_PaynowLogisticsShipping' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_shipping_methods_filter直接含WC_PaynowLogisticsShipping類別(): void {
		// When
		ProviderRegister::register_hooks();
		$methods = \apply_filters( 'woocommerce_shipping_methods', [] );

		// Then: 直接斷言 class string 存在
		$this->assertContains(
			WC_PaynowLogisticsShipping::class,
			$methods,
			'woocommerce_shipping_methods 應直接含 WC_PaynowLogisticsShipping::class'
		);
	}

	// ========== ProviderRegister::save_checkout_meta 委派 PayNow ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_save_checkout_meta_hook被掛載(): void {
		// Given
		ProviderRegister::register_hooks();

		// Then: woocommerce_checkout_create_order hook 已被掛載
		$this->assertGreaterThan(
			0,
			\has_action( 'woocommerce_checkout_create_order', [ ProviderRegister::class, 'save_checkout_meta' ] ),
			'woocommerce_checkout_create_order 應掛載 ProviderRegister::save_checkout_meta'
		);
	}
}
