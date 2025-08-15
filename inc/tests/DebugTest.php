<?php
/**
 * Debug Test
 * run `composer test:debug "inc\tests\DebugTest.php"`
 */

namespace J7\PowerCheckoutTests;

use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/** Debug Test */
class DebugTest extends WC_UnitTestCase {
    
    /** @var Plugin[] 測試前需要安裝的插件 */
    protected array $required_plugins = [
        Plugin::WOOCOMMERCE,
        Plugin::POWERHOUSE,
        Plugin::POWER_CHECKOUT,
    ];
    
    /**
     * @testdox 有 WC notice
     * @group   example
     */
    public function test_has_notice(): void {
        \wc_add_notice( '處理結帳時發生錯誤，請查閱 Woocommerce logger 紀錄了解詳情', 'error' );
        $notice = WC()->session->get( 'wc_notices' );
        $this->assertNotEmpty( $notice );
    }
    
    /**
     * @testdox 測試 do_action 函式是否存在
     */
    public function test_do_action_exist(): void {
        $this->assertTrue( function_exists( 'do_action' ) );
    }
    
    /**
     * @testdox      測試加法
     * @group        debug
     * @dataProvider provideTrimData
     */
    public function test_add( $a, $b, $expected ): void {
        $result = trim( $a + $b );
        $this->assertEquals( $expected, $result, "Expected {$expected} but got {$result} for inputs {$a} and {$b}" );
    }
    
    public function provideTrimData(): array {
        return [
            '正整數' => [ 1, 9, 10 ],
            '負數'   => [ -10, 2, -8 ],
            '小數'   => [ 1.5, 2.5, 4 ],
            '零'     => [ 0, 0, 0 ],
        ];
    }
    
}
