<?php
/**
 * 綠界綁卡代扣（CreatePaymentWithCardID）AES-128-CBC 加解密
 *
 * 綁卡代扣（ecpg domain）與站內付 2.0 / 電子發票共用 AES-128-CBC 規則。本檔於
 * Payment/EcpayAIO 子域自建一份（與 Payment/Ecpg、Invoice/Ecpay 的 AesCrypto 規則相同），
 * 維持 EcpayAIO 子域自包含、不跨域耦合；日後可 refactor 提取為跨 domain 共用 Helper。
 *
 * 加密流程（嚴格順序，以 ECPay-API-Skill guides/14 為準）：
 *   明文陣列 → json_encode → urlencode（空格→+，~→%7E）→ AES-128-CBC（PKCS#7）→ base64_encode
 * 解密流程：base64_decode → AES-128-CBC 解密 → urldecode → json_decode
 *
 * Key / IV 各取 HashKey / HashIV 的前 16 bytes。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/03-payment-backend.md §16 綁卡代扣
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/** 綠界綁卡代扣 AES-128-CBC 加解密 Helper */
final class AesJsonCrypto {

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
	 * @return string Base64 字串（標準 alphabet）
	 * @throws \RuntimeException JSON 編碼或加密失敗
	 */
	public function encrypt( array $data ): string {
		$json = \wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			throw new \RuntimeException( 'AIO 綁卡代扣 AES encrypt: json_encode 失敗' );
		}

		$url_encoded = \urlencode( $json );

		$encrypted = \openssl_encrypt(
			$url_encoded,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA,
			$this->get_iv()
		);

		if ( false === $encrypted ) {
			throw new \RuntimeException( 'AIO 綁卡代扣 AES encrypt: openssl_encrypt 失敗' );
		}

		return \base64_encode( $encrypted );
	}

	/**
	 * 解密
	 *
	 * @param string $cipher_text Base64 密文字串
	 * @return array<string, mixed> 解密後的明文陣列
	 * @throws \RuntimeException 解密或 JSON 解碼失敗
	 */
	public function decrypt( string $cipher_text ): array {
		$raw = \base64_decode( $cipher_text, true );
		if ( false === $raw ) {
			throw new \RuntimeException( 'AIO 綁卡代扣 AES decrypt: base64_decode 失敗' );
		}

		$decrypted = \openssl_decrypt(
			$raw,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA,
			$this->get_iv()
		);

		if ( false === $decrypted ) {
			throw new \RuntimeException( 'AIO 綁卡代扣 AES decrypt: openssl_decrypt 失敗' );
		}

		$json = \urldecode( $decrypted );

		try {
			$result = \json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new \RuntimeException( 'AIO 綁卡代扣 AES decrypt: json_decode 失敗 ' . $e->getMessage() );
		}

		if ( ! \is_array( $result ) ) {
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
