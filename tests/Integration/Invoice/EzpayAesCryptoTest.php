<?php
/**
 * ezPay AesCrypto 整合測試
 *
 * 驗證 AES-256-CBC 加密實作符合 ezPay 官方規格：
 *  - 自行補 PKCS#7 padding（blocksize=32，非標準 AES block 16）
 *  - 加密時使用 OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
 *  - 輸出為 bin2hex 小寫 hex 字串
 *  - KEY10002 = 填充錯誤（若用標準 padding 會發生）
 *
 * 官方加密範例出處：ezpay-invoice skill references/concepts.md §AES-256-CBC 加密
 *
 * 注意：此為整合測試層，純函式邏輯驗證請同步執行離線 harness：
 *   php tests/offline/ezpay-pure-harness.php
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\AesCrypto;
use Tests\Integration\TestCase;

/**
 * EzpayAesCrypto 加密測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 */
final class EzpayAesCryptoTest extends TestCase {

	/**
	 * 官方測試用 HashKey（32 bytes）
	 */
	private const KEY = 'abcdefghijklmnopqrstuvwxyzabcdef';

	/**
	 * 官方測試用 HashIV（16 bytes）
	 */
	private const IV = '1234567891234567';

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_AesCrypto_類別可實例化(): void {
		$crypto = new AesCrypto( self::KEY, self::IV );
		$this->assertInstanceOf( AesCrypto::class, $crypto );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_encrypt_回傳小寫hex字串(): void {
		$crypto     = new AesCrypto( self::KEY, self::IV );
		$ciphertext = $crypto->encrypt( 'test=1' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $ciphertext, '加密輸出必須為小寫 hex 字串' );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_decrypt_還原原始字串(): void {
		$crypto    = new AesCrypto( self::KEY, self::IV );
		$plaintext = 'MerchantID=12345678&RespondType=JSON&Version=1.5';

		$ciphertext = $crypto->encrypt( $plaintext );
		$decrypted  = $crypto->decrypt( $ciphertext );

		$this->assertSame( $plaintext, $decrypted );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_blocksize32補padding_非16倍數字串可正確加解密(): void {
		// 長度 = 33（超過 32 blocksize，測試 PKCS#7 wrapping）
		$crypto    = new AesCrypto( self::KEY, self::IV );
		$plaintext = str_repeat( 'A', 33 );

		$ciphertext = $crypto->encrypt( $plaintext );
		$decrypted  = $crypto->decrypt( $ciphertext );

		$this->assertSame( $plaintext, $decrypted );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_精確32bytes字串加解密(): void {
		// 正好 32 bytes：PKCS#7 會補整個 block（32 個 chr(32)）
		$crypto    = new AesCrypto( self::KEY, self::IV );
		$plaintext = str_repeat( 'X', 32 );

		$ciphertext = $crypto->encrypt( $plaintext );
		$decrypted  = $crypto->decrypt( $ciphertext );

		$this->assertSame( $plaintext, $decrypted );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_中文參數url_encode後可正確加解密(): void {
		$crypto    = new AesCrypto( self::KEY, self::IV );
		$plaintext = 'ItemName=' . rawurlencode( '測試商品' ) . '&ItemCount=1';

		$ciphertext = $crypto->encrypt( $plaintext );
		$decrypted  = $crypto->decrypt( $ciphertext );

		$this->assertSame( $plaintext, $decrypted );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_空字串加密後仍可解密回空字串(): void {
		$crypto     = new AesCrypto( self::KEY, self::IV );
		$ciphertext = $crypto->encrypt( '' );
		$decrypted  = $crypto->decrypt( $ciphertext );

		$this->assertSame( '', $decrypted );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_不同KEY產生不同加密結果(): void {
		$crypto1 = new AesCrypto( self::KEY, self::IV );
		$crypto2 = new AesCrypto( str_repeat( 'z', 32 ), self::IV );

		$plaintext   = 'MerchantID=12345678';
		$ciphertext1 = $crypto1->encrypt( $plaintext );
		$ciphertext2 = $crypto2->encrypt( $plaintext );

		$this->assertNotSame( $ciphertext1, $ciphertext2, '不同 KEY 必須產生不同密文' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_標準AES16blockPadding會造成解密異常(): void {
		// 若以 PKCS_PADDING（blocksize=16）加密，用 AesCrypto 解密應無法還原
		$key       = self::KEY;
		$iv        = self::IV;
		$plaintext = 'test=data&key=value';

		// 標準 openssl padding（PKCS#7 blocksize=16，會補不同量的 bytes）
		$wrongCipherHex = bin2hex( openssl_encrypt( $plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv ) );

		$crypto    = new AesCrypto( $key, $iv );
		$decrypted = $crypto->decrypt( $wrongCipherHex );

		// 解密結果與原始字串不同（因 padding block 不一致，去 padding 會得到錯誤結果）
		$this->assertNotSame( $plaintext, $decrypted, '標準 16-blocksize padding 應無法被正確解密' );
	}
}
