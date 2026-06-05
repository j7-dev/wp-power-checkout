<?php
/**
 * 綠界電子收據領用方式 RetrievalMethod
 *
 * | 值 | 方式 | 附帶必填 |
 * |----|------|----------|
 * | 1  | 紙本 | DeliveryAddress 必填 |
 * | 2  | 電子 | Email 必填 |
 * | 3  | 自行處理 | — |
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §Issue 必填欄位速查
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums;

/** 綠界電子收據領用方式 */
enum ERetrievalMethod: int {
	/** 紙本（需寄送地址） */
	case PAPER = 1;

	/** 電子（需 Email） */
	case ELECTRONIC = 2;

	/** 自行處理 */
	case SELF = 3;

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::PAPER      => '紙本',
			self::ELECTRONIC => '電子',
			self::SELF       => '自行處理',
		};
	}
}
