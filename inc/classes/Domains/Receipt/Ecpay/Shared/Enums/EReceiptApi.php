<?php
/**
 * 綠界電子收據 API endpoint
 *
 * 電子收據與電子發票共用網域（einvoice(-stage).ecpay.com.tw），但端點前綴為 /Receipt/，
 * 且 RqHeader 只需 Timestamp、**不需要 Revision**（與 B2C/B2B 發票不同）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md
 * @see .claude/skills/ECPay-API-Skill/references/Receipt/電子收據API技術串接文件.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums;

/** 綠界電子收據 API endpoint */
enum EReceiptApi: string {
	// 開立收據
	case ISSUE = '/Receipt/Issue';

	// 作廢收據
	case INVALID = '/Receipt/Invalid';

	// 單筆查詢收據
	case GET = '/Receipt/GetReceipt';

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::ISSUE   => '開立收據',
			self::INVALID => '作廢收據',
			self::GET     => '查詢收據',
		};
	}

	/** @return bool 是否為開立（true）或作廢/查詢（false） */
	public function is_issue(): bool {
		return self::ISSUE === $this;
	}
}
