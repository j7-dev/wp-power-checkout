<?php
/**
 * 藍新 NewebPay MPG 付款方式（TradeInfo 旗標）
 *
 * Enum 值 = 藍新 TradeInfo 的付款方式參數名（CREDIT=1 / VACC=1 / ...）。
 * 白名單模式：MpgSettingsDTO::allowedPayments 勾選哪些，組 TradeInfo 時就把對應旗標設 1。
 *
 * ⚠️ TWQR 需 Version=2.3（MpgSettingsDTO 預設 version='2.3'）。
 * ⚠️ AFTEE（BNPL）本期不實作（需 OrderDetail），故不納入此白名單 enum。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §Payment Method Parameters
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums;

/** 藍新 MPG 付款方式（TradeInfo 旗標名） */
enum MpgPaymentMethod: string {
	/** 信用卡一次付清 */
	case CREDIT = 'CREDIT';
	/** ATM 虛擬帳號（offline，16 碼） */
	case VACC = 'VACC';
	/** WebATM（需讀卡機） */
	case WEBATM = 'WEBATM';
	/** 超商代碼（offline，金額 30-20000） */
	case CVS = 'CVS';
	/** 超商條碼（offline，金額 30-20000） */
	case BARCODE = 'BARCODE';
	/** LINE Pay */
	case LINEPAY = 'LINEPAY';
	/** Apple Pay */
	case APPLEPAY = 'APPLEPAY';
	/** 玉山 Wallet */
	case ESUNWALLET = 'ESUNWALLET';
	/** 台灣 Pay */
	case TAIWANPAY = 'TAIWANPAY';
	/** TWQR 跨機構行動支付（需 Version=2.3） */
	case TWQR = 'TWQR';

	/**
	 * 取得付款方式中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREDIT     => '信用卡',
			self::VACC       => 'ATM 虛擬帳號',
			self::WEBATM     => 'WebATM',
			self::CVS        => '超商代碼',
			self::BARCODE    => '超商條碼',
			self::LINEPAY    => 'LINE Pay',
			self::APPLEPAY   => 'Apple Pay',
			self::ESUNWALLET => '玉山 Wallet',
			self::TAIWANPAY  => '台灣 Pay',
			self::TWQR       => 'TWQR 行動支付',
		};
	}

	/**
	 * 是否為 offline 取號型（VACC / CVS / BARCODE）
	 *
	 * 取號型：先取得繳費資訊（虛擬帳號 / 代碼 / 條碼），消費者另行繳費，
	 * 取號通知不改訂單狀態，付款完成才轉 processing。
	 *
	 * @return bool
	 */
	public function is_offline(): bool {
		return match ( $this ) {
			self::VACC, self::CVS, self::BARCODE => true,
			default => false,
		};
	}

	/**
	 * 是否為 e-wallet（退款走 /API/EWallet/refund）
	 *
	 * @return bool
	 */
	public function is_ewallet(): bool {
		return match ( $this ) {
			self::LINEPAY, self::ESUNWALLET, self::TAIWANPAY => true,
			default => false,
		};
	}
}
