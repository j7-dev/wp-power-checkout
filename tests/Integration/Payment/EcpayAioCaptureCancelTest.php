<?php
/**
 * 綠界 AIO 信用卡請款（Action=C）/ 取消授權（Action=N）整合測試
 *
 * 對應任務：B4a 綠界 AIO 進階金流 — 請款 / 取消授權。
 *
 * API 出處（ECPay-API-Skill guides/01-payment-aio.md §信用卡請款 / 退款 / 取消，2026-06）：
 *  - DoAction 端點 /CreditDetail/DoAction，回應 pipe-separated `RtnCode|RtnMsg`
 *  - Action=C 請款（關帳）、Action=N 放棄（取消請款 / 取消授權）、Action=E 取消關帳、Action=R 退款
 *  - TradeNo 必須是綠界回傳的交易編號（存於 _pc_ecpay_payment_detail），非 MerchantTradeNo
 *  - DoAction 僅正式環境可用，故 MOCK 攔截回 `1|OK`
 *
 * 語意：授權後請款(C)；請款前取消授權(N)。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO 請款 / 取消授權測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group capture
 */
final class EcpayAioCaptureCancelTest extends TestCase {

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
	 * 建立綠界信用卡訂單並以 meta fixture 設定付款明細（PaymentType / TradeNo）
	 *
	 * @param array<string, mixed> $payment_detail _pc_ecpay_payment_detail 內容
	 * @param float                $total          訂單金額
	 * @return \WC_Order
	 */
	private function create_credit_order( array $payment_detail = [], float $total = 1000 ): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => AioRedirectGateway::ID,
				'total'          => $total,
			]
		);
		$detail    = array_merge(
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => '2026060512345678',
			],
			$payment_detail
		);
		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_trade_no( 'MTN' . $order->get_id() );
		$meta_keys->update_payment_detail( $detail );
		return $order;
	}

	// ========== 請款 Capture（Action=C） ==========

	/**
	 * DoActionClient::capture 對 MOCK 回 RtnCode=1（client 層僅回應，note / meta 由 gateway handler 負責）
	 *
	 * @test
	 * @group happy
	 */
	public function test_DoActionClient請款回應RtnCode為1(): void {
		// Given: MOCK 模式信用卡訂單
		$order  = $this->create_credit_order( [], 1000 );
		$client = new DoActionClient( $order );

		// When: 請款（Action=C），金額為授權全額
		$result = $client->capture( '2026060512345678', 1000 );

		// Then: RtnCode=1
		$this->assertSame( '1', $result['RtnCode'] );
		$this->assertSame( 'OK', $result['RtnMsg'] );
	}

	/**
	 * 請款缺 TradeNo → 拋例外並記錄 order note
	 *
	 * @test
	 * @group error
	 */
	public function test_請款缺TradeNo時拋例外(): void {
		// Given
		$order  = $this->create_credit_order();
		$client = new DoActionClient( $order );

		// When / Then
		try {
			$client->capture( '', 1000 );
			$this->fail( '預期缺 TradeNo 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'TradeNo', $e->getMessage() );
		}
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), 'TradeNo' );
	}

	/**
	 * 請款回應 RtnCode 非 1 → 拋例外並記錄 order note
	 *
	 * @test
	 * @group error
	 */
	public function test_請款回應RtnCode非1時拋例外(): void {
		// Given
		$order  = $this->create_credit_order();
		$client = new DoActionClient( $order );

		// When / Then
		try {
			$client->parse_response( '10200047|不存在' );
			$this->fail( '預期 RtnCode 非 1 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '10200047', $e->getMessage() );
		}
	}

	// ========== 取消授權 Cancel-Auth（Action=N） ==========

	/**
	 * DoActionClient::cancel_auth 對 MOCK 回 RtnCode=1（client 層僅回應）
	 *
	 * @test
	 * @group happy
	 */
	public function test_DoActionClient取消授權回應RtnCode為1(): void {
		// Given: MOCK 模式信用卡訂單
		$order  = $this->create_credit_order( [], 1000 );
		$client = new DoActionClient( $order );

		// When: 取消授權（Action=N），金額為原授權全額
		$result = $client->cancel_auth( '2026060512345678', 1000 );

		// Then: RtnCode=1
		$this->assertSame( '1', $result['RtnCode'] );
		$this->assertSame( 'OK', $result['RtnMsg'] );
	}

	// ========== 後台訂單操作 woocommerce_order_actions ==========

	/**
	 * 信用卡 AIO 訂單於後台訂單操作清單出現請款 / 取消授權選項
	 *
	 * @test
	 * @group happy
	 */
	public function test_信用卡訂單後台訂單操作含請款與取消授權(): void {
		// Given: AIO 信用卡訂單
		$order = $this->create_credit_order();

		// When: 套用 woocommerce_order_actions filter
		$actions = AioRedirectGateway::add_order_actions( [], $order );

		// Then: 含請款 / 取消授權兩個動作
		$this->assertArrayHasKey( 'pc_ecpay_aio_capture', $actions );
		$this->assertArrayHasKey( 'pc_ecpay_aio_cancel_auth', $actions );
	}

	/**
	 * 非信用卡（ATM）AIO 訂單不出現請款 / 取消授權選項
	 *
	 * @test
	 * @group edge
	 */
	public function test_ATM訂單後台訂單操作不含請款選項(): void {
		// Given: AIO ATM 訂單（非信用卡）
		$order = $this->create_credit_order( [ 'PaymentType' => 'ATM_TAISHIN' ] );

		// When
		$actions = AioRedirectGateway::add_order_actions( [], $order );

		// Then: 不含請款 / 取消授權
		$this->assertArrayNotHasKey( 'pc_ecpay_aio_capture', $actions );
		$this->assertArrayNotHasKey( 'pc_ecpay_aio_cancel_auth', $actions );
	}

	/**
	 * 非綠界 AIO 訂單不出現請款選項
	 *
	 * @test
	 * @group edge
	 */
	public function test_非綠界訂單後台訂單操作不含請款選項(): void {
		// Given: SLP 訂單
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000,
			]
		);

		// When
		$actions = AioRedirectGateway::add_order_actions( [], $order );

		// Then
		$this->assertArrayNotHasKey( 'pc_ecpay_aio_capture', $actions );
	}

	/**
	 * 後台觸發請款 handler → 對信用卡訂單呼叫 DoAction(C) 並記錄成功（MOCK）
	 *
	 * @test
	 * @group happy
	 */
	public function test_後台觸發請款handler成功記錄order_note(): void {
		// Given: AIO 信用卡訂單
		$order = $this->create_credit_order( [], 1000 );

		// When: 觸發後台請款 handler
		AioRedirectGateway::handle_capture_action( $order );

		// Then: 請款成功 note
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '請款' );
		$captured = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_capture_status();
		$this->assertSame( 'captured', $captured );
	}

	/**
	 * 後台觸發取消授權 handler → 對信用卡訂單呼叫 DoAction(N) 並記錄成功（MOCK）
	 *
	 * @test
	 * @group happy
	 */
	public function test_後台觸發取消授權handler成功記錄order_note(): void {
		// Given: AIO 信用卡訂單
		$order = $this->create_credit_order( [], 1000 );

		// When: 觸發後台取消授權 handler
		AioRedirectGateway::handle_cancel_auth_action( $order );

		// Then: 取消授權成功 note
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '取消授權' );
		$captured = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_capture_status();
		$this->assertSame( 'voided', $captured );
	}

	/**
	 * 後台觸發請款 handler 對非信用卡訂單僅記錄人工提示，不改 capture 狀態
	 *
	 * @test
	 * @group error
	 */
	public function test_後台請款handler對ATM訂單僅記錄人工提示(): void {
		// Given: AIO ATM 訂單
		$order = $this->create_credit_order( [ 'PaymentType' => 'ATM_TAISHIN' ] );

		// When
		AioRedirectGateway::handle_capture_action( $order );

		// Then: 不支援，capture 狀態維持空
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '不支援' );
		$captured = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_capture_status();
		$this->assertSame( '', $captured );
	}
}
