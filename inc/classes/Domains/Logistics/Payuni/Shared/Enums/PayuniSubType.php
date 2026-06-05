<?php
/**
 * PAYUNi 物流子類型 PayuniSubType（結帳頁顧客可選的運送方式）
 *
 * PAYUNi 物流目前只支援 7-ELEVEN 與黑貓宅配（無全家 / 萊爾富 / OK）。
 * 因此子類型僅兩種：SEVEN（超商取貨，需 ship_map 選店）/ HOME（宅配，不選店）。
 *
 * ⚠️ 與綠界 LogisticsSubType（FAMI/UNIMART/HILIFE/HOME）刻意不同，兩 provider 各有自己的
 *    enabled_methods 集合，由 get_supported_methods() 各自把關。
 *
 * @see .claude/skills/payuni-logistics-v3/references/enums.md §ShipType §超商代碼
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums;

/** PAYUNi 物流子類型 */
enum PayuniSubType: string {
	/** 7-ELEVEN 超商取貨（需 ship_map 選店；ShipType=1） */
	case SEVEN = 'SEVEN';
	/** 黑貓宅配（不選店；ShipType=2） */
	case HOME = 'HOME';

	/**
	 * 是否為超商取貨（需 ship_map 選店）
	 *
	 * @return bool
	 */
	public function is_cvs(): bool {
		return self::SEVEN === $this;
	}

	/**
	 * 取得 PAYUNi ShipType 通路類別（1=7-ELEVEN / 2=黑貓）
	 *
	 * @return int
	 */
	public function ship_type(): int {
		return match ( $this ) {
			self::SEVEN => 1,
			self::HOME  => 2,
		};
	}

	/**
	 * 取得中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::SEVEN => '7-ELEVEN 超商取貨',
			self::HOME  => '黑貓宅配',
		};
	}
}
