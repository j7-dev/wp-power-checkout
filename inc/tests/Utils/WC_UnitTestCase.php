<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Utils;

/**
 * WC_UnitTestCase
 *
 * 設計思想：
 * 1. 抽象出公用屬性，方便設定
 * 2. 預設已經先執行必要的生命週期，也可以自己添加
 * 3. 測試結束後，清除所有數據
 *    - 所有資源單例都儲存在 $GLOBALS 的測試用例 [__CLASS__] 中 $GLOBALS[__CLASS__] 中，裡面是資源實例組成的 array
 *    - 執行 tear_down 時，會遍歷 $GLOBALS[__CLASS__] 中的所有實例，並執行其 tear_down 方法
 *  */
abstract class WC_UnitTestCase {

	/** @var string 測試名稱 */
	protected string $name = '';

	/** @var string[] 每個測試都要載入的外掛 */
	protected array $required_plugins = [
		'woocommerce/woocommerce.php',
	];

	/** Constructor */
	protected function __construct() {
		// 載入必要外掛，做完主要的幾個生命週期
		\add_action('plugins_loaded', [ $this, 'required_plugins' ], -1);
		\do_action('plugins_loaded');
		\do_action('after_setup_theme');
		\do_action('init');
		\do_action('wp_loaded');
		\do_action('parse_request');
		\do_action('send_headers');

		// 運行測試
		$this->run_tests();

		// 運行完所有測試，清除所有數據
		$this->tear_down();
	}

	/** 載入 WooCommerce 插件  */
	public function required_plugins() {
		foreach ($this->required_plugins as $plugin) {
			require_once PLUGIN_DIR . $plugin;
		}
	}

	/** 測試結束後 刪除資料 */
	abstract protected function run_tests();

	/**
	 * 測試結束後 刪除資料
	 * 所有資源單例都儲存在 $GLOBALS 的測試用例 [__CLASS__] 中 $GLOBALS[__CLASS__] 中，裡面是資源實例組成的 array
	 * 執行 tear_down 時，會遍歷 $GLOBALS[__CLASS__] 中的所有實例，並執行其 tear_down 方法
	 * */
	protected function tear_down() {
		foreach ($GLOBALS[ static::class ] as $instance) {
			if (! method_exists($instance, 'tear_down')) {
				continue;
			}
			$instance->tear_down();
		}
	}
}
