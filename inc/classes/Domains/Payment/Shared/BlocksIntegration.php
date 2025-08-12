<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared;

use J7\PowerCheckout\Plugin;

if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
	return;
}

/**
 * 區塊結帳整合
 *
 * @see https://developer.woocommerce.com/docs/block-development/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
 * 使用方法
 * 1. 繼承這個類
 * 2. 設定 name (與付款方式 id 相同)
 * 3. 設定 $gateway 屬性
 * */
class BlocksIntegration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/** @var string 付款方式 id， 只是 AbstractPaymentMethodType 把它定義為 name */
	protected $name;

	/** @param AbstractPaymentGateway $gateway 付款方式 */
	public function __construct( private readonly AbstractPaymentGateway $gateway ) {
		$this->name    = $gateway->id;
	}

	/**
	 * @return void 初始化
	 * @throws \Exception 如果未設定 $gateway 或 name 與付款方式 id 不同
	 * */
	public function initialize(): void {
		if ($this->name !== $this->gateway->id) {
			throw new \Exception('AbstractBlocksIntegration 的 name 必須與付款方式 id 相同');
		}
		$this->settings = \get_option("woocommerce_{$this->name}_settings", []);
	}

	/** @return boolean 是否啟用 */
	public function is_active(): bool {
		return $this->gateway->is_available();
	}

	/** @return array<string> 區塊結帳支援的腳本 */
	public function get_payment_method_script_handles(): array {
		$handle = "wc-{$this->name}-blocks-integration";
		\wp_register_script(
					$handle,
					Plugin::$url . "/inc/assets/dist/blocks/{$this->name}.js",
					[
						'react',
						'wc-blocks-registry',
						'wc-settings',
						'wp-element',
						'wp-html-entities',
						'wp-i18n',
					],
					Plugin::$version,
					true
			);

		Plugin::instance()->add_module_handle($handle);

		// \wp_set_script_translations('ry-ecpay-atm-block', 'ry-woocommerce-tools-pro', RY_WTP_PLUGIN_LANGUAGES_DIR);

		return [ $handle ];
	}
    
    /** @return array<string, mixed> 給前端取得的付款方式資料 */
	public function get_payment_method_data(): array {
		return [
			'name'              => $this->name,
			'title'             => $this->gateway->payment_label,
			'description'       => $this->gateway->description,
			'supports'          => $this->gateway->supports,
			'order_button_text' => $this->gateway->order_button_text,
			'icons'             => [
				'src' => $this->gateway->icon,
				'alt' => $this->gateway->payment_label,
			],
		];
	}
}
