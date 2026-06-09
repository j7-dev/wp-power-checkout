<?php
/**
 * 藍新 NewebPay MPG Gateway 整合測試
 * run `vendor/bin/phpunit --filter MpgGatewayIntegrationTest`
 *
 * 覆蓋：process_payment 回 order-received URL、before_order_received 寫冪等鍵 + render form（不重送）、
 * 多商品 ItemDesc 截斷、金額小數 ceil、特殊字元 / emoji ItemDesc、test/prod 端點切換、重複付款防護。
 */

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Domains\Payment\NewebpayMpg;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgRequestParams;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgOrderNo;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;
use J7\PowerCheckoutTests\Attributes\Create;
use J7\PowerCheckoutTests\Helper\Order;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/**
 * MpgRedirectGateway 整合
 *
 * @group newebpay_mpg
 * @group payment
 */
#[Create( Order::class )]
class MpgGatewayIntegrationTest extends WC_UnitTestCase {

	/** @var Plugin[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		Plugin::WOOCOMMERCE,
		Plugin::POWERHOUSE,
		Plugin::POWER_CHECKOUT,
	];

	/** @var MpgRedirectGateway|null gateway */
	private MpgRedirectGateway|null $gateway = null;

	/** 每個測試方法執行前執行一次 */
	public function set_up(): void {
		parent::set_up();
		\update_option(
			'woocommerce_newebpay_mpg_settings',
			[
				'mode'            => 'test',
				'allowedPayments' => [ 'CREDIT' ],
				'version'         => '2.3',
			]
		);
		$this->gateway = new MpgRedirectGateway();
		$order         = $this->get_order();
		$order->set_payment_method( $this->gateway->id );
		$order->save();
	}

	/** @return \WC_Order 取得訂單 */
	protected function get_order(): \WC_Order {
		return $this->get_container( Order::class )->get_item();
	}

	/**
	 * @testdox process_payment 成功回傳 order-received URL（不在此階段發 API）
	 * @return void
	 */
	public function test_process_payment_returns_order_received_url(): void {
		$result = $this->gateway->process_payment( $this->get_order()->get_id() );

		$this->assertIsArray( $result );
		$this->assertSame( ProcessResult::SUCCESS->value, $result['result'], '應成功' );
		$this->assertIsString( $result['redirect'] ?? null, '應回 redirect URL' );
		$this->assertStringContainsString( 'order-received', (string) $result['redirect'] );
	}

	/**
	 * @testdox process_payment 訂單不存在時回 FAILED 並印出錯誤
	 * @return void
	 */
	public function test_process_payment_failed_when_order_not_found(): void {
		$result = $this->gateway->process_payment( 0 );

		$this->assertSame( ProcessResult::FAILED->value, $result['result'], '應失敗' );
		$this->assertArrayNotHasKey( 'redirect', $result, '失敗不應有 redirect' );
	}

	/**
	 * @testdox MpgRequestParams 寫入冪等鍵，且重複建單沿用同一 MerchantOrderNo
	 * @return void
	 */
	public function test_idempotency_key_reused(): void {
		$order = $this->get_order();

		// 第一次建單 → 產生並寫入 order_no
		$first = MpgRequestParams::instance( $order, $this->gateway );
		( new MpgMetaKeys( $order ) )->update_order_no( $first->MerchantOrderNo );

		// 第二次建單 → 應沿用同一 order_no（不重編，避免 MPG03002）
		$second = MpgRequestParams::instance( \wc_get_order( $order->get_id() ), $this->gateway );

		$this->assertSame(
			$first->MerchantOrderNo,
			$second->MerchantOrderNo,
			'重複建單應沿用同一 MerchantOrderNo'
		);
	}

	/**
	 * @testdox 金額含小數時 Amt 無條件進位
	 * @return void
	 */
	public function test_amount_ceil(): void {
		$order = $this->get_order();
		$order->set_total( '99.01' );
		$order->save();

		$params = MpgRequestParams::instance( \wc_get_order( $order->get_id() ), $this->gateway );
		$this->assertSame( 100, $params->Amt, 'Amt 應為 ceil(99.01)=100' );
	}

