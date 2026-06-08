<?php
/**
 * 藍新 ezPay B2C 發票載具類別 CarrierType
 *
 * 僅 Category=B2C 適用。ezPay API 以「字串」傳入，故 backing type 為 string。
 *  - 0 手機條碼載具
 *  - 1 自然人憑證條碼載具
 *  - 2 ezPay 電子發票載具（CarrierType=2 時 BuyerEmail 變必填）
 *  - NONE 空值（無載具；此時可搭配 LoveCode 捐贈或 PrintFlag=Y 索取紙本）
 *
 * 規則：CarrierType 有值時 CarrierNum 必填，且 LoveCode 必為空（載具與捐贈互斥）。
 *
 * @see .claude/skills/ezpay-invoice/references/concepts.md §載具規則
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums;

/** 藍新 ezPay B2C 發票載具類別 */
enum ECarrierType: string {
	// 無載具（紙本或捐贈）.
	case NONE = '';

	// 手機條碼載具.
	case MOBILE = '0';

	// 自然人憑證條碼載具.
	case MOICA = '1';

	// ezPay 電子發票載具.
	case EZPAY = '2';

	/**
	 * 取得載具類別中文標籤
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::NONE   => '無載具',
			self::MOBILE => '手機條碼',
			self::MOICA  => '自然人憑證條碼',
			self::EZPAY  => 'ezPay 電子發票載具',
		};
	}
}
