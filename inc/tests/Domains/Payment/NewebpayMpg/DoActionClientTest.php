<?php
/**
 * 藍新 NewebPay MPG DoActionClient + 退款分流測試
 * run `vendor/bin/phpunit --filter DoActionClientTest`
 *
 * 驗證：退款分流（信用卡→true / e-wallet→true / ATM→WP_Error）、
 * DoAction parse_response（Status）、MOCK 不打真 API、EWallet 退款。
 */

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Domains\Payment\NewebpayMpg;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\EWalletRefundClient;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckoutTests\Attributes\Create;
use J7\PowerCheckoutTests\Helper\Order;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/**
 * DoActionClient 退款 / 請款 / 取消授權 + 退款分流
 *
 * @group newebpay_mpg
 * @group payment
 */
#[Create( Order::class )]
class DoActionClientTest extends WC_UnitTestCase {

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
		$this->gateway = new MpgRedirectGateway();
		$order         = $this->get_order();
		$order->set_payment_method( MpgRedirectGateway::ID );
		$order->save();
	}

	/** @return \WC_Order 取得訂單 */
	protected function get_order(): \WC_Order {
		return $this->get_container( Order::class )->get_item();
	}

	/**
	 * 設定訂單付款方式 meta（模擬藍新回傳的 PaymentType + TradeNo）
	 *
	 * @param string $payment_type PaymentType
	 * @return void
	 */
	private function set_payment_type( string $payment_type ): void {
		$order     = $this->get_order();
		$meta_keys = new MpgMetaKeys( $order );
		$meta_keys->update_payment_detail(
			[
				'PaymentType' => $payment_type,
				'TradeNo'     => '26060512345678',
			]
			);
		$meta_keys->update_trade_no( '26060512345678' );
	}

	/**
	 * @testdox 信用卡訂單 process_refund 回 true
	 * @return void
	 */
	public function test_process_refund_credit_returns_true(): void {
		$this->set_payment_type( 'CREDIT' );
		$result = $this->gateway->process_refund( $this->get_order()->get_id(), 100.0 );
		$this->assertTrue( $result, '信用卡退款應允許' );
	}

	/**
	 * @testdox e-wallet（LINEPAY）訂單 process_refund 回 true
	 * @return void
	 */
	public function test_process_refund_ewallet_returns_true(): void {
		$this->set_payment_type( 'LINEPAY' );
		$result = $this->gateway->process_refund( $this->get_order()->get_id(), 100.0 );
		$this->assertTrue( $result, 'e-wallet 退款應允許' );
	}

	/**
	 * @testdox ATM（VACC）訂單 process_refund 回 WP_Error(refund_unsupported)
	 * @return void
	 */
	public function test_process_refund_atm_returns_wp_error(): void {
		$this->set_payment_type( 'VACC' );
		$result = $this->gateway->process_refund( $this->get_order()->get_id(), 100.0 );
		$this->assertInstanceOf( \WP_Error::class, $result, 'ATM 退款應回 WP_Error' );
		$this->assertSame( 'refund_unsupported', $result->get_error_code() );
	}

	/**
	 * @testdox ApplePay 訂單 process_refund 回 WP_Error（無 API 退款）
	 * @return void
	 */
	public function test_process_refund_applepay_returns_wp_error(): void {
		$this->set_payment_type( 'APPLEPAY' );
		$result = $this->gateway->process_refund( $this->get_order()->get_id(), 100.0 );
		$this->assertInstanceOf( \WP_Error::class, $result, 'ApplePay 退款應回 WP_Error' );
	}

	/**
	 * @testdox DoAction parse_response 對 Status=SUCCESS 回 Result，非 SUCCESS 拋例外
	 * @return void
	 */
	public function test_doaction_parse_response(): void {
		$client = new DoActionClient( $this->get_order() );

		$ok = \wp_json_encode(
			[
				'Status'  => 'SUCCESS',
				'Message' => 'ok',
				'Result'  => [ 'TradeNo' => 'TN1' ],
			]
			);
		$this->assertSame( 'TN1', $client->parse_response( (string) $ok )['TradeNo'] ?? '' );

		$this->expectException( \Exception::class );
		$client->parse_response(
			(string) \wp_json_encode(
			[
				'Status'  => 'TRA10012',
				'Message' => '退款超過原金額',
			]
			)
			);
	}

	/**
	 * @testdox DoAction MOCK 模式退款回固定 fixture（不打真 API）
	 * @return void
	 */
	public function test_doaction_mock_refund(): void {
		$result = ( new DoActionClient( $this->get_order() ) )->refund( '26060512345678', 100.0 );
		$this->assertIsArray( $result, 'MOCK 退款應回陣列' );
	}

	/**
	 * @testdox DoAction 缺 TradeNo 時拋例外
	 * @return void
	 */
	public function test_doaction_missing_trade_no_throws(): void {
		$this->expectException( \Exception::class );
		( new DoActionClient( $this->get_order() ) )->refund( '', 100.0 );
	}

	/**
	 * @testdox EWalletRefundClient 對不支援的 PaymentType 拋例外
	 * @return void
	 */
	public function test_ewallet_unsupported_type_throws(): void {
		$this->expectException( \Exception::class );
		( new EWalletRefundClient( $this->get_order() ) )->refund( 'TN1', 100.0, 'CREDIT' );
	}

	/**
	 * @testdox EWalletRefundClient MOCK 模式退款回固定 fixture
	 * @return void
	 */
	public function test_ewallet_mock_refund(): void {
		$result = ( new EWalletRefundClient( $this->get_order() ) )->refund( 'TN1', 100.0, 'LINEPAY' );
		$this->assertIsArray( $result );
	}
}