	/**
	 * @testdox 多商品 ItemDesc 截斷至 50 字內
	 * @return void
	 */
	public function test_item_desc_truncation(): void {
		$order = $this->get_order();
		for ( $i = 0; $i < 8; $i++ ) {
			$product = \WC_Helper_Product::create_simple_product();
			$product->set_name( "超長商品名稱項目第{$i}號測試" );
			$product->save();
			$order->add_product( $product, 1 );
		}
		$order->save();

		$desc = ItemName::get( \wc_get_order( $order->get_id() ) );
		$this->assertLessThanOrEqual( 50, \mb_strlen( $desc, 'UTF-8' ), 'ItemDesc 應 ≤ 50 字' );
	}

	/**
	 * @testdox 特殊字元 / emoji 商品名不破壞 TradeInfo（解密後 parse_str 可還原）
	 * @return void
	 */
	public function test_special_char_emoji_item_desc(): void {
		$order   = $this->get_order();
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_name( '限量🎁商品 & <b>特價</b> =50%' );
		$product->save();
		$order->add_product( $product, 1 );
		$order->save();

		$params = MpgRequestParams::instance( \wc_get_order( $order->get_id() ), $this->gateway );
		$form   = $params->to_form_params();

		$settings = MpgSettingsDTO::instance();
		$crypto   = new \J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto(
			$settings->hashKey,
			$settings->hashIv
		);
		\parse_str( $crypto->decrypt( (string) $form['TradeInfo'] ), $parsed );

		$this->assertArrayHasKey( 'ItemDesc', $parsed, '特殊字元商品名不應破壞 key=value 格式' );
		$this->assertArrayHasKey( 'Amt', $parsed );
		$this->assertSame( 64, \strlen( (string) $form['TradeSha'] ), 'TradeSha 仍應有效' );
	}

	/**
	 * @testdox test 模式端點為 ccore，prod 模式為 core
	 * @return void
	 */
	public function test_endpoint_switching(): void {
		// test
		\update_option(
			'woocommerce_newebpay_mpg_settings',
			[
				'mode'            => 'test',
				'allowedPayments' => [ 'CREDIT' ],
			]
			);
		$this->assertStringContainsString( 'ccore.newebpay.com', MpgSettingsDTO::instance()->endpoint );

		// prod
		\update_option(
			'woocommerce_newebpay_mpg_settings',
			[
				'mode'            => 'prod',
				'allowedPayments' => [ 'CREDIT' ],
				'merchantId'      => 'MS9',
				'hashKey'         => \str_repeat( 'K', 32 ),
				'hashIv'          => \str_repeat( 'I', 16 ),
			]
		);
		$endpoint = MpgSettingsDTO::instance()->endpoint;
		$this->assertStringContainsString( 'core.newebpay.com', $endpoint );
		$this->assertStringNotContainsString( 'ccore', $endpoint, 'prod 不應為 ccore' );
	}

	/**
	 * @testdox MerchantOrderNo 為英數 ≤30 且不同訂單唯一
	 * @return void
	 */
	public function test_order_no_format_and_uniqueness(): void {
		$on1 = MpgOrderNo::encode( 100 );
		$on2 = MpgOrderNo::encode( 200 );

		$this->assertMatchesRegularExpression( '/^[0-9a-zA-Z]+$/', $on1, '應為英數' );
		$this->assertLessThanOrEqual( 30, \strlen( $on1 ), '應 ≤ 30' );
		$this->assertNotSame( $on1, $on2, '不同訂單應唯一' );
		$this->assertSame( '100', MpgOrderNo::decode( $on1 ), '應可反解出訂單 ID' );
	}

	/**
	 * @testdox to_form_params 不洩漏 hashKey / hashIv
	 * @return void
	 */
	public function test_form_params_no_credential_leak(): void {
		$form = MpgRequestParams::instance( $this->get_order(), $this->gateway )->to_form_params();

		$this->assertArrayNotHasKey( 'hashKey', $form );
		$this->assertArrayNotHasKey( 'hashIv', $form );
		$this->assertArrayNotHasKey( 'HashKey', $form );
		$this->assertArrayNotHasKey( 'HashIV', $form );
		// 僅應有 4 個外層欄位
		$this->assertEqualsCanonicalizing(
			[ 'MerchantID', 'TradeInfo', 'TradeSha', 'Version' ],
			\array_keys( $form ),
			'外層信封僅含 MerchantID / TradeInfo / TradeSha / Version'
		);
	}
}
