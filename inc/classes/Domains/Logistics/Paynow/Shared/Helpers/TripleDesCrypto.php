<?php
/**
 * PayNow 物流 TripleDES 加密 Helper（立吉富體系 1，woomp 對齊）
 *
 * PayNow 物流 API 對「訂單 JSON」與「apicode」使用兩種不可互換的 TripleDES 路徑（R2）：
 *
 *  - encrypt_order_json()：'DES-EDE3' + OPENSSL_NO_PADDING
 *      明文須先手動補 `\0` 到 8 bytes 邊界（已對齊則不補），再 base64。
 *      ⚠️ 與 apicode 路徑不互換——對齊輸入不補 block，密文長度比 apicode 少一個 block。
 *
 *  - encrypt_apicode()：'DES-EDE3-ECB' + OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING
 *      明文一律手動補 `\0`（對齊時固定補滿一個 8 bytes block，與 woomp 一致），
 *      再 base64，最後 `str_replace(' ', '+', ...)`（修正部分環境 base64 空格汙染）。
 *
 * ⚠️ OpenSSL 的 'DES-EDE3'（無 -CBC 後綴）實為 3DES-EDE **ECB** 變體，IV 被忽略，
 *    與 'DES-EDE3-ECB' 等價（已以 woomp 已知向量 V//aGezX... 鎖定）。兩路徑的真正差異
 *    在「padding 契約」（order_json 對齊不補 / apicode 一律補滿），而非 chaining mode。
 *
 * key/IV 為 woomp 體系公開測試常數（R3）；正式環境須由 PayNow 換鑰。
 * [GAP: prod 環境 key/IV 待 PayNow 提供；屆時改由 SettingsDTO 注入而非 hardcode]
 *
 * 全程比照 woomp `class-paynow-shipping-request.php`（build_ciphertext / build_encrypted_args）
 * 與 `class-paynow-shipping.php`（apicode 加密）逐行對齊，並以已知向量鎖定。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 1
 * @see ../woomp/.../shippings/api/class-paynow-shipping-request.php L716-751
 * @see ../woomp/.../includes/class-paynow-shipping.php L242-253
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers;

/** PayNow 物流 TripleDES 加密 Helper（兩模式不可互換，R2） */
final class TripleDesCrypto {

	/** @var string 預設 TripleDES key（24 bytes，woomp 體系公開測試常數，R3）。[GAP: prod 換鑰] */
	public const DEFAULT_KEY = '123456789070828783123456';

	/** @var string 預設 IV（8 bytes，woomp 體系公開測試常數，R3；ECB 不使用，保留供未來 CBC）。[GAP: prod 換鑰] */
	public const DEFAULT_IV = '12345678';

	/** @var int TripleDES block size（bytes） */
	private const BLOCK_SIZE = 8;

	/**
	 * Constructor
	 *
	 * ⚠️ 第二參數 $iv 僅為簽章相容保留（woomp 體系傳 IV）：OpenSSL 的 'DES-EDE3' 為 ECB
	 * 變體不使用 IV，故 $iv 不被任何加解密呼叫使用，亦不儲存為屬性（避免 onlyWritten）。
	 * 未來若改用真 CBC（'DES-EDE3-CBC'），再將 $iv 升級為儲存屬性並注入。
	 *
	 * @param string $key TripleDES key（24 bytes）；預設 woomp 測試常數
	 * @param string $iv  保留參數（ECB 不使用，預設 woomp 測試常數）
	 *
	 * @phpstan-ignore-next-line constructor.unusedParameter
	 */
	public function __construct(
		private readonly string $key = self::DEFAULT_KEY,
		string $iv = self::DEFAULT_IV, // phpcs:ignore
	) {}

