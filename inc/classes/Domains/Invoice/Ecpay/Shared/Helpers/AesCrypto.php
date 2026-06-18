<?php
/**
 * 綠界 AES-128-CBC 加解密（薄包裝）
 *
 * 綠界電子發票（B2C/B2B）、電子收據（Receipt）與 ECPG 站內付 2.0 共用此加密規則。
 * 本類別現為**薄包裝**，演算法已單一化提升至領域中立的
 * {@see \J7\PowerCheckout\Shared\Helpers\EcpayAesCrypto}，本類別委派至該 helper。
 * 保留原類名 + namespace + 對外簽名（encrypt(array): string / decrypt(string): array），
 * 以免波及既有 `use ...Invoice\Ecpay\...\AesCrypto` 呼叫點（InvoiceApiClient、ReceiptApiClient）；
 * 密文位元組與重構前完全一致。
 *
 * @see \J7\PowerCheckout\Shared\Helpers\EcpayAesCrypto 單一化後的演算法真實來源
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers;

use J7\PowerCheckout\Shared\Helpers\EcpayAesCrypto;

/** 綠界 AES-128-CBC 加解密 Helper（委派至 Shared\Helpers\EcpayAesCrypto） */
final class AesCrypto {

	/** @var EcpayAesCrypto 單一化的綠界 AES-128-CBC 加解密器 */
	private readonly EcpayAesCrypto $crypto;

	/** Constructor */
	public function __construct(
		/** @var string HashKey */
		string $hash_key,
		/** @var string HashIV */
		string $hash_iv,
	) {
		$this->crypto = new EcpayAesCrypto( $hash_key, $hash_iv );
	}

	/**
	 * 加密
	 *
	 * @param array<string, mixed> $data 要加密的明文陣列
	 *
	 * @return string Base64 字串
	 * @throws \RuntimeException JSON 編碼或加密失敗
	 */
	public function encrypt( array $data ): string {
		return $this->crypto->encrypt( $data );
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
		return $this->crypto->decrypt( $cipher_text );
	}
}
