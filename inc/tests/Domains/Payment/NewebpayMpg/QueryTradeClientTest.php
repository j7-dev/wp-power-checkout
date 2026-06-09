<?php
/**
 * 藍新 NewebPay MPG QueryTradeClient 測試（對帳查詢，NPA-B02）
 * run `vendor/bin/phpunit --filter QueryTradeClientTest`
 *
 * 驗證：CheckValue（IV/Key 鍵）組裝、parse_response、TradeStatus 判定、MOCK 不打真 API。
 */

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Domains\Payment\NewebpayMpg;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\QueryTradeClient;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckoutTests\Attributes\Create;
use J7\PowerCheckoutTests\Helper\Order;
use J7\PowerCheckoutTests\Shared\Plugin;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/**
 * QueryTradeClient 交易查詢
 *
 * @group newebpay_mpg
 * @group payment
 */
#[Create( Order::class )]
class QueryTradeClientTest extends WC_UnitTestCase {

	/** @var Plugin[] 測試前需要安裝的插件 */
	protected array $required_plugins = [
		Plugin::WOOCOMMERCE,
		Plugin::POWERHOUSE,
		Plugin::POWER_CHECKOUT,
	];

	/** 每個測試方法執行前執行一次 */
	public function set_up(): void {
		parent::set_up();
		$order = $this->get_order();
		$order->set_payment_method( MpgRedirectGateway::ID );
		( new MpgMetaKeys( $order ) )->update_order_no( 'PC' . $order->get_id() . 'TQ' );
		$order->save();
	}

	/** @return \WC_Order 取得訂單 */
	protected function get_order(): \WC_Order {
		return $this->get_container( Order::class )->get_item();
	}

	/**
	 * @testdox build_request_params 的 CheckValue 與 TradeInfoCrypto::generate_check_value 一致（IV/Key 鍵）
	 * @return void
	 */
	public function test_build_request_params_check_value(): void {
		$order  = $this->get_order();
		$client = new QueryTradeClient( $order );
		$params = $client->build_request_params();

		$settings = MpgSettingsDTO::instance();
		$crypto   = new TradeInfoCrypto( $settings->hashKey, $settings->hashIv );
		$expected = $crypto->generate_check_value(
			$settings->merchantId,
			(string) $params['MerchantOrderNo'],
			(int) $params['Amt']
		);

		$this->assertSame( $expected, $params['CheckValue'], 'CheckValue 不一致' );
		$this->assertSame( '1.3', $params['Version'], 'Version 應為 1.3' );
		$this->assertSame( 'JSON', $params['RespondType'] );
	}

	/**
	 * @testdox parse_response 解析成功回應的 Result，is_paid 對 TradeStatus=1 回 true
	 * @return void
	 */
	public function test_parse_response_and_is_paid(): void {
		$client = new QueryTradeClient( $this->get_order() );

		$json = \wp_json_encode(
			[
				'Status'  => 'SUCCESS',
				'Message' => '查詢成功',
				'Result'  => [
					'TradeStatus' => '1',
					'PaymentType' => 'CREDIT',
					'TradeNo'     => 'TN9',
					'Amt'         => 1000,
				],
			]
		);

		$result = $client->parse_response( (string) $json );
		$this->assertSame( '1', $result['TradeStatus'] ?? '' );
		$this->assertTrue( QueryTradeClient::is_paid( $result ), 'TradeStatus=1 應視為已付款' );
		$this->assertFalse( QueryTradeClient::is_paid( [ 'TradeStatus' => '0' ] ), 'TradeStatus=0 未付款' );
		$this->assertFalse( QueryTradeClient::is_paid( [ 'TradeStatus' => '6' ] ), 'TradeStatus=6 已退款非已付款' );
	}

	/**
	 * @testdox parse_response 對 Status 非 SUCCESS 拋出例外
	 * @return void
	 */
	public function test_parse_response_throws_on_failure(): void {
		$client = new QueryTradeClient( $this->get_order() );

		$json = \wp_json_encode(
			[
				'Status'  => 'TRA10001',
				'Message' => '查無交易',
			]
			);

		$this->expectException( \Exception::class );
		$client->parse_response( (string) $json );
	}

	/**
	 * @testdox MOCK 模式 query 回固定 fixture（不打真 API）
	 * @return void
	 */
	public function test_query_mock_mode(): void {
		// API_MODE=mock（composer test 預設）
		$result = ( new QueryTradeClient( $this->get_order() ) )->query();

		$this->assertSame( '1', $result['TradeStatus'] ?? '', 'MOCK 應回已付款 fixture' );
		$this->assertArrayHasKey( 'TradeNo', $result );
	}
}
