<?php
/**
 * 綠界站內付 2.0（ECPG）非信用卡幕後取號整合測試（B4b）
 *
 * 驗證 ATM 虛擬帳號 / CVS 超商代碼 / BARCODE 超商條碼 的站內付 2.0 幕後取號：
 *  - allowedPayments 預設含 ATM/CVS/BARCODE
 *  - GetTokenParams 接受 ATMInfo / CVSInfo / BarcodeInfo（依 ChoosePaymentList）
 *  - EcpgApiClient::get_code() 取號（GetToken → CreatePayment 直接用 Token）→ 回各自取號資訊
 *  - EcpgGateway::before_process_payment 非信用卡分支 → 寫 _pc_ecpay_payment_info + order note，訂單維持 pending
 *  - 取號 ≠ 付款：訂單維持待付款，付款完成由 ReturnURL（RtnCode=1）流轉 processing（沿用 StatusManager）
 *
 * 真 API 呼叫以 API_MODE=mock 攔截（不打綠界）；取號資訊解析以 parse_response()
 * 搭配真 AES 加密 Data 直接驗證，不需 HTTP。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02b-ecpg-atm-cvs-spa.md
 * @see https://developers.ecpay.com.tw/9053.md（CreatePayment 非信用卡取號回應欄位）
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\GetTokenParams;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgApiClient;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\EcpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界站內付 2.0 非信用卡幕後取號測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group ecpg
 */
final class EcpgGetCodeTest extends TestCase {

