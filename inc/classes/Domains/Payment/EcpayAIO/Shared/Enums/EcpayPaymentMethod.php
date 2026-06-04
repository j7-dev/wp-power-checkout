<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums;

/**
 * 綠界 AIO ChoosePayment 付款方式
 *
 * Enum 值對齊綠界 ChoosePayment 參數：Credit / WebATM / ATM / CVS / BARCODE / ApplePay。
 *
 * ⚠️ 分期付款（CreditInstallment）與定期定額（Period）在綠界皆屬於 ChoosePayment=Credit，
 * 並非獨立的 ChoosePayment 值，而是透過額外參數區分：
 *  - 分期付款：ChoosePayment=Credit + CreditInstallment=3,6,12,18,24,30
 *  - 定期定額：ChoosePayment=Credit + PeriodAmount / PeriodType / Frequency / ExecTimes
 * 因此本 enum 的 CREDIT_INSTALLMENT、PERIOD 兩個 case 的 value 同樣回傳 'Credit'，
 * 僅作為「業務語意」層的區分；組裝 ChoosePayment 時請用 {@see self::choose_payment()}，
 * 額外參數則於階段二的 RequestParams 依語意 case 附加。
 *
 * @see https://developers.ecpay.com.tw/?p=2862
 */
enum EcpayPaymentMethod: string {
	/** 信用卡一次付清 */
	case CREDIT = 'Credit';
	/** 信用卡分期（ChoosePayment=Credit + CreditInstallment 參數） */
	case CREDIT_INSTALLMENT = 'CreditInstallment';
	/** 定期定額（ChoosePayment=Credit + Period 系列參數） */
	case PERIOD = 'Period';
	/** 網路 ATM */
	case WEB_ATM = 'WebATM';
	/** 自動櫃員機（ATM 虛擬帳號） */
	case ATM = 'ATM';
	/** 超商代碼 */
	case CVS = 'CVS';
	/** 超商條碼 */
	case BARCODE = 'BARCODE';
	/** Apple Pay（僅支援手機支付） */
	case APPLE_PAY = 'ApplePay';

	/**
	 * 取得實際送往綠界的 ChoosePayment 值
	 *
	 * 分期與定期定額對綠界而言皆為 Credit，其餘 case value 即為 ChoosePayment 值。
	 *
	 * @return string ChoosePayment 值
	 */
	public function choose_payment(): string {
		return match ( $this ) {
			self::CREDIT, self::CREDIT_INSTALLMENT, self::PERIOD => self::CREDIT->value,
			self::WEB_ATM => self::WEB_ATM->value,
			self::ATM => self::ATM->value,
			self::CVS => self::CVS->value,
			self::BARCODE => self::BARCODE->value,
			self::APPLE_PAY => self::APPLE_PAY->value,
		};
	}

	/**
	 * 取得付款方式的中文標籤
	 *
	 * @return string 標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREDIT => '信用卡',
			self::CREDIT_INSTALLMENT => '信用卡分期',
			self::PERIOD => '定期定額',
			self::WEB_ATM => '網路 ATM',
			self::ATM => 'ATM 虛擬帳號',
			self::CVS => '超商代碼',
			self::BARCODE => '超商條碼',
			self::APPLE_PAY => 'Apple Pay',
		};
	}

	/**
	 * 是否為非即時付款（取號後消費者另行繳費）
	 *
	 * ATM / CVS / BARCODE 為非即時付款，會先取號再由消費者臨櫃 / 轉帳繳費。
	 *
	 * @return bool
	 */
	public function is_get_code_payment(): bool {
		return match ( $this ) {
			self::ATM, self::CVS, self::BARCODE => true,
			default => false,
		};
	}
}
