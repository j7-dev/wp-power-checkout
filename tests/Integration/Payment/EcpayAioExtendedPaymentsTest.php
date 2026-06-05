<?php
/**
 * 綠界 AIO 第二期付款方式擴充整合測試
 *
 * 對應任務：P2-A AIO 付款方式擴充 — TWQR 行動支付、BNPL 先買後付（裕富/中租）、微信支付、銀聯卡。
 *
 * ChoosePayment / 額外參數對照（官方查證）：
 *  - TWQR 行動支付     ChoosePayment=TWQR     （Source: developers.ecpay.com.tw/36991.md、2862.md）
 *  - BNPL 先買後付     ChoosePayment=BNPL + ChooseSubPayment=URICH(裕富)/ZINGALA(中租)（36659.md）
 *  - 微信支付          ChoosePayment=WeiXin   （56448.md、2862.md）
 *  - 銀聯卡            ChoosePayment=Credit + UnionPay=0/1/2（非獨立 ChoosePayment，2866.md）
 *
 * IgnorePayment 可排除全集（官方 2862.md）：
 *  Credit / WebATM / ATM / CVS / BARCODE / ApplePay / TWQR / BNPL / WeiXin
 *  （DigitalPayment 不可排除；UnionPay 非付款方式值，不可排除）
 *
 * 驗證重點：
 *  - allowedPayments 含 / 不含 TWQR / BNPL / WeiXin 時 IgnorePayment 反推正確
 *  - 新付款方式在 allowedPayments 白名單驗證通過
 *  - BNPL ChooseSubPayment（lender）參數隨設定送出
 *  - 銀聯 UnionPay 參數隨設定送出
 *  - 擴充後 CheckMacValue 仍可重算驗證一致
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 AIO 第二期付款方式擴充測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group extended-payments
 */
final class EcpayAioExtendedPaymentsTest extends TestCase {

	/** @var string 綠界 AIO 測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @var array<string> 含 4 種新付款方式的完整白名單（銀聯不在白名單，走 UnionPay 參數） */
	private const FULL_ALLOWED = [ 'Credit', 'ATM', 'WebATM', 'CVS', 'BARCODE', 'ApplePay', 'TWQR', 'BNPL', 'WeiXin' ];

