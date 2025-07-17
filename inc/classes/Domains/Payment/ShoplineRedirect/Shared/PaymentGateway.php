<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared;

use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;

/** Shopline 跳轉支付付款閘道抽象類別 */
abstract class PaymentGateway extends AbstractPaymentGateway {

	/** @var string 付款 icon */
	public $icon = 'https://img.shoplineapp.com/media/image_clips/62297669a344ad002979d725/original.png';


	/** [後台]顯示錯誤訊息 WC_Admin_Settings */
	public function display_errors(): void {
		if ( $this->errors ) {
			foreach ( $this->errors as $error ) {
				\WC_Admin_Settings::add_error( $error );
			}
		}
	}
}
