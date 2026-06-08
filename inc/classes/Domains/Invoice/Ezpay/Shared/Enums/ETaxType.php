<?php
/**
 * 藍新 ezPay 發票課稅別 TaxType
 *
 * 發票層級課稅別，ezPay API 以「字串」傳入（'1' ~ '9'），故 backing type 為 string。
 *  - 1 應稅
 *  - 2 零稅率（須帶 CustomsClearance 報關標記）
 *  - 3 免稅
 *  - 9 混合應稅與免稅或零稅率（限 Category=B2C；須帶 AmtSales/AmtZero/AmtFree 分項）
 *
 * @see .claude/skills/ezpay-invoice/references/concepts.md §課稅別 TaxType
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums;

/** 藍新 ezPay 發票課稅別 */
enum ETaxType: string {
	// 應稅.
	case TAXABLE = '1';

	// 零稅率.
	case ZERO_RATED = '2';

	// 免稅.
	case EXEMPT = '3';

	// 混合應稅與免稅或零稅率（限 B2C）.
	case MIXED = '9';

	/**
	 * 取得課稅別中文標籤
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::TAXABLE    => '應稅',
			self::ZERO_RATED => '零稅率',
			self::EXEMPT     => '免稅',
			self::MIXED      => '混合稅率',
		};
	}
}
