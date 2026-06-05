<?php
/**
 * 綠界電子收據捐贈人類型 DonorType
 *
 * | 值 | 類型 | Identifier 內容 |
 * |----|------|-----------------|
 * | 1  | 自然人 | 證號 |
 * | 2  | 公司法人 | 統編 |
 * | 3  | 人民團體 | 人民團體登記字號 |
 * | 4  | 政黨 | 政黨登記字號 |
 * | 5  | 匿名 | — |
 *
 * 限制：
 *  - ReceiptType=2（公益）僅可填 1 或 2，不可填 3/4/5。
 *  - ReceiptType=4（政治）1~5 皆可；=5（匿名）金額 ≤ 10,000。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §Issue 必填欄位速查
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums;

/** 綠界電子收據捐贈人類型 */
enum EDonorType: int {
	/** 自然人 */
	case INDIVIDUAL = 1;

	/** 公司法人 */
	case COMPANY = 2;

	/** 人民團體 */
	case ASSOCIATION = 3;

	/** 政黨 */
	case PARTY = 4;

	/** 匿名 */
	case ANONYMOUS = 5;

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::INDIVIDUAL  => '自然人',
			self::COMPANY     => '公司法人',
			self::ASSOCIATION => '人民團體',
			self::PARTY       => '政黨',
			self::ANONYMOUS   => '匿名',
		};
	}

	/** @return bool 公益收據是否允許此捐贈人類型（僅 1/2） */
	public function is_allowed_for_charity(): bool {
		return match ($this) {
			self::INDIVIDUAL, self::COMPANY => true,
			default                         => false,
		};
	}
}
