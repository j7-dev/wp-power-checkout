<?php
/**
 * PayuniCrypto 單元測試（AES-256-GCM + SHA256 HashInfo）
 *
 * 驗證 PAYUNi 加密規範（與綠界 AES-128-CBC 不同）：
 *   plaintext → http_build_query → AES-256-GCM(key=HashKey 32B, iv=HashIV 16B)
 *   → EncryptInfo = hex( base64(cipher) + ":::" + base64(tag) )
 *   → HashInfo = SHA256( HashKey + EncryptInfo + HashIV ) 大寫 hex
 *
 * ⚠️ AES-GCM 每次密文不同（受 tag 影響），故驗收以「round-trip 還原」為準，不寫死密文比對。
 *
 * 測試向量（payuni-logistics-v3 skill encryption.md §測試向量）：
 *   HashKey = "12345678901234567890123456789012"（32 bytes）
 *   HashIV  = "1234567890123456"（16 bytes）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PayuniCryptoTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use Tests\Integration\TestCase;

/**
 * PayuniCrypto 測試類別
 *
 * @group integration
 * @group logistics
 * @group payuni
 */
final class PayuniCryptoTest extends TestCase {

	// PAYUNi 官方文件測試金鑰（payuni-logistics-v3 encryption.md §測試向量）
	private const HASH_KEY = '12345678901234567890123456789012';
	private const HASH_IV  = '1234567890123456';

	private function crypto(): PayuniCrypto {
		return new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
	}

	// ========== 加密結構（Smoke / Encrypt） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_加密_EncryptInfo為hex字串且含三冒號分隔符(): void {
		$encrypt_info = $this->crypto()->encrypt(
			[
				'MerID'      => 'AAA',
				'MerTradeNo' => 'BBB',
			]
		);

		// EncryptInfo 必為 hex 字串（只含 0-9a-f），長度為偶數
		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $encrypt_info );
		$this->assertSame( 0, strlen( $encrypt_info ) % 2 );

		// hex decode 後應含 ":::" 分隔符（cipher:::tag）
		$decoded = hex2bin( $encrypt_info );
		$this->assertIsString( $decoded );
		$this->assertStringContainsString( ':::', $decoded );
	}

	// ========== Round-trip（Happy） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_加解密_round_trip還原為原始參數(): void {
		$params = [
			'MerID'      => 'AAA',
			'MerTradeNo' => 'BBB',
		];

		$crypto       = $this->crypto();
		$encrypt_info = $crypto->encrypt( $params );
		$decrypted    = $crypto->decrypt( $encrypt_info );

		$this->assertSame( 'AAA', $decrypted['MerID'] ?? '' );
		$this->assertSame( 'BBB', $decrypted['MerTradeNo'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_加解密_中文與特殊字元正確還原(): void {
		$params = [
			'StoreName' => '敦安門市',
			'Address'   => '台北市大安區安和路一段27號',
			'Consignee' => '周大宇',
		];

		$crypto    = $this->crypto();
		$decrypted = $crypto->decrypt( $crypto->encrypt( $params ) );

		$this->assertSame( '敦安門市', $decrypted['StoreName'] ?? '' );
		$this->assertSame( '台北市大安區安和路一段27號', $decrypted['Address'] ?? '' );
		$this->assertSame( '周大宇', $decrypted['Consignee'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_加密_空值欄位被過濾不進密文(): void {
		$crypto    = $this->crypto();
		$decrypted = $crypto->decrypt(
			$crypto->encrypt(
				[
					'MerID'  => 'AAA',
					'Empty'  => '',
					'Filled' => 'X',
				]
			)
		);

		// 空字串欄位應被過濾（querystring 不含 Empty）
		$this->assertArrayNotHasKey( 'Empty', $decrypted );
		$this->assertSame( 'AAA', $decrypted['MerID'] ?? '' );
		$this->assertSame( 'X', $decrypted['Filled'] ?? '' );
	}

	// ========== HashInfo（Happy / Security） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_HashInfo_為SHA256大寫hex且公式為key加info加iv(): void {
		$crypto       = $this->crypto();
		$encrypt_info = $crypto->encrypt( [ 'MerID' => 'AAA' ] );
		$hash_info    = $crypto->hash_info( $encrypt_info );

		// 64 字元大寫 hex
		$this->assertSame( 64, strlen( $hash_info ) );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{64}$/', $hash_info );

		// 公式固定為 SHA256(HashKey + EncryptInfo + HashIV) 大寫
		$expected = strtoupper( hash( 'sha256', self::HASH_KEY . $encrypt_info . self::HASH_IV ) );
		$this->assertSame( $expected, $hash_info );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_HashInfo驗證_正確雜湊通過_竄改不通過(): void {
		$crypto       = $this->crypto();
		$encrypt_info = $crypto->encrypt( [ 'MerID' => 'AAA' ] );
		$valid_hash   = $crypto->hash_info( $encrypt_info );

		// 正確雜湊：timing-safe 驗證通過
		$this->assertTrue( $crypto->verify_hash( $encrypt_info, $valid_hash ) );

		// 竄改後的雜湊：不通過
		$this->assertFalse( $crypto->verify_hash( $encrypt_info, 'DEADBEEF' ) );
		$this->assertFalse( $crypto->verify_hash( $encrypt_info, '' ) );
	}

	// ========== 解密失敗（Error） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_解密_格式錯誤拋出例外(): void {
		$crypto = $this->crypto();

		$this->expectException( \RuntimeException::class );
		// 非 hex / 缺 ::: 分隔符 → 解密失敗
		$crypto->decrypt( 'not-a-valid-encrypt-info' );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_解密_錯誤金鑰因AuthTag驗證失敗而拋例外(): void {
		// 用正確金鑰加密
		$encrypt_info = $this->crypto()->encrypt( [ 'MerID' => 'AAA' ] );

		// 用錯誤金鑰解密 → AES-256-GCM AuthTag 驗證失敗 → throw
		$wrong = new PayuniCrypto( 'ffffffffffffffffffffffffffffffff', self::HASH_IV );

		$this->expectException( \RuntimeException::class );
		$wrong->decrypt( $encrypt_info );
	}
}
