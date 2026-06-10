<?php
/**
 * PayNow 付款方式 / 意圖狀態 / 退款狀態 Enum 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowPaymentMethod
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowIntentStatus
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowRefundStatus
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 4/5
 *   - specs/open-issue/paynow-execution-plan.md §澄清裁決 Q1（排除 ApplePayDeferred）
 *   - .claude/skills/paynow/references/concepts.md §4 付款方式總覽（體系 1）
 *   - .claude/skills/paynow/references/payment-rest-api.md §5 Refund 退款狀態
 *
 * 付款方式 7 值（排除 ApplePayDeferred，Q1 裁決）：
 *   CreditCard / CreditCardInstallment / ATM / ConvenienceStore /
 *   LINEPayOnline / LINEPayOffline / ApplePay
 *
 * is_offline() 分類：ATM / ConvenienceStore = true，其餘 = false
 *
 * IntentStatus 5 值：draft / processing / pending_review / success / canceled
 *   is_success() → success；is_draft() → draft
 *
 * RefundStatus 5 值：success / failed / rejected / processing / validation_error
 *   is_success() / is_rejected() / is_processing()
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowEnumTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowIntentStatus;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowRefundStatus;
use Tests\Integration\TestCase;

/**
 * PayNow Enum 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowEnumTest extends TestCase {

	// ========== PaynowPaymentMethod 值域（Smoke） ==========

	/**
	 * PaynowPaymentMethod 包含 7 個合法付款方式值（排除 ApplePayDeferred）
	 * 依 Q1 裁決：全部 7 值，不含 ApplePayDeferred（不可與其他併用）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod包含7個合法值(): void {
		$cases = PaynowPaymentMethod::cases();
		$this->assertCount( 7, $cases, 'PaynowPaymentMethod 應包含 7 個 case（排除 ApplePayDeferred）' );
	}

	/**
	 * CreditCard 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_CreditCard值正確(): void {
		$this->assertSame( 'CreditCard', PaynowPaymentMethod::CreditCard->value );
	}

	/**
	 * CreditCardInstallment 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_CreditCardInstallment值正確(): void {
		$this->assertSame( 'CreditCardInstallment', PaynowPaymentMethod::CreditCardInstallment->value );
	}

	/**
	 * ATM 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_ATM值正確(): void {
		$this->assertSame( 'ATM', PaynowPaymentMethod::ATM->value );
	}

	/**
	 * ConvenienceStore 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_ConvenienceStore值正確(): void {
		$this->assertSame( 'ConvenienceStore', PaynowPaymentMethod::ConvenienceStore->value );
	}

	/**
	 * LINEPayOnline 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_LINEPayOnline值正確(): void {
		$this->assertSame( 'LINEPayOnline', PaynowPaymentMethod::LINEPayOnline->value );
	}

	/**
	 * LINEPayOffline 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_LINEPayOffline值正確(): void {
		$this->assertSame( 'LINEPayOffline', PaynowPaymentMethod::LINEPayOffline->value );
	}

	/**
	 * ApplePay 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_PaymentMethod_ApplePay值正確(): void {
		$this->assertSame( 'ApplePay', PaynowPaymentMethod::ApplePay->value );
	}

	/**
	 * ApplePayDeferred 不在 PaynowPaymentMethod enum 中（Q1 排除）
	 * 以 tryFrom 確認值不存在
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_ApplePayDeferred不在enum中(): void {
		$result = PaynowPaymentMethod::tryFrom( 'ApplePayDeferred' );
		$this->assertNull( $result, 'ApplePayDeferred 不應存在於 PaynowPaymentMethod enum（Q1 排除）' );
	}

	// ========== is_offline() 分類（Happy） ==========

	/**
	 * ATM 是離線付款（is_offline = true）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ATM是離線付款(): void {
		$this->assertTrue( PaynowPaymentMethod::ATM->is_offline() );
	}

	/**
	 * ConvenienceStore 是離線付款（is_offline = true）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ConvenienceStore是離線付款(): void {
		$this->assertTrue( PaynowPaymentMethod::ConvenienceStore->is_offline() );
	}

	/**
	 * CreditCard 不是離線付款（is_offline = false）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_CreditCard不是離線付款(): void {
		$this->assertFalse( PaynowPaymentMethod::CreditCard->is_offline() );
	}

	/**
	 * CreditCardInstallment 不是離線付款（is_offline = false）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_CreditCardInstallment不是離線付款(): void {
		$this->assertFalse( PaynowPaymentMethod::CreditCardInstallment->is_offline() );
	}

	/**
	 * LINEPayOnline 不是離線付款（is_offline = false）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_LINEPayOnline不是離線付款(): void {
		$this->assertFalse( PaynowPaymentMethod::LINEPayOnline->is_offline() );
	}

	/**
	 * LINEPayOffline 不是離線付款（is_offline = false）
	 * 注意：LINEPayOffline 是「LINE Pay 實體」但非 ATM/超商代碼的「待繳付款」模式
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_LINEPayOffline不是離線付款(): void {
		$this->assertFalse( PaynowPaymentMethod::LINEPayOffline->is_offline() );
	}

	/**
	 * ApplePay 不是離線付款（is_offline = false）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_ApplePay不是離線付款(): void {
		$this->assertFalse( PaynowPaymentMethod::ApplePay->is_offline() );
	}

	// ========== PaynowIntentStatus 值域（Smoke） ==========

	/**
	 * IntentStatus draft 值正確
	 * 依 concepts.md §5：draft = 已建立尚未付款
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_draft值正確(): void {
		$this->assertSame( 'draft', PaynowIntentStatus::Draft->value );
	}

	/**
	 * IntentStatus processing 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_processing值正確(): void {
		$this->assertSame( 'processing', PaynowIntentStatus::Processing->value );
	}

	/**
	 * IntentStatus pending_review 值正確
	 * 已發起付款、待驗證 3DS（信用卡）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_pending_review值正確(): void {
		$this->assertSame( 'pending_review', PaynowIntentStatus::PendingReview->value );
	}

	/**
	 * IntentStatus success 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_success值正確(): void {
		$this->assertSame( 'success', PaynowIntentStatus::Success->value );
	}

	/**
	 * IntentStatus canceled 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_canceled值正確(): void {
		$this->assertSame( 'canceled', PaynowIntentStatus::Canceled->value );
	}

	// ========== IntentStatus 判定方法（Happy） ==========

	/**
	 * Success → is_success() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_Success的is_success為true(): void {
		$this->assertTrue( PaynowIntentStatus::Success->is_success() );
	}

	/**
	 * Draft → is_success() = false
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_Draft的is_success為false(): void {
		$this->assertFalse( PaynowIntentStatus::Draft->is_success() );
	}

	/**
	 * Draft → is_draft() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_Draft的is_draft為true(): void {
		$this->assertTrue( PaynowIntentStatus::Draft->is_draft() );
	}

	/**
	 * Success → is_draft() = false
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_IntentStatus_Success的is_draft為false(): void {
		$this->assertFalse( PaynowIntentStatus::Success->is_draft() );
	}

	// ========== PaynowRefundStatus 值域（Smoke） ==========

	/**
	 * RefundStatus success 值正確
	 * 依 payment-rest-api.md §5：退款狀態 5 值
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_success值正確(): void {
		$this->assertSame( 'success', PaynowRefundStatus::Success->value );
	}

	/**
	 * RefundStatus failed 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_failed值正確(): void {
		$this->assertSame( 'failed', PaynowRefundStatus::Failed->value );
	}

	/**
	 * RefundStatus rejected 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_rejected值正確(): void {
		$this->assertSame( 'rejected', PaynowRefundStatus::Rejected->value );
	}

	/**
	 * RefundStatus processing 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_processing值正確(): void {
		$this->assertSame( 'processing', PaynowRefundStatus::Processing->value );
	}

	/**
	 * RefundStatus validation_error 值正確
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_validation_error值正確(): void {
		$this->assertSame( 'validation_error', PaynowRefundStatus::ValidationError->value );
	}

	// ========== RefundStatus 判定方法（Happy） ==========

	/**
	 * Success → is_success() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_Success的is_success為true(): void {
		$this->assertTrue( PaynowRefundStatus::Success->is_success() );
	}

	/**
	 * Rejected → is_success() = false
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_Rejected的is_success為false(): void {
		$this->assertFalse( PaynowRefundStatus::Rejected->is_success() );
	}

	/**
	 * Rejected → is_rejected() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_Rejected的is_rejected為true(): void {
		$this->assertTrue( PaynowRefundStatus::Rejected->is_rejected() );
	}

	/**
	 * Success → is_rejected() = false
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_Success的is_rejected為false(): void {
		$this->assertFalse( PaynowRefundStatus::Success->is_rejected() );
	}

	/**
	 * Processing → is_processing() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_Processing的is_processing為true(): void {
		$this->assertTrue( PaynowRefundStatus::Processing->is_processing() );
	}

	/**
	 * Success → is_processing() = false
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_RefundStatus_Success的is_processing為false(): void {
		$this->assertFalse( PaynowRefundStatus::Success->is_processing() );
	}
}
