<?php
/**
 * PayNow description（付款描述）組裝
 *
 * PayNow PaymentIntent 的 `description` 上限 255 字（payment-rest-api.md §4.1）。
 * 比照 PayuniUniEmbed\Shared\Helpers\ItemName 風格，但長度上限改為 255、
 * 多項商品以半形分號 `;` 分隔。
 *
 *  - get()：由訂單組裝商品名描述，累計長度不超過 255，無有效商品名時給預設值。
 *  - truncate()：純長度截斷（≤255），不過濾字元（短字串原樣返回）。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 8
 * @see .claude/skills/paynow/references/payment-rest-api.md §4.1 description <= 255
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/** PayNow description 組裝 */
final class ItemName {

	/** @var int PayNow description 長度上限 */
	private const MAX_LENGTH = 255;

	/** @var string 多項商品分隔字元 */
	private const SEPARATOR = ';';

	/**
	 * 取得付款描述 description，多項以半形分號連接，累計長度不超過 255 字
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

		$result = self::truncate( \implode( self::SEPARATOR, $item_names ) );

		// 完全無有效商品名時給預設值（description 為付款必填顯示用）
		return '' !== $result ? $result : (string) ( '訂單 #' . $order->get_id() );
	}

	/**
	 * 純長度截斷（≤255），不過濾字元
	 *
	 * 255 字元以內原樣返回；超過則以 mb_substr 截至 255。
	 *
	 * @param string $description 原始描述
	 * @return string 截斷後描述
	 */
	public static function truncate( string $description ): string {
		if ( \mb_strlen( $description, 'UTF-8' ) <= self::MAX_LENGTH ) {
			return $description;
		}
		return \mb_substr( $description, 0, self::MAX_LENGTH, 'UTF-8' );
	}
}
