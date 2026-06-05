<?php
/**
 * PAYUNi 物流型態 LgsType
 *
 * PAYUNi 7-ELEVEN 超商物流分大宗寄倉（B2C）與店到店（C2C），決定 trade / ship_map 的 LgsType 參數。
 * 與綠界 LogisticsAccountType（b2c/c2c 小寫）不同：PAYUNi 直接以大寫 LgsType 當 API 參數。
 *
 * @see .claude/skills/payuni-logistics-v3/references/enums.md §LgsType
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums;

/** PAYUNi 物流型態 */
enum PayuniLgsType: string {
	/** 大宗寄倉（賣家寄到物流中心 → 配送門市） */
	case B2C = 'B2C';
	/** 店到店（賣家在門市寄、買家在門市取） */
	case C2C = 'C2C';
	/** 黑貓宅配（正物流 + 退貨） */
	case HOME = 'HOME';
	/** 退貨便（7-ELEVEN 超商退貨便，僅 B2C 開通會員可用） */
	case C2B = 'C2B';

	/**
	 * 取得中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::B2C  => '7-11 大宗寄倉',
			self::C2C  => '7-11 店到店',
			self::HOME => '黑貓宅配',
			self::C2B  => '7-11 退貨便',
		};
	}
}