	/**
	 * 啟用 ecpay_aio（test 模式 + 指定白名單與額外設定）
	 *
	 * @param array<string, mixed> $extra 覆寫設定（allowedPayments / bnplSubPayment / unionPayEnabled / unionPay 等）
	 * @return void
	 */
	private function setup_aio( array $extra = [] ): void {
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			array_merge(
				[
					'enabled'         => 'yes',
					'mode'            => 'test',
					'merchantId'      => '3002607',
					'hashKey'         => self::HASH_KEY,
					'hashIv'          => self::HASH_IV,
					'allowedPayments' => self::FULL_ALLOWED,
					'expireDate'      => 3,
				],
				$extra
			)
		);
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		parent::tear_down();
	}

	// ========== Enum 擴充（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_EcpayPaymentMethod新增TWQR_BNPL_WeiXin三個case(): void {
		$this->assertSame( 'TWQR', EcpayPaymentMethod::TWQR->value );
		$this->assertSame( 'BNPL', EcpayPaymentMethod::BNPL->value );
		$this->assertSame( 'WeiXin', EcpayPaymentMethod::WEIXIN->value );
	}

	/**
	 * 新付款方式的 choose_payment 即為其 value（非 Credit 變體）
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_新付款方式choose_payment回傳自身值(): void {
		$this->assertSame( 'TWQR', EcpayPaymentMethod::TWQR->choose_payment() );
		$this->assertSame( 'BNPL', EcpayPaymentMethod::BNPL->choose_payment() );
		$this->assertSame( 'WeiXin', EcpayPaymentMethod::WEIXIN->choose_payment() );
	}

	// ========== 白名單驗證（Settings） ==========

	/**
	 * allowedPayments 含 4 種新付款方式時 AioSettingsDTO 驗證通過
	 *
	 * @test
	 * @group happy
	 */
	public function test_白名單含TWQR_BNPL_WeiXin時設定驗證通過(): void {
		// Given / When: 白名單含新付款方式
		$this->setup_aio();
		$settings = AioSettingsDTO::instance();

		// Then: 驗證通過（建構不丟例外），白名單原樣保留
		$this->assertContains( 'TWQR', $settings->allowedPayments );
		$this->assertContains( 'BNPL', $settings->allowedPayments );
		$this->assertContains( 'WeiXin', $settings->allowedPayments );
	}

	/**
	 * 預設白名單不含新付款方式（需向綠界申請開通後商家自行勾選）
	 *
	 * @test
	 * @group happy
	 */
	public function test_預設白名單不含新付款方式(): void {
		// Given: 全新 DTO（不帶 allowedPayments）
		$settings = new AioSettingsDTO( [] );

		// Then: 預設僅 6 種基本付款方式，不含 TWQR / BNPL / WeiXin
		$this->assertNotContains( 'TWQR', $settings->allowedPayments );
		$this->assertNotContains( 'BNPL', $settings->allowedPayments );
		$this->assertNotContains( 'WeiXin', $settings->allowedPayments );
	}

	// ========== IgnorePayment 反推（Happy） ==========

	/**
	 * 全選（含新付款方式）時 IgnorePayment 為空不送出
	 *
	 * @test
	 * @group happy
	 */
	public function test_全選含新付款方式時IgnorePayment為空(): void {
		// Given: 白名單為完整全集
		$this->setup_aio();
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 全選時 IgnorePayment 為空（被 to_form_params 過濾）
		$this->assertSame( 'ALL', $params->ChoosePayment );
		$this->assertArrayNotHasKey( 'IgnorePayment', $form );
	}

	/**
	 * 不勾選新付款方式時 IgnorePayment 反推含 TWQR / BNPL / WeiXin
	 *
	 * @test
	 * @group happy
	 */
	public function test_不勾選新付款方式時IgnorePayment反推含TWQR_BNPL_WeiXin(): void {
		// Given: 白名單僅 Credit + ATM（不含新付款方式）
		$this->setup_aio( [ 'allowedPayments' => [ 'Credit', 'ATM' ] ] );
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params   = RequestParams::instance( $order, $gateway );
		$segments = explode( '#', $params->IgnorePayment );

		// Then: 新付款方式全部進入 IgnorePayment
		$this->assertContains( 'TWQR', $segments );
		$this->assertContains( 'BNPL', $segments );
		$this->assertContains( 'WeiXin', $segments );
		// 舊付款方式反推仍正確
		$this->assertContains( 'WebATM', $segments );
		$this->assertContains( 'CVS', $segments );
		$this->assertNotContains( 'Credit', $segments );
		$this->assertNotContains( 'ATM', $segments );
	}

	/**
	 * 僅勾選 TWQR 時其餘（含 BNPL / WeiXin）皆被排除
	 *
	 * @test
	 * @group happy
	 */
	public function test_僅勾選TWQR時其餘皆被排除(): void {
		// Given: 白名單僅 TWQR
		$this->setup_aio( [ 'allowedPayments' => [ 'TWQR' ] ] );
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params   = RequestParams::instance( $order, $gateway );
		$segments = explode( '#', $params->IgnorePayment );

		// Then: TWQR 不被排除，其餘 8 種被排除
		$this->assertNotContains( 'TWQR', $segments );
		$this->assertContains( 'BNPL', $segments );
		$this->assertContains( 'WeiXin', $segments );
		$this->assertContains( 'Credit', $segments );
	}

	// ========== BNPL ChooseSubPayment（lender） ==========

	/**
	 * 設定 BNPL lender 為裕富（URICH）時送出 ChooseSubPayment=URICH
	 *
	 * @test
	 * @group happy
	 */
	public function test_設定BNPL裕富時送出ChooseSubPayment為URICH(): void {
		// Given: 白名單含 BNPL，lender=URICH
		$this->setup_aio( [ 'bnplSubPayment' => 'URICH' ] );
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: ChooseSubPayment=URICH
		$this->assertSame( 'URICH', $form['ChooseSubPayment'] );
	}

	/**
	 * 設定 BNPL lender 為中租（ZINGALA）時送出 ChooseSubPayment=ZINGALA
	 *
	 * @test
	 * @group happy
	 */
	public function test_設定BNPL中租時送出ChooseSubPayment為ZINGALA(): void {
		// Given: 白名單含 BNPL，lender=ZINGALA
		$this->setup_aio( [ 'bnplSubPayment' => 'ZINGALA' ] );
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then
		$this->assertSame( 'ZINGALA', $form['ChooseSubPayment'] );
	}

	/**
	 * 未設定 BNPL lender 時不送 ChooseSubPayment（由綠界後台決定）
	 *
	 * @test
	 * @group happy
	 */
	public function test_未設定BNPL_lender時不送ChooseSubPayment(): void {
		// Given: 白名單含 BNPL 但 lender 為空
		$this->setup_aio( [ 'bnplSubPayment' => '' ] );
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 空字串被過濾，不送 ChooseSubPayment
		$this->assertArrayNotHasKey( 'ChooseSubPayment', $form );
	}

	/**
	 * BNPL 未勾選時即使有 lender 設定也不送 ChooseSubPayment
	 *
	 * @test
	 * @group edge
	 */
	public function test_BNPL未勾選時即使有lender也不送ChooseSubPayment(): void {
		// Given: 白名單不含 BNPL，但設了 lender
		$this->setup_aio(
			[
				'allowedPayments' => [ 'Credit', 'ATM' ],
				'bnplSubPayment'  => 'URICH',
			]
		);
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: BNPL 被 ignore，ChooseSubPayment 無意義不送
		$this->assertArrayNotHasKey( 'ChooseSubPayment', $form );
	}

	/**
	 * BNPL lender 非法值時設定層即驗證失敗（fail fast）
	 *
	 * 設定層 AioSettingsDTO::validate 先攔截（RequestParams::instance 第一行讀設定），
	 * 故訊息為 bnplSubPayment 而非 ChooseSubPayment。
	 *
	 * @test
	 * @group error
	 */
	public function test_BNPL_lender非法值時建單失敗(): void {
		// Given: lender 非 URICH / ZINGALA
		$this->setup_aio( [ 'bnplSubPayment' => 'INVALID_LENDER' ] );
		$order = $this->create_wc_order( [ 'total' => 3000 ] );

		// When / Then: gateway 建構時即讀設定觸發 AioSettingsDTO::validate（fail fast），
		// 故 expectException 須在 new AioRedirectGateway() 之前設定
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'bnplSubPayment' );

		$gateway = new AioRedirectGateway();
		RequestParams::instance( $order, $gateway );
	}

	/**
	 * RequestParams 層直接組裝非法 ChooseSubPayment 時驗證失敗
	 *
	 * 直接以建構子繞過設定層，驗證 RequestParams::validate 自身的防線。
	 *
	 * @test
	 * @group error
	 */
	public function test_RequestParams層非法ChooseSubPayment驗證失敗(): void {
		// When / Then: 直接建構帶非法 ChooseSubPayment 的 DTO
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'ChooseSubPayment' );

		new RequestParams(
			[
				'MerchantID'       => '3002607',
				'MerchantTradeNo'  => 'TEST123',
				'TotalAmount'      => 3000,
				'ChoosePayment'    => 'ALL',
				'ChooseSubPayment' => 'INVALID_LENDER',
			]
		);
	}

	// ========== 銀聯 UnionPay ==========

	/**
	 * 啟用銀聯（unionPay=1）時送出 UnionPay=1
	 *
	 * @test
	 * @group happy
	 */
	public function test_啟用銀聯時送出UnionPay參數(): void {
		// Given: 啟用銀聯，UnionPay=1（強制銀聯）
		$this->setup_aio(
			[
				'unionPayEnabled' => true,
				'unionPay'        => 1,
			]
		);
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: UnionPay=1 送出
		$this->assertSame( 1, $form['UnionPay'] );
	}

	/**
	 * 未啟用銀聯時不送 UnionPay 參數
	 *
	 * @test
	 * @group happy
	 */
	public function test_未啟用銀聯時不送UnionPay參數(): void {
		// Given: 不啟用銀聯
		$this->setup_aio( [ 'unionPayEnabled' => false ] );
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 不送 UnionPay
		$this->assertArrayNotHasKey( 'UnionPay', $form );
	}

	/**
	 * 銀聯選項非法值（非 0/1/2）時設定層即驗證失敗（fail fast）
	 *
	 * @test
	 * @group error
	 */
	public function test_銀聯選項非法值時建單失敗(): void {
		// Given: 啟用銀聯但值為 5（非 0/1/2）
		$this->setup_aio(
			[
				'unionPayEnabled' => true,
				'unionPay'        => 5,
			]
		);
		$order = $this->create_wc_order( [ 'total' => 3000 ] );

		// When / Then: gateway 建構時即讀設定觸發 AioSettingsDTO::validate（fail fast）
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'unionPay' );

		$gateway = new AioRedirectGateway();
		RequestParams::instance( $order, $gateway );
	}

	/**
	 * RequestParams 層直接組裝非法 UnionPay 時驗證失敗
	 *
	 * @test
	 * @group error
	 */
	public function test_RequestParams層非法UnionPay驗證失敗(): void {
		// When / Then: 直接建構帶非法 UnionPay 的 DTO（5 非 -1/0/1/2）
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'UnionPay' );

		new RequestParams(
			[
				'MerchantID'      => '3002607',
				'MerchantTradeNo' => 'TEST123',
				'TotalAmount'     => 3000,
				'ChoosePayment'   => 'ALL',
				'UnionPay'        => 5,
			]
		);
	}

	// ========== CheckMacValue 整合（擴充後仍正確） ==========

	/**
	 * 含 BNPL lender + UnionPay 時 CheckMacValue 仍可重算驗證一致
	 *
	 * @test
	 * @group happy
	 */
	public function test_含新參數時CheckMacValue重算一致(): void {
		// Given: 含 BNPL lender 與 UnionPay 的完整設定
		$this->setup_aio(
			[
				'bnplSubPayment'  => 'URICH',
				'unionPayEnabled' => true,
				'unionPay'        => 0,
			]
		);
		$order   = $this->create_wc_order( [ 'total' => 3000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: 以 form 參數（去 CheckMacValue）重算一致，且含新參數
		$this->assertSame( 'URICH', $form['ChooseSubPayment'] );
		$this->assertSame( 0, $form['UnionPay'] );

		$args = $form;
		unset( $args['CheckMacValue'] );
		$recalculated = CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV, 'sha256' );

		$this->assertSame( $recalculated, $form['CheckMacValue'] );
		$this->assertMatchesRegularExpression( '/^[A-F0-9]{64}$/', $form['CheckMacValue'] );
	}
}
