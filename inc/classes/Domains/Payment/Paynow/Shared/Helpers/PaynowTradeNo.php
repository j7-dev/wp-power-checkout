<?php
/**
 * PayNow 交易單號（冪等鍵）編解碼
 *
 * PayNow REST API 的 PaymentIntent 自帶 `paymentNo`（可自訂，不指定則系統產生）。
 * 本 helper 產生「冪等」的 paymentNo：同一 order_id 多次呼叫產生相同單號
 * （純由 order_id 衍生，不含 timestamp），供付款重試時用相同單號去重、
 * 並可由單號反查訂單。
 *
 * 格式：PCN{order_id}。
 *  - PCN 前綴（PayNow Checkout No）：與 UNi Embed 的 PCE、UPP 的 PCU 明確區隔，三者不撞。
 *  - order_id 十進位。
 *
 * ⚠️ Webhook 反查訂單的「主鍵」是 PaymentIntentId（pp_xxx），不是本單號；
 *    本單號僅供 paymentNo / OrderNo 對照與冪等用途（見 PaynowMetaKeys）。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 6
 * @see \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo 對照（前綴 PCE）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers;

/** PayNow 交易單號編解碼（冪等） */
final class PaynowTradeNo {

	/** @var string 前綴（PayNow Checkout No，與 UNi Embed 的 PCE、UPP 的 PCU 區隔） */
	private const PREFIX = 'PCN';

	/**
	 * 由訂單 ID 生成冪等的交易單號
	 *
	 * 純函數：相同 order_id 必產生相同單號（冪等），不同 order_id 必不同。
	 *
	 * @param int $order_id 訂單 ID
	 * @return string 交易單號（PCN{order_id}）
	 */
	public static function generate( int $order_id ): string {
		return self::PREFIX . $order_id;
	}

	/**
	 * 由交易單號反解出訂單 ID
	 *
	 * 容錯：前綴不符 / 數字段缺失 → 回 null（不拋例外）。
	 *
	 * @param string $trade_no 交易單號
	 * @return int|null 訂單 ID（無法解析時回 null）
	 */
	public static function parse( string $trade_no ): int|null {
		if ( ! \str_starts_with( $trade_no, self::PREFIX ) ) {
			return null;
		}

		$without_prefix = \substr( $trade_no, \strlen( self::PREFIX ) );

		// 前綴後必須是純數字（order_id 十進位），否則視為不合法
		if ( '' === $without_prefix || ! \ctype_digit( $without_prefix ) ) {
			return null;
		}

		return (int) $without_prefix;
	}
}
