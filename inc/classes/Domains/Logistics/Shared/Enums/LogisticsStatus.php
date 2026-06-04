<?php
/**
 * 物流貨態 LogisticsStatus
 *
 * _pc_logistics_status 儲存綠界 LogisticsStatus 原始碼字串，不直接映射 WC 訂單狀態，
 * 與金流 StatusManager 解耦（計畫 R8 / T2）。
 *
 * 貨態碼眾多且依子類型而異，硬編完整表易過時（計畫 T1）。本 enum 僅承載代表性碼值，
 * 系統真正需要的單一語意判定「是否取件完成」集中於 {@see self::is_pickup_completed()}，
 * 以官方碼集合判定，可獨立於 enum case 擴充而不破壞既有資料。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/06-logistics-domestic.md §狀態碼（2067 / 3022）
 * @see .claude/skills/ECPay-API-Skill/guides/21-webhook-events-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 物流貨態（代表性碼；原始碼一律以字串儲存於 order meta） */
enum LogisticsStatus: string {
	/** 物流單已建立 / 已出貨（代表性） */
	case SHIPPED = '300';
	/** 超商取件完成（代表性；7-ELEVEN，COD 訂單觸發取貨付款完成標記） */
	case PICKED_UP = '2067';
	/** 本系統取消 C2C 物流單後的標記值（非綠界碼） */
	case CANCELLED = 'cancelled';

	/**
	 * 取件完成貨態碼集合（消費者已取貨）
	 *
	 * 7-ELEVEN = 2067；全家 / 萊爾富 / OK mart = 3022。
	 * 集中於此常數，新增碼值不需改動其他模組。
	 *
	 * @var array<int, string>
	 */
	private const PICKUP_COMPLETED_CODES = [
		'2067', // 7-ELEVEN 消費者已取貨
		'3022', // 全家 / 萊爾富 / OK mart 消費者成功取件
	];

	/**
	 * 判定給定貨態碼是否為「超商取件完成」
	 *
	 * COD 訂單於取件完成貨態時標記 _pc_logistics_collection_paid=yes（計畫 T2）。
	 *
	 * @param string $status_code 綠界回傳的 LogisticsStatus 原始碼字串
	 * @return bool
	 */
	public static function is_pickup_completed( string $status_code ): bool {
		return \in_array( $status_code, self::PICKUP_COMPLETED_CODES, true );
	}
}
