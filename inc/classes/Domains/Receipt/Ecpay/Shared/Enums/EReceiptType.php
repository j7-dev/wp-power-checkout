<?php
/**
 * 綠界電子收據類型 ReceiptType
 *
 * | 值 | 類型 | 用途 | 特別限制 |
 * |----|------|------|----------|
 * | 1  | 一般收據 | 記帳、定金、押金、雜支等非發票用途 | — |
 * | 2  | 公益收據 | 捐贈給公益團體 | 僅可 1 項商品；DonorType 只能 1 或 2 |
 * | 4  | 政治獻金 | 捐贈給政黨/政治團體/擬參選人 | 匿名(5) ≤ 1 萬；現金(3) ≤ 10 萬；需 DonationInfo |
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §收據類型詳細限制
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums;

/** 綠界電子收據類型 */
enum EReceiptType: int {
	/** 一般收據 */
	case GENERAL = 1;

	/** 公益收據 */
	case CHARITY = 2;

	/** 政治獻金 */
	case POLITICAL = 4;

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::GENERAL   => '一般收據',
			self::CHARITY   => '公益收據',
			self::POLITICAL => '政治獻金收據',
		};
	}

	/** @return bool 是否為捐贈類（公益/政治），需 DonorType */
	public function is_donation(): bool {
		return match ($this) {
			self::CHARITY, self::POLITICAL => true,
			self::GENERAL                  => false,
		};
	}

	/** @return bool 公益收據僅可帶 1 項商品 */
	public function is_single_item_only(): bool {
		return self::CHARITY === $this;
	}
}
