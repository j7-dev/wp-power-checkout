<?php

namespace J7\PowerCheckoutTests\Shared;

/**
 * Plugin 列舉
 * 可以取得外掛路徑
 */
enum Plugin:string {
    case WOOCOMMERCE = 'woocommerce/woocommerce.php';
    case POWERHOUSE = 'powerhouse/plugin.php';
    case POWER_CHECKOUT = 'power-checkout/plugin.php';
}