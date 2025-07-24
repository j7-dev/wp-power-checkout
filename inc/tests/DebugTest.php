<?php
/**
 * Debug Test
 * run `composer test:debug "inc\tests\DebugTest.php"`
 */

namespace J7\PowerCheckoutTests;

use J7\PowerCheckoutTests\Helper;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;
use J7\PowerCheckoutTests\Utils\STDOUT;

/** Debug Test */
class DebugTest extends WC_UnitTestCase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 測試名稱 */
	protected string $name = '【Debug Test】';

	/** @var string[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		'woocommerce/woocommerce.php',
		'powerhouse/plugin.php',
		'power-checkout/plugin.php',
	];

	/** 測試主體 */
	protected function run_tests(): void {
		// 測試開始前執行
		beforeAll(function () {});
		// 每次測試前執行
		beforeEach(
			function () {
				// 建立測試訂單
				// $this->order = Helper\Order::instance()->create()->get_order();
			}
			);
		// 每次測試後執行
		afterEach(
				function () {
					// Helper\Order::instance()->tear_down();
					// $this->order = null;
				}
			);
		// 測試結束後執行
		afterAll(function () {});

		it(
		"{$this->name}",
		function () {
			\wc_add_notice( '處理結帳時發生錯誤，請查閱 123 的 log 紀錄了解詳情', 'error' );
			$notice = WC()->session->get('wc_notices');
			STDOUT::debug($notice);
		}
		);
	}
}

DebugTest::instance();
