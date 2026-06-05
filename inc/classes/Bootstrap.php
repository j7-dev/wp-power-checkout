<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout;

use J7\PowerCheckout\Plugin;

if ( class_exists( 'J7\PowerCheckout\Bootstrap' ) ) {
	return;
}

/** Bootstrap */
final class Bootstrap {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		$is_compatible = self::check_compatibility();
		if (! $is_compatible ) {
			return;
		}

		Domains\Payment\ProviderRegister::register_hooks();
		Domains\Settings\Services\SettingApiService::register_hooks();
		Domains\Settings\Services\SettingTabService::register_hooks();
		Domains\Settings\Services\DefaultSetting::register_hooks();
		Domains\Invoice\ProviderRegister::register_hooks();
		Domains\Receipt\ProviderRegister::register_hooks();
		Domains\Logistics\ProviderRegister::register_hooks();

		\add_action( 'before_woocommerce_init', [ __CLASS__, 'declare_compatibility' ] );
		\add_action('admin_head', [ __CLASS__, 'custom_css' ]);
	}

	/**
	 * 檢查與 Powerhouse 外掛的相容性
	 *
	 * @return bool 回傳 Powerhouse 版本與相容性結果
	 */
	private static function check_compatibility(): bool {
		// 如果沒有安裝 Powerhouse ，就不用考慮相容性
		if (!\class_exists( '\J7\Powerhouse\Plugin')) {
			return true;
		}

		$min_ver       = '3.3.38';
		$ver           = \J7\Powerhouse\Plugin::$version;
		$is_compatible = \version_compare($ver, $min_ver, '>=');

		if (!$is_compatible) {
			\add_action(
			'admin_notices',
			static function () use ( $ver, $min_ver ) {
				\printf(
					'<div class="notice notice-error is-dismissible">
                                    <p><strong>錯誤：</strong>%1$s 無法啟用，Powerhouse 當前版本為 v%2$s 最小版本要求為 v%3$s，<a href="%4$s" target="_blank">前往安裝</a></p>
                                </div>
                                ',
					Plugin::$app_name,
					$ver,
					$min_ver,
					\admin_url('plugins.php')
				);
			}
			);
		}

		return $is_compatible;
	}


	/**
	 * 宣告區塊結帳相容性
	 *
	 * @return void
	 */
	public static function declare_compatibility(): void {
		if ( !class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		// 宣告 Blocks 結帳相容性
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			'power-checkout\plugin.php'
		);

		// 宣告 HPOS (High-Performance Order Storage) 相容性
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			'power-checkout\plugin.php',
			true
		);
	}

	/** @return void 自訂 css */
	public static function custom_css(): void {
		?>
		<style>
			.order_notes{
				.array_to_html{
					grid-template-columns: 72px 1fr;
				}
			}
			.array_to_html{
				display: grid;
				grid-template-columns: 1fr 2fr;
				gap: 0;width: 100%;
				align-items: start;
				font-size: 12px;
				justify-content: start;
				word-break: break-all;
				white-space: normal;

				& > div:nth-child(odd) {
					padding-right: 4px;
					font-weight: bold;
					border-bottom: 1px solid #aaa;
					height:100%;
				}

				& > div:nth-child(even) {
					border-bottom: 1px solid #aaa;
					height:100%;
					overflow-wrap: anywhere;
				}
			}
			
			.order_data_column > p {
				margin: 0;
			}
		</style>
		<?php
	}
}
