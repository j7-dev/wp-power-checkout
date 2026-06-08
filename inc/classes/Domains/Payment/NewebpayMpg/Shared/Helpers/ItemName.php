<?php
/**
 * 藍新 NewebPay MPG ItemDesc 與語系推導
 *
 * 與綠界 ItemName 對應，但藍新 ItemDesc 規格不同：
 *  - 長度上限 50（綠界 ItemName 為 400）。
 *  - 多筆商品以「逗號」連接（綠界以 # 連接）。
 *
 * ItemDesc 的值之後會由 RequestParams 在組 TradeInfo 時 URL-encode，故含特殊字元不會破壞格式；
 * 但仍先 filter 去除特殊字元（保留中英數與空白），避免藍新付款頁顯示亂碼，並以 50 字截斷。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §ItemDesc（max 50, comma-separated）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/** 藍新 MPG ItemDesc 與語系推導 */
final class ItemName {

	/** @var int 藍新 ItemDesc 長度上限（超過藍新會截斷，截斷處多位元組字元可能亂碼） */
	private const MAX_LENGTH = 50;

	/** @var string 多筆商品分隔字元（藍新建議逗號） */
	private const SEPARATOR = ',';

	/**
	 * 取得商品描述 ItemDesc，多筆以逗號連接，累計長度不超過 50 字
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public static function get( \WC_Order $order ): string {
		$item_names = [];
		foreach ( $order->get_items() as $item ) {
			// 移除特殊字元（含逗號），避免破壞分隔與顯示亂碼
			$item_name = ( new StrHelper( $item->get_name() ) )->filter()->value;
			$item_name = \str_replace( self::SEPARATOR, ' ', $item_name );
			if ( '' === \trim( $item_name ) ) {
				continue;
			}

			$candidate        = [ ...$item_names, $item_name ];
			$candidate_string = \implode( self::SEPARATOR, $candidate );
			if ( \mb_strlen( $candidate_string, 'UTF-8' ) > self::MAX_LENGTH ) {
				// 超過上限：若目前已有商品則停止；否則截斷此單一商品名後納入並停止
				if ( [] === $item_names ) {
					$item_names[] = \mb_substr( $item_name, 0, self::MAX_LENGTH, 'UTF-8' );
				}
				break;
			}

			$item_names = $candidate;
		}

		$result = \implode( self::SEPARATOR, $item_names );

		// 保險：最終再截斷一次（避免任何邊界情況超過 50）
		if ( \mb_strlen( $result, 'UTF-8' ) > self::MAX_LENGTH ) {
			$result = \mb_substr( $result, 0, self::MAX_LENGTH, 'UTF-8' );
		}

		// 完全無有效商品名時給預設值（藍新 ItemDesc 為必填）
		return '' !== $result ? $result : (string) ( '訂單 #' . $order->get_id() );
	}

	/**
	 * 依 WordPress locale 推導藍新語系
	 *
	 * @return 'zh-tw'|'en'|'jp' 藍新 LangType（預設 zh-tw）
	 */
	public static function get_language(): string {
		$locale = \get_locale();
		return match ( $locale ) {
			'ja'    => 'jp',
			'zh_TW', 'zh_HK', 'zh_CN' => 'zh-tw',
			default => 'en',
		};
	}
}
