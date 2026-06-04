<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/**
 * 綠界 .NET 風格 urlencode
 *
 * 綠界 CheckMacValue 計算時要求的 urlencode 規則：
 * 先用 PHP urlencode，再將 11 個特殊字元的編碼還原為 .NET HttpUtility.UrlEncode 的輸出，
 * 使 PHP 與綠界後端（.NET）的編碼結果一致。
 *
 * @see https://developers.ecpay.com.tw/?p=2904
 */
final class UrlEncoder {

	/**
	 * 綠界要求的 urlencode 規則
	 *
	 * @param string $str 要編碼的字串
	 * @return string 編碼後的字串
	 */
	public static function encode( string $str ): string {
		return str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $str )
		);
	}
}
