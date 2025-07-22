<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\Log;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;

/**
 * Checkout class
 */
class Checkout extends WC_UnitTestCase
{
	use \J7\WpUtils\Traits\SingletonTrait;

	public $required_plugins = [
		'woocommerce/woocommerce.php',
		'powerhouse/plugin.php',
		'power-checkout/plugin.php'
	];

	/** 測試結束後 刪除資料 */
	public function tear_down(){}

}
