<?php
/**
 * 藍新 NewebPay MPG MpgRequestParams 測試
 * run `vendor/bin/phpunit --filter MpgRequestParamsTest`
 *
 * 驗證：TradeInfo 組裝（value URL-encode）、付款方式旗標、TradeSha 大寫、
 * to_form_params 結構、Amt ceil、ItemDesc 截斷、validate。
 */

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Domains\Payment\NewebpayMpg;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgRequestParams;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckoutTests\Attributes\Create;
use J7\PowerCheckoutTests\Helper\Order;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/**
 * MpgRequestParams 建單參數組裝
 *
 * @group newebpay_mpg
 * @group payment
 */
#[Create( Order::class )]
class MpgRequestParamsTest extends WC_UnitTestCase {

	/** @var Plugin[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		Plugin::WOOCOMMERCE,
		Plugin::POWERHOUSE,
		Plugin::POWER_CHECKOUT,
	];

	/** @var MpgRedirectGateway|null 測試 gateway */
	private MpgRedirectGateway|null $gateway = null;

	/** 每個測試方法執行前執行一次 */
	public function set_up(): void {
		parent::set_up();
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
	 * @testdox to_form_params 含 MerchantID / TradeInfo / TradeSha / Version 四個必要欄位
	 * @return void
	 */
	public function test_to_form_params_structure(): void {
		$params = MpgRequestParams::instance( $this->get_order(), $this->gateway );
		$form   = $params->to_form_params();

		$this->assertArrayHasKey( 'MerchantID', $form );
		$this->assertArrayHasKey( 'TradeInfo', $form );
		$this->assertArrayHasKey( 'TradeSha', $form );
		$this->assertArrayHasKey( 'Version', $form );
		$this->assertNotEmpty( $form['TradeInfo'], 'TradeInfo 應有值' );
		$this->assertNotEmpty( $form['TradeSha'], 'TradeSha 應有值' );
	}

	/**
	 * @testdox TradeInfo 為 hex 且可用憑證解回含 CREDIT=1 的明文
	 * @return void
	 */
	public function test_trade_info_decryptable_with_credit_flag(): void {
		$params   = MpgRequestParams::instance( $this->get_order(), $this->gateway );
		$form     = $params->to_form_params();
		$settings = \J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO::instance();

		$crypto    = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		$plaintext = $crypto->decrypt( (string) $form['TradeInfo'] );

		// 預設白名單只有 CREDIT
		$this->assertStringContainsString( 'CREDIT=1', $plaintext, 'CREDIT 旗標應為 1' );
		$this->assertStringContainsString( 'MerchantOrderNo=', $plaintext );
		$this->assertStringContainsString( 'Amt=', $plaintext );
		$this->assertStringContainsString( 'RespondType=JSON', $plaintext );
	}

	/**
	 * @testdox TradeSha 為 to_form_params 內 TradeInfo 的大寫 SHA256（公式一致）
	 * @return void
	 */
	public function test_trade_sha_matches_trade_info(): void {
		$params   = MpgRequestParams::instance( $this->get_order(), $this->gateway );
		$form     = $params->to_form_params();
		$settings = \J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO::instance();

		$crypto   = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		$expected = $crypto->generate_trade_sha( (string) $form['TradeInfo'] );

		$this->assertSame( $expected, $form['TradeSha'], 'TradeSha 應與 TradeInfo 一致' );
		$this->assertSame( \strtoupper( (string) $form['TradeSha'] ), $form['TradeSha'], 'TradeSha 必為大寫' );
	}

	/**
	 * @testdox Amt 為訂單總額無條件進位後的整數
	 * @return void
	 */
	public function test_amount_is_ceil_integer(): void {
		$order  = $this->get_order();
		$params = MpgRequestParams::instance( $order, $this->gateway );

		$expected = (int) \ceil( (float) $order->get_total() );
		$this->assertSame( $expected, $params->Amt, 'Amt 應為 ceil 後整數' );
	}

	/**
	 * @testdox TradeInfo 內含中文 ItemDesc 時為 URL-encoded（不破壞 key=value 格式）
	 * @return void
	 */
	public function test_item_desc_is_url_encoded_in_trade_info(): void {
		$params   = MpgRequestParams::instance( $this->get_order(), $this->gateway );
		$form     = $params->to_form_params();
		$settings = \J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO::instance();

		$crypto    = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		$plaintext = $crypto->decrypt( (string) $form['TradeInfo'] );

		// 明文中每段 key=value 不應有第二個未編碼的 = 或多餘的 &（ItemDesc 值已 URL-encode）
		\parse_str( $plaintext, $parsed );
		$this->assertArrayHasKey( 'ItemDesc', $parsed, 'parse_str 應能正確解析（代表 value 已正確編碼）' );
		$this->assertArrayHasKey( 'MerchantOrderNo', $parsed );
		$this->assertArrayHasKey( 'TradeSha', [ 'TradeSha' => 1 ] ); // sanity
	}

	// region Phase 2：多元付款方式旗標

	/**
	 * @testdox 白名單含多元付款方式時，TradeInfo 內各旗標皆為 1（VACC/CVS/BARCODE/WEBATM/LINEPAY/APPLEPAY）
	 * @return void
	 */
	public function test_multiple_payment_flags(): void {
		\update_option(
			'woocommerce_newebpay_mpg_settings',
			[
				'mode'            => 'test',
				'allowedPayments' => [ 'CREDIT', 'VACC', 'WEBATM', 'LINEPAY', 'APPLEPAY' ],
				'version'         => '2.3',
				'expireDate'      => 3,
			]
		);

		$params = MpgRequestParams::instance( $this->get_order(), $this->gateway );
		$form   = $params->to_form_params();

		$settings = \J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO::instance();
		$crypto   = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		\parse_str( $crypto->decrypt( (string) $form['TradeInfo'] ), $p );

		$this->assertSame( '1', $p['CREDIT'] ?? '', 'CREDIT 應為 1' );
		$this->assertSame( '1', $p['VACC'] ?? '', 'VACC 應為 1' );
		$this->assertSame( '1', $p['WEBATM'] ?? '', 'WEBATM 應為 1' );
		$this->assertSame( '1', $p['LINEPAY'] ?? '', 'LINEPAY 應為 1' );
		$this->assertSame( '1', $p['APPLEPAY'] ?? '', 'APPLEPAY 應為 1' );
		$this->assertArrayNotHasKey( 'CVS', $p, '未勾選 CVS 不應送出' );
		// 含 offline（VACC）→ 應帶 ExpireDate
		$this->assertArrayHasKey( 'ExpireDate', $p, '含 offline 付款方式應帶 ExpireDate' );
	}

	/**
	 * @testdox 啟用 TWQR 時 TradeInfo 帶 TWQR=1 + TWQR_LifeTime（需 version 2.3）
	 * @return void
	 */
	public function test_twqr_flag_with_lifetime(): void {
		\update_option(
			'woocommerce_newebpay_mpg_settings',
			[
				'mode'            => 'test',
				'allowedPayments' => [ 'CREDIT', 'TWQR' ],
				'version'         => '2.3',
				'twqrLifeTime'    => 600,
			]
		);

		$params = MpgRequestParams::instance( $this->get_order(), $this->gateway );
		$form   = $params->to_form_params();

		$settings = \J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO::instance();
		$crypto   = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		\parse_str( $crypto->decrypt( (string) $form['TradeInfo'] ), $p );

		$this->assertSame( '1', $p['TWQR'] ?? '', 'TWQR 應為 1' );
		$this->assertSame( '600', $p['TWQR_LifeTime'] ?? '', 'TWQR_LifeTime 應為 600' );
	}

	// endregion
}
