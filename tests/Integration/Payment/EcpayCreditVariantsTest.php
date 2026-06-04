<?php
/**
 * 綠界 ECPay 信用卡分期 / 定期定額建單參數整合測試
 *
 * 對應規格：specs/features/payment/ecpay-credit-variants.feature
 *
 * 驗證：
 *  - 後台啟用 [3,6,12] 時選 6 期 → ChoosePayment='Credit'、CreditInstallment='6'
 *  - 選未啟用的 24 期 → throw '分期期數不在允許範圍'
 *  - 定期定額完整（PeriodType=M, Frequency=1, ExecTimes=12）→ PeriodAmount/PeriodType/Frequency/ExecTimes 系列
 *  - 定期定額缺 ExecTimes → throw '定期定額參數不完整'
 *
 * 變體選擇機制：後台白名單 / periodConfig 存於 AioSettingsDTO；顧客選擇存於 order meta
 *（_pc_ecpay_credit_variant / _pc_ecpay_installment）。本測試以 settings + order meta fixture 設定。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 綠界 ECPay 信用卡分期 / 定期定額測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group credit-variants
 */
final class EcpayCreditVariantsTest extends TestCase {

	/** @var string 綠界 AIO 測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/**
	 * 啟用 ecpay_aio（test 模式）並設定分期 / 定期定額參數
	 *
	 * @param array<int>           $installment_periods 分期白名單
	 * @param array<string, mixed> $period_config       定期定額設定
	 * @return void
	 */
	private function setup_aio( array $installment_periods = [ 3, 6, 12, 18, 24, 30 ], array $period_config = [] ): void {
		ProviderUtils::update_option(
			AioRedirectGateway::ID,
			[
				'enabled'            => 'yes',
				'mode'               => 'test',
				'merchantId'         => '3002607',
				'hashKey'            => self::HASH_KEY,
				'hashIv'             => self::HASH_IV,
				'allowedPayments'    => [ 'Credit', 'ATM', 'WebATM', 'CVS', 'BARCODE', 'ApplePay' ],
				'installmentPeriods' => $installment_periods,
				'periodConfig'       => $period_config,
			]
		);
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( AioRedirectGateway::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立 12000 元訂單並設定信用卡變體 meta
	 *
	 * @param string $variant     ''｜'installment'｜'period'
	 * @param string $installment 分期期數（如 '6'）
	 * @return \WC_Order
	 */
	private function create_variant_order( string $variant, string $installment = '' ): \WC_Order {
		$order     = $this->create_wc_order( [ 'total' => 12000 ] );
		$meta_keys = new EcpayMetaKeys( $order );
		$meta_keys->update_credit_variant( $variant );
		if ( '' !== $installment ) {
			$meta_keys->update_installment( $installment );
		}
		return $order;
	}

	// ========== 分期：CreditInstallment 必須為後台允許期數 ==========

	/**
	 * 後台啟用 3/6/12 期時送出 6 期分期
	 *
	 * @test
	 * @group happy
	 */
	public function test_啟用3_6_12期時選6期分期送出Credit與CreditInstallment6(): void {
		// Given: installment_periods=[3,6,12]，顧客選 6 期
		$this->setup_aio( [ 3, 6, 12 ] );
		$order   = $this->create_variant_order( 'installment', '6' );
		$gateway = new AioRedirectGateway();

		// When: 組裝建單參數
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: ChoosePayment=Credit、CreditInstallment='6'
		$this->assertSame( 'Credit', $form['ChoosePayment'] );
		$this->assertSame( '6', $form['CreditInstallment'] );
		// Credit-only 不再帶 IgnorePayment
		$this->assertArrayNotHasKey( 'IgnorePayment', $form );
	}

	/**
	 * 顧客選擇未啟用的期數時建單失敗
	 *
	 * @test
	 * @group error
	 */
	public function test_選未啟用的24期時建單失敗(): void {
		// Given: installment_periods=[3,6,12]，顧客選 24 期（未啟用）
		$this->setup_aio( [ 3, 6, 12 ] );
		$order   = $this->create_variant_order( 'installment', '24' );
		$gateway = new AioRedirectGateway();

		// When / Then: 組裝建單參數時拋出「分期期數不在允許範圍」
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '分期期數不在允許範圍' );

		RequestParams::instance( $order, $gateway );
	}

	/**
	 * 分期未填期數（meta 缺）時建單失敗
	 *
	 * @test
	 * @group error
	 */
	public function test_分期未填期數時建單失敗(): void {
		// Given: 變體為 installment 但無期數
		$this->setup_aio( [ 3, 6, 12 ] );
		$order   = $this->create_variant_order( 'installment', '' );
		$gateway = new AioRedirectGateway();

		// When / Then
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '分期期數不在允許範圍' );

		RequestParams::instance( $order, $gateway );
	}

