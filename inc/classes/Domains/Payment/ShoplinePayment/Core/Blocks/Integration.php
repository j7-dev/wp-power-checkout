<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core\Blocks;

use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core\RedirectGateway;

if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
	return;
}

/**
 * RedirectGateway 跳轉支付區塊結帳整合
 *
 * @see https://developer.woocommerce.com/docs/block-development/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
 * */
final class Integration extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

	/** @var string 付款方式 id， 只是 AbstractPaymentMethodType 把它定義為 name */
	protected $name;

	/** @var \J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core\RedirectGateway 付款方式 */
	private RedirectGateway $gateway;

	/** @return void 初始化 */
	public function initialize() {
		$this->gateway  = new RedirectGateway();
		$this->name     = $this->gateway->id;
		$this->settings = \get_option("woocommerce_{$this->name}_settings", []);
	}

	/** @return boolean 是否啟用 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/** @return array<string> 區塊結帳支援的腳本 */
	public function get_payment_method_script_handles() {
		$handle = "wc-{$this->name}-blocks-integration";
		\wp_register_script(
					$handle,
					Plugin::$url . '/inc/classes/Domains/Payment/ShoplinePayment/Core/Blocks/checkout.js',
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

		// \wp_set_script_translations('ry-ecpay-atm-block', 'ry-woocommerce-tools-pro', RY_WTP_PLUGIN_LANGUAGES_DIR);

		return [ $handle ];
	}

	/**
	 * Returns an array of script handles to be enqueued for the admin.
	 *
	 * Include this if your payment method has a script you _only_ want to load in the editor context for the checkout block.
	 * Include here any script from `get_payment_method_script_handles` that is also needed in the admin.
	 */
	public function get_payment_method_script_handles_for_admin() {
		return $this->get_payment_method_script_handles();
	}


	/** @return array<string, mixed> 給前端取得的付款方式資料 */
	public function get_payment_method_data() {
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
