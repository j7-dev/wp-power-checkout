<?php
/**
 * PayNow 物流單狀態 Enum（立吉富體系 1）
 *
 * 物流單成立狀態（非貨態碼）：
 *   0 = 成立中（Active，有效物流單）
 *   1 = 無效（Invalid，已取消 / 作廢）
 *
 * 貨態碼（LogisticCode，例如 5000 已到店）屬另一維度，於 callback 解析時以字串保存，
 * 不在此 enum 列舉（貨態碼數量多且會隨 PayNow 擴充）。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 3
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums;

/** PayNow 物流單狀態（成立中 / 無效） */
enum PaynowLogisticsStatus: string {

	/** 成立中（有效物流單） */
	case Active = '0';

	/** 無效（已取消 / 作廢） */
	case Invalid = '1';

	/**
	 * 是否為成立中（有效）物流單
	 *
	 * @return bool 成立中回 true
	 */
	public function is_active(): bool {
		return self::Active === $this;
	}

	/**
	 * 取得人類可讀標籤（繁體中文）
	 *
	 * @return string 狀態名稱
	 */
	public function label(): string {
		return match ( $this ) {
			self::Active  => \__( '成立中', 'power_checkout' ),
			self::Invalid => \__( '無效', 'power_checkout' ),
		};
	}
}
