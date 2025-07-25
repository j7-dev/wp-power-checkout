<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Abstracts;

use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\Services;


/** EcPay 用付款閘道抽象類別 */
abstract class PaymentGateway extends AbstractPaymentGateway {

	/** @var string 付款 icon */
	public $icon = 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQMTjo4Y8SMNcXz0ZSm5Bg92fqHYYTICRTwPw&s';


	/**
	 * 提交表單
	 * 需透過前端網頁導轉(Submit)到綠界付款API網址
	 * 因為 auto-form 會中斷後續頁面渲染，就不會清空購物車，這邊要手動清空
	 *
	 * @see https://developers.ecpay.com.tw/?p=2872
	 * @param \WC_Order $order 訂單
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$service = new Services($this, $order);
		/** @var \WC_Order $order */
		$params = $service->get_params( $order, $this );

		Plugin::load_template(
				'auto-form',
				[
					'params' => $params,
					'url'    => $service->settings->aio_checkout_endpoint,
				]
				);

		// 自動送出表單到綠界後清除購物車
		\WC()->cart->empty_cart();
	}

	/** [後台]顯示錯誤訊息 WC_Admin_Settings */
	public function display_errors(): void {
		if ( $this->errors ) {
			foreach ( $this->errors as $error ) {
				\WC_Admin_Settings::add_error( $error );
			}
		}
	}
}
