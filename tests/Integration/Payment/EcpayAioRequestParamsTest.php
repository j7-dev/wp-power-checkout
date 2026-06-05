<?php
/**
 * 綠界 AIO 建單參數整合測試
 * 驗證 RequestParams 組裝：ChoosePayment=ALL + IgnorePayment 反推、CheckMacValue 整合、MerchantTradeNo ≤ 20
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO 建單參數測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 */
final class EcpayAioRequestParamsTest extends TestCase {

	/** @var string 綠界 AIO 測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/**
	 * 每次測試前啟用 ecpay_aio（test 模式 + 測試帳號）
	 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'enabled'         => 'yes',
				'mode'            => 'test',
				'merchantId'      => '3002607',
				'hashKey'         => self::HASH_KEY,
				'hashIv'          => self::HASH_IV,
				'allowedPayments' => [ 'Credit', 'ATM', 'WebATM', 'CVS', 'BARCODE', 'ApplePay' ],
				'expireDate'      => 3,
			]
		);
	}

	/**
	 * 每次測試後清理設定
	 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_AioRedirectGateway_ID常數正確(): void {
		$this->assertSame( 'ecpay_aio', AioRedirectGateway::ID );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單參數含必填欄位且PaymentType為aio(): void {
		// Given: 一筆 1000 元訂單
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$gateway = new AioRedirectGateway();

		// When: 組裝建單參數
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 必填欄位齊全
		$this->assertSame( '3002607', $form['MerchantID'] );
		$this->assertSame( 'aio', $form['PaymentType'] );
		$this->assertSame( 1000, $form['TotalAmount'] );
		$this->assertSame( 'ALL', $form['ChoosePayment'] );
		$this->assertSame( 1, $form['EncryptType'] );
		$this->assertArrayHasKey( 'CheckMacValue', $form );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_全選付款方式時IgnorePayment為空不送出(): void {
		// Given: allowedPayments 顯式設為完整全集（含第二期新增的 TWQR / BNPL / WeiXin，
		// 銀聯為 Credit+UnionPay 參數不在此列），確保全選時無任何方式被排除
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'allowedPayments' => [ 'Credit', 'WebATM', 'ATM', 'CVS', 'BARCODE', 'ApplePay', 'TWQR', 'BNPL', 'WeiXin' ],
			]
		);
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 全選時無 IgnorePayment（空字串會被 to_form_params 過濾掉）
		$this->assertArrayNotHasKey( 'IgnorePayment', $form );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_僅勾選Credit與ATM時IgnorePayment反推其餘付款方式(): void {
		// Given: allowedPayments = [Credit, ATM]
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[ 'allowedPayments' => [ 'Credit', 'ATM' ] ]
		);
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );

		// Then: ChoosePayment=ALL，IgnorePayment 含未勾選的付款方式
		$this->assertSame( 'ALL', $params->ChoosePayment );
		$segments = explode( '#', $params->IgnorePayment );
		$this->assertContains( 'WebATM', $segments );
		$this->assertContains( 'CVS', $segments );
		$this->assertContains( 'BARCODE', $segments );
		$this->assertContains( 'ApplePay', $segments );
		$this->assertNotContains( 'Credit', $segments );
		$this->assertNotContains( 'ATM', $segments );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_CheckMacValue以參數重算可驗證一致(): void {
		// Given
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$gateway = new AioRedirectGateway();

		// When: 取得建單參數
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 以 form 參數（去掉 CheckMacValue）重算應與 DTO 計算的一致
		$args = $form;
		unset( $args['CheckMacValue'] );
		$recalculated = CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV, 'sha256' );

		$this->assertSame( $recalculated, $form['CheckMacValue'] );
		$this->assertMatchesRegularExpression( '/^[A-F0-9]{64}$/', $form['CheckMacValue'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_MerchantTradeNo為英數字且長度不超過20(): void {
		// Given
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );

		// Then
		$this->assertLessThanOrEqual( 20, strlen( $params->MerchantTradeNo ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]+$/', $params->MerchantTradeNo );
	}

	// ========== 設定（Settings） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_test模式套用綠界AIO公開測試帳號與stage端點(): void {
		// Given: 未填憑證、mode=test
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'mode'       => 'test',
				'merchantId' => '',
				'hashKey'    => '',
				'hashIv'     => '',
			]
		);

		// When
		$settings = AioSettingsDTO::instance();

		// Then: 套用公開測試帳號 + stage 端點
		$this->assertSame( '3002607', $settings->merchantId );
		$this->assertSame( self::HASH_KEY, $settings->hashKey );
		$this->assertSame( self::HASH_IV, $settings->hashIv );
		$this->assertStringContainsString( 'payment-stage.ecpay.com.tw', $settings->endpoint );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_prod模式使用正式端點且不套用預設憑證(): void {
		// Given: mode=prod，未填憑證
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'mode'       => 'prod',
				'merchantId' => '',
				'hashKey'    => '',
				'hashIv'     => '',
			]
		);

		// When
		$settings = AioSettingsDTO::instance();

		// Then: prod 不套用任何預設憑證，端點為正式環境
		$this->assertSame( '', $settings->merchantId );
		$this->assertSame( '', $settings->hashKey );
		$this->assertSame( 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5', $settings->endpoint );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_金額無條件進位為整數(): void {
		// Given: 含小數的金額
		$order   = $this->create_wc_order( [ 'total' => 99.01 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );

		// Then: 進位為 100（避免少收）
		$this->assertSame( 100, $params->TotalAmount );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_ReturnURL與PaymentInfoURL指向ecpay端點(): void {
		// Given
		$order   = $this->create_wc_order( [ 'total' => 1000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );

		// Then
		$this->assertStringContainsString( 'power-checkout/ecpay/aio/return', $params->ReturnURL );
		$this->assertStringContainsString( 'power-checkout/ecpay/aio/payment-info', $params->PaymentInfoURL );
	}
}
