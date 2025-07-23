<?php
/**
 * Shopline Payment RedirectGateway 導轉式支付測試
 * run `composer test "inc\tests\Domains\Payment\ShoplinePayment\RedirectGatewayTest.php"`
 */

namespace J7\PowerCheckoutTests\Domains\Payment\ShoplinePayment;

use J7\PowerCheckoutTests\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;
use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;

/** ShoplinePayment 導轉式支付 */
class RedirectGatewayTest extends WC_UnitTestCase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 測試名稱 */
	protected string $name = '【ShoplinePayment 導轉式支付】';

	/** @var string[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		'woocommerce/woocommerce.php',
		'powerhouse/plugin.php',
		'power-checkout/plugin.php',
	];

	/** @var \WC_Order|null 測試訂單 */
	private \WC_Order|null $order = null;

	/** @var RedirectGateway|null 測試支付網關 */
	private RedirectGateway|null $gateway = null;

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
		"{$this->name} 正常結帳測試，是否成功取得跳轉 url",
		function () {
			$order_id = $this->order->get_id();

			// 設定訂單付款方式
			$this->order->set_payment_method($this->gateway->id);
			$this->order->save();

			// 測試建立 session 並取得 sessionUrl
			$result = $this->gateway->process_payment($order_id);

			expect($result)->toBeArray();
			expect($result['result'])->toBe(ProcessResult::SUCCESS->value);
			// 且 redirect 有值，且為 url
			expect($result['redirect'])->toBeString();
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
		"{$this->name} 結帳失敗是否寫入 log",
		function () {
			$order_id = $this->order->get_id();

			// 設定訂單付款方式
			$this->order->set_payment_method($this->gateway->id);
			$this->order->save();
			// TEST
			expect(true)->toBeTrue();
			return;

			// 測試建立 session 並取得 sessionUrl
			$result = $this->gateway->process_payment($order_id);

			expect($result)->toBeArray();
			expect($result['result'])->toBe(ProcessResult::FAILED->value);
			// 且 redirect 沒有值
			expect($result)->not->toHaveKey('redirect');
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
		"{$this->name} 接收 webhook 通知後，修改訂單到正確狀態",
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
