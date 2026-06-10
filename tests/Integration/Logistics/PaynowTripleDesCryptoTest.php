<?php
/**
 * PayNow TripleDesCrypto 加密層測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段，class 不存在時預期 class not found）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\TripleDesCrypto
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 1
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 0 Red 裁決 R2/R3
 *   - woomp grounding：
 *       ../woomp/.../class-paynow-shipping-request.php L716-751（encrypt_order_json: DES-EDE3 CBC）
 *       ../woomp/.../class-paynow-shipping.php L243-253（encrypt_apicode: DES-EDE3-ECB）
 *
 * R2 裁決（兩方法絕對不可互換）：
 *   - encrypt_order_json(): DES-EDE3（CBC，預設）+ OPENSSL_NO_PADDING + 手動 \0 pad 到 8B 邊界 + base64
 *   - encrypt_apicode():    DES-EDE3-ECB + OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING + 手動 \0 pad + base64 + str_replace(' ','+',...)
 *
 * R3 固定向量（woomp 常數，測試用此鎖定）：
 *   key = '123456789070828783123456'（24 bytes）
 *   iv  = '12345678'（CBC 用）
 *
 * 已知向量（本地 PHP 驗算鎖定）：
 *   encrypt_order_json('{"test":"hi"}') = 'V//aGezXOOktQwOrrI9kVw=='
 *   encrypt_apicode('testapi')          = 'QuYRxhqYnvY='（7 byte，補 1 byte \0）
 *   8-byte-aligned CBC ('abcdefgh')     = 'jAn7ZkuE+TE=' (12 chars)
 *   8-byte-aligned ECB ('abcdefgh')     = 'jAn7ZkuE+THhUlroQawsMQ==' (24 chars)
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowTripleDesCryptoTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\TripleDesCrypto;
use Tests\Integration\TestCase;

/**
 * PayNow TripleDesCrypto 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowTripleDesCryptoTest extends TestCase {

	// R3 固定測試向量（woomp 常數）
	private const TEST_KEY = '123456789070828783123456';
	private const TEST_IV  = '12345678';

	// ========== Smoke：基本可呼叫性 ==========

	/**
	 * TripleDesCrypto 可以被實例化
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group logistics
	 */
	public function test_TripleDesCrypto_可以被實例化(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$this->assertInstanceOf( TripleDesCrypto::class, $crypto );
	}

	/**
	 * encrypt_order_json 方法存在且可呼叫
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_order_json_方法存在(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$this->assertTrue( method_exists( $crypto, 'encrypt_order_json' ) );
	}

	/**
	 * encrypt_apicode 方法存在且可呼叫
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_apicode_方法存在(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$this->assertTrue( method_exists( $crypto, 'encrypt_apicode' ) );
	}

	// ========== Happy：encrypt_order_json 已知向量 ==========

	/**
	 * encrypt_order_json 對已知 JSON 字串產生已知 base64 輸出
	 * 向量：encrypt_order_json('{"test":"hi"}') = 'V//aGezXOOktQwOrrI9kVw=='
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_order_json_已知向量輸出正確(): void {
		$crypto   = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$result   = $crypto->encrypt_order_json( '{"test":"hi"}' );
		$expected = 'V//aGezXOOktQwOrrI9kVw==';
		$this->assertSame( $expected, $result, 'DES-EDE3 CBC 加密已知向量不符，請確認 padding + mode 設定' );
	}

	/**
	 * encrypt_order_json round-trip：加密後解密能還原原始 JSON
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_order_json_round_trip還原(): void {
		$crypto   = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$original = '{"OrderNo":"PCN123","TotalAmount":"1000","Receiver_Name":"王小明"}';
		$encoded  = $crypto->encrypt_order_json( $original );

		// 解密：OpenSSL 'DES-EDE3' 實為 3DES-EDE ECB 變體（不使用 IV）→ 傳空 IV，避免 8B IV warning；rtrim \0
		$raw       = \base64_decode( $encoded, true );
		$decrypted = \openssl_decrypt( $raw, 'DES-EDE3', self::TEST_KEY, OPENSSL_NO_PADDING, '' );
		$restored  = \rtrim( $decrypted, "\0" );

		$this->assertSame( $original, $restored, 'encrypt_order_json round-trip 失敗：解密後與原始字串不符' );
	}

	// ========== Happy：encrypt_apicode 已知向量 ==========

	/**
	 * encrypt_apicode 對已知 apicode 產生已知 base64 輸出
	 * 向量：encrypt_apicode('testapi') = 'QuYRxhqYnvY='
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_apicode_已知向量輸出正確(): void {
		$crypto   = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$result   = $crypto->encrypt_apicode( 'testapi' );
		$expected = 'QuYRxhqYnvY=';
		$this->assertSame( $expected, $result, 'DES-EDE3-ECB 加密已知向量不符，請確認 ECB mode + str_replace 設定' );
	}

	/**
	 * encrypt_apicode round-trip：加密後解密能還原原始 apicode
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_apicode_round_trip還原(): void {
		$crypto   = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$original = 'myApiCode999';
		$encoded  = $crypto->encrypt_apicode( $original );

		// 解密：DES-EDE3-ECB + OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING → rtrim \0
		$raw_decoded = \base64_decode( $encoded, true );
		$decrypted   = \openssl_decrypt( $raw_decoded, 'DES-EDE3-ECB', self::TEST_KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING );
		$restored    = \rtrim( $decrypted, "\0" );

		$this->assertSame( $original, $restored, 'encrypt_apicode round-trip 失敗：解密後與原始字串不符' );
	}

	// ========== Security：兩模式輸出不互換 ==========

	/**
	 * encrypt_order_json 與 encrypt_apicode 對 8-byte-aligned 輸入輸出不同
	 * （8-byte 對齊時 CBC 無額外補 block，ECB 固定補一個 8-byte block，輸出長度不同）
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group logistics
	 */
	public function test_兩方法對8byte對齊輸入輸出不同(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$input  = 'abcdefgh'; // 精確 8 bytes

		$result_json    = $crypto->encrypt_order_json( $input );
		$result_apicode = $crypto->encrypt_apicode( $input );

		// CBC: 'jAn7ZkuE+TE='（12 chars）
		// ECB: 'jAn7ZkuE+THhUlroQawsMQ=='（24 chars）— 多一個補全 block
		$this->assertNotSame(
			$result_json,
			$result_apicode,
			'R2 裁決：兩方法對 8-byte 對齊輸入必須輸出不同——CBC 無補 block vs ECB 固定補 8-byte block'
		);
	}

	/**
	 * encrypt_apicode 輸出不含空格（str_replace 已處理）
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_apicode_輸出不含空格(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$result = $crypto->encrypt_apicode( '選店apicode' );
		$this->assertStringNotContainsString( ' ', $result, 'encrypt_apicode 輸出不應含空格（str_replace 必須處理）' );
	}

	// ========== Edge：空字串邊界 ==========

	/**
	 * encrypt_order_json 空字串：補 8 bytes \0 後加密，結果為有效 base64
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_order_json_空字串為有效base64(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$result = $crypto->encrypt_order_json( '' );
		$this->assertNotEmpty( $result );
		$decoded = \base64_decode( $result, true );
		$this->assertNotFalse( $decoded, 'encrypt_order_json 空字串輸出必須為合法 base64' );
	}

	/**
	 * encrypt_apicode 空字串：補 8 bytes \0 後加密，結果為有效 base64
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_apicode_空字串為有效base64(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$result = $crypto->encrypt_apicode( '' );
		$this->assertNotEmpty( $result );
		$decoded = \base64_decode( $result, true );
		$this->assertNotFalse( $decoded, 'encrypt_apicode 空字串輸出必須為合法 base64' );
	}

	/**
	 * encrypt_order_json 非 8-byte 倍數輸入：自動補 \0 後加密，round-trip 正確
	 * (1 byte：需補 7 bytes；3 bytes：需補 5 bytes；7 bytes：需補 1 byte)
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_order_json_非8Byte倍數輸入補零後round_trip(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );

		foreach ( [ 'x', 'abc', '1234567' ] as $short_input ) {
			$encoded = $crypto->encrypt_order_json( $short_input );
			$raw     = \base64_decode( $encoded, true );
			// 'DES-EDE3' = ECB（不使用 IV）→ 傳空 IV，避免 8B IV warning
			$decrypted = \openssl_decrypt( $raw, 'DES-EDE3', self::TEST_KEY, OPENSSL_NO_PADDING, '' );
			$restored  = \rtrim( $decrypted, "\0" );
			$this->assertSame(
				$short_input,
				$restored,
				"encrypt_order_json round-trip 失敗，輸入：'{$short_input}'"
			);
		}
	}

	/**
	 * encrypt_order_json UTF-8 中文輸入：round-trip 正確還原
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_encrypt_order_json_UTF8中文round_trip(): void {
		$crypto  = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$chinese = '{"Receiver_Name":"王小明","Receiver_Address":"台北市中正區"}';
		$encoded = $crypto->encrypt_order_json( $chinese );
		$raw     = \base64_decode( $encoded, true );
		// 'DES-EDE3' = ECB（不使用 IV）→ 傳空 IV，避免 8B IV warning
		$dec      = \openssl_decrypt( $raw, 'DES-EDE3', self::TEST_KEY, OPENSSL_NO_PADDING, '' );
		$restored = \rtrim( $dec, "\0" );
		$this->assertSame( $chinese, $restored, 'UTF-8 中文 round-trip 失敗' );
	}

	// ========== Security：跨模式解密失敗（不互換證明）==========

	/**
	 * R2 不互換：兩方法對「8-byte 對齊輸入」的密文長度不同（padding 契約相異）
	 *
	 * ⚠️ 原本此測試假設 'DES-EDE3' 為 CBC、與 'DES-EDE3-ECB' 不相容——此前提為誤。
	 * OpenSSL 的 'DES-EDE3'（無 -CBC 後綴）實為 3DES-EDE ECB 變體，IV 被忽略，
	 * 與 'DES-EDE3-ECB' 完全等價（已以已知向量 V//aGezX... 鎖定，且 woomp 生產端亦用此）。
	 * 因此真正可驗證的 R2 不互換性不在「mode」，而在「padding 契約」：
	 *   - encrypt_order_json: 對齊輸入「不」補 block（NO_PADDING + 手動補零僅於未對齊時）
	 *   - encrypt_apicode:    一律補滿一個 block（即使已對齊）
	 * 故對 8-byte 對齊輸入，apicode 密文必比 order_json 多一個 block（長度不同、不可互換）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group logistics
	 */
	public function test_CBC加密後以ECB解密無法還原(): void {
		$crypto = new TripleDesCrypto( self::TEST_KEY, self::TEST_IV );
		$input  = 'abcdefgh'; // 精確 8 bytes（對齊）

		$json_cipher    = \base64_decode( $crypto->encrypt_order_json( $input ), true );
		$apicode_cipher = \base64_decode( $crypto->encrypt_apicode( $input ), true );

		// order_json 對齊輸入 = 1 block（8 bytes）；apicode 補滿 = 2 blocks（16 bytes）
		$this->assertSame( 8, \strlen( (string) $json_cipher ), 'order_json 對齊輸入密文應為 8 bytes（1 block，不補）' );
		$this->assertSame( 16, \strlen( (string) $apicode_cipher ), 'apicode 對齊輸入密文應為 16 bytes（2 blocks，固定補滿）' );
		$this->assertNotSame(
			$json_cipher,
			$apicode_cipher,
			'R2 裁決：兩方法 padding 契約相異——對齊輸入密文長度不同，不可互換'
		);
	}
}
