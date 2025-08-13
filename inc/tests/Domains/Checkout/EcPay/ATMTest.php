<?php
/**
 * 模擬綠界結帳 ATM 櫃員機
 * run `composer test "inc\tests\Domains\Checkout\EcPay\ATMTest.php"`
 */

namespace J7\PowerCheckoutTests\Domains\Checkout\EcPay;

use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;

/** 綠界結帳 ATM 櫃員機 */
class ATMTest extends WC_UnitTestCase {
    use \J7\WpUtils\Traits\SingletonTrait;
    
    /** @var string 測試名稱 */
    protected string $name = '【綠界結帳 ATM 櫃員機】';
    
    /** @var Plugin[] 測試前需要安裝的插件 */
    protected array $required_plugins = [
        Plugin::WOOCOMMERCE,
        Plugin::POWERHOUSE,
        Plugin::POWER_CHECKOUT
    ];
    
    /** 測試主體 */
    protected function run_tests(): void {
        // 測試開始前執行
        beforeAll( function() {} );
        // 每次測試前執行
        beforeEach( function() {} );
        // 每次測試後執行
        afterEach( function() {} );
        // 測試結束後執行
        afterAll( function() {} );
        
        it(
            "{$this->name} 正常結帳測試，是否成功取得跳轉 url", function() {}
        );
        
        it(
            "{$this->name} 結帳金額超過最大金額", function() {}
        );
        
        it(
            "{$this->name} 結帳金額小於最小金額", function() {}
        );
        
        it(
            "{$this->name} 結帳失敗是否寫入 log", function() {}
        );
        
        it(
            "{$this->name} 訂單包含10種商品", function() {}
        );
        
        it(
            "{$this->name} 訂單金額小數點測試", function() {}
        );
        
        it(
            "{$this->name} 商品名稱包含特殊字符 & emoji", function() {}
        );
        
        it(
            "{$this->name} 超過時間未結帳就禁止付款", function() {}
        );
        
        it(
            "{$this->name} 接收 webhook 通知後，修改訂單到正確狀態", function() {}
        );
        
        it(
            "{$this->name} 測試環境與正式環境切換", function() {
            // 測試不同模式下的 API 端點
        }
        );
        
        it(
            "{$this->name} 重複付款防護測試", function() {
            // 測試同一訂單多次付款的處理
        }
        );
        
        it(
            "{$this->name} 訂單取消後不能付款", function() {
            // 測試已取消訂單的付款嘗試
        }
        );
    }
}

ATMTest::instance();
