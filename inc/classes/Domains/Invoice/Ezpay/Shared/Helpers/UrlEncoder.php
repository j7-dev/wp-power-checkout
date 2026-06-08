<?php
/**
 * 藍新 ezPay 電子發票 PostData_ 明文組裝（rawurlencode query string）
 *
 * 將業務參數陣列組成 key=value&key=value... 的 query string（明文），供 AesCrypto 加密成 PostData_。
 *
 * 組裝規格（EZP_INVI_1.2.1，以 ezpay-invoice skill concepts.md §CarrierNum 為準）：
 *  - 逐項以 $key . '=' . rawurlencode((string)$value) 組裝，再以 '&' implode。
 *  - 採 rawurlencode（RFC 3986）：空白→%20（非 urlencode 的 +）；值內 `&` / `=` 會被編碼，
 *    避免破壞 key=value 格式（中文商品名、含特殊字元的欄位皆安全）。
 *  - CarrierNum（載具號碼）特別規定值前後不得含空白，故先 trim() 再編碼。
 *  - 空陣列回傳空字串。
 *
 * ⚠️ 與藍新 MPG 的 UrlEncoder（靜態方法、只對單一 value 編碼）介面不同：ezPay 版接整個參數陣列、
 *    回傳組好的 query string，因 ezPay 規格將「組 query + 補 padding + AES」拆為前後兩步。
 *    可參照藍新 UrlEncoder 的 rawurlencode 取捨與註解風格。
 *
 * @see .claude/skills/ezpay-invoice/references/concepts.md §載具規則（CarrierNum）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers;

/** 藍新 ezPay PostData_ 明文 query string 組裝 Helper（rawurlencode + CarrierNum trim） */
final class UrlEncoder {

	/**
	 * 將參數陣列組成 rawurlencode 後的 query string
	 *
	 * @param array<string, mixed> $params 業務參數（key=>value）.
	 *
	 * @return string key=value&key=value... 字串；空陣列回 ''.
	 */
	public function encode( array $params ): string {
		if ( [] === $params ) {
			return '';
		}

		$pairs = [];
		foreach ( $params as $key => $value ) {
			$value = (string) $value;

			// 載具號碼前後不得含空白.
			if ( 'CarrierNum' === $key ) {
				$value = \trim( $value );
			}

			$pairs[] = $key . '=' . \rawurlencode( $value );
		}

		return \implode( '&', $pairs );
	}
}
