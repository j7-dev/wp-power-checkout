<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

use J7\PowerCheckout\Shared\Utils\StrHelper;

/**
 * 綠界 MerchantTradeNo 編解碼
 *
 * MerchantTradeNo 格式：{order_id}TS{反轉後的 timestamp}，並截斷至 20 碼以內。
 * 反轉 timestamp 是為了讓相鄰訂單的編號前綴差異化（避免綠界端視為重複）。
 *
 * @see https://www.ecpay.com.tw/CascadeFAQ/CascadeFAQ_Qa?nID=1454
 */
final class TradeNo {

	/**
	 * 由訂單 ID 生成唯一的 MerchantTradeNo（≤ 20 碼）
	 *
	 * @param int $order_id 訂單 ID
	 * @return string MerchantTradeNo
	 */
	public static function encode( int $order_id ): string {
		$trade_no = $order_id . 'TS' . strrev( (string) time() );
		return substr( $trade_no, 0, 20 );
	}

	/**
	 * 由 MerchantTradeNo 反解出訂單 ID
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return string 訂單 ID
	 */
	public static function decode( string $trade_no ): string {
		$order_prefix = '';
		$offset       = ( new StrHelper( $order_prefix ) )->get_strlen();
		return substr( $trade_no, $offset, strrpos( $trade_no, 'TS' ) ?: 0 );
	}
}
