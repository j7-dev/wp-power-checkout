<?php
/**
 * 綠界站內付 2.0（ECPG）Gateway / ApiClient 整合測試
 * 驗證：ConsumerInfo 缺 Email→失敗、雙層錯誤檢查（TransCode→RtnCode）、
 * ThreeDURL 空/非空分流、MerchantTradeNo 冪等寫入。
 *
 * 真 API 呼叫以 API_MODE=mock 攔截（不打綠界）；雙層錯誤檢查以 parse_response()
 * 搭配真 AES 加密 Data 直接驗證，不需 HTTP。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\GetTokenParams;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgApiClient;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界站內付 2.0 Gateway / ApiClient 測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group ecpg
 */
final class EcpgGatewayTest extends TestCase {

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
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( EcpgGateway::ID ) );
		EcpgSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * 建立含 billing email/phone 的 pending 訂單
	 *
	 * @param bool $with_contact 是否填入 billing email / phone
	 * @return \WC_Order
	 */
	private function create_ecpg_order( bool $with_contact = true ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => EcpgGateway::ID,
				'total'          => 1000,
			]
		);
		if ( $with_contact ) {
			$order->set_billing_email( 'buyer@example.com' );
			$order->set_billing_phone( '0912345678' );
			$order->save();
		}
		return $order;
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_EcpgGateway_ID常數正確(): void {
		$this->assertSame( 'ecpay_ecpg', EcpgGateway::ID );
	}

	// ========== 前置：ConsumerInfo Email/Phone 必填 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_ConsumerInfo含Email時GetTokenParams驗證通過(): void {
		// Given / When
		$params = new GetTokenParams(
			[
				'MerchantID'   => '3002607',
				'OrderInfo'    => [
					'MerchantTradeNo' => 'EG200ABCDEF',
					'TotalAmount'     => 1000,
				],
				'ConsumerInfo' => [ 'Email' => 'buyer@example.com' ],
			]
		);

		// Then: 不拋錯，內層 MerchantID 正確
		$this->assertSame( '3002607', $params->MerchantID );
		$this->assertSame( 'EG200ABCDEF', $params->OrderInfo['MerchantTradeNo'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_ConsumerInfo缺Email與Phone時GetTokenParams驗證失敗(): void {
		// Given: local 環境 DTO 嚴格模式會 throw
		if ( 'local' !== wp_get_environment_type() ) {
			$this->markTestSkipped( 'DTO 嚴格模式僅在 local 環境拋出例外' );
		}

		// Then
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Email 或 Phone' );

		// When: ConsumerInfo 缺 Email 與 Phone
		new GetTokenParams(
			[
				'MerchantID'   => '3002607',
				'OrderInfo'    => [
					'MerchantTradeNo' => 'EG200ABCDEF',
					'TotalAmount'     => 1000,
				],
				'ConsumerInfo' => [ 'Name' => '王大明' ],
			]
		);
	}

	// ========== 雙層錯誤檢查（TransCode → RtnCode） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_雙層皆成功時parse_response回傳解密Data(): void {
		// Given: TransCode=1 外層，Data 解密後 RtnCode=1
		$order  = $this->create_ecpg_order();
		$client = new EcpgApiClient( $order );
		$crypto = new AesCrypto( self::HASH_KEY, self::HASH_IV );
		$body   = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $crypto->encrypt(
				[
					'RtnCode' => 1,
					'RtnMsg'  => 'Success',
					'Token'   => 'm12dae4846446sq',
				]
			),
		];

		// When
		$decrypted = $client->parse_response( $body );

		// Then
		$this->assertSame( 1, $decrypted['RtnCode'] );
		$this->assertSame( 'm12dae4846446sq', $decrypted['Token'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_傳輸層TransCode非1時拋例外並記錄order_note(): void {
		// Given: TransCode=0（傳輸層失敗）
		$order  = $this->create_ecpg_order();
		$client = new EcpgApiClient( $order );
		$body   = [
			'TransCode' => 0,
			'TransMsg'  => 'AES decrypt error',
			'Data'      => '',
		];

		// When / Then
		try {
			$client->parse_response( $body );
			$this->fail( '預期傳輸層失敗應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '傳輸層失敗', $e->getMessage() );
		}
		$this->assert_order_note_contains( $order, '傳輸層失敗' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_業務層RtnCode非1時拋例外並記錄order_note(): void {
		// Given: TransCode=1 但解密後 RtnCode=10100050（業務層失敗）
		$order  = $this->create_ecpg_order();
		$client = new EcpgApiClient( $order );
		$crypto = new AesCrypto( self::HASH_KEY, self::HASH_IV );
		$body   = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $crypto->encrypt(
				[
					'RtnCode' => 10100050,
					'RtnMsg'  => '授權失敗',
				]
			),
		];

		// When / Then
		try {
			$client->parse_response( $body );
			$this->fail( '預期業務層失敗應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( '業務層失敗', $e->getMessage() );
			$this->assertStringContainsString( '10100050', $e->getMessage() );
		}
		$this->assert_order_note_contains( $order, '10100050' );
	}

	// ========== ThreeDURL 空 / 非空分流 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_CreatePayment回應含ThreeDURL時need_3ds為true(): void {
		// Given: MOCK 模式（mock_create_payment_response 預設帶 ThreeDURL）
		putenv( 'API_MODE=mock' );
		$order  = $this->create_ecpg_order();
		$client = new EcpgApiClient( $order );

		// When
		$result = $client->create_payment( 'mock_pay_token', 'EG200ABCDEF' );

		// Then: 需導向 3DS
		$this->assertTrue( $result['need_3ds'] );
		$this->assertNotSame( '', $result['three_d_url'] );
		$this->assertStringContainsString( '3DVerify', $result['three_d_url'] );

		putenv( 'API_MODE' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_create_payment解析巢狀ThreeDInfo而非扁平ThreeDURL(): void {
		// Given: 解密後資料為巢狀 ThreeDInfo.ThreeDURL（非扁平 data.ThreeDURL）
		$order  = $this->create_ecpg_order();
		$client = new EcpgApiClient( $order );
		$crypto = new AesCrypto( self::HASH_KEY, self::HASH_IV );

		// 直接驗證 parse_response 取出的結構為巢狀（不打 API）
		$body = [
			'TransCode' => 1,
			'TransMsg'  => 'Success',
			'Data'      => $crypto->encrypt(
				[
					'RtnCode'    => 1,
					'RtnMsg'     => 'Success',
					'OrderInfo'  => [ 'MerchantTradeNo' => 'EG200ABCDEF' ],
					'ThreeDInfo' => [ 'ThreeDURL' => 'https://ecpayment-stage.ecpay.com.tw/Cashier/3DVerify?tk=x' ],
				]
			),
		];

		// When
		$decrypted = $client->parse_response( $body );

		// Then: ThreeDURL 在 ThreeDInfo 物件內，非頂層
		$this->assertArrayHasKey( 'ThreeDInfo', $decrypted );
		$this->assertArrayNotHasKey( 'ThreeDURL', $decrypted );
		$this->assertSame(
			'https://ecpayment-stage.ecpay.com.tw/Cashier/3DVerify?tk=x',
			$decrypted['ThreeDInfo']['ThreeDURL']
		);
	}

	// ========== MerchantTradeNo 冪等寫入 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_token寫入MerchantTradeNo冪等鍵(): void {
		// Given: MOCK 模式
		putenv( 'API_MODE=mock' );
		$order  = $this->create_ecpg_order();
		$client = new EcpgApiClient( $order );

		// When
		$decrypted = $client->get_token();

		// Then: _pc_ecpay_trade_no 有值，回應含 Token
		$trade_no = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_trade_no();
		$this->assertNotSame( '', $trade_no );
		$this->assertSame( 1, $decrypted['RtnCode'] );
		$this->assertStringContainsString( $trade_no, (string) $decrypted['Token'] );

		putenv( 'API_MODE' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_重複get_token時沿用同一MerchantTradeNo(): void {
		// Given: MOCK 模式，已寫入 trade_no
		putenv( 'API_MODE=mock' );
		$order = $this->create_ecpg_order();

		// When: 連續呼叫兩次 get_token
		( new EcpgApiClient( $order ) )->get_token();
		$first = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_trade_no();
		( new EcpgApiClient( wc_get_order( $order->get_id() ) ) )->get_token();
		$second = ( new EcpayMetaKeys( wc_get_order( $order->get_id() ) ) )->get_trade_no();

		// Then: 冪等，trade_no 不變
		$this->assertSame( $first, $second );

		putenv( 'API_MODE' );
	}

	// ========== 設定（Settings） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_test模式套用ECPG測試帳號與雙Domain端點(): void {
		// Given: 未填憑證、mode=test
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'mode'       => 'test',
				'merchantId' => '',
				'hashKey'    => '',
				'hashIv'     => '',
			]
		);
		EcpgSettingsDTO::reset();

		// When
		$settings = EcpgSettingsDTO::instance();

		// Then: 套用公開測試帳號 + 雙 Domain stage 端點
		$this->assertSame( '3002607', $settings->merchantId );
		$this->assertSame( self::HASH_KEY, $settings->hashKey );
		$this->assertSame( self::HASH_IV, $settings->hashIv );
		$this->assertStringContainsString( 'ecpg-stage.ecpay.com.tw', $settings->tokenEndpoint );
		$this->assertStringContainsString( 'ecpayment-stage.ecpay.com.tw', $settings->paymentEndpoint );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_prod模式使用正式雙Domain端點且不套用預設憑證(): void {
		// Given: mode=prod，未填憑證
		ProviderUtils::update_option(
			EcpgGateway::ID,
			[
				'mode'       => 'prod',
				'merchantId' => '',
				'hashKey'    => '',
				'hashIv'     => '',
			]
		);
		EcpgSettingsDTO::reset();

		// When
		$settings = EcpgSettingsDTO::instance();

		// Then: prod 不套用任何預設憑證，端點為正式環境
		$this->assertSame( '', $settings->merchantId );
		$this->assertSame( '', $settings->hashKey );
		$this->assertSame( 'https://ecpg.ecpay.com.tw', $settings->tokenEndpoint );
		$this->assertSame( 'https://ecpayment.ecpay.com.tw', $settings->paymentEndpoint );
	}

	/**
	 * 退款分流：非信用卡訂單 process_refund 回 WP_Error('refund_unsupported')
	 *
	 * create_ecpg_order 未設定付款明細（無 PaymentType），EcpayPaymentType::order_is_credit
	 * 判定為非信用卡，故退款被擋下回 refund_unsupported，不呼叫任何退款 API。
	 *
	 * @test
	 * @group error
	 */
	public function test_非信用卡訂單退款回WP_Error_refund_unsupported(): void {
		// Given: 未帶綠界付款明細的訂單（視為非信用卡）
		$order   = $this->create_ecpg_order();
		$gateway = new EcpgGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 500, '測試退款' );

		// Then: 非信用卡一律擋下，不呼叫退款 API
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'refund_unsupported', $result->get_error_code() );
	}
}
