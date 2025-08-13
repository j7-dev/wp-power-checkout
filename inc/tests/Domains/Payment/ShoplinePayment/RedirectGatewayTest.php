<?php
/**
 * Shopline Payment RedirectGateway 導轉式支付測試
 * run `composer test "inc\tests\Domains\Payment\ShoplinePayment\RedirectGatewayTest.php"`
 */

namespace J7\PowerCheckoutTests\Domains\Payment\ShoplinePayment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\Service;
use J7\PowerCheckoutTests\Helper;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\Api;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\PaymentGateway;


/**
 * ShoplinePayment 導轉式支付
 *
 * @group slp
 * @group payment
 */
class RedirectGatewayTest extends WC_UnitTestCase {
    
    /** @var Plugin[] 測試前需要安裝的插件 */
    protected static array $required_plugins = [
        Plugin::WOOCOMMERCE,
        Plugin::POWERHOUSE,
        Plugin::POWER_CHECKOUT,
    ];
    
    /** @var \WC_Order|null 測試訂單 */
    private \WC_Order|null $order = null;
    
    /** @var PaymentGateway|null 測試支付網關 */
    private PaymentGateway|null $gateway = null;
    
    /** 每個測試方法執行前執行一次 */
    public function set_up(): void {
        // 建立測試訂單
        $this->order = Helper\Order::instance()->create()->get_order();
        $this->gateway = new RedirectGateway();
        // 設定訂單付款方式
        $this->order->set_payment_method( $this->gateway->id );
        $this->order->save();
    }
    
    /** 每個測試方法執行後執行一次 */
    public function tear_down(): void {
        Helper\Order::instance()->tear_down();
        $this->order = null;
        $this->gateway = null;
    }
    
    /**
     * @testdox 發起結帳請求，成功是否取得跳轉 url
     * @return void
     * @throws \Exception
     */
    public function test_payment_success(): void {
        $result = null;
        // 模擬 API 環境 - 不發請求
        if( Api::MOCK === $this->api ) {                // 這邊實例化 $service 看會不會報錯
            $service = new Service( $this->gateway, $this->order );
            $redirect = "https://pay-sandbox.shoplinepayments.com/checkout/session?sessionToken=BGPGC6M6A4A27OILWBY54WP4J5UDTY3BPE5SSMHPTTORKOPFRM2OWNYQ6C6KM4TFUYFQGWF3EMCDMRP7QHAZ2R3HADADXGYEQUEWJWDCZ32SLPR5EBKBMYGOCOOGZW4FIDKNHXQWAIS7US66XEBCBGZ5FM======--v1";
            $result = ProcessResult::SUCCESS->to_array( $redirect );
        }
        
        // 對金流測試環境發請求
        if( Api::SANDBOX === $this->api ) {
            // 測試建立 session 並取得 sessionUrl
            $result = $this->gateway->process_payment( $this->order->get_id() );
        }
        
        // 對金流正式環境發請求
        if( Api::LIVE === $this->api ) {
            throw new \Exception( '請求正式環境的測試尚未實作' );
            // TODO: 這邊要發請求到正式環境
        }
        
        // 且 redirect 有值，且為 url
        $this->assertIsArray( $result );
        $this->assertEquals(
            ProcessResult::SUCCESS->value, $result['result']
        );
        $this->assertIsString( $result['redirect'] );
    }
    
    /**
     * @testdox 發起結帳請求，失敗是否印出錯誤
     * @return void
     * @throws \Exception
     */
    public function test_payment_failed(): void {
        // 測試建立 session 並取得 sessionUrl 故意找不到訂單
        // API 還沒發出去就會 throw error 了
        $result = $this->gateway->process_payment( 0 );
        
        $this->assertIsArray( $result );
        $this->assertEquals( ProcessResult::FAILED->value, $result['result'] );
        // 且沒有 redirect 鍵
        $this->assertArrayNotHasKey( 'redirect', $result );
        
        // 結帳頁印出錯誤
        $notices = WC()->session->get( 'wc_notices' );
        $this->assertNotEmpty( $notices );
    }
    
    /**
     * @testdox 接收 SLP webhook 通知用戶付款失敗後，修改訂單到正確狀態
     * @return void
     */
    public function test_update_order_status_after_payment_failed(): void {
        $this->fail();
    }
    
    /**
     * @testdox 結帳金額超過最大金額
     * @return void
     */
    public function test_exceed_max_allowed_amount(): void {
        $this->fail();
    }
    
    
    /**
     * @testdox 結帳金額小於最小金額
     * @return void
     */
    public function test_below_min_allowed_amount(): void {
        $this->fail();
    }
    
    /**
     * @testdox 訂單包含10種商品
     * @return void
     */
    public function test_order_contains_10_products(): void {
        $this->fail();
    }
    
    /**
     * @testdox 訂單金額小數點測試
     * @return void
     */
    public function test_order_amount_with_decimal(): void {
        $this->fail();
    }
    
    /**
     * @testdox 商品名稱包含特殊字符 & emoji
     * @return void
     */
    public function test_product_name_with_special_characters_and_emoji(): void {
        $this->fail();
    }
    
    /**
     * @testdox 超過時間未結帳就禁止付款
     * @return void
     */
    public function test_payment_forbidden_after_timeout(): void {
        $this->fail();
    }
    
    /**
     * @testdox 測試環境與正式環境切換
     * @return void
     */
    public function test_switch_between_sandbox_and_live(): void {
        $this->fail();
    }
    
    /**
     * @testdox 重複付款防護測試
     * @return void
     */
    public function test_duplicate_payment_protection(): void {
        $this->fail();
    }
    
    /**
     * @testdox 訂單取消後不能付款
     * @return void
     */
    public function test_payment_not_allowed_after_order_cancelled(): void {
        $this->fail();
    }
    
}
