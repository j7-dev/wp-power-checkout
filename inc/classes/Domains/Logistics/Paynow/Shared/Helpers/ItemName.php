<?php
/**
 * PayNow 物流商品名稱（Description）組裝 Helper（立吉富體系 1，woomp 對齊）
 *
 * PayNow 物流 Add_Order 的 Description（商品名稱）上限 25 字（woomp 實證）：
 *   - 多項商品以 `{name}X{quantity}` 串接、半形逗號分隔。
 *   - 僅保留中文 / 英文 / 數字 / 空格（過濾特殊字元，避免破壞 JSON 與顯示）。
 *   - 最後 mb_substr 截斷至 25 字。
 *
 * 對齊 woomp `class-paynow-shipping-request.php::get_items_infos()`（L789-803）：
 *   item_name .= name . 'X' . qty（非最後一項補逗號）
 *   → preg_replace 僅留 [A-Za-z0-9 中文]
 *   → mb_substr(0, 25)
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟（ItemName）
 * @see ../woomp/.../shippings/api/class-paynow-shipping-request.php L789-803
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/** PayNow 物流商品名稱組裝 Helper */
final class ItemName {

	/** @var int PayNow 物流 Description 長度上限 */
	private const MAX_LENGTH = 25;

	/** @var string 多項商品分隔字元 */
	private const SEPARATOR = ',';

	/**
	 * 由訂單組裝物流商品名稱（多項以逗號連接，過濾特殊字元後截斷 25 字）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 物流商品名稱（≤25 字，無有效商品名時回預設值）
	 */
	public static function get( \WC_Order $order ): string {
		$segments = [];
		foreach ( $order->get_items() as $item ) {
			$name = \trim( (string) $item->get_name() );
			if ( '' === $name ) {
				continue;
			}
			// get_quantity() 僅存在於 WC_Order_Item_Product；非商品項（運費 / 折扣等）數量視為 1
			$quantity   = $item instanceof \WC_Order_Item_Product ? (int) $item->get_quantity() : 1;
			$segments[] = "{$name}X{$quantity}";
		}

		$joined   = \implode( self::SEPARATOR, $segments );
		$filtered = ( new StrHelper( $joined ) )->filter()->value;
		$result   = self::truncate( $filtered );

		return '' !== \trim( $result ) ? $result : (string) ( '訂單' . $order->get_id() );
	}

	/**
	 * 純長度截斷（≤25 字），不過濾字元
	 *
	 * @param string $name 原始名稱
	 * @return string 截斷後名稱
	 */
	public static function truncate( string $name ): string {
		if ( \mb_strlen( $name, 'UTF-8' ) <= self::MAX_LENGTH ) {
			return $name;
		}
		return \mb_substr( $name, 0, self::MAX_LENGTH, 'UTF-8' );
	}
}
