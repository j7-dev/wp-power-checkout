<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Utils;

/** WC_UnitTestCase */
abstract class WC_UnitTestCase extends \WP_UnitTestCase{

	/** @var string[] */
	public $required_plugins = [
		'woocommerce/woocommerce.php'
	];

	/** Constructor */
	public function __construct()
	{
		\add_action('plugins_loaded', [$this, 'required_plugins'], -1);
		\do_action('plugins_loaded');
		\do_action('after_setup_theme');
		\do_action('init');
		\do_action('wp_loaded');
		\do_action('parse_request');
		\do_action('send_headers');
	}

	/**
	 * 載入 WooCommerce 插件
	 */
	public function required_plugins()
	{
		foreach ($this->required_plugins as $plugin) {
			require_once PLUGIN_DIR . $plugin;
		}
	}

}