	/** @var string 綠界 ECPG 線上金流測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 線上金流測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** 每次測試前啟用 ecpay_ecpg（test 模式 + 測試帳號） */
	protected function configure_dependencies(): void {
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
		putenv( 'API_MODE=mock' );
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( EcpgGateway::ID ) );
		EcpgSettingsDTO::reset();
		// 還原套件預設 API_MODE=mock，避免外洩到後續依賴 mock 的測試
		putenv( 'API_MODE=mock' );
		parent::tear_down();
	}

	/** @return AesCrypto 測試用加解密器 */
	private function crypto(): AesCrypto {
		return new AesCrypto( self::HASH_KEY, self::HASH_IV );
	}

	/**
	 * 建立含 billing email/phone 並指定 ECPG 付款方式的 pending 訂單
	 *
	 * @param string $payment ECPG 付款方式（EcpayPaymentMethod::value：ATM/CVS/BARCODE/Credit）
	 * @return \WC_Order
	 */
	private function create_ecpg_order( string $payment ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();
		( new EcpgMetaKeys( $order ) )->update_payment( $payment );
		return $order;
	}

	// ========== allowedPayments 預設值 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_allowedPayments預設含ATM_CVS_BARCODE(): void {
		// Given: test 模式預設設定
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'mode'       => 'test',
				'merchantId' => '3002607',
				'hashKey'    => self::HASH_KEY,
				'hashIv'     => self::HASH_IV,
			]
		);
		EcpgSettingsDTO::reset();

		// When
		$allowed = EcpgSettingsDTO::instance()->allowedPayments;

		// Then: 站內付支援的非信用卡方式皆在白名單
		$this->assertContains( EcpayPaymentMethod::CREDIT->value, $allowed );
		$this->assertContains( EcpayPaymentMethod::ATM->value, $allowed );
		$this->assertContains( EcpayPaymentMethod::CVS->value, $allowed );
		$this->assertContains( EcpayPaymentMethod::BARCODE->value, $allowed );
	}

	// ========== GetTokenParams 接受非信用卡 Info ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_GetTokenParams接受ATMInfo與ChoosePaymentList3(): void {
		// Given / When: ATM 取號參數
		$params = new GetTokenParams(
			[
				'MerchantID'        => '3002607',
				'ChoosePaymentList' => '3',
				'OrderInfo'         => [
					'MerchantTradeNo' => 'EG200ATM001',
					'TotalAmount'     => 1000,
				],
				'ATMInfo'           => [ 'ExpireDate' => 3 ],
				'ConsumerInfo'      => [ 'Email' => 'buyer@example.com' ],
			]
		);

		// Then
		$this->assertSame( '3', $params->ChoosePaymentList );
		$this->assertSame( 3, $params->ATMInfo['ExpireDate'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_GetTokenParams接受BarcodeInfo與ChoosePaymentList5(): void {
		// Given / When: BARCODE 取號參數
		$params = new GetTokenParams(
			[
				'MerchantID'        => '3002607',
				'ChoosePaymentList' => '5',
				'OrderInfo'         => [
					'MerchantTradeNo' => 'EG200BAR001',
					'TotalAmount'     => 1000,
				],
				'BarcodeInfo'       => [ 'StoreExpireDate' => 7 ],
				'ConsumerInfo'      => [ 'Email' => 'buyer@example.com' ],
			]
		);

		// Then
		$this->assertSame( '5', $params->ChoosePaymentList );
		$this->assertSame( 7, $params->BarcodeInfo['StoreExpireDate'] );
	}

	// ========== get_code 取號（解析各付款方式取號資訊） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_codeATM回虛擬帳號(): void {
		// Given: ATM 訂單（MOCK）
		$order  = $this->create_ecpg_order( EcpayPaymentMethod::ATM->value );
		$client = new EcpgApiClient( $order );

		// When: 後端幕後取號（GetToken → CreatePayment 直接用 Token）
		$info = $client->get_code( EcpayPaymentMethod::ATM );

		// Then: 回 ATM 虛擬帳號取號資訊
		$this->assertArrayHasKey( 'ATMInfo', $info );
		$this->assertNotSame( '', (string) ( $info['ATMInfo']['BankCode'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $info['ATMInfo']['vAccount'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $info['ATMInfo']['ExpireDate'] ?? '' ) );
		// 取號成功 RtnCode=1（整數）
		$this->assertSame( 1, (int) $info['RtnCode'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_codeCVS回繳費代碼(): void {
		// Given: CVS 訂單（MOCK）
		$order  = $this->create_ecpg_order( EcpayPaymentMethod::CVS->value );
		$client = new EcpgApiClient( $order );

		// When
		$info = $client->get_code( EcpayPaymentMethod::CVS );

		// Then: 回 CVS 繳費代碼
		$this->assertArrayHasKey( 'CVSInfo', $info );
		$this->assertNotSame( '', (string) ( $info['CVSInfo']['PaymentNo'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $info['CVSInfo']['ExpireDate'] ?? '' ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_codeBARCODE回三段條碼(): void {
		// Given: BARCODE 訂單（MOCK）
		$order  = $this->create_ecpg_order( EcpayPaymentMethod::BARCODE->value );
		$client = new EcpgApiClient( $order );

		// When
		$info = $client->get_code( EcpayPaymentMethod::BARCODE );

		// Then: 回三段條碼（官方 9053.md：Barcode1/2/3）
		$this->assertArrayHasKey( 'BarcodeInfo', $info );
		$this->assertNotSame( '', (string) ( $info['BarcodeInfo']['Barcode1'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $info['BarcodeInfo']['Barcode2'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $info['BarcodeInfo']['Barcode3'] ?? '' ) );
		$this->assertNotSame( '', (string) ( $info['BarcodeInfo']['ExpireDate'] ?? '' ) );
	}

	/**
	 * 取號的 CreatePayment 回應無 ThreeDURL（非信用卡不需 3DS）
	 *
	 * @test
	 * @group happy
	 */
	public function test_get_code回應不含ThreeDURL(): void {
		// Given: ATM 訂單（MOCK）
		$order  = $this->create_ecpg_order( EcpayPaymentMethod::ATM->value );
		$client = new EcpgApiClient( $order );

		// When
		$info = $client->get_code( EcpayPaymentMethod::ATM );

		// Then: 非信用卡取號無 3DS 巢狀結構
		$this->assertArrayNotHasKey( 'ThreeDInfo', $info );
	}

	// ========== before_process_payment 非信用卡分支 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_下單時ATM取號寫入payment_info並維持pending(): void {
		// Given: ATM 訂單（MOCK）
		$order   = $this->create_ecpg_order( EcpayPaymentMethod::ATM->value );
		$gateway = new EcpgGateway();

		// When: 走 process_payment（內部呼叫 before_process_payment 非信用卡分支）
		$result = $gateway->process_payment( $order->get_id() );

		// Then: process_payment 成功
		$this->assertSame( 'success', $result['result'] );

		$fresh = wc_get_order( $order->get_id() );
		// 取號資訊寫入 _pc_ecpay_payment_info
		$info = ( new EcpayMetaKeys( $fresh ) )->get_payment_info();
		$this->assertNotEmpty( $info );
		$this->assertArrayHasKey( 'ATMInfo', $info );
		$this->assertNotSame( '', (string) ( $info['ATMInfo']['vAccount'] ?? '' ) );

		// 取號 ≠ 付款：訂單維持待付款（pending）
		$this->assert_order_status( $fresh, 'pending' );
		// order note 記錄取號資訊
		$this->assert_order_note_contains( $fresh, '取號' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下單時CVS取號寫入payment_info(): void {
		// Given: CVS 訂單（MOCK）
		$order   = $this->create_ecpg_order( EcpayPaymentMethod::CVS->value );
		$gateway = new EcpgGateway();

		// When
		$gateway->process_payment( $order->get_id() );

		// Then
		$fresh = wc_get_order( $order->get_id() );
		$info  = ( new EcpayMetaKeys( $fresh ) )->get_payment_info();
		$this->assertArrayHasKey( 'CVSInfo', $info );
		$this->assertNotSame( '', (string) ( $info['CVSInfo']['PaymentNo'] ?? '' ) );
		$this->assert_order_status( $fresh, 'pending' );
	}

	/**
	 * 信用卡訂單仍走 token 流程（不取號、不寫 payment_info）
	 *
	 * @test
	 * @group happy
	 */
	public function test_信用卡訂單仍走token流程不寫payment_info(): void {
		// Given: 信用卡訂單（MOCK）
		$order   = $this->create_ecpg_order( EcpayPaymentMethod::CREDIT->value );
		$gateway = new EcpgGateway();

		// When
		$gateway->process_payment( $order->get_id() );

		// Then: 寫入 token（信用卡流程），但不寫取號資訊
		$fresh = wc_get_order( $order->get_id() );
		$this->assertNotSame( '', EcpgGateway::get_ecpg_token( $fresh ) );
		$this->assertEmpty( ( new EcpayMetaKeys( $fresh ) )->get_payment_info() );
	}

	// ========== 取號 vs 付款完成兩階段：ReturnURL 流轉 ==========

	/**
	 * 取號後，消費者實際繳費，ReturnURL（RtnCode=1）將 pending 訂單轉 processing
	 *
	 * @test
	 * @group happy
	 */
	public function test_取號後繳費完成ReturnURL將訂單轉processing(): void {
		// Given: 已取號的 ATM 訂單（pending）
		$order   = $this->create_ecpg_order( EcpayPaymentMethod::ATM->value );
		$gateway = new EcpgGateway();
		$gateway->process_payment( $order->get_id() );
		$trade_no = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_trade_no();
		$this->assertNotSame( '', $trade_no );

		// When: 消費者繳費後綠界送 ReturnURL（RtnCode=1，PaymentType=ATM_*）
		$payload = [
			'MerchantID' => '3002607',
			'TransCode'  => 1,
			'TransMsg'   => 'Success',
			'Data'       => $this->crypto()->encrypt(
				[
					'RtnCode'   => 1,
					'RtnMsg'    => '交易成功',
					'OrderInfo' => [
						'MerchantTradeNo' => $trade_no,
						'TradeNo'         => '2026031215360001',
						'TradeAmt'        => 1000,
						'PaymentType'     => 'ATM_TAISHIN',
					],
				]
			),
		];
		\J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgCallback::instance()->handle_return( $payload );

		// Then: 取號 → 繳費完成 → processing
		$this->assert_order_status( wc_get_order( $order->get_id() ), 'processing' );
	}

	// ========== 退款分流：非信用卡擋下 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_ATM取號訂單退款回WP_Error_refund_unsupported(): void {
		// Given: 已取號（PaymentType=ATM_*）的訂單
		$order   = $this->create_ecpg_order( EcpayPaymentMethod::ATM->value );
		$gateway = new EcpgGateway();
		$gateway->process_payment( $order->get_id() );

		// When
		$result = $gateway->process_refund( $order->get_id(), 500, '測試退款' );

		// Then: 非信用卡一律擋下，不呼叫退款 API
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'refund_unsupported', $result->get_error_code() );
	}
}
