<?php
/**
 * PayNow CreatePaymentIntentParams + RefundParams 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\DTOs\CreatePaymentIntentParams
 *   J7\PowerCheckout\Domains\Payment\Paynow\DTOs\RefundParams
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 10
 *   - .claude/skills/paynow/references/payment-rest-api.md §4.1 建立付款意圖
 *   - .claude/skills/paynow/references/payment-rest-api.md §5.1 退款開立
 *   - specs/open-issue/paynow-execution-plan.md §澄清裁決 Q1（排除 ApplePayDeferred）
 *
 * CreatePaymentIntentParams 守衛：
 *   - currency 必須是 TWD
 *   - 不得含 ApplePayDeferred（Q1 排除）
 *   - allowInstallments 合法值：3/6/9/12/18/24
 *
 * RefundParams 守衛：
 *   - ATM 退款必填 bankCode / bankBranchCode / bankAccount（三欄缺一不可）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowParamsTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\CreatePaymentIntentParams;
use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\RefundParams;
use Tests\Integration\TestCase;

/**
 * PayNow Params 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowParamsTest extends TestCase {

	// ========== CreatePaymentIntentParams（Happy） ==========

	/**
	 * currency 固定為 TWD
	 * 依 payment-rest-api.md §4.1：currency 必填，固定 TWD
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_currency固定為TWD(): void {
		$params = CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'allowedPaymentMethods' => [ 'CreditCard' ],
			]
		);

		$this->assertSame( 'TWD', $params->currency );
	}

	/**
	 * 正常參數可建立 CreatePaymentIntentParams
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_正常參數可建立params(): void {
		$params = CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'currency'              => 'TWD',
				'allowedPaymentMethods' => [ 'CreditCard', 'ATM' ],
				'webhookUrl'            => 'https://example.com/notify',
				'resultUrl'             => 'https://example.com/order-received',
				'expireDays'            => 3,
			]
		);

		$this->assertSame( 1000, $params->amount );
		$this->assertSame( 'TWD', $params->currency );
	}

	// ========== ApplePayDeferred 排除（Edge / Security） ==========

	/**
	 * allowedPaymentMethods 含 ApplePayDeferred 時拒絕（丟出例外）
	 * Q1 裁決：排除 ApplePayDeferred（不可與其他付款方式併用）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_含ApplePayDeferred時拒絕(): void {
		$this->expectException( \InvalidArgumentException::class );

		CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'allowedPaymentMethods' => [ 'CreditCard', 'ApplePayDeferred' ],
			]
		);
	}

	/**
	 * 只傳 ApplePayDeferred 時也拒絕
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_只傳ApplePayDeferred也拒絕(): void {
		$this->expectException( \InvalidArgumentException::class );

		CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'allowedPaymentMethods' => [ 'ApplePayDeferred' ],
			]
		);
	}

	// ========== allowInstallments 白名單（Edge） ==========

	/**
	 * allowInstallments 合法期數 3 通過
	 * 依 payment-rest-api.md §4.1：可選 3/6/9/12/18/24
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_allowInstallments_合法期數3通過(): void {
		$params = CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'allowedPaymentMethods' => [ 'CreditCardInstallment' ],
				'allowInstallments'     => [ 3, 6, 12 ],
			]
		);

		$this->assertContains( 3, $params->allowInstallments );
		$this->assertContains( 6, $params->allowInstallments );
		$this->assertContains( 12, $params->allowInstallments );
	}

	/**
	 * allowInstallments 含非白名單期數時拒絕（例如 5 不在白名單）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_allowInstallments_非白名單期數拒絕(): void {
		$this->expectException( \InvalidArgumentException::class );

		CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'allowedPaymentMethods' => [ 'CreditCardInstallment' ],
				'allowInstallments'     => [ 5 ],  // 5 不在白名單 3/6/9/12/18/24
			]
		);
	}

	/**
	 * allowInstallments 全部合法白名單期數（3/6/9/12/18/24）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_allowInstallments_所有白名單期數通過(): void {
		$valid_installments = [ 3, 6, 9, 12, 18, 24 ];

		$params = CreatePaymentIntentParams::create(
			[
				'amount'                => 1000,
				'allowedPaymentMethods' => [ 'CreditCardInstallment' ],
				'allowInstallments'     => $valid_installments,
			]
		);

		$this->assertCount( 6, $params->allowInstallments );
	}

	// ========== RefundParams（Happy / Edge） ==========

	/**
	 * 信用卡退款不需帶 bank 欄位
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_信用卡退款不需帶bank欄位(): void {
		$params = RefundParams::create(
			[
				'amount' => 500,
				'reason' => '客戶取消',
			]
		);

		$this->assertSame( 500, $params->amount );
		$this->assertSame( '客戶取消', $params->reason );
	}

	/**
	 * ATM 退款必填 bankCode / bankBranchCode / bankAccount（三欄全填）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM退款三欄全填通過(): void {
		$params = RefundParams::create(
			[
				'amount'         => 1000,
				'reason'         => '客戶申請退款',
				'bankCode'       => '004',
				'bankBranchCode' => '0037',
				'bankAccount'    => '1234567890',
			]
		);

		$this->assertSame( '004', $params->bankCode );
		$this->assertSame( '0037', $params->bankBranchCode );
		$this->assertSame( '1234567890', $params->bankAccount );
	}

	/**
	 * ATM 退款缺少 bankCode 時拒絕
	 * 依 payment-rest-api.md §5.1：ATM 退款必填 bankCode/bankBranchCode/bankAccount
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM退款缺bankCode時拒絕(): void {
		$this->expectException( \InvalidArgumentException::class );

		RefundParams::create_for_atm(
			[
				'amount'         => 1000,
				'reason'         => '客戶申請',
				// bankCode 缺失
				'bankBranchCode' => '0037',
				'bankAccount'    => '1234567890',
			]
		);
	}

	/**
	 * ATM 退款缺少 bankBranchCode 時拒絕
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM退款缺bankBranchCode時拒絕(): void {
		$this->expectException( \InvalidArgumentException::class );

		RefundParams::create_for_atm(
			[
				'amount'      => 1000,
				'reason'      => '客戶申請',
				'bankCode'    => '004',
				// bankBranchCode 缺失
				'bankAccount' => '1234567890',
			]
		);
	}

	/**
	 * ATM 退款缺少 bankAccount 時拒絕
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM退款缺bankAccount時拒絕(): void {
		$this->expectException( \InvalidArgumentException::class );

		RefundParams::create_for_atm(
			[
				'amount'         => 1000,
				'reason'         => '客戶申請',
				'bankCode'       => '004',
				'bankBranchCode' => '0037',
				// bankAccount 缺失
			]
		);
	}
}
