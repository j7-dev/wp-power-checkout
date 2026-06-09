<?php
/**
 * PAYUNi UPP V2 ProdDesc（商品說明）組裝
 *
 * 與藍新 ItemName / 綠界 ItemName 對應，但 PAYUNi ProdDesc 規格不同：
 *  - 長度上限 550（payuni-upp-v2 SKILL.md §EncryptInfo 內層通用請求參數）。
 *  - 多項商品以「半形分號」分隔（payuni-upp-v2：多項以半形 `;` 分隔）。
 *
 * ProdDesc 的值之後會由 RequestParams 在組 EncryptInfo（http_build_query）時 URL-encode，
 * 故含特殊字元不會破壞 querystring 格式；但仍先 filter 去除特殊字元（保留中英數與空白），
 * 避免 PAYUNi 付款頁顯示亂碼，並以 550 字截斷。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §EncryptInfo 內層通用請求參數（ProdDesc max 550, `;` 分隔）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/** PAYUNi UPP V2 ProdDesc 組裝 */
final class ItemName {

	/** @var int PAYUNi ProdDesc 長度上限（超過 PAYUNi 會截斷，截斷處多位元組字元可能亂碼） */
	private const MAX_LENGTH = 550;

	/** @var string 多項商品分隔字元（PAYUNi 規範為半形分號） */
	private const SEPARATOR = ';';

	/**
	 * 取得商品說明 ProdDesc，多項以半形分號連接，累計長度不超過 550 字
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public static function get( \WC_Order $order ): string {
		$item_names = [];
		foreach ( $order->get_items() as $item ) {
			// 移除特殊字元（含分號），避免破壞分隔與顯示亂碼
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

		// 保險：最終再截斷一次（避免任何邊界情況超過 550）
		if ( \mb_strlen( $result, 'UTF-8' ) > self::MAX_LENGTH ) {
			$result = \mb_substr( $result, 0, self::MAX_LENGTH, 'UTF-8' );
		}

		// 完全無有效商品名時給預設值（PAYUNi ProdDesc 為必填）
		return '' !== $result ? $result : (string) ( '訂單 #' . $order->get_id() );
	}
}
