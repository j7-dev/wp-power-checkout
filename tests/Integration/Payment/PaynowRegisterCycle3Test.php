<?php
/**
 * PayNow Register Cycle 3 測試（TDD Red 階段）
 *
 * 測試目標：
 *   - PaynowCallback 端點是否已向 WP REST API 註冊（namespace + route）
 *   - BlocksIntegration 是否已透過 woocommerce_blocks_payment_method_type_registration 掛鉤
 *
 * ⚠️ 不覆蓋 Cycle 1 的 PaynowRegisterTest.php（ProviderRegister + gateway 啟用/停用）
 * ⚠️ 不測試 create-payment FrontendApi（PayNow 體系 1 不需要後端建單）
 *
 * 設計依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 19
 *   - CLAUDE.md → REST API 表（power-checkout/paynow → POST /notify）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowRegisterCycle3Test"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use Tests\Integration\TestCase;

/**
 * PayNow Register Cycle 3 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowRegisterCycle3Test extends TestCase {

	// =====================================================================
	// Smoke：PaynowCallback 端點已向 WP REST API 註冊
	// =====================================================================

	/**
	 * REST namespace 'power-checkout/paynow' 已註冊
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_paynow_REST_namespace已註冊(): void {
		$server    = \rest_get_server();
		$routes    = $server->get_routes();
		$namespace = 'power-checkout/paynow';

		// 確認 namespace 下至少有一條 route
		$has_namespace = false;
		foreach ( \array_keys( $routes ) as $route ) {
			if ( \str_starts_with( $route, '/' . $namespace ) ) {
				$has_namespace = true;
				break;
			}
		}

		$this->assertTrue(
			$has_namespace,
			"REST namespace '/{$namespace}' 應已向 WP 核心註冊"
		);
	}

	/**
	 * POST /power-checkout/paynow/notify 端點已存在
	 *
	 * CLAUDE.md REST API 表：power-checkout/paynow → POST /notify
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_paynow_notify端點已註冊(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();
		$route  = '/power-checkout/paynow/notify';

		$this->assertArrayHasKey(
			$route,
			$routes,
			"REST 端點 '{$route}' 應已向 WP 核心註冊"
		);
	}

	/**
	 * /power-checkout/paynow/notify 支援 POST 方法
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_paynow_notify端點支援POST方法(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();
		$route  = '/power-checkout/paynow/notify';

		if ( ! isset( $routes[ $route ] ) ) {
			$this->fail( "端點 '{$route}' 尚未註冊" );
		}

		$methods = [];
		foreach ( $routes[ $route ] as $handler ) {
			if ( isset( $handler['methods'] ) ) {
				$methods = \array_merge( $methods, \array_keys( $handler['methods'] ) );
			}
		}

		$this->assertContains(
			'POST',
			$methods,
			"端點 '{$route}' 應支援 POST 方法"
		);
	}

	/**
	 * /power-checkout/paynow/notify 端點的 permission_callback 為開放（__return_true）
	 *
	 * HMAC 驗簽在 Callback 內部進行，permission_callback 設 __return_true（對齊 ECPay / PAYUNi）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_paynow_notify端點permission_callback為開放(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();
		$route  = '/power-checkout/paynow/notify';

		if ( ! isset( $routes[ $route ] ) ) {
			$this->fail( "端點 '{$route}' 尚未註冊" );
		}

		// 找到 POST handler 的 permission_callback
		$found_open_permission = false;
		foreach ( $routes[ $route ] as $handler ) {
			if ( empty( $handler['methods']['POST'] ) ) {
				continue;
			}
			$perm = $handler['permission_callback'] ?? null;
			if ( '__return_true' === $perm || ( \is_callable( $perm ) && true === $perm() ) ) {
				$found_open_permission = true;
				break;
			}
		}

		$this->assertTrue(
			$found_open_permission,
			"'{$route}' 的 permission_callback 應為開放（__return_true），HMAC 驗簽在 callback 內部處理"
		);
	}

	// =====================================================================
	// Smoke：BlocksIntegration 已向 WC Blocks 掛鉤
	// =====================================================================

	/**
	 * woocommerce_blocks_payment_method_type_registration 動作鉤子有 paynow 的 callback
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_BlocksIntegration已掛WC_Blocks_hook(): void {
		global $wp_filter;

		$hook_name = 'woocommerce_blocks_payment_method_type_registration';

		$this->assertArrayHasKey(
			$hook_name,
			$wp_filter,
			"Hook '{$hook_name}' 應存在（BlocksIntegration 需透過此 hook 注入）"
		);

		// 確認有至少一個 callback 包含 'paynow'（大小寫不敏感）
		$found_paynow_callback = false;
		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback_info ) {
				$func = $callback_info['function'] ?? null;

				if ( \is_array( $func ) ) {
					$class_str = \is_object( $func[0] ) ? \get_class( $func[0] ) : (string) $func[0];
					if ( false !== \stripos( $class_str, 'paynow' ) ) {
						$found_paynow_callback = true;
						break 2;
					}
				}

				if ( \is_string( $func ) && false !== \stripos( $func, 'paynow' ) ) {
					$found_paynow_callback = true;
					break 2;
				}

				if ( $func instanceof \Closure ) {
					// Closure 無法直接判斷，改查 class_name；嘗試 reflection
					try {
						$ref = new \ReflectionFunction( $func );
						if ( false !== \stripos( $ref->getClosureScopeClass()?->getName() ?? '', 'paynow' ) ) {
							$found_paynow_callback = true;
							break 2;
						}
					} catch ( \ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// 無法反射時略過
					}
				}
			}
		}

		$this->assertTrue(
			$found_paynow_callback,
			"Hook '{$hook_name}' 應有 paynow BlocksIntegration 相關 callback"
		);
	}

	/**
	 * PaynowGateway 類別有 register_checkout_blocks 靜態方法
	 *
	 * WC Blocks 整合入口：PaynowGateway 透過 register_checkout_blocks() 掛鉤
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway有register_checkout_blocks方法(): void {
		$this->assertTrue(
			\method_exists(
				\J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway::class,
				'register_checkout_blocks'
			),
			'PaynowGateway 應有 register_checkout_blocks() 靜態方法（用於掛鉤 WC Blocks）'
		);
	}

	// =====================================================================
	// Smoke：PaynowCallback 類別結構驗證
	// =====================================================================

	/**
	 * PaynowCallback 類別存在
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowCallback類別存在(): void {
		$this->assertTrue(
			\class_exists( \J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowCallback::class ),
			'PaynowCallback 類別應存在（Cycle 3 目標，Red 階段此測試失敗）'
		);
	}

	/**
	 * WebhookVerifier 類別存在
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_WebhookVerifier類別存在(): void {
		$this->assertTrue(
			\class_exists( \J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\WebhookVerifier::class ),
			'WebhookVerifier 類別應存在（Cycle 3 目標，Red 階段此測試失敗）'
		);
	}

	/**
	 * paynow BlocksIntegration 類別存在
	 *
	 * 對齊 ECPay ECPG：EcpgBlocksIntegration；PAYUNi：BlocksIntegration（在 gateway 內部 Closure）
	 * PayNow 應有對應的 BlocksIntegration 類別
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowBlocksIntegration類別存在(): void {
		// 嘗試多個可能的命名慣例
		$possible_classes = [
			\J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowBlocksIntegration::class,
			\J7\PowerCheckout\Domains\Payment\Paynow\BlocksIntegration::class,
			\J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowBlocksIntegration::class,
		];

		$found = false;
		foreach ( $possible_classes as $class ) {
			if ( \class_exists( $class ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			'PayNow BlocksIntegration 類別應存在（對齊其他 gateway 的 WC Blocks 整合模式）'
		);
	}

	// =====================================================================
	// Smoke：確認 FrontendApi 不存在（PayNow 體系 1 無須後端建單）
	// =====================================================================

	/**
	 * PaynowFrontendApi 不應存在（PayNow 體系 1 由前端直接呼叫 PayNow API，不需後端建單）
	 *
	 * 設計依據：PayNow 體系 1（REST PaymentIntent + Component SDK v2）
	 *   - 建立 PaymentIntent：前端直接呼叫 PayNow API（使用 PublicKey）
	 *   - 無需後端 /create-payment REST 端點（對比 ECPG / UNi Embed 需要）
	 *   - NotifyURL（Webhook）→ PaynowCallback（後端）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_FrontendApi不存在_確認設計正確(): void {
		$this->assertFalse(
			\class_exists( \J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowFrontendApi::class ),
			'PaynowFrontendApi 不應存在（PayNow 體系 1 由前端直接建 PaymentIntent，無需後端 create-payment 端點）'
		);
	}

	/**
	 * /power-checkout/paynow/create-payment 端點不應存在
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_create_payment端點不存在(): void {
		$server = \rest_get_server();
		$routes = $server->get_routes();

		$this->assertArrayNotHasKey(
			'/power-checkout/paynow/create-payment',
			$routes,
			'/power-checkout/paynow/create-payment 端點不應存在（PayNow 體系 1 不需後端建單）'
		);
	}
}
