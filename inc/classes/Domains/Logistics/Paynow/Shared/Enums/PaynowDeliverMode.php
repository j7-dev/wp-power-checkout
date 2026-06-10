<?php
/**
 * PayNow 物流取貨付款模式 Enum（DeliverMode，立吉富體系 1）
 *
 * 01 = 取貨付款（COD，貨到付款）
 * 02 = 取貨不付款（線上已付款）
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 3
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums;

/** PayNow 物流取貨付款模式（DeliverMode） */
enum PaynowDeliverMode: string {

	/** 取貨付款（COD） */
	case Cod = '01';

	/** 取貨不付款（線上已付款） */
	case NoCod = '02';

	/**
	 * 是否為貨到付款（COD）
	 *
	 * @return bool COD 回 true
	 */
	public function is_cod(): bool {
		return self::Cod === $this;
	}

	/**
	 * 取得人類可讀標籤（繁體中文）
	 *
	 * @return string 模式名稱
	 */
	public function label(): string {
		return match ( $this ) {
			self::Cod   => \__( '取貨付款（貨到付款）', 'power_checkout' ),
			self::NoCod => \__( '取貨不付款', 'power_checkout' ),
		};
	}
}
