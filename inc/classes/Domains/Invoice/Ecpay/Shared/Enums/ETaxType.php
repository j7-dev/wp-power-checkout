<?php
/**
 * 綠界發票課稅別 TaxType
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §稅別
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums;

/**
 * 綠界發票課稅別
 *
 * 綠界 API 以「字串」傳入（'1' ~ '9'），故 backing type 為 string。
 */
enum ETaxType: string {
	// 應稅
	case TAXABLE = '1';

	// 零稅率
	case ZERO_RATED = '2';

	// 免稅
	case EXEMPT = '3';

	// 特種應稅（InvType=08 時）
	case SPECIAL = '4';

	// 混合稅率（Items 中各項目分別指定 ItemTaxType）
	case MIXED = '9';

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::TAXABLE => '應稅',
			self::ZERO_RATED => '零稅率',
			self::EXEMPT => '免稅',
			self::SPECIAL => '特種應稅',
			self::MIXED => '混合稅率',
		};
	}
}
