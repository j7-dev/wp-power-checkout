<?php
/**
 * 藍新 NewebPay MPG TradeInfo value URL-encode
 *
 * ⚠️ 與綠界 EcpayAIO\Shared\Helpers\UrlEncoder 不同：
 *    綠界做 .NET HttpUtility.UrlEncode 的字元還原（給 CheckMacValue 用）；
 *    藍新 MPG 是「標準 RFC urlencode」（對齊 skill 範例的 encodeURIComponent），不做 .NET 還原。
 *
 * 用途：組 TradeInfo 明文（key=value&...）時，每個 value 先 URL-encode，避免值內含 `&` / `=`
 *      破壞 key=value 格式（例如中文商品名、含特殊字元的 ItemDesc）。解密後由呼叫端 urldecode 還原。
 *
 * 採 rawurlencode（RFC 3986）：空格→%20、`!*'()` 亦編碼，最接近 JS encodeURIComponent 行為，
 * 對藍新 TradeInfo 而言安全（藍新以標準 urldecode 解析，rawurlencode 為其子集，可正確還原）。
 *
 * @see .claude/skills/newebpay-mpg/references/examples.md §Create Payment Form（encodeURIComponent）
 * @see .claude/skills/newebpay-mpg/SKILL.md Pitfall 2（URL-encode Chinese values before AES-encrypting）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

/** 藍新 MPG 標準 RFC urlencode（非 .NET CMV 規則） */
final class UrlEncoder {

	/**
	 * 標準 RFC 3986 urlencode（對齊 JS encodeURIComponent）
	 *
	 * @param string $value 要編碼的 value
	 *
	 * @return string 編碼後字串
	 */
	public static function encode( string $value ): string {
		return \rawurlencode( $value );
	}

	/**
	 * 還原（標準 urldecode）
	 *
	 * @param string $value 已編碼的 value
	 *
	 * @return string 還原後字串
	 */
	public static function decode( string $value ): string {
		return \rawurldecode( $value );
	}
}
