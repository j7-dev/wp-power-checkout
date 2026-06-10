<?php
/**
 * PayNow 電子發票課稅別 TaxType
 *
 * 發票層級課稅別，PayNow 發票 API（體系 3）以「字串列舉」傳入，故 backing type 為 string，
 * 且 enum value 直接對應 PayNow API 的字面值（SaleTax / FreeTax / ZeroTax / MixTax）。
 *  - SaleTax 應稅
 *  - FreeTax 免稅
 *  - ZeroTax 零稅率（須帶 is_pass_customs + zero_tax_rate_reason）
 *  - MixTax  混合（只能 應稅+免 或 應稅+零稅率）
 *
 * @see .claude/skills/paynow/references/invoice-api.md §10 載具 / 課稅別 / 零稅率原因全表
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums;

/** PayNow 電子發票課稅別 */
enum ETaxType: string {
	// 應稅.
	case SaleTax = 'SaleTax';

	// 免稅.
	case FreeTax = 'FreeTax';

	// 零稅率.
	case ZeroTax = 'ZeroTax';

	// 混合（應稅+免 或 應稅+零稅率）.
	case MixTax = 'MixTax';

	/**
	 * 取得課稅別中文標籤
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::SaleTax => '應稅',
			self::FreeTax => '免稅',
			self::ZeroTax => '零稅率',
			self::MixTax  => '混合稅率',
		};
	}
}
