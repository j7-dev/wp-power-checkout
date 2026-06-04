<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 綠界 ItemName 與語系推導
 *
 * Trade-off：ItemName（商品名稱組裝）與 get_language（語系推導）合併在同一個 helper。
 * 兩者皆為「由訂單 / WordPress 環境推導 AIO 請求參數」的純函式，呼叫端（階段二的
 * RequestParams 重建）會同時用到，合併可減少一個檔案且語意內聚；若日後語系邏輯擴張
 * （例如支援更多綠界語系或可設定），再拆出獨立的 Language helper。
 */
final class ItemName {

	/** 綠界 ItemName 長度上限（超過綠界會自動截斷，截斷處的多位元組字元可能亂碼導致 CMV 不一致） */
	private const MAX_LENGTH = 400;

	/**
	 * 取得商品名稱，多筆以 # 連接，累計長度不超過 400 字
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public static function get( \WC_Order $order ): string {
		$item_names = [];
		foreach ( $order->get_items() as $item ) {
			// 移除商品名稱中的特殊字元（含 # 符號）
			$item_name    = ( new StrHelper( $item->get_name() ) )->filter()->value;
			$item_names[] = $item_name;

			// 檢查累計字串長度是否超過上限
			$item_names_helper = new StrHelper( implode( '#', $item_names ), 'item_names', self::MAX_LENGTH );
			if ( $item_names_helper->get_strlen() >= self::MAX_LENGTH ) {
				// 超過上限則去除剛加入的商品名稱後停止
				$item_names = array_slice( $item_names, 0, -1 );
				break;
			}
		}

		return implode( '#', $item_names );
	}

	/**
	 * 依 WordPress locale 推導綠界語系
	 *
	 * @return 'ENG'|'KOR'|'JPN'|'CHI'|null 語系（zh_TW / zh_HK 回傳 null 代表使用綠界預設繁中）
	 */
	public static function get_language(): string|null {
		$locale = \get_locale();
		return match ( $locale ) {
			'zh_HK', 'zh_TW' => null,
			'ko_KR' => 'KOR',
			'ja' => 'JPN',
			'zh_CN' => 'CHI',
			default => 'ENG',
		};
	}
}
