<?php
/**
 * PAYUNi Payment 版 Enum 測試：PayuniPaymentMethod + PayuniTradeStatus
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniPaymentMethod
 *   J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniTradeStatus
 *
 * 設計說明（兩個不同概念的拆分）：
 *   - PayuniPaymentMethod：string-backed enum，代表建單 request 的付款方式開關（UPP 頁讓顧客選擇）。
 *     case backing = 識別字串（Credit / ATM / CVS / ...），彼此唯一，無重複衝突。
 *     PHP backed enum 禁止重複 backing value，因此不用 int-backed——
 *     Credit / ApplePay / GooglePay 雖都映射 PaymentType=1，但各自是獨立開關，value 不可同為 1。
 *   - PayuniPaymentMethod::payment_type(): int 方法負責映射 PAYUNi 回傳的 PaymentType（可重複，無衝突）。
 *   - PayuniTradeStatus：int-backed enum（0/1/2/3/8 無重複，保持原設計）。
 *
 * 規格依據：
 *   - PaymentType 值域：payuni-upp-v2 SKILL.md §PaymentType（UPP V2 回傳）
 *     1=信用卡系列（Credit/CreditInst/ApplePay/GooglePay）, 2=ATM, 3=超商代碼, 5=貨到付款,
 *     6=icash Pay, 7=AFTEE, 9=LINE Pay, 10=宅配到付（黑貓）, 11=街口支付
 *   - TradeStatus 值域：payuni-upp-v2 SKILL.md §EncryptInfo 內 通用回傳參數 TradeStatus
 *     0=取號成功, 1=已付款, 2=付款失敗, 3=付款取消, 8=訂單待確認
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniTradeStatus;
use Tests\Integration\TestCase;

/**
 * PAYUNi Enum 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniEnumTest extends TestCase {

	// ========== PayuniPaymentMethod：string backing 值正確（Happy） ==========

	/**
	 * PayuniPaymentMethod 是 string-backed enum；各 case 的 value 就是識別字串本身。
	 * PHP backed enum 禁止重複 backing value，用 string 避免 Credit/ApplePay/GooglePay 全為 1 的衝突。
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_PaymentMethod_各case的string_value與case名一致(): void {
		$this->assertSame( 'Credit',     PayuniPaymentMethod::Credit->value );
		$this->assertSame( 'CreditInst', PayuniPaymentMethod::CreditInst->value );
		$this->assertSame( 'ApplePay',   PayuniPaymentMethod::ApplePay->value );
		$this->assertSame( 'GooglePay',  PayuniPaymentMethod::GooglePay->value );
		$this->assertSame( 'ATM',        PayuniPaymentMethod::ATM->value );
		$this->assertSame( 'CVS',        PayuniPaymentMethod::CVS->value );
		$this->assertSame( 'ICash',      PayuniPaymentMethod::ICash->value );
		$this->assertSame( 'LinePay',    PayuniPaymentMethod::LinePay->value );
		$this->assertSame( 'JKoPay',     PayuniPaymentMethod::JKoPay->value );
	}

	// ========== payment_type() 方法映射（Happy） ==========
	// 多個付款方式可映射同一 PaymentType（一對多），故改用方法而非 int backing value。

	/**
	 * Credit / CreditInst / ApplePay / GooglePay 映射 PaymentType = 1（信用卡系列）
	 * 依 payuni-upp-v2 §PaymentType 表：1=信用卡（含分期/紅利/銀聯/Apple/Google/Samsung Pay）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_type_信用卡系列回傳1(): void {
		$this->assertSame( 1, PayuniPaymentMethod::Credit->payment_type() );
		$this->assertSame( 1, PayuniPaymentMethod::CreditInst->payment_type() );
		$this->assertSame( 1, PayuniPaymentMethod::ApplePay->payment_type() );
		$this->assertSame( 1, PayuniPaymentMethod::GooglePay->payment_type() );
	}

	/**
	 * ATM 映射 PaymentType = 2
	 * 依 payuni-upp-v2 §PaymentType 表：2=ATM 轉帳
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_type_ATM回傳2(): void {
		$this->assertSame( 2, PayuniPaymentMethod::ATM->payment_type() );
	}

	/**
	 * CVS 映射 PaymentType = 3
	 * 依 payuni-upp-v2 §PaymentType 表：3=超商代碼
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_type_CVS回傳3(): void {
		$this->assertSame( 3, PayuniPaymentMethod::CVS->payment_type() );
	}

	/**
	 * ICash 映射 PaymentType = 6
	 * 依 payuni-upp-v2 §PaymentType 表：6=愛金卡（icash Pay）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_type_ICash回傳6(): void {
		$this->assertSame( 6, PayuniPaymentMethod::ICash->payment_type() );
	}

	/**
	 * LinePay 映射 PaymentType = 9
	 * 依 payuni-upp-v2 §PaymentType 表：9=LINE Pay
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_type_LinePay回傳9(): void {
		$this->assertSame( 9, PayuniPaymentMethod::LinePay->payment_type() );
	}

	/**
	 * JKoPay 映射 PaymentType = 11
	 * 依 payuni-upp-v2 §PaymentType 表：11=街口支付（JKoPay）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_type_JKoPay回傳11(): void {
		$this->assertSame( 11, PayuniPaymentMethod::JKoPay->payment_type() );
	}

	// ========== is_offline / is_credit 判定（Happy） ==========

	/**
	 * ATM 是離線付款（需取號等待繳費）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_offline_ATM為離線付款(): void {
		$this->assertTrue( PayuniPaymentMethod::ATM->is_offline() );
	}

	/**
	 * CVS 是離線付款（超商代碼，需到超商繳費）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_offline_CVS為離線付款(): void {
		$this->assertTrue( PayuniPaymentMethod::CVS->is_offline() );
	}

	/**
	 * 信用卡不是離線付款（即時付款）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_offline_Credit不是離線付款(): void {
		$this->assertFalse( PayuniPaymentMethod::Credit->is_offline() );
	}

	/**
	 * LinePay 不是離線付款
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_offline_LinePay不是離線付款(): void {
		$this->assertFalse( PayuniPaymentMethod::LinePay->is_offline() );
	}

	/**
	 * is_credit() 對信用卡系列（Credit / CreditInst / ApplePay / GooglePay）回傳 true
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_credit_信用卡系列為true(): void {
		$this->assertTrue( PayuniPaymentMethod::Credit->is_credit() );
		$this->assertTrue( PayuniPaymentMethod::CreditInst->is_credit() );
		$this->assertTrue( PayuniPaymentMethod::ApplePay->is_credit() );
		$this->assertTrue( PayuniPaymentMethod::GooglePay->is_credit() );
	}

	/**
	 * is_credit() 對非信用卡（ATM / CVS / LinePay / JKoPay / ICash）回傳 false
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_credit_非信用卡為false(): void {
		$this->assertFalse( PayuniPaymentMethod::ATM->is_credit() );
		$this->assertFalse( PayuniPaymentMethod::CVS->is_credit() );
		$this->assertFalse( PayuniPaymentMethod::LinePay->is_credit() );
		$this->assertFalse( PayuniPaymentMethod::JKoPay->is_credit() );
		$this->assertFalse( PayuniPaymentMethod::ICash->is_credit() );
	}

	// ========== PayuniTradeStatus 值域（Happy） ==========

	/**
	 * TradeStatus=0 代表取號成功（ATM/CVS 等待繳費）
	 * 依 payuni-upp-v2 §TradeStatus：0=取號成功
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus_0為取號成功(): void {
		$this->assertSame( 0, PayuniTradeStatus::GetCode->value );
	}

	/**
	 * TradeStatus=1 代表已付款
	 * 依 payuni-upp-v2 §TradeStatus：1=已付款
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus_1為已付款(): void {
		$this->assertSame( 1, PayuniTradeStatus::Paid->value );
	}

	/**
	 * TradeStatus=2 代表付款失敗
	 * 依 payuni-upp-v2 §TradeStatus：2=付款失敗
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus_2為付款失敗(): void {
		$this->assertSame( 2, PayuniTradeStatus::Failed->value );
	}

	/**
	 * TradeStatus=3 代表付款取消
	 * 依 payuni-upp-v2 §TradeStatus：3=付款取消
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus_3為付款取消(): void {
		$this->assertSame( 3, PayuniTradeStatus::Cancelled->value );
	}

	/**
	 * TradeStatus=8 代表訂單待確認
	 * 依 payuni-upp-v2 §TradeStatus：8=訂單待確認（UNKNOWN 情境，60秒未收銀行回應）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus_8為訂單待確認(): void {
		$this->assertSame( 8, PayuniTradeStatus::Pending->value );
	}

	// ========== is_paid / is_get_code 判定（Happy） ==========

	/**
	 * TradeStatus=1（已付款）→ is_paid() = true
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_paid_已付款為true(): void {
		$this->assertTrue( PayuniTradeStatus::Paid->is_paid() );
	}

	/**
	 * TradeStatus=0（取號成功）→ is_paid() = false
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_paid_取號成功為false(): void {
		$this->assertFalse( PayuniTradeStatus::GetCode->is_paid() );
	}

	/**
	 * TradeStatus=2（付款失敗）→ is_paid() = false
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_paid_付款失敗為false(): void {
		$this->assertFalse( PayuniTradeStatus::Failed->is_paid() );
	}

	/**
	 * TradeStatus=0 → is_get_code() = true（ATM/CVS 取號成功，等待繳費）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_get_code_取號成功為true(): void {
		$this->assertTrue( PayuniTradeStatus::GetCode->is_get_code() );
	}

	/**
	 * TradeStatus=1（已付款）→ is_get_code() = false
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_is_get_code_已付款為false(): void {
		$this->assertFalse( PayuniTradeStatus::Paid->is_get_code() );
	}

	/**
	 * TradeStatus=8（待確認）→ is_paid() = false（不算正式付款）
	 * 依 payuni-upp-v2 §常見注意事項：UNKNOWN 60秒後由 NotifyURL 確認最終結果
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_is_paid_待確認為false(): void {
		$this->assertFalse( PayuniTradeStatus::Pending->is_paid() );
	}

	// ========== TradeStatus from / tryFrom 整數值（Edge） ==========

	/**
	 * PayuniTradeStatus::tryFrom() 可從整數值建立 enum case（int-backed）
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus_tryFrom_整數值建立enum(): void {
		$this->assertSame( PayuniTradeStatus::Paid,      PayuniTradeStatus::tryFrom( 1 ) );
		$this->assertSame( PayuniTradeStatus::Failed,    PayuniTradeStatus::tryFrom( 2 ) );
		$this->assertSame( PayuniTradeStatus::Cancelled, PayuniTradeStatus::tryFrom( 3 ) );
		$this->assertSame( PayuniTradeStatus::GetCode,   PayuniTradeStatus::tryFrom( 0 ) );
		$this->assertSame( PayuniTradeStatus::Pending,   PayuniTradeStatus::tryFrom( 8 ) );
		$this->assertNull( PayuniTradeStatus::tryFrom( 999 ) );
	}

	// ========== PayuniPaymentMethod from / tryFrom 字串值（Edge） ==========

	/**
	 * PayuniPaymentMethod::from() / tryFrom() 以識別字串建立 enum case（string-backed）
	 * 不用整數——PayuniPaymentMethod 是 string backing，與 PayuniTradeStatus 的 int backing 不同。
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_PaymentMethod_from_字串值建立enum(): void {
		$this->assertSame( PayuniPaymentMethod::Credit,     PayuniPaymentMethod::from( 'Credit' ) );
		$this->assertSame( PayuniPaymentMethod::CreditInst, PayuniPaymentMethod::from( 'CreditInst' ) );
		$this->assertSame( PayuniPaymentMethod::ATM,        PayuniPaymentMethod::from( 'ATM' ) );
		$this->assertSame( PayuniPaymentMethod::CVS,        PayuniPaymentMethod::from( 'CVS' ) );
		$this->assertSame( PayuniPaymentMethod::LinePay,    PayuniPaymentMethod::from( 'LinePay' ) );
		$this->assertSame( PayuniPaymentMethod::JKoPay,     PayuniPaymentMethod::tryFrom( 'JKoPay' ) );
		$this->assertSame( PayuniPaymentMethod::ApplePay,   PayuniPaymentMethod::tryFrom( 'ApplePay' ) );
		$this->assertSame( PayuniPaymentMethod::GooglePay,  PayuniPaymentMethod::tryFrom( 'GooglePay' ) );
		$this->assertSame( PayuniPaymentMethod::ICash,      PayuniPaymentMethod::tryFrom( 'ICash' ) );
	}

	/**
	 * PayuniPaymentMethod::tryFrom() 對未知字串回傳 null
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_PaymentMethod_tryFrom_未知字串回傳null(): void {
		$this->assertNull( PayuniPaymentMethod::tryFrom( 'NotExist' ) );
		$this->assertNull( PayuniPaymentMethod::tryFrom( '' ) );
		// 整數字串也不符合任何 case
		$this->assertNull( PayuniPaymentMethod::tryFrom( '1' ) );
	}
}
