<?php
/**
 * Debug Test
 * run `composer test:debug "inc\tests\DebugTest.php"`
 */

namespace J7\PowerCheckoutTests;

use J7\PowerCheckoutTests\Attributes\Create;
use J7\PowerCheckoutTests\Helper\Order;
use J7\PowerCheckoutTests\Helper\Product;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;
use J7\PowerCheckoutTests\Utils\STDOUT;

/** Debug Test */
#[Create(Product::class)]
class ExampleTest extends WC_UnitTestCase {

	/** @var Plugin[] 測試前需要安裝的插件 */
	protected static array $required_plugins = [
		Plugin::WOOCOMMERCE,
		Plugin::POWERHOUSE,
		Plugin::POWER_CHECKOUT,
	];
    
    /**
     * @testdox 有 WC notice
     * @group example
     */
    public function test_has_notice():void{
        \wc_add_notice( '處理結帳時發生錯誤，請查閱 Woocommerce logger 紀錄了解詳情', 'error' );
        $notice = WC()->session->get('wc_notices');
        $this->assertNotEmpty( $notice );
    }
}
