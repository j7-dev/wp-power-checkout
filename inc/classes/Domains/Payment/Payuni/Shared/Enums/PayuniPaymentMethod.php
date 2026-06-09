<?php
/**
 * PAYUNi UPP V2 付款方式（建單 request 付款方式開關）
 *
 * string-backed enum：case backing = 識別字串本身（Credit / ATM / CVS / ...），彼此唯一。
 *
 * ⚠️ 為何用 string-backed 而非 int-backed：
 *   PHP backed enum 禁止重複 backing value。Credit / CreditInst / ApplePay / GooglePay
 *   雖都映射 PAYUNi PaymentType=1，但各自是獨立的「付款方式開關」，value 不可同為 1，
 *   故以字串 backing 區分，再用 payment_type() 方法做「多對一」的 PaymentType 映射。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §付款方式 / §PaymentType
 * @see .claude/skills/payuni-upp-v2/references/codes-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums;

/** PAYUNi UPP V2 付款方式開關（string-backed） */
enum PayuniPaymentMethod: string {
	/** 信用卡一次付清 */
	case Credit = 'Credit';
	/** 信用卡分期（PAYUNi InstFlag 3/6/9/12/18/24/30） */
	case CreditInst = 'CreditInst';
	/** Apple Pay（信用卡系列） */
	case ApplePay = 'ApplePay';
	/** Google Pay（信用卡系列） */
	case GooglePay = 'GooglePay';
	/** ATM 虛擬帳號（offline 取號） */
	case ATM = 'ATM';
	/** 超商代碼（offline 取號） */
	case CVS = 'CVS';
	/** icash Pay（愛金卡） */
	case ICash = 'ICash';
	/** LINE Pay */
	case LinePay = 'LinePay';
	/** 街口支付（JKoPay） */
	case JKoPay = 'JKoPay';

	/**
	 * 映射 PAYUNi 回傳的 PaymentType（多對一，可重複，無 backing 衝突）
	 *
	 * 依 payuni-upp-v2 §PaymentType 表：
	 *  1=信用卡系列（含分期 / Apple / Google Pay）, 2=ATM, 3=超商代碼,
	 *  6=icash Pay, 9=LINE Pay, 11=街口支付
	 *
	 * @return int PAYUNi PaymentType
	 */
	public function payment_type(): int {
		return match ( $this ) {
			self::Credit, self::CreditInst, self::ApplePay, self::GooglePay => 1,
			self::ATM     => 2,
			self::CVS     => 3,
			self::ICash   => 6,
			self::LinePay => 9,
			self::JKoPay  => 11,
		};
	}

	/**
	 * 是否為 offline 取號型（ATM / CVS）
	 *
	 * 取號型：先取得繳費資訊（虛擬帳號 / 超商代碼），消費者另行繳費，
	 * 取號通知不改訂單狀態，付款完成才轉 processing。
	 *
	 * @return bool
	 */
	public function is_offline(): bool {
		return match ( $this ) {
			self::ATM, self::CVS => true,
			default => false,
		};
	}

	/**
	 * 是否為信用卡系列（Credit / CreditInst / ApplePay / GooglePay）
	 *
	 * @return bool
	 */
	public function is_credit(): bool {
		return match ( $this ) {
			self::Credit, self::CreditInst, self::ApplePay, self::GooglePay => true,
			default => false,
		};
	}

	/**
	 * 取得付款方式中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Credit     => '信用卡',
			self::CreditInst => '信用卡分期',
			self::ApplePay   => 'Apple Pay',
			self::GooglePay  => 'Google Pay',
			self::ATM        => 'ATM 虛擬帳號',
			self::CVS        => '超商代碼',
			self::ICash      => 'icash Pay',
			self::LinePay    => 'LINE Pay',
			self::JKoPay     => '街口支付',
		};
	}
}