	// ========== 定期定額：PeriodAmount / PeriodType / Frequency / ExecTimes ==========

	/**
	 * 建立每月扣款定期定額訂單
	 *
	 * @test
	 * @group happy
	 */
	public function test_建立每月扣款定期定額訂單(): void {
		// Given: period_config PeriodType=M, Frequency=1, ExecTimes=12，顧客選定期定額
		$this->setup_aio(
			[ 3, 6, 12 ],
			[
				'PeriodType' => 'M',
				'Frequency'  => 1,
				'ExecTimes'  => 12,
			]
		);
		$order   = $this->create_variant_order( 'period' );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: ChoosePayment=Credit + 定期定額系列參數，PeriodAmount = TotalAmount(12000)
		$this->assertSame( 'Credit', $form['ChoosePayment'] );
		$this->assertSame( 12000, $form['PeriodAmount'] );
		$this->assertSame( 'M', $form['PeriodType'] );
		$this->assertSame( 1, $form['Frequency'] );
		$this->assertSame( 12, $form['ExecTimes'] );
	}

	/**
	 * 定期定額缺 ExecTimes 時建單失敗
	 *
	 * @test
	 * @group error
	 */
	public function test_定期定額缺ExecTimes時建單失敗(): void {
		// Given: period_config 缺 ExecTimes
		$this->setup_aio(
			[ 3, 6, 12 ],
			[
				'PeriodType' => 'M',
				'Frequency'  => 1,
				// 無 ExecTimes
			]
		);
		$order   = $this->create_variant_order( 'period' );
		$gateway = new AioRedirectGateway();

		// When / Then
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '定期定額參數不完整' );

		RequestParams::instance( $order, $gateway );
	}

	/**
	 * 定期定額缺 PeriodType 時建單失敗
	 *
	 * @test
	 * @group error
	 */
	public function test_定期定額缺PeriodType時建單失敗(): void {
		// Given: period_config 缺 PeriodType
		$this->setup_aio(
			[ 3, 6, 12 ],
			[
				'Frequency' => 1,
				'ExecTimes' => 12,
			]
		);
		$order   = $this->create_variant_order( 'period' );
		$gateway = new AioRedirectGateway();

		// When / Then
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '定期定額參數不完整' );

		RequestParams::instance( $order, $gateway );
	}

	// ========== 無變體：維持原 ALL 流程 ==========

	/**
	 * 無信用卡變體時維持 ChoosePayment=ALL（不帶分期 / 定期定額）
	 *
	 * @test
	 * @group happy
	 */
	public function test_無變體時維持ALL流程不帶分期定期定額(): void {
		// Given: 無變體 meta
		$this->setup_aio();
		$order   = $this->create_wc_order( [ 'total' => 12000 ] );
		$gateway = new AioRedirectGateway();

		// When
		$params = RequestParams::instance( $order, $gateway );
		$form   = $params->to_form_params();

		// Then: ChoosePayment=ALL，無分期 / 定期定額參數
		$this->assertSame( 'ALL', $form['ChoosePayment'] );
		$this->assertArrayNotHasKey( 'CreditInstallment', $form );
		$this->assertArrayNotHasKey( 'PeriodAmount', $form );
		$this->assertArrayNotHasKey( 'PeriodType', $form );
	}
}
