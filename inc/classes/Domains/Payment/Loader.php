<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment;

/** Loader 載入付款方式 */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		ShoplineRedirect\Core\Init::instance();
	}
}
