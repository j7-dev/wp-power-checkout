<?php
/**
 * PayNow 物流服務類別 Enum（Logistic_serviceID，立吉富體系 1）
 *
 * 對齊 woomp `class-paynow-shipping-logistic-service.php` 的 01-06 / 21-24 常數。
 *
 * 超商取貨（is_cvs() = true）：01-05 + 21-24
 * 宅配（is_cvs() = false）：06（黑貓宅配）
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 3
 * @see ../woomp/.../utils/class-paynow-shipping-logistic-service.php
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums;

/** PayNow 物流服務類別（Logistic_serviceID） */
enum PaynowLogisticService: string {

	/** 7-11 店到店 */
	case Seven = '01';

	/** 7-11 大宗 */
	case SevenBulk = '02';

	/** 全家 店到店 */
	case Fami = '03';

	/** 全家 大宗 */
	case FamiBulk = '04';

	/** HiLife 店到店 */
	case Hilife = '05';

	/** 黑貓 宅配 */
	case Tcat = '06';

	/** 7-11 交貨便冷凍 C2C */
	case SevenFrozenC2c = '21';

	/** 7-11 大宗冷凍 */
	case SevenFrozen = '22';

	/** 全家 店到店冷凍 C2C */
	case FamiFrozenC2c = '23';

	/** 全家 大宗冷凍 */
	case FamiFrozen = '24';

	/**
	 * 以 case 名稱解析 enum（大小寫不敏感）
	 *
	 * 結帳設定 enabled_methods 儲存的是 case 名稱（如 'SEVEN' / 'Seven' / 'Fami' / 'TCAT'），
	 * 而非 backed value（01-06）。此 helper 將 case 名稱（不分大小寫）映射回 enum 物件。
	 *
	 * @param string $name case 名稱（如 'SEVEN' / 'Seven' / 'Fami' / 'TCAT'）
	 * @return self|null 對應 enum，無對應回 null
	 */
	public static function try_from_name( string $name ): ?self {
		$normalized = \strtolower( \trim( $name ) );
		foreach ( self::cases() as $case ) {
			if (\strtolower( $case->name ) === $normalized) {
				return $case;
			}
		}
		return null;
	}

	/**
	 * 是否為超商取貨（需選店）
	 *
	 * 黑貓宅配（Tcat）為宅配，其餘皆為超商取貨。
	 *
	 * @return bool 超商取貨回 true，宅配回 false
	 */
	public function is_cvs(): bool {
		return self::Tcat !== $this;
	}

	/**
	 * 取得人類可讀標籤（繁體中文）
	 *
	 * @return string 服務名稱
	 */
	public function label(): string {
		return match ( $this ) {
			self::Seven          => \__( '7-11 店到店', 'power_checkout' ),
			self::SevenBulk      => \__( '7-11 大宗', 'power_checkout' ),
			self::Fami           => \__( '全家 店到店', 'power_checkout' ),
			self::FamiBulk       => \__( '全家 大宗', 'power_checkout' ),
			self::Hilife         => \__( 'HiLife 店到店', 'power_checkout' ),
			self::Tcat           => \__( '黑貓宅配', 'power_checkout' ),
			self::SevenFrozenC2c => \__( '7-11 交貨便冷凍 C2C', 'power_checkout' ),
			self::SevenFrozen    => \__( '7-11 大宗冷凍', 'power_checkout' ),
			self::FamiFrozenC2c  => \__( '全家 店到店冷凍 C2C', 'power_checkout' ),
			self::FamiFrozen     => \__( '全家 大宗冷凍', 'power_checkout' ),
		};
	}
}
