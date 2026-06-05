<?php
/**
 * 綠界電子發票 API endpoint
 *
 * B2C：賣給消費者，RqHeader.Revision = '3.0.0'，端點前綴 /B2CInvoice/
 * B2B：賣給企業（含統編），RqHeader.Revision = '1.0.0' 且額外必填 RqID，端點前綴 /B2BInvoice/
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums;

/** 綠界電子發票 API endpoint */
enum EApi: string {
	// B2C 開立發票
	case B2C_ISSUE = '/B2CInvoice/Issue';

	// B2C 作廢發票
	case B2C_INVALID = '/B2CInvoice/Invalid';

	// B2B 開立發票（存證模式）
	case B2B_ISSUE = '/B2BInvoice/Issue';

	// B2B 作廢發票（存證模式，作廢即生效）
	case B2B_INVALID = '/B2BInvoice/Invalid';

	// B2C 開立折讓（部分退款開折讓單）
	case B2C_ALLOWANCE = '/B2CInvoice/Allowance';

	// B2C 作廢折讓
	case B2C_ALLOWANCE_INVALID = '/B2CInvoice/AllowanceInvalid';

	// B2B 開立折讓（存證模式，直接生效，不需 AllowanceConfirm）
	case B2B_ALLOWANCE = '/B2BInvoice/Allowance';

	// B2B 作廢折讓（存證模式）
	case B2B_ALLOWANCE_INVALID = '/B2BInvoice/AllowanceInvalid';

	// B2C 查詢發票明細（唯讀，GetIssue）
	case B2C_GET_ISSUE = '/B2CInvoice/GetIssue';

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::B2C_ISSUE => 'B2C 開立發票',
			self::B2C_INVALID => 'B2C 作廢發票',
			self::B2B_ISSUE => 'B2B 開立發票',
			self::B2B_INVALID => 'B2B 作廢發票',
			self::B2C_ALLOWANCE => 'B2C 開立折讓',
			self::B2C_ALLOWANCE_INVALID => 'B2C 作廢折讓',
			self::B2B_ALLOWANCE => 'B2B 開立折讓',
			self::B2B_ALLOWANCE_INVALID => 'B2B 作廢折讓',
			self::B2C_GET_ISSUE => 'B2C 查詢發票',
		};
	}

	/** @return string RqHeader.Revision，B2C 為 3.0.0、B2B 為 1.0.0 */
	public function revision(): string {
		return match ($this) {
			self::B2C_ISSUE, self::B2C_INVALID, self::B2C_ALLOWANCE, self::B2C_ALLOWANCE_INVALID, self::B2C_GET_ISSUE => '3.0.0',
			self::B2B_ISSUE, self::B2B_INVALID, self::B2B_ALLOWANCE, self::B2B_ALLOWANCE_INVALID => '1.0.0',
		};
	}

	/** @return bool 是否為 B2B（B2B 的 RqHeader 需額外帶 RqID） */
	public function is_b2b(): bool {
		return match ($this) {
			self::B2B_ISSUE, self::B2B_INVALID, self::B2B_ALLOWANCE, self::B2B_ALLOWANCE_INVALID => true,
			default => false,
		};
	}

	/** @return bool 是否為開立（true）或作廢（false） */
	public function is_issue(): bool {
		return match ($this) {
			self::B2C_ISSUE, self::B2B_ISSUE => true,
			default => false,
		};
	}

	/** @return bool 是否為開立折讓（true，與作廢折讓區分） */
	public function is_allowance(): bool {
		return match ($this) {
			self::B2C_ALLOWANCE, self::B2B_ALLOWANCE => true,
			default => false,
		};
	}
}
