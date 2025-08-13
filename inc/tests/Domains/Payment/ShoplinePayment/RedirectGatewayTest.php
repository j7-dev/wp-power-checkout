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
use J7\PowerCheckoutTests\Utils\STDOUT;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\PaymentGateway;


/** ShoplinePayment 導轉式支付 */
class RedirectGatewayTest extends WC_UnitTestCase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 測試名稱 */
	protected string $name = '【ShoplinePayment 導轉式支付】';

	/** @var Plugin[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		Plugin::WOOCOMMERCE,
		Plugin::POWERHOUSE,
		Plugin::POWER_CHECKOUT,
	];

	/** @var \WC_Order|null 測試訂單 */
	private \WC_Order|null $order = null;
    
    /** @var string API 模式 */
    protected string $api_mode = 'mock';

	/** @var PaymentGateway|null 測試支付網關 */
	private PaymentGateway|null $gateway = null;

	/** 測試主體 */
	protected function run_tests(): void {
		// 測試開始前執行
		beforeAll(function () {});
		// 每次測試前執行
		beforeEach(
			function () {
				// 建立測試訂單
				$this->order   = Helper\Order::instance()->create()->get_order();
				$this->gateway = new RedirectGateway();
                // 設定訂單付款方式
                $this->order->set_payment_method($this->gateway->id);
                $this->order->save();
			}
			);
		// 每次測試後執行
		afterEach(
				function () {
					Helper\Order::instance()->tear_down();
					$this->order   = null;
					$this->gateway = null;
				}
			);
		// 測試結束後執行
		afterAll(function () {});

		it(
		"{$this->name} 發起結帳請求，成功是否取得跳轉 url",
		function ()  {
            $result = null;
            STDOUT::debug($this);
//            STDOUT::debug( Api::MOCK === $this->api_mode );
            
            
            // 模擬 API 環境 - 不發請求
            if(Api::MOCK === $this->api) {                // 這邊實例化 $service 看會不會報錯
                $service     = new Service( $this->gateway, $this->order );
                $redirect = "https://pay-sandbox.shoplinepayments.com/checkout/session?sessionToken=BGPGC6M6A4A27OILWBY54WP4J5UDTY3BPE5SSMHPTTORKOPFRM2OWNYQ6C6KM4TFUYFQGWF3EMCDMRP7QHAZ2R3HADADXGYEQUEWJWDCZ32SLPR5EBKBMYGOCOOGZW4FIDKNHXQWAIS7US66XEBCBGZ5FM======--v1";
                $result =  ProcessResult::SUCCESS->to_array( $redirect );
            }
            
            // 對金流測試環境發請求
            if(Api::SANDBOX === $this->api) {
                // 測試建立 session 並取得 sessionUrl
                $result = $this->gateway->process_payment( $this->order->get_id());
            }
            
            // 對金流正式環境發請求
            if(Api::LIVE === $this->api) {
                throw new \Exception( '請求正式環境的測試尚未實作' );
                // TODO: 這邊要發請求到正式環境
            }
            
            // 且 redirect 有值，且為 url
			expect( $result )->toBeArray()->and( $result['result'] )->toBe( ProcessResult::SUCCESS->value )->and(
                    $result['redirect']
                )->toBeString();
        }
		);

		it(
			"{$this->name} 發起結帳請求，失敗是否印出錯誤",
			function () {
    
				// 測試建立 session 並取得 sessionUrl 故意找不到訂單
                // API 還沒發出去就會 throw error 了
				$result = $this->gateway->process_payment(0);

				expect( $result )->toBeArray()->and( $result['result'] )->toBe( ProcessResult::FAILED->value )->and(
                        $result
                    )->not->toHaveKey( 'redirect' );
                // 且 redirect 沒有值
                
                // 結帳頁印出錯誤
				$notices = WC()->session->get('wc_notices');
				expect($notices)->toBeArray();
			}
		);

		it(
				"{$this->name} 接收 SLP webhook 通知用戶付款成功後，修改訂單到正確狀態",
				function () {
					// 會先通知 trade.succeeded
					// 然後通知 trade.customer_action
				}
		);

		it(
			"{$this->name} 接收 SLP webhook 通知用戶付款失敗後，修改訂單到正確狀態",
			function () {
			}
		);

		it(
		"{$this->name} 結帳金額超過最大金額",
		function () {
		}
		);

		it(
		"{$this->name} 結帳金額小於最小金額",
		function () {
		}
		);

		it(
		"{$this->name} 訂單包含10種商品",
		function () {
		}
		);

		it(
		"{$this->name} 訂單金額小數點測試",
		function () {
		}
		);

		it(
		"{$this->name} 商品名稱包含特殊字符 & emoji",
		function () {
		}
		);

		it(
		"{$this->name} 超過時間未結帳就禁止付款",
		function () {
		}
		);

		it(
		"{$this->name} 測試環境與正式環境切換",
		function () {
			// 測試不同模式下的 API 端點
		}
		);

		it(
		"{$this->name} 重複付款防護測試",
		function () {
			// 測試同一訂單多次付款的處理
		}
		);

		it(
		"{$this->name} 訂單取消後不能付款",
		function () {
			// 測試已取消訂單的付款嘗試
		}
		);
	}
}

RedirectGatewayTest::instance();
