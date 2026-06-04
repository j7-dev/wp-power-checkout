<?php
/**
 * 綠界站內付 2.0（ECPG）AES-128-CBC 加解密整合測試
 * 驗證 AES round-trip、標準 Base64 alphabet、巢狀結構 / 中文 / 特殊字元保真。
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use Tests\Integration\TestCase;

/**
 * 綠界站內付 2.0 AesCrypto 測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 * @group ecpg
 */
final class EcpgAesCryptoTest extends TestCase {

	/** @var string 綠界 ECPG 線上金流測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 ECPG 線上金流測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	/** @return AesCrypto 測試用加解密器 */
	private function crypto(): AesCrypto {
		return new AesCrypto( self::HASH_KEY, self::HASH_IV );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_加解密round_trip可還原原始陣列(): void {
		// Given: 含巢狀 OrderInfo / ConsumerInfo 的明文
		$crypto = $this->crypto();
		$data   = [
			'MerchantID'   => '3002607',
			'PayToken'     => 'header.payload.sig',
			'OrderInfo'    => [
				'MerchantTradeNo' => 'EG200ABCDEF',
				'TotalAmount'     => 1000,
			],
			'ConsumerInfo' => [
				'Email' => 'buyer@example.com',
				'Phone' => '0912345678',
			],
		];

		// When: 加密後再解密
		$encrypted = $crypto->encrypt( $data );
		$decrypted = $crypto->decrypt( $encrypted );

		// Then: 完全還原（含巢狀）
		$this->assertSame( $data, $decrypted );
		$this->assertSame( 'EG200ABCDEF', $decrypted['OrderInfo']['MerchantTradeNo'] );
		$this->assertSame( 1000, $decrypted['OrderInfo']['TotalAmount'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_密文為標準Base64_alphabet不含URL_safe字元(): void {
		// Given
		$crypto = $this->crypto();

		// When
		$encrypted = $crypto->encrypt(
			[
				'MerchantID' => '3002607',
				'RtnCode'    => 1,
			]
			);

		// Then: 僅含標準 alphabet（A-Za-z0-9 + / =），不可出現 URL-safe 的 - 與 _
		$this->assertMatchesRegularExpression( '#^[A-Za-z0-9+/=]+$#', $encrypted );
		$this->assertStringNotContainsString( '-', $encrypted );
		$this->assertStringNotContainsString( '_', $encrypted );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_中文_空格_波浪號_單引號等特殊字元保真(): void {
		// Given: 含中文、空格、~、'、& 等需 URL encode 的字元
		$crypto = $this->crypto();
		$data   = [
			'ItemName'  => '測試商品 A#測試商品 B',
			'TradeDesc' => "Order ~ test ' & <tag>",
			'Name'      => '王 大明',
		];

		// When
		$decrypted = $crypto->decrypt( $crypto->encrypt( $data ) );

		// Then
		$this->assertSame( $data, $decrypted );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_解密非法Base64時拋出RuntimeException(): void {
		// Given: 不是合法密文（隨機字串，base64_decode strict 會失敗或解密失敗）
		$crypto = $this->crypto();

		// Then
		$this->expectException( \RuntimeException::class );

		// When: 用明顯非法的密文（含非 base64 字元）
		$crypto->decrypt( '###not-a-valid-cipher###' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_以不同金鑰解密時失敗(): void {
		// Given: A 金鑰加密，B 金鑰解密
		$enc       = $this->crypto()->encrypt(
			[
				'MerchantID' => '3002607',
				'RtnCode'    => 1,
			]
			);
		$wrong_key = new AesCrypto( 'wrongkeywrongkey', self::HASH_IV );

		// Then: 解密失敗（padding / json 解析錯誤）
		$this->expectException( \RuntimeException::class );

		// When
		$wrong_key->decrypt( $enc );
	}
}
