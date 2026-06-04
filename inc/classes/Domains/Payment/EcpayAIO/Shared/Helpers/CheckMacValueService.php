<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/**
 * 綠界 CheckMacValue 檢查碼生成
 *
 * 演算法（與綠界後端一致）：
 * 1. 移除 CheckMacValue 欄位（避免用舊值生成）
 * 2. 依 key 字母排序（ksort，SORT_STRING | SORT_FLAG_CASE）
 * 3. 開頭加上 HashKey、結尾加上 HashIV，並用 & 連接所有 key=value
 * 4. 套用綠界 .NET urlencode（{@see UrlEncoder::encode}）
 * 5. 轉小寫
 * 6. hash（sha256 | md5）
 * 7. 轉大寫
 *
 * HashKey / HashIV 由參數注入（不依賴任何 Settings 單例），方便測試與多帳號情境。
 *
 * @see https://developers.ecpay.com.tw/?p=2902
 */
final class CheckMacValueService {

	/**
	 * 生成 CheckMacValue
	 *
	 * @param array<string, string|int> $args      參數（不含 CheckMacValue）
	 * @param string                    $hash_key  綠界 HashKey
	 * @param string                    $hash_iv   綠界 HashIV
	 * @param string                    $hash_algo 'sha256' | 'md5' 雜湊演算法
	 * @return string CheckMacValue（大寫 hex）
	 * @throws \InvalidArgumentException 如果雜湊演算法不符合規定
	 */
	public static function get_check_value(
		array $args,
		string $hash_key,
		string $hash_iv,
		string $hash_algo = 'sha256'
	): string {

		if ( ! in_array( $hash_algo, [ 'sha256', 'md5' ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid hash algorithm' );
		}

		unset( $args['CheckMacValue'] ); // 確保不會用 CheckMacValue 生成
		ksort( $args, SORT_STRING | SORT_FLAG_CASE ); // 依照 key 字母排序

		$args_string   = [];
		$args_string[] = "HashKey={$hash_key}"; // 開頭加上 HashKey
		foreach ( $args as $key => $value ) {
			$args_string[] = "{$key}={$value}";
		}
		$args_string[] = "HashIV={$hash_iv}"; // 結尾加上 HashIV

		$joined      = implode( '&', $args_string ); // 用 & 連接
		$encoded     = UrlEncoder::encode( $joined ); // 綠界要求 urlencode 的規則
		$lowered     = strtolower( $encoded ); // 轉小寫
		$check_value = hash( $hash_algo, $lowered ); // 生成 CheckMacValue

		return strtoupper( $check_value ); // 轉大寫
	}
}
