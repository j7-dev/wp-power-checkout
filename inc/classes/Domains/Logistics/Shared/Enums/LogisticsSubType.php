<?php
/**
 * 綠界物流子類型 LogisticsSubType
 *
 * 對應結帳頁 WC_Shipping_Method 選項與綠界全方位物流 v2 LogisticsSubType 參數。
 * 值對齊綠界 API（FAMI / UNIMART / HILIFE / HOME）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 綠界物流子類型 */
enum LogisticsSubType: string {
	/** 全家超商取貨 */
	case FAMI = 'FAMI';
	/** 統一超商（7-11）取貨 */
	case UNIMART = 'UNIMART';
	/** 萊爾富超商取貨 */
	case HILIFE = 'HILIFE';
	/** 宅配（黑貓，含溫層 Temperature） */
	case HOME = 'HOME';

	/**
	 * 是否為超商取貨子類型（須選店）
	 *
	 * @return bool
	 */
	public function is_cvs(): bool {
		return match ( $this ) {
			self::FAMI, self::UNIMART, self::HILIFE => true,
			self::HOME => false,
		};
	}

	/**
	 * 取得子類型的中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::FAMI => '全家超商取貨',
			self::UNIMART => '統一超商（7-11）取貨',
			self::HILIFE => '萊爾富超商取貨',
			self::HOME => '宅配',
		};
	}

	/**
	 * 以子類型字串值取得中文標籤（非合法值回傳原字串，供顯示退化）
	 *
	 * @param string $value 子類型字串（FAMI/UNIMART/HILIFE/HOME）
	 * @return string
	 */
	public static function label_of( string $value ): string {
		$case = self::tryFrom( $value );
		return $case instanceof self ? $case->label() : $value;
	}
}
