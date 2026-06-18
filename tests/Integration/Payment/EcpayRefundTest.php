<?php
/**
 * 綠界 ECPay 退款分流整合測試
 *
 * 對應規格：specs/features/payment/ecpay-refund.feature
 *
 * 驗證：
 *  - 非綠界訂單不由本 gateway 處理退款（is_this_gateway 擋下）
 *  - AIO 信用卡全額 / 部分退款 → DoAction(Action=R) + order note（MOCK 模式）
 *  - ECPG 信用卡退款 → ecpayment domain DoAction（MOCK 模式）
 *  - ATM 退款 → process_refund 回 WP_Error('refund_unsupported')，不呼叫 API
 *
 * 退款分流依據：綠界回傳並存於 _pc_ecpay_payment_detail 的 PaymentType（非前端），
 * 測試以 order meta fixture 設定。DoAction 僅正式環境可用，故以 API_MODE=mock 攔截。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayPaymentType;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 ECPay 退款分流測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group refund
 */
final class EcpayRefundTest extends TestCase {

	/** @var string 綠界測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** 每次測試前啟用 AIO 與 ECPG（test 模式 + 測試帳號），開啟 MOCK */
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
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'enabled'    => 'yes',
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
		EcpgSettingsDTO::reset();
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		putenv( 'API_MODE' );
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		delete_option( ProviderUtils::get_option_name( EcpgGateway::ID ) );
		EcpgSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * 建立綠界訂單並以 meta fixture 設定付款明細（PaymentType / TradeNo）
	 *
	 * @param string               $gateway_id     付款方式 ID
	 * @param array<string, mixed>  $payment_detail _pc_ecpay_payment_detail 內容（含 PaymentType / TradeNo）
	 * @param float                $total          訂單金額
	 * @return \WC_Order
	 */
	private function create_ecpay_order( string $gateway_id, array $payment_detail, float $total = 1000 ): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => $gateway_id,
				'total'          => $total,
			]
		);
		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_trade_no( 'MTN' . $order->get_id() );    // MerchantTradeNo
		$meta_keys->update_payment_detail( $payment_detail );        // 含綠界回傳 PaymentType / TradeNo
		return $order;
	}

	/**
	 * 建立一筆 gateway 退款（refunded_payment=true），不觸發 WC 內建 gateway 流程
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 退款金額
	 * @param string    $reason 退款原因
	 * @return \WC_Order_Refund
	 */
	private function create_gateway_refund( \WC_Order $order, float $amount, string $reason = '測試退款' ): \WC_Order_Refund {
		$refund = wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => $amount,
				'reason'   => $reason,
			]
		);
		// wc_create_refund 失敗會回 WP_Error
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$refund->set_refunded_payment( true ); // 標記為「經 gateway 退款」（非手動）
		$refund->save();
		return $refund;
	}

	// ========== 前置：訂單必須使用綠界 ECPay 付款 ==========

	/**
	 * 非綠界訂單不由綠界 gateway 處理退款
	 *
	 * @test
	 * @group happy
	 */
	public function test_非綠界訂單handle不處理退款(): void {
		// Given: 一筆 SLP 訂單（非綠界）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000,
			]
		);
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new AioRedirectGateway();

		// When: 對非綠界訂單呼叫 AIO 退款處理
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 不處理（refund 仍存在，無綠界退款成功 note）
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );
	}

	// ========== 信用卡（AIO）→ DoAction Action=R ==========

	/**
	 * AIO 信用卡訂單 process_refund 判定為可退款（信用卡類）
	 *
	 * @test
	 * @group happy
	 */
	public function test_AIO信用卡process_refund回true(): void {
		// Given: AIO 信用卡訂單
		$order   = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => '2026060412345678',
			]
		);
		$gateway = new AioRedirectGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '測試退款' );

		// Then: 信用卡類允許退款
		$this->assertTrue( $result );
	}

	/**
	 * AIO 信用卡全額退款 → DoAction 成功 + order note 記錄金額
	 *
	 * @test
	 * @group happy
	 */
	public function test_AIO信用卡全額退款成功並記錄order_note(): void {
		// Given: MOCK 模式 AIO 信用卡訂單
		$order   = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => '2026060412345678',
			],
			1000
		);
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new AioRedirectGateway();

		// When: 全額退款
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 退款成功 note 含金額 1000；refund 未被刪除
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款成功' );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '1000' );
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );
	}

	/**
	 * AIO 信用卡部分退款 → DoAction 成功 + order note 記錄部分金額
	 *
	 * @test
	 * @group happy
	 */
	public function test_AIO信用卡部分退款成功並記錄order_note(): void {
		// Given: MOCK 模式 AIO 信用卡訂單（原 1000，退 300）
		$order   = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => '2026060412345678',
			],
			1000
		);
		$refund  = $this->create_gateway_refund( $order, 300 );
		$gateway = new AioRedirectGateway();

		// When: 部分退款 300
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 退款成功 note 含金額 300
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款成功' );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '300' );
	}

	/**
	 * DoAction 回應解析：RtnCode=1 為成功
	 *
	 * @test
	 * @group happy
	 */
	public function test_DoAction回應pipe格式RtnCode為1時解析成功(): void {
		// Given
		$order  = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => '2026060412345678',
			]
		);
		$client = new DoActionClient( $order );

		// When: 解析綠界 pipe-separated 回應
		$result = $client->parse_response( '1|OK' );

		// Then
		$this->assertSame( '1', $result['RtnCode'] );
		$this->assertSame( 'OK', $result['RtnMsg'] );
	}

	/**
	 * DoAction 回應 RtnCode 非 1 → 拋例外並記錄 order note
	 *
	 * @test
	 * @group error
	 */
	public function test_DoAction回應RtnCode非1時拋例外(): void {
		// Given
		$order  = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'Credit_CreditCard',
				'TradeNo'     => '2026060412345678',
			]
		);
		$client = new DoActionClient( $order );

		// When / Then
		try {
			$client->parse_response( '10100050|退款失敗' );
			$this->fail( '預期 RtnCode 非 1 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '10100050', $e->getMessage() );
		}
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '10100050' );
	}

	/**
	 * DoAction 缺 TradeNo → 退款失敗、refund 被刪除
	 *
	 * @test
	 * @group error
	 */
	public function test_AIO缺TradeNo時退款失敗並刪除refund(): void {
		// Given: 付款明細缺 TradeNo
		$order     = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[ 'PaymentType' => 'Credit_CreditCard' ] // 無 TradeNo
		);
		$refund    = $this->create_gateway_refund( $order, 1000 );
		$refund_id = $refund->get_id();
		$gateway   = new AioRedirectGateway();

		// When
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 退款失敗 note + refund 被刪除（回滾）；wc_get_order 對不存在訂單回 false
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( wc_get_order( $refund_id ) );
	}

	// ========== 信用卡（ECPG）→ ecpayment domain DoAction ==========

	/**
	 * ECPG 信用卡訂單 process_refund 判定為可退款
	 *
	 * @test
	 * @group happy
	 */
	public function test_ECPG信用卡process_refund回true(): void {
		// Given: ECPG 信用卡訂單（巢狀 OrderInfo.PaymentType / OrderInfo.TradeNo）
		$order   = $this->create_ecpay_order(
			EcpgGateway::ID,
			[
				'OrderInfo' => [
					'PaymentType' => 'Credit',
					'TradeNo'     => '2026060498765432',
				],
			]
		);
		$gateway = new EcpgGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '測試退款' );

		// Then
		$this->assertTrue( $result );
	}

	/**
	 * ECPG 信用卡退款 → ecpayment domain DoAction 成功 + order note（MOCK）
	 *
	 * @test
	 * @group happy
	 */
	public function test_ECPG信用卡退款成功並記錄order_note(): void {
		// Given: MOCK 模式 ECPG 信用卡訂單（巢狀結構）
		$order   = $this->create_ecpay_order(
			EcpgGateway::ID,
			[
				'OrderInfo' => [
					'PaymentType' => 'Credit',
					'TradeNo'     => '2026060498765432',
				],
			],
			1000
		);
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new EcpgGateway();

		// When
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 站內付退款成功 note 含金額 1000
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '站內付' );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '1000' );
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );
	}

	// ========== 非信用卡（ATM/CVS/BARCODE/WebATM/ApplePay）→ 人工 ==========

	/**
	 * ATM 訂單 process_refund 回 WP_Error，不呼叫 API
	 *
	 * @test
	 * @group error
	 */
	public function test_ATM訂單process_refund回WP_Error不呼叫API(): void {
		// Given: AIO ATM 訂單
		$order   = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'ATM_TAISHIN',
				'TradeNo'     => '2026060411112222',
			]
		);
		$gateway = new AioRedirectGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '測試退款' );

		// Then: 回正規化 UNSUPPORTED \WP_Error（取代舊 refund_unsupported），錯誤訊息提示人工處理
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
		$this->assertStringContainsString( '人工處理', $result->get_error_message() );
	}

	/**
	 * ATM 訂單 handle 不呼叫 DoAction（雙重防禦），僅記錄人工提示
	 *
	 * @test
	 * @group error
	 */
	public function test_ATM訂單handle不發API僅記錄人工提示(): void {
		// Given: AIO ATM 訂單
		$order   = $this->create_ecpay_order(
			AioRedirectGateway::ID,
			[
				'PaymentType' => 'ATM_TAISHIN',
				'TradeNo'     => '2026060411112222',
			]
		);
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new AioRedirectGateway();

		// When
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 記錄人工提示，無「退款成功」note
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '人工處理' );
	}

	// ========== PaymentType 判定邏輯（單元層級） ==========

	/**
	 * EcpayPaymentType 信用卡前綴判定
	 *
	 * @test
	 * @group happy
	 */
	public function test_PaymentType信用卡前綴判定為信用卡(): void {
		// 信用卡類
		$this->assertTrue( EcpayPaymentType::is_credit( 'Credit_CreditCard' ) );
		$this->assertTrue( EcpayPaymentType::is_credit( 'Credit' ) );
		$this->assertTrue( EcpayPaymentType::is_credit( 'Flexible_Installment' ) );

		// 非信用卡（ATM/CVS/BARCODE/WebATM/ApplePay）
		$this->assertFalse( EcpayPaymentType::is_credit( 'ATM_TAISHIN' ) );
		$this->assertFalse( EcpayPaymentType::is_credit( 'CVS_CVS' ) );
		$this->assertFalse( EcpayPaymentType::is_credit( 'BARCODE_BARCODE' ) );
		$this->assertFalse( EcpayPaymentType::is_credit( 'WebATM_TAISHIN' ) );
		$this->assertFalse( EcpayPaymentType::is_credit( 'ApplePay' ) );
		$this->assertFalse( EcpayPaymentType::is_credit( '' ) );
	}
}
