<?php
/**
 * 藍新 ezPay 發票種類 Category
 *
 * 對應 PostData_ 的 Category 欄位，ezPay API 以「字串」傳入，故 backing type 為 string。
 *  - B2B 買受人為營業人（有統一編號，須索取紙本 PrintFlag=Y）
 *  - B2C 買受人為個人（可存載具 / 捐贈 / 索取紙本）
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §開立發票 Category
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums;

/** 藍新 ezPay 發票種類 */
enum ECategory: string {
	// 買受人為營業人（有統編）.
	case B2B = 'B2B';

	// 買受人為個人.
	case B2C = 'B2C';

	/**
	 * 取得發票種類中文標籤
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::B2B => '公司戶（統編）',
			self::B2C => '個人',
		};
	}
}
