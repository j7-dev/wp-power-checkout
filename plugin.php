<?php
/**
 * Plugin Name:       Power Checkout | 極致順暢的 WooCommerce 結帳體驗
 * Plugin URI:        https://github.com/j7-dev/wp-power-checkout
 * Description:       串接金流、物流、電子發票，全面優化WooCommerce購物車、結帳頁、我的帳號等頁面，讓結帳轉換率一飛沖天
 * Version:           0.0.1
 * Requires at least: 5.7
 * Requires PHP:      8.1
 * Author:            J7
 * Author URI:        https://github.com/j7-dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power_checkout
 * Domain Path:       /languages
 * Tags: your tags
 */

declare (strict_types = 1);

namespace J7\PowerCheckout;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

if ( \class_exists( 'J7\PowerCheckout\Plugin' ) ) {
	return;
}
require_once __DIR__ . '/vendor/autoload.php';

/** Class Plugin */
final class Plugin {
	use \J7\WpUtils\Traits\PluginTrait;
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Constructor
	 */
	public function __construct() {

		self::$template_page_names = [ 'auto-form' ];

		$this->required_plugins = [
			[
				'name'     => 'WooCommerce',
				'slug'     => 'woocommerce',
				'required' => true,
				'version'  => '8.3.0',
			],
		];

		$this->init(
			[
				'app_name'    => 'Power Checkout',
				'github_repo' => 'https://github.com/j7-dev/wp-power-checkout',
				'callback'    => [ Bootstrap::class, 'instance' ],
				'lc'          => 'ZmFsc2',
			]
		);
	}
}

Plugin::instance();
