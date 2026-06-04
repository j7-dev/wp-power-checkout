<?php
/**
 * 綠界 AES-128-CBC 加解密
 *
 * 綠界電子發票（B2C/B2B）與 ECPG 站內付 2.0 共用此加密規則。
 * 本階段於 Invoice/Ecpay 下自建一份；階段三 ECPG 會另建一份於 Payment/Ecpg 下，
 * 暫可重複，日後 refactor 提取為共用 Helper。
 *
 * 加密流程（非常規順序，必須嚴格遵守）：
 *   明文陣列 → json_encode → urlencode（空格→+）→ AES-128-CBC（PKCS#7）→ base64_encode（標準 alphabet）
 * 解密流程：
 *   base64_decode → AES-128-CBC 解密 → urldecode → json_decode
 *
 * Key / IV 各取 HashKey / HashIV 的前 16 bytes。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers;

/** 綠界 AES-128-CBC 加解密 Helper */
final class AesCrypto {

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
	 * @return string Base64 字串
	 * @throws \RuntimeException JSON 編碼或加密失敗
	 */
	public function encrypt( array $data ): string {
		$json = \wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if (false === $json) {
			throw new \RuntimeException( 'AES encrypt: json_encode 失敗' );
		}

		// 綠界：加密前先 URL encode（urlencode 空格→+，與綠界 SDK 一致）
		$url_encoded = \urlencode( $json );

		$encrypted = \openssl_encrypt(
			$url_encoded,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA, // 輸出原始二進位 + PKCS#7（CBC 預設）
			$this->get_iv()
		);

		if (false === $encrypted) {
			throw new \RuntimeException( 'AES encrypt: openssl_encrypt 失敗' );
		}

		// 標準 Base64 alphabet（+ / =），綠界不接受 URL-safe
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
			throw new \RuntimeException( 'AES decrypt: base64_decode 失敗' );
		}

		$decrypted = \openssl_decrypt(
			$raw,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA,
			$this->get_iv()
		);

		if (false === $decrypted) {
			throw new \RuntimeException( 'AES decrypt: openssl_decrypt 失敗' );
		}

		// 綠界：解密後才 URL decode
		$json = \urldecode( $decrypted );

		try {
			$result = \json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch (\JsonException $e) {
			throw new \RuntimeException( 'AES decrypt: json_decode 失敗 ' . $e->getMessage() );
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
