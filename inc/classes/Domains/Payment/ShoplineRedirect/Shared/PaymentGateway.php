<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared;

use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;

/** Shopline 跳轉支付付款閘道抽象類別 */
abstract class PaymentGateway extends AbstractPaymentGateway {
	/** @var string 付款 icon */
	public $icon = 'https://img.shoplineapp.com/media/image_clips/62297669a344ad002979d725/original.png';
}