	/**
	 * 加密訂單 JSON（DES-EDE3 + NO_PADDING + 手動補零 + base64）
	 *
	 * 明文長度非 8 bytes 倍數時手動補 `\0` 至邊界；已對齊則不補（與 woomp 一致，
	 * 故 8-byte 對齊輸入不會多出一個 block，與 apicode 路徑輸出長度不同）。
	 *
	 * @param string $json 訂單 JSON 明文
	 * @return string base64 後密文
	 */
	public function encrypt_order_json( string $json ): string {
		$padded = $this->pad_zero_when_unaligned( $json );

		// ⚠️ OpenSSL 的 'DES-EDE3' 為 3DES-EDE ECB 變體，不使用 IV（傳入 8 bytes IV 僅會被截斷並噴 warning）。
		// woomp 傳 IV 實為 no-op；此處改傳空 IV，輸出向量完全一致且不噴 warning。$iv 保留供未來 CBC 用。
		$cipher = \openssl_encrypt(
			$padded,
			'DES-EDE3',
			$this->key,
			OPENSSL_NO_PADDING,
			''
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return \base64_encode( false === $cipher ? '' : $cipher );
	}

	/**
	 * 解密訂單 JSON（DES-EDE3 + NO_PADDING；右側 `\0` 由呼叫端自行 rtrim）
	 *
	 * @param string $encoded base64 後密文
	 * @return string 解密後明文（含尾端補零，呼叫端可 rtrim("\0")）
	 */
	public function decrypt_order_json( string $encoded ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = \base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}

		$plain = \openssl_decrypt(
			$raw,
			'DES-EDE3',
			$this->key,
			OPENSSL_NO_PADDING,
			''
		);

		return false === $plain ? '' : \rtrim( $plain, "\0" );
	}

	/**
	 * 加密 apicode（DES-EDE3-ECB + RAW_DATA|ZERO_PADDING + 手動補零 + base64 + 空格轉加號）
	 *
	 * Apicode 一律手動補滿一個 `\0` block（即使已對齊也補滿，與 woomp 完全一致），
	 * 因此 8-byte 對齊輸入會多一個 block，輸出長度與 ECB order_json 模式不同（R2 不互換）。
	 *
	 * @param string $apicode apicode 明文
	 * @return string base64 後密文（已將空格替換為加號）
	 */
	public function encrypt_apicode( string $apicode ): string {
		$pad    = self::BLOCK_SIZE - ( \strlen( $apicode ) % self::BLOCK_SIZE );
		$padded = $apicode . \str_repeat( "\0", $pad );

		$cipher = \openssl_encrypt(
			$padded,
			'DES-EDE3-ECB',
			$this->key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return \str_replace( ' ', '+', \base64_encode( false === $cipher ? '' : $cipher ) );
	}

	/**
	 * 解密 apicode（DES-EDE3-ECB + RAW_DATA|ZERO_PADDING；尾端 `\0` 由呼叫端 rtrim）
	 *
	 * @param string $encoded base64 後密文
	 * @return string 解密後明文（含尾端補零，呼叫端可 rtrim("\0")）
	 */
	public function decrypt_apicode( string $encoded ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = \base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}

		$plain = \openssl_decrypt(
			$raw,
			'DES-EDE3-ECB',
			$this->key,
			OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
		);

		return false === $plain ? '' : \rtrim( $plain, "\0" );
	}

	/**
	 * 非 8 bytes 倍數時補 `\0` 至邊界；已對齊則原樣返回（order_json 用）
	 *
	 * @param string $text 明文
	 * @return string 補零後明文
	 */
	private function pad_zero_when_unaligned( string $text ): string {
		// 空字串補滿一個 block（避免加密輸出為空；spec §A-Cycle 0 edge：空字串→補 8 bytes \0）。
		if ( '' === $text ) {
			return \str_repeat( "\0", self::BLOCK_SIZE );
		}
		$remainder = \strlen( $text ) % self::BLOCK_SIZE;
		if ( 0 === $remainder ) {
			return $text;
		}
		return \str_pad( $text, \strlen( $text ) + self::BLOCK_SIZE - $remainder, "\0" );
	}
}
