<?php
/**
 * 藍新 ezPay 電子發票 PostData_ 加解密（AES-256-CBC + 自補 PKCS#7 blocksize=32 + hex）
 *
 * ⚠️ 絕對不可複用 J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto::encrypt()
 *    —— 那支用 OPENSSL_RAW_DATA（openssl 自動補 PKCS#7，block 16）；ezPay 規格要求 blocksize=32
 *    自補 PKCS#7 + OPENSSL_ZERO_PADDING（告訴 openssl 別再補）。兩者 padding 行為不同，混用會被
 *    ezPay 平台回 KEY10002（資料解密錯誤）。可參照 TradeInfoCrypto 的 key/iv getter 風格與類別結構。
 *
 * 加密規格（EZP_INVI_1.2.1，以 ezpay-invoice skill concepts.md §AES-256-CBC 加密 為準）：
 *  - 演算法：AES-256-CBC
 *  - Key：HashKey 固定 32 bytes（256-bit）。getter 以 padEnd(32,'0') 取前 32 bytes 防呆，
 *    對官方固定 32 字 key 為 no-op，不影響官方向量。
 *  - IV：HashIV 固定 16 bytes（128-bit）。getter 同理 padEnd(16,'0') 取前 16 bytes。
 *  - Padding：自補 PKCS#7，blocksize=**32**（非標準 AES block 16）。
 *  - 加密選項：OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING（已自補，禁止 openssl 再補）。
 *  - 輸出：bin2hex → strtolower（小寫 hex 字串）。
 *  - 輸入：已組好的 key=value&... 明文字串（value 須先 rawurlencode，由 UrlEncoder 負責）。
 *
 * HashKey / HashIV 由建構子參數注入（不依賴任何 Settings 單例），方便測試與多帳號情境。
 *
 * @see .claude/skills/ezpay-invoice/references/concepts.md §AES-256-CBC 加密
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers;

/** 藍新 ezPay AES-256-CBC 加解密 Helper（自補 PKCS#7 blocksize=32 + hex 小寫） */
final class AesCrypto {

	/** @var string AES 演算法（256，輸出 hex） */
	private const CIPHER = 'aes-256-cbc';

	/** @var int Key 長度（bytes，ezPay 固定 32） */
	private const KEY_LENGTH = 32;

	/** @var int IV 長度（bytes，ezPay 固定 16） */
	private const IV_LENGTH = 16;

	/** @var int 自補 PKCS#7 的 blocksize（ezPay 規定 32，非標準 AES block 16） */
	private const PAD_BLOCK_SIZE = 32;

	/**
	 * Constructor
	 *
	 * @param string $hashKey ezPay 商店 HashKey（32 bytes）.
	 * @param string $hashIv  ezPay 商店 HashIV（16 bytes）.
	 */
	public function __construct(
		private readonly string $hashKey,
		private readonly string $hashIv,
	) {}

	/**
	 * AES-256-CBC 加密，輸出小寫 hex
	 *
	 * 流程：自補 PKCS#7（blocksize=32）→ openssl 加密（RAW_DATA | ZERO_PADDING）→ bin2hex → strtolower。
	 *
	 * @param string $plaintext 已組好的 key=value&... 明文字串（value 須已 rawurlencode）.
	 *
	 * @return string 小寫 hex 字串（即 PostData_）.
	 * @throws \RuntimeException When openssl_encrypt 失敗.
	 */
	public function encrypt( string $plaintext ): string {
		$encrypted = \openssl_encrypt(
			$this->add_pkcs7_padding( $plaintext ),
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, // 已自補 PKCS#7(32)，禁止 openssl 再補.
			$this->get_iv()
		);

		if ( false === $encrypted ) {
			throw new \RuntimeException( 'ezPay PostData_ encrypt 失敗' );
		}

		return \strtolower( \bin2hex( $encrypted ) );
	}

	/**
	 * AES-256-CBC 解密，輸入 hex
	 *
	 * 流程：hex2bin → openssl 解密（RAW_DATA | ZERO_PADDING）→ 移除自補的 PKCS#7 padding。
	 *
	 * @param string $hex hex 密文字串.
	 *
	 * @return string 解密並去 padding 後的明文.
	 * @throws \RuntimeException When hex 非法 / openssl_decrypt 失敗.
	 */
	public function decrypt( string $hex ): string {
		$raw = \hex2bin( \trim( $hex ) );
		if ( false === $raw ) {
			throw new \RuntimeException( 'ezPay PostData_ decrypt：hex2bin 失敗' );
		}

		$decrypted = \openssl_decrypt(
			$raw,
			self::CIPHER,
			$this->get_key(),
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, // 自行去 padding，禁止 openssl 自動去.
			$this->get_iv()
		);

		if ( false === $decrypted ) {
			throw new \RuntimeException( 'ezPay PostData_ decrypt：openssl_decrypt 失敗' );
		}

		return $this->remove_pkcs7_padding( $decrypted );
	}

	/**
	 * 自補 PKCS#7 padding（blocksize=32）
	 *
	 * 規則：缺 N bytes 就補 N 個值為 N 的 byte，N = blocksize − (len mod blocksize)；
	 * 當 len 恰為 blocksize 倍數時補滿一整個 block（N = blocksize）。
	 *
	 * @param string $input 原始明文.
	 *
	 * @return string 補 padding 後的字串（長度必為 blocksize 倍數）.
	 */
	private function add_pkcs7_padding( string $input ): string {
		$pad = self::PAD_BLOCK_SIZE - ( \strlen( $input ) % self::PAD_BLOCK_SIZE );
		return $input . \str_repeat( \chr( $pad ), $pad );
	}

	/**
	 * 移除 PKCS#7 padding
	 *
	 * 讀最後一 byte 的 ord 值 N，僅當 N 落在 1..blocksize 區間才視為合法 padding 並去尾 N bytes；
	 * 否則（含空字串或 N 超界）原樣回傳，避免誤砍真實資料。
	 *
	 * @param string $input 解密後（含 padding）的字串.
	 *
	 * @return string 去 padding 後的明文.
	 */
	private function remove_pkcs7_padding( string $input ): string {
		if ( '' === $input ) {
			return '';
		}

		$pad = \ord( $input[ \strlen( $input ) - 1 ] );
		if ( $pad < 1 || $pad > self::PAD_BLOCK_SIZE ) {
			return $input;
		}

		return \substr( $input, 0, -$pad );
	}

	/** @return string AES Key（HashKey padEnd(32,'0') 取前 32 bytes；對 32 字 key 為 no-op） */
	private function get_key(): string {
		return \substr( \str_pad( $this->hashKey, self::KEY_LENGTH, '0' ), 0, self::KEY_LENGTH );
	}

	/** @return string AES IV（HashIV padEnd(16,'0') 取前 16 bytes；對 16 字 iv 為 no-op） */
	private function get_iv(): string {
		return \substr( \str_pad( $this->hashIv, self::IV_LENGTH, '0' ), 0, self::IV_LENGTH );
	}
}
