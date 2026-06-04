<?php
/**
 * 宅配溫層 Temperature（綠界全方位物流 v2）
 *
 * 對應綠界 HOME 宅配的 Temperature 參數，值對齊綠界 API（0001 / 0002 / 0003）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 宅配溫層 */
enum LogisticsTemperature: string {
	/** 常溫 */
	case NORMAL = '0001';
	/** 冷藏 */
	case REFRIGERATED = '0002';
	/** 冷凍 */
	case FROZEN = '0003';

	/**
	 * 取得溫層的中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::NORMAL => '常溫',
			self::REFRIGERATED => '冷藏',
			self::FROZEN => '冷凍',
		};
	}
}
