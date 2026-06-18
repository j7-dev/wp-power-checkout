<?php
/**
 * 綠界 AES-128-CBC 加解密（領域中立單一化 helper）
 *
 * 本 helper 為「ECPay AES-128-CBC 單一化」重構的唯一真實來源，從 Payment/Ecpg/AesCrypto
 * 原樣提升（演算法一個位元組都不改），供以下三處（含 Logistics）共用：
 *   - Invoice/Ecpay（電子發票 B2C/B2B、電子收據 Receipt）
 *   - Payment/Ecpg（站內付 2.0）
 *   - Logistics/Ecpay（全方位物流 v2）
 *
 * Invoice/Ecpay/AesCrypto 與 Payment/Ecpg/AesCrypto 改為薄包裝（委派至本 helper），
 * 保留原類名與 namespace 以免波及既有 14 處 `use ...AesCrypto` 呼叫點（含 Receipt 域與測試）。
 *
 * ⚠️ 絕對不可把 ezPay 的 AES-256-CBC（hex + 自補 PKCS#7 blocksize=32）併入本 helper——
 *    那是不同演算法（256-bit key、hex 輸出、blocksize=32 padding），混用會被平台回 KEY10002。
 *    見 {@see \J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\AesCrypto}。
 *
 * 綠界加密流程（非常規順序，必須嚴格遵守，以 ECPay-API-Skill guides/14 為準）：
 *   明文陣列 → wp_json_encode(UNESCAPED_UNICODE|UNESCAPED_SLASHES) → urlencode（空格→+，~→%7E）
 *   → AES-128-CBC（PKCS#7）→ base64_encode（標準 alphabet +/=，非 URL-safe）
 * 解密流程：
 *   base64_decode → AES-128-CBC 解密 → urldecode → json_decode
 *
 * Key / IV 各取 HashKey / HashIV 的前 16 bytes。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md §AES vs CMV URL Encode 對比表
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Helpers;

/** 綠界 AES-128-CBC 加解密 Helper（領域中立，三處共用） */
final class EcpayAesCrypto {

	/** @var string AES 演算法 */
	private const CIPHER = 'aes-128-cbc';

	/** Constructor */
	public function __construct(
		/** @var string HashKey */
		private readonly string $hash_key,
		/** @var string HashIV */
		private readonly string $hash_iv,
	) {}

	/**
	 * 加密
	 *
	 * @param array<string, mixed> $data 要加密的明文陣列
	 *
	 * @return string Base64 字串（標準 alphabet +/=）
	 * @throws \RuntimeException JSON 編碼或加密失敗
	 */
	public function encrypt( array $data ): string {
		$json = \wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if (false === $json) {
			throw new \RuntimeException( 'ECPay AES encrypt: json_encode 失敗' );
		}

		// 綠界：加密前先 URL encode（urlencode 空格→+，~→%7E，與綠界 aesUrlEncode 一致）
		$url_encoded = \urlencode( $json );

		$encrypted = \openssl_encrypt(
			$url_encoded,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA, // 輸出原始二進位 + PKCS#7（CBC 預設）
			$this->get_iv()
		);

		if (false === $encrypted) {
			throw new \RuntimeException( 'ECPay AES encrypt: openssl_encrypt 失敗' );
		}

		// 標準 Base64 alphabet（+ / =），綠界不接受 URL-safe（- _）
		return \base64_encode( $encrypted );
	}

	/**
	 * 解密
	 *
	 * @param string $cipher_text Base64 密文字串
	 *
	 * @return array<string, mixed> 解密後的明文陣列
	 * @throws \RuntimeException 解密或 JSON 解碼失敗
	 */
	public function decrypt( string $cipher_text ): array {
		$raw = \base64_decode( $cipher_text, true );
		if (false === $raw) {
			throw new \RuntimeException( 'ECPay AES decrypt: base64_decode 失敗' );
		}

		$decrypted = \openssl_decrypt(
			$raw,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA,
			$this->get_iv()
		);

		if (false === $decrypted) {
			throw new \RuntimeException( 'ECPay AES decrypt: openssl_decrypt 失敗' );
		}

		// 綠界：解密後才 URL decode
		$json = \urldecode( $decrypted );

		try {
			$result = \json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch (\JsonException $e) {
			throw new \RuntimeException( 'ECPay AES decrypt: json_decode 失敗 ' . $e->getMessage() );
		}

		if (!\is_array( $result )) {
			return [];
		}

		/** @var array<string, mixed> $result */
		return $result;
	}

	/** @return string Key（取前 16 bytes） */
	private function get_key(): string {
		return \substr( $this->hash_key, 0, 16 );
	}

	/** @return string IV（取前 16 bytes） */
	private function get_iv(): string {
		return \substr( $this->hash_iv, 0, 16 );
	}
}
