<?php
/**
 * PAYUNi 統一金流物流 AES-256-GCM 加解密 + SHA256 HashInfo
 *
 * ⚠️ 與綠界（AES-128-CBC）截然不同：PAYUNi 物流與金流共用 AES-256-GCM。
 *
 * 加密流程（payuni-logistics-v3 encryption.md §3.1）：
 *   明文陣列 → http_build_query（過濾空值；依進入順序，不排序）
 *   → AES-256-GCM(key=HashKey 32 bytes, iv=HashIV 16 bytes，輸出 ciphertext + 16-byte AuthTag)
 *   → EncryptInfo = hex( base64(ciphertext) + ":::" + base64(AuthTag) )
 * 解密流程（§3.2）：
 *   hex2bin → 以 ":::" 拆 ciphertext / AuthTag → base64_decode →
 *   AES-256-GCM 解密（AuthTag 驗證失敗即 throw）→ urldecode-parse 回陣列
 * HashInfo（§3.3）：
 *   strtoupper( sha256( HashKey + EncryptInfo + HashIV ) )，順序固定不可換。
 *
 * Key / IV 一律 trim（PAYUNi 後台複製偶有 trailing 空白），長度需正好 32 / 16 bytes。
 *
 * @see .claude/skills/payuni-logistics-v3/references/encryption.md
 * @see .claude/skills/payuni-logistics-v3/references/quick-checks.md §Check 1 / 2 / 3
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers;

/** PAYUNi AES-256-GCM 加解密 + SHA256 HashInfo Helper */
final class PayuniCrypto {

	/** @var string AES 演算法（與綠界 AES-128-CBC 不同） */
	private const CIPHER = 'aes-256-gcm';

	/** @var string cipher / tag 分隔符（PAYUNi 規範） */
	private const SEPARATOR = ':::';

	/** @var int AES-256-GCM AuthTag 長度（bytes） */
	private const TAG_LENGTH = 16;

	/** @var string trim 後的 HashKey（32 bytes） */
	private readonly string $hash_key;

	/** @var string trim 後的 HashIV（16 bytes） */
	private readonly string $hash_iv;

	/** Constructor */
	public function __construct( string $hash_key, string $hash_iv ) {
		// PAYUNi 後台複製偶有 trailing 空白，一律 trim（payuni-logistics-v3 §共通規則）
		$this->hash_key = \trim( $hash_key );
		$this->hash_iv  = \trim( $hash_iv );
	}

	/**
	 * 加密：明文陣列 → EncryptInfo（hex 字串）
	 *
	 * 空值（'' / null）欄位過濾後再 http_build_query（對齊官方 PHP 範例與 quick-checks §3）。
	 *
	 * @param array<string, mixed> $data 要加密的明文陣列（含 MerID / Timestamp）
	 *
	 * @return string EncryptInfo（hex 字串）
	 * @throws \RuntimeException 加密失敗
	 */
	public function encrypt( array $data ): string {
		// 過濾 null / 空字串（避免空欄位佔 querystring；對齊官方 http_build_query 行為）
		$filtered = \array_filter(
			$data,
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);

		$query = \http_build_query( $filtered );

		$tag       = '';
		$encrypted = \openssl_encrypt(
			$query,
			self::CIPHER,
			$this->hash_key,
			OPENSSL_RAW_DATA,
			$this->hash_iv,
			$tag,
			'',
			self::TAG_LENGTH
		);

		if (false === $encrypted) {
			throw new \RuntimeException( 'PAYUNi AES encrypt: openssl_encrypt 失敗' );
		}

		// EncryptInfo = hex( base64(cipher) + ":::" + base64(tag) )
		$combined = \base64_encode( $encrypted ) . self::SEPARATOR . \base64_encode( $tag );

		return \bin2hex( $combined );
	}

	/**
	 * 解密：EncryptInfo（hex 字串）→ 明文陣列
	 *
	 * AuthTag 驗證失敗（金鑰錯 / 密文被竄改）→ openssl_decrypt 回 false → throw。
	 *
	 * @param string $encrypt_info EncryptInfo（hex 字串）
	 *
	 * @return array<string, mixed> 解密後明文陣列
	 * @throws \RuntimeException 格式錯誤 / 解密失敗
	 */
	public function decrypt( string $encrypt_info ): array {
		$combined = @\hex2bin( \trim( $encrypt_info ) );
		if (false === $combined) {
			throw new \RuntimeException( 'PAYUNi AES decrypt: hex2bin 失敗（非 hex 字串）' );
		}

		$sep_pos = \strpos( $combined, self::SEPARATOR );
		if (false === $sep_pos) {
			throw new \RuntimeException( 'PAYUNi AES decrypt: EncryptInfo 缺少 ":::" 分隔符' );
		}

		$cipher_b64 = \substr( $combined, 0, $sep_pos );
		$tag_b64    = \substr( $combined, $sep_pos + \strlen( self::SEPARATOR ) );

		$cipher = \base64_decode( $cipher_b64, true );
		$tag    = \base64_decode( $tag_b64, true );
		if (false === $cipher || false === $tag) {
			throw new \RuntimeException( 'PAYUNi AES decrypt: base64_decode 失敗' );
		}

		$decrypted = \openssl_decrypt(
			$cipher,
			self::CIPHER,
			$this->hash_key,
			OPENSSL_RAW_DATA,
			$this->hash_iv,
			$tag
		);

		// AuthTag 驗證失敗（金鑰 / iv 錯，或密文被竄改）→ false
		if (false === $decrypted) {
			throw new \RuntimeException( 'PAYUNi AES decrypt: openssl_decrypt 失敗（AuthTag 驗證不通過）' );
		}

		\parse_str( $decrypted, $result );

		// parse_str 產出 key 一律為 string；以 array<string, mixed> 回傳（值可能為巢狀陣列）
		$normalized = [];
		foreach ( $result as $key => $value ) {
			$normalized[ (string) $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * 計算 HashInfo = strtoupper( SHA256( HashKey + EncryptInfo + HashIV ) )
	 *
	 * 順序固定 HashKey + EncryptInfo + HashIV，不可換序（payuni-logistics-v3 §Check 1）。
	 *
	 * @param string $encrypt_info EncryptInfo（hex 字串）
	 *
	 * @return string 64 字元大寫 hex
	 */
	public function hash_info( string $encrypt_info ): string {
		return \strtoupper(
			\hash( 'sha256', $this->hash_key . $encrypt_info . $this->hash_iv )
		);
	}

	/**
	 * Timing-safe 驗證 HashInfo（回應 / Notify 驗簽用）
	 *
	 * @param string $encrypt_info  EncryptInfo（hex 字串）
	 * @param string $received_hash 對方帶來的 HashInfo
	 *
	 * @return bool 是否相符
	 */
	public function verify_hash( string $encrypt_info, string $received_hash ): bool {
		$expected = $this->hash_info( $encrypt_info );
		// PAYUNi 大小寫不敏感，但統一以大寫比對（對齊官方範例）
		return \hash_equals( $expected, \strtoupper( \trim( $received_hash ) ) );
	}
}
