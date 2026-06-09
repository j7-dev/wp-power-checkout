<?php
/**
 * PAYUNi Payment 版 PayuniCrypto — 官方測試向量驗證 + 等價性斷言
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto
 *
 * 加密規範（payuni-upp-v2 skill encryption.md）：
 *   AES-256-GCM；EncryptInfo = hex( base64(cipher) + ":::" + base64(AuthTag) )
 *   HashInfo = SHA256( HashKey + EncryptInfo + HashIV ).toUpperCase()
 *
 * 官方測試向量（payuni-upp-v2 skill encryption.md §官方測試向量）：
 *   HashKey = "12345678901234567890123456789012"（32 bytes）
 *   HashIV  = "1234567890123456"（16 bytes）
 *   輸入資料：{ MerID: "AAA", MerTradeNO: "BBB", Prod: "商品說明" }
 *   SHA256 預期值：E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7
 *
 * 注意：上述 SHA256 值是 Node.js 官方範例給出的 deterministic 值；PHP 官方範例（#/7/29）
 * 因 input data 欄位名稱不同（MerTradeNo vs MerTradeNO），得到不同密文，但演算法相同。
 * 等價性斷言確保 Payment 版與 Logistics 版輸出相同 hash_info（同 key/iv/data）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto as LogisticsCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto as PaymentCrypto;
use Tests\Integration\TestCase;

/**
 * PAYUNi Payment PayuniCrypto 官方測試向量驗證
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniCryptoGoldenVectorTest extends TestCase {

	// PAYUNi 官方文件公開測試向量金鑰（payuni-upp-v2 encryption.md §官方測試向量）
	private const HASH_KEY = '12345678901234567890123456789012'; // 32 bytes
	private const HASH_IV  = '1234567890123456';                 // 16 bytes

	/**
	 * 官方測試向量資料（Node.js 範例，含中文商品名）
	 * 對齊 payuni-upp-v2 encryption.md §執行範例 的 merData 欄位名稱
	 *
	 * @return array<string, mixed>
	 */
	private function golden_params(): array {
		return [
			'MerID'      => 'AAA',
			'MerTradeNO' => 'BBB',
			'Prod'       => '商品說明',
		];
	}

	private function payment_crypto(): PaymentCrypto {
		return new PaymentCrypto( self::HASH_KEY, self::HASH_IV );
	}

	private function logistics_crypto(): LogisticsCrypto {
		return new LogisticsCrypto( self::HASH_KEY, self::HASH_IV );
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PaymentCrypto可被實例化(): void {
		$crypto = $this->payment_crypto();
		$this->assertInstanceOf( PaymentCrypto::class, $crypto );
	}

	// ========== HashInfo 官方向量驗證（Happy / Security） ==========

	/**
	 * SHA256 預期值來自 payuni-upp-v2 encryption.md §官方測試向量（Node.js 範例）。
	 * 輸入為 Node.js querystring.stringify({ MerID:"AAA", MerTradeNO:"BBB", Prod:"商品說明" })
	 * 產出固定密文，對應 SHA256 期望值：E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7
	 *
	 * @test
	 * @group happy
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_HashInfo_官方測試向量_SHA256期望值與文件相符(): void {
		$crypto = $this->payment_crypto();

		// 用相同輸入加密，得到 EncryptInfo
		$encrypt_info = $crypto->encrypt( $this->golden_params() );

		// HashInfo 必為 64 字元大寫 hex（payuni-upp-v2 §HashInfo 規範）
		$hash_info = $crypto->hash_info( $encrypt_info );
		$this->assertSame( 64, strlen( $hash_info ) );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{64}$/', $hash_info );

		// 公式驗證：SHA256(HashKey + EncryptInfo + HashIV).toUpperCase()
		$expected = strtoupper( hash( 'sha256', self::HASH_KEY . $encrypt_info . self::HASH_IV ) );
		$this->assertSame( $expected, $hash_info );

		// 官方向量期望值（payuni-upp-v2 encryption.md §預期結果 SHA256 結果）
		// 依 skill §4 節：官方 SHA256 = E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7
		// 此值只有在 AES-GCM deterministic（IV 固定）時才成立；PHP openssl_encrypt 與 Node.js 輸出
		// 格式相同（base64(cipher):::base64(tag)），相同明文 + 相同 key/iv → 相同 EncryptInfo → 相同 SHA256
		$this->assertSame(
			'E97180D78C8378D64A188D292938B9D2717034F292B626019B01DF160AEFC0B7',
			$hash_info,
			'SHA256 值不符官方測試向量（payuni-upp-v2 encryption.md §預期結果）'
		);
	}

	// ========== Round-trip 加解密（Happy） ==========

	/**
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_加解密_round_trip_官方向量資料完整還原(): void {
		$crypto       = $this->payment_crypto();
		$params       = $this->golden_params();
		$encrypt_info = $crypto->encrypt( $params );
		$decrypted    = $crypto->decrypt( $encrypt_info );

		$this->assertSame( 'AAA', $decrypted['MerID'] ?? '' );
		$this->assertSame( 'BBB', $decrypted['MerTradeNO'] ?? '' );
		$this->assertSame( '商品說明', $decrypted['Prod'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_加解密_中文與特殊字元正確還原(): void {
		$crypto = $this->payment_crypto();
		$params = [
			'MerID'    => 'PAYUNI_TEST',
			'ProdDesc' => '測試商品；含分號、中英文、emoji',
		];

		$decrypted = $crypto->decrypt( $crypto->encrypt( $params ) );
		$this->assertSame( 'PAYUNI_TEST', $decrypted['MerID'] ?? '' );
		$this->assertSame( '測試商品；含分號、中英文、emoji', $decrypted['ProdDesc'] ?? '' );
	}

	// ========== Payment 版與 Logistics 版等價性斷言（Security） ==========

	/**
	 * 等價性斷言：相同 (hash_key, hash_iv, data) 下，Payment 版與 Logistics 版 hash_info 輸出一致。
	 * 鎖死「複用正確性」——兩版本是同源不同 namespace 的 copy，不得有演算法分歧。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_等價性_Payment版與Logistics版hash_info輸出相同(): void {
		$params = $this->golden_params();

		$payment_crypto    = $this->payment_crypto();
		$logistics_crypto  = $this->logistics_crypto();

		// 各自加密同一份資料
		$payment_encrypt   = $payment_crypto->encrypt( $params );
		$logistics_encrypt = $logistics_crypto->encrypt( $params );

		// 兩者加密結果應相同（AES-GCM + 同 key/iv/plaintext → deterministic）
		$this->assertSame(
			$payment_encrypt,
			$logistics_encrypt,
			'Payment 版與 Logistics 版加密結果不一致（演算法分歧）'
		);

		// hash_info 必然也相同
		$payment_hash   = $payment_crypto->hash_info( $payment_encrypt );
		$logistics_hash = $logistics_crypto->hash_info( $logistics_encrypt );

		$this->assertSame(
			$payment_hash,
			$logistics_hash,
			'Payment 版與 Logistics 版 hash_info 不一致（複用正確性驗證失敗）'
		);
	}

	/**
	 * 等價性斷言：Payment 版的 round-trip 結果與 Logistics 版一致
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_等價性_Payment版解密Logistics版密文(): void {
		$params = [ 'MerID' => 'CROSS_TEST', 'TradeAmt' => '1000' ];

		$logistics_encrypt_info = $this->logistics_crypto()->encrypt( $params );
		$decrypted_by_payment   = $this->payment_crypto()->decrypt( $logistics_encrypt_info );

		$this->assertSame( 'CROSS_TEST', $decrypted_by_payment['MerID'] ?? '' );
		$this->assertSame( '1000', $decrypted_by_payment['TradeAmt'] ?? '' );
	}

	// ========== AuthTag 竄改（Security） ==========

	/**
	 * AuthTag 被竄改 → AES-GCM 驗證失敗 → decrypt 拋出 RuntimeException
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_解密_AuthTag竄改後拋出RuntimeException(): void {
		$crypto       = $this->payment_crypto();
		$encrypt_info = $crypto->encrypt( $this->golden_params() );

		// 解析出 cipher + tag，竄改 tag 部分後重新 hex encode
		$combined  = hex2bin( $encrypt_info );
		$this->assertIsString( $combined );

		$sep_pos   = strpos( $combined, ':::' );
		$this->assertNotFalse( $sep_pos );

		$cipher_b64  = substr( $combined, 0, $sep_pos );
		// 竄改：將 AuthTag base64 替換為 base64('AAAAAAAAAAAAAAAA')（16 bytes 全零）
		$tampered_combined = $cipher_b64 . ':::' . base64_encode( str_repeat( "\x00", 16 ) );
		$tampered_info     = bin2hex( $tampered_combined );

		$this->expectException( \RuntimeException::class );
		$crypto->decrypt( $tampered_info );
	}

	/**
	 * 錯誤金鑰 → AES-GCM AuthTag 驗證失敗 → RuntimeException
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_解密_錯誤金鑰因AuthTag驗證失敗而拋例外(): void {
		$encrypt_info = $this->payment_crypto()->encrypt( $this->golden_params() );

		// 用不同金鑰解密
		$wrong_crypto = new PaymentCrypto(
			'ffffffffffffffffffffffffffffffff',
			self::HASH_IV
		);

		$this->expectException( \RuntimeException::class );
		$wrong_crypto->decrypt( $encrypt_info );
	}

	// ========== Edge Cases ==========

	/**
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_加密_空值欄位被過濾不進密文(): void {
		$crypto    = $this->payment_crypto();
		$decrypted = $crypto->decrypt(
			$crypto->encrypt(
				[
					'MerID'  => 'AAA',
					'Empty'  => '',
					'Filled' => 'X',
				]
			)
		);

		// 空字串欄位應被過濾
		$this->assertArrayNotHasKey( 'Empty', $decrypted );
		$this->assertSame( 'AAA', $decrypted['MerID'] ?? '' );
		$this->assertSame( 'X', $decrypted['Filled'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_解密_非hex格式輸入拋出RuntimeException(): void {
		$crypto = $this->payment_crypto();

		$this->expectException( \RuntimeException::class );
		$crypto->decrypt( 'not-a-valid-encrypt-info-string' );
	}

	/**
	 * verify_hash 正確雜湊通過、竄改不通過（timing-safe）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_verify_hash_正確通過竄改不通過(): void {
		$crypto       = $this->payment_crypto();
		$encrypt_info = $crypto->encrypt( $this->golden_params() );
		$valid_hash   = $crypto->hash_info( $encrypt_info );

		$this->assertTrue( $crypto->verify_hash( $encrypt_info, $valid_hash ) );
		$this->assertFalse( $crypto->verify_hash( $encrypt_info, 'DEADBEEF' ) );
		$this->assertFalse( $crypto->verify_hash( $encrypt_info, '' ) );
	}
}
