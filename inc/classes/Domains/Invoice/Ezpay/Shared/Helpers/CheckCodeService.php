<?php
/**
 * 藍新 ezPay 電子發票回應驗證碼 CheckCode（SHA256 大寫）
 *
 * CheckCode 是「回應端」驗證機制，讓商店確認回應確實來自 ezPay，與 request 端的 AES 加密無關。
 *
 * 計算規格（EZP_INVI_1.2.1，以 ezpay-invoice skill concepts.md §CheckCode 回應驗證 為準）：
 *  1. 取回應五欄位：InvoiceTransNo / MerchantID / MerchantOrderNo / RandomNum / TotalAmt。
 *  2. 依英文字母 A~Z 排序（ksort）後以 http_build_query 串聯成 query string。
 *  3. 前綴 "HashIV={IV}&"、後綴 "&HashKey={Key}"。
 *  4. 整串 SHA256 → strtoupper 即 CheckCode。
 *
 * ⚠️ 與藍新 MPG 的 CheckCode（固定欄位順序 HashIV/Amt/MerchantID/MerchantOrderNo/TradeNo/HashKey）
 *    不同：ezPay 是「對傳入實際存在欄位 ksort」，缺欄位不補空值，且前後綴鍵名為 HashIV= / HashKey=。
 *
 * compute() 僅對「傳入實際存在的欄位」ksort 串聯（缺欄位不補空值，由呼叫端負責備齊五欄位）。
 * verify() 以 hash_equals 做 timing-safe 比對，受驗碼先轉大寫，避免大小寫差異誤判。
 *
 * HashKey / HashIV 由建構子參數注入（不依賴任何 Settings 單例），方便測試與多帳號情境。
 *
 * @see .claude/skills/ezpay-invoice/references/concepts.md §CheckCode 回應驗證
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers;

/** 藍新 ezPay CheckCode 計算與驗證 Helper（SHA256 大寫 + timing-safe verify） */
final class CheckCodeService {

	/**
	 * Constructor
	 *
	 * @param string $hashKey ezPay 商店 HashKey.
	 * @param string $hashIv  ezPay 商店 HashIV.
	 */
	public function __construct(
		private readonly string $hashKey,
		private readonly string $hashIv,
	) {}

	/**
	 * 計算 CheckCode（SHA256 大寫）
	 *
	 * 對傳入「實際存在」的欄位 ksort（A~Z）後 http_build_query 串聯，前後加 HashIV / HashKey，
	 * 再做 SHA256 並轉大寫。缺欄位不補空值。
	 *
	 * @param array<string, mixed> $fields 回應欄位（通常為 InvoiceTransNo/MerchantID/MerchantOrderNo/RandomNum/TotalAmt）.
	 *
	 * @return string CheckCode（64 字大寫 hex）.
	 */
	public function compute( array $fields ): string {
		\ksort( $fields );
		$query = \http_build_query( $fields );
		$raw   = 'HashIV=' . $this->hashIv . '&' . $query . '&HashKey=' . $this->hashKey;

		return \strtoupper( \hash( 'sha256', $raw ) );
	}

	/**
	 * 驗證回應的 CheckCode（timing-safe）
	 *
	 * 以 hash_equals 比對自算值與受驗值；受驗值先轉大寫以對齊本服務大寫輸出。
	 *
	 * @param array<string, mixed> $fields    回應欄位.
	 * @param string               $checkCode ezPay 回傳的 CheckCode.
	 *
	 * @return bool 相符回 true，否則 false.
	 */
	public function verify( array $fields, string $checkCode ): bool {
		return \hash_equals( $this->compute( $fields ), \strtoupper( $checkCode ) );
	}
}
