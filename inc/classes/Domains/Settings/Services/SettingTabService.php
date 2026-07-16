<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\Base;
use J7\PowerCheckout\Shared\Utils\OrderUtils;

/**
 * WooCommerce 設定分頁服務
 */
class SettingTabService {

	/** @var string 儲存的 option_name */
	private const OPTION_NAME = 'power_checkout_settings';

	/** @var array{0:string, 1:string} 設定分頁 [value, label] */
	private static array $tab;

	/** @var string js,css handle */
	public static string $handle = 'power-checkout-wc-setting-tab';

	/**
	 * 取得存在 DB 中的 資料
	 *
	 * @param string $provider_key integration key
	 *
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( string $provider_key = '' ): array {
		$settings = \get_option( self::OPTION_NAME );
		$settings = \is_array( $settings ) ? $settings : [];
		if ( !$provider_key) {
			return $settings;
		}
		/** @var array<string, mixed> $result */
		$result = $settings[ $provider_key ] ?? [];
		return $result;
	}

	/** @param array<string, mixed> $value 儲存設定 */
	public static function save_settings( array $value ): void {
		\update_option( self::OPTION_NAME, $value );
	}


	/** Register hooks */
	public static function register_hooks(): void {
		self::$tab = [ 'power_checkout_wc_settings', \__( 'Power Checkout 設定', 'power_checkout' ) ];

		\add_action( 'woocommerce_settings_tabs_array', [ __CLASS__, 'add_settings_tab' ], 30);
		\add_action( 'admin_head', [ __CLASS__, 'hide_default_element' ]);
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ], 0 );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ], 0 );
	}

	/**
	 * 新增一個設定分頁到 WooCommerce 的設定分頁陣列。
	 *
	 * @param array<string, string> $settings_tabs WooCommerce 設定分頁及標籤的陣列，不包含訂閱分頁。
	 * @return array<string, string> $settings_tabs WooCommerce 設定分頁及標籤的陣列，包含訂閱分頁。
	 */
	public static function add_settings_tab( array $settings_tabs ): array {
		[$tab_key, $tab_label]     = self::$tab;
		$settings_tabs[ $tab_key ] = $tab_label;
		return $settings_tabs;
	}

	/** @return void 隱藏 WooCommerce 設定頁面中的預設元素 */
	public static function hide_default_element(): void {
		// 只在指定的 WC Settings tab 載入
		if (!self::is_current_tab()) { // phpcs:ignore
			return;
		}
		?>
		<style>
			#mainform{
				#message, .notice, .submit {
					display: none;
				}
			}
		</style>
		<?php
	}

	/** @return bool 是否為目前的設定分頁 */
	private static function is_current_tab(): bool {
		[$tab_key ] = self::$tab;
        if(!isset($_GET['page'], $_GET['tab'])) { // phpcs:ignore
			return false;
		}
        return 'wc-settings' === $_GET['page'] && $tab_key === $_GET['tab']; // phpcs:ignore
	}

	/**
	 * 載入腳本
	 *
	 * @param string $hook  當前頁面的 hook 名稱
	 *
	 * @return void
	 */
	public static function enqueue_scripts( $hook ): void { // phpcs:ignore

		if (\is_admin()) {
			if (!OrderUtils::is_order_detail($hook) && !self::is_current_tab() && !OrderUtils::is_order_list()) {
				return;
			}
		}

		\wp_register_script(
			self::$handle,
			Plugin::$url . '/js/dist/index.js',
			[ 'jquery' ],
			Plugin::$version,
			[
				'strategy'  => 'async',
				'in_footer' => true,
			]
		);

		$obj_name      = Plugin::$snake . '_data'; // power_checkout_data
		$post_id       = \get_the_ID();
		$raw_permalink = $post_id ? \get_permalink( $post_id ) : '';
		$permalink     = \is_string($raw_permalink) ? $raw_permalink : '';

		$order_statuses_kv = \wc_get_order_statuses();
		$order_statuses    = [];
		foreach ($order_statuses_kv as $value => $label) {
			$order_statuses[] = [
				'value' => $value,
				'label' => $label,
			];
		}

		\wp_localize_script(
			self::$handle,
			$obj_name,
			[
				'env' => [
					'SITE_URL'        => \untrailingslashit( \site_url() ),
					'API_URL'         => \untrailingslashit( \esc_url_raw( \rest_url() ) ),
					'CURRENT_USER_ID' => \get_current_user_id(),
					'CURRENT_POST_ID' => (int) $post_id,
					'PERMALINK'       => \untrailingslashit( $permalink ),
					'APP_NAME'        => Plugin::$app_name,
					'KEBAB'           => Plugin::$kebab,
					'SNAKE'           => Plugin::$snake,
					'NONCE'           => \wp_create_nonce( 'wp_rest' ),
					'APP1_SELECTOR'   => Base::APP1_SELECTOR,
					'IS_LOCAL'        => Plugin::$env === 'local',
					'ORDER_STATUSES'  => $order_statuses,
				],
			]
		);

		Plugin::instance()->add_module_handle( self::$handle);

		\wp_register_style(
			self::$handle,
			Plugin::$url . '/js/dist/index.css',
			[],
			Plugin::$version,
		);

        if(!self::is_current_tab()) { // phpcs:ignore
			return;
		}

		self::enqueue_vue_app();
	}

	/** @return void 載入 Vue App */
	public static function enqueue_vue_app(): void {
		// before_order_received 於 `wp` 階段呼叫本方法時，enqueue_scripts（掛在較晚的 wp_enqueue_scripts）
		// 尚未執行到 wp_register_script，handle 未註冊會導致後續 wp_localize_script 靜默丟棄資料
		// （例如 power_checkout_payuni_uni_data / power_checkout_ecpg_data 無法送達前端）。
		// 此處補上冪等註冊，確保呼叫端 localize 前 handle 必定已註冊。
		if (!\wp_script_is( self::$handle, 'registered' )) {
			\wp_register_script(
				self::$handle,
				Plugin::$url . '/js/dist/index.js',
				[ 'jquery' ],
				Plugin::$version,
				[
					'strategy'  => 'async',
					'in_footer' => true,
				]
			);
		}

		if (!\wp_style_is( self::$handle, 'registered' )) {
			\wp_register_style(
				self::$handle,
				Plugin::$url . '/js/dist/index.css',
				[],
				Plugin::$version,
			);
		}

		\wp_enqueue_script(self::$handle);
		\wp_enqueue_style(self::$handle);
	}
}
