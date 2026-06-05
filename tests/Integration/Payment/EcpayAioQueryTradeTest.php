<?php
/**
 * 綠界 AIO 交易查詢 QueryTradeInfo 整合測試
 *
 * 對應任務：B4a 綠界 AIO 進階金流 — 交易查詢（對帳用）。
 *
 * API 出處（ECPay-API-Skill guides/01-payment-aio.md §查詢訂單 / 一般查詢，2026-06）：
 *  - 端點 /Cashier/QueryTradeInfo/V5（test: payment-stage / prod: payment）
 *  - 請求參數：MerchantID、MerchantTradeNo、TimeStamp（Unix 秒，3 分鐘有效期）、CheckMacValue（SHA256）
 *  - 回應為 `key=value&key=value...` 字串，內含 CheckMacValue 必須驗證
 *  - TradeStatus：0=未付款, 1=已付款, 10200095=交易未成立
 *  - DoAction/Query 連 Stage 都可用，但本測試以 API_MODE=mock 攔截避免外部相依
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\QueryTradeClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO 交易查詢測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group query
 */
final class EcpayAioQueryTradeTest extends TestCase {

	/** @var string 綠界測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** 每次測試前啟用 AIO（test 模式 + 測試帳號），開啟 MOCK */
	protected function configure_dependencies(): void {
		putenv( 'API_MODE=mock' );
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		putenv( 'API_MODE' );
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立綠界訂單（已寫入 MerchantTradeNo）
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_ecpay_order( float $total = 1000 ): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => $total,
			]
		);
		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_trade_no( 'MTN' . $order->get_id() );
		return $order;
	}

	// ========== 回應解析（可獨立測試，不需真 HTTP） ==========

	/**
	 * 解析 key=value 回應字串為陣列
	 *
	 * @test
	 * @group happy
	 */
	public function test_解析key_value回應字串(): void {
		// Given: 綠界 QueryTradeInfo 回應格式
		$client = new QueryTradeClient( $this->create_ecpay_order() );
		$body   = 'MerchantID=3002607&MerchantTradeNo=MTN123&TradeNo=2026060512345678&TradeAmt=1000&TradeStatus=1&PaymentType=Credit_CreditCard';

		// When
		$parsed = $client->parse_response( $body );

		// Then
		$this->assertSame( '3002607', $parsed['MerchantID'] );
		$this->assertSame( '1', $parsed['TradeStatus'] );
		$this->assertSame( 'Credit_CreditCard', $parsed['PaymentType'] );
		$this->assertSame( '1000', $parsed['TradeAmt'] );
	}

	/**
	 * 解析含 URL encode 值（PaymentDate 內含空格 +）的回應
	 *
	 * @test
	 * @group edge
	 */
	public function test_解析含url_encode值的回應(): void {
		// Given: PaymentDate 可能 url-encode（空格 → +）
		$client = new QueryTradeClient( $this->create_ecpay_order() );
		$body   = 'MerchantTradeNo=MTN123&PaymentDate=2026%2F06%2F05+12%3A30%3A00&TradeStatus=1';

		// When
		$parsed = $client->parse_response( $body );

		// Then: 還原為可讀日期
		$this->assertSame( '2026/06/05 12:30:00', $parsed['PaymentDate'] );
	}

	// ========== TradeStatus 語意判定 ==========

	/**
	 * TradeStatus=1 判定為已付款
	 *
	 * @test
	 * @group happy
	 */
	public function test_TradeStatus為1判定已付款(): void {
		$this->assertTrue( QueryTradeClient::is_paid( '1' ) );
		$this->assertFalse( QueryTradeClient::is_paid( '0' ) );
		$this->assertFalse( QueryTradeClient::is_paid( '10200095' ) );
	}

	// ========== 查詢流程（MOCK） ==========

	/**
	 * MOCK 模式查詢 → 回固定 fixture（含 TradeStatus / PaymentType）
	 *
	 * @test
	 * @group happy
	 */
	public function test_MOCK模式查詢回固定fixture(): void {
		// Given: MOCK 模式綠界訂單
		$order  = $this->create_ecpay_order( 1000 );
		$client = new QueryTradeClient( $order );

		// When: 依 MerchantTradeNo 查詢
		$result = $client->query();

		// Then: 回傳含交易狀態的陣列
		$this->assertArrayHasKey( 'TradeStatus', $result );
		$this->assertArrayHasKey( 'MerchantTradeNo', $result );
		$this->assertSame( 'MTN' . $order->get_id(), $result['MerchantTradeNo'] );
	}

	/**
	 * 缺 MerchantTradeNo 時查詢拋例外
	 *
	 * @test
	 * @group error
	 */
	public function test_缺MerchantTradeNo時查詢拋例外(): void {
		// Given: 未寫入 MerchantTradeNo 的訂單
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => 1000,
			]
		);

		// When / Then
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'MerchantTradeNo' );

		( new QueryTradeClient( $order ) )->query();
	}

	// ========== CheckMacValue 請求簽章一致性 ==========

	/**
	 * 查詢請求參數的 CheckMacValue 可重算驗證一致
	 *
	 * @test
	 * @group security
	 */
	public function test_查詢請求CheckMacValue重算一致(): void {
		// Given
		$order  = $this->create_ecpay_order();
		$client = new QueryTradeClient( $order );

		// When: 組裝查詢請求參數（含 TimeStamp + CheckMacValue）
		$params = $client->build_request_params();

		// Then: 以 form 參數（去 CheckMacValue）重算一致
		$cmv = $params['CheckMacValue'];
		unset( $params['CheckMacValue'] );
		$recalculated = CheckMacValueService::get_check_value( $params, self::HASH_KEY, self::HASH_IV, 'sha256' );
		$this->assertSame( $recalculated, $cmv );
		$this->assertMatchesRegularExpression( '/^[A-F0-9]{64}$/', $cmv );
	}
}
