<?php
/**
 * 藍新 NewebPay MPG TradeInfoCrypto 測試（AES-256-CBC + SHA256）
 * run `vendor/bin/phpunit --filter TradeInfoCryptoTest`
 *
 * 高風險第一 TDD 目標：encrypt→decrypt round-trip、TradeSha 大寫、CheckCode 固定順序、固定向量。
 * 加解密為純函式（不需 WP DB），可獨立驗證。
 */

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Domains\Payment\NewebpayMpg;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckoutTests\Shared\WC_UnitTestCase;

/**
 * TradeInfoCrypto（AES-256-CBC / hex / PKCS#7）
 *
 * @group newebpay_mpg
 * @group payment
 */
class TradeInfoCryptoTest extends WC_UnitTestCase {

	/** @var string 測試用 HashKey（32 bytes，藍新規格） */
	private const HASH_KEY = 'abcdefghijklmnopqrstuvwxyz123456';

	/** @var string 測試用 HashIV（16 bytes，藍新規格） */
	private const HASH_IV = '1234567890abcdef';

	/**
	 * @testdox AES-256-CBC encrypt → decrypt round-trip 還原原文（中文 + 特殊字元）
	 * @return void
	 */
	public function test_encrypt_decrypt_round_trip(): void {
		$crypto    = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$plaintext = 'MerchantOrderNo=PC123&Amt=1000&ItemDesc=' . \rawurlencode( '測試商品 & 折扣' );

		$encrypted = $crypto->encrypt( $plaintext );
		$decrypted = $crypto->decrypt( $encrypted );

		$this->assertSame( $plaintext, $decrypted, 'round-trip 應還原原文' );
	}

	/**
	 * @testdox encrypt 輸出為 hex（非 base64），且為偶數長度
	 * @return void
	 */
	public function test_encrypt_output_is_hex(): void {
		$crypto    = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$encrypted = $crypto->encrypt( 'Amt=1000' );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $encrypted, 'TradeInfo 應為小寫 hex' );
		$this->assertSame( 0, \strlen( $encrypted ) % 2, 'hex 長度應為偶數' );
	}

	/**
	 * @testdox generate_trade_sha 必為大寫（小寫會被藍新回 MPG03012）
	 * @return void
	 */
	public function test_trade_sha_is_uppercase(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$hex    = $crypto->encrypt( 'Amt=1000' );
		$sha    = $crypto->generate_trade_sha( $hex );

		$this->assertSame( \strtoupper( $sha ), $sha, 'TradeSha 必為大寫' );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{64}$/', $sha, 'SHA256 hex 應為 64 字大寫' );
	}

	/**
	 * @testdox generate_trade_sha 符合公式 SHA256("HashKey={K}&{hex}&HashIV={IV}")
	 * @return void
	 */
	public function test_trade_sha_formula(): void {
		$crypto   = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );
		$hex      = $crypto->encrypt( 'Amt=1000' );
		$expected = \strtoupper(
			\hash( 'sha256', 'HashKey=' . self::HASH_KEY . '&' . $hex . '&HashIV=' . self::HASH_IV )
		);

		$this->assertSame( $expected, $crypto->generate_trade_sha( $hex ), 'TradeSha 公式不符' );
	}

	/**
	 * @testdox verify_check_code 以固定順序 HashIV,Amt,MerchantID,MerchantOrderNo,TradeNo,HashKey 驗證成功
	 * @return void
	 */
	public function test_verify_check_code_success(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		$result = [
			'Amt'             => 1000,
			'MerchantID'      => 'MS123456',
			'MerchantOrderNo' => 'PC123',
			'TradeNo'         => '26060512345678',
		];

		// 以官方固定順序自行算出正確 CheckCode
		$raw = 'HashIV=' . self::HASH_IV
			. '&Amt=' . $result['Amt']
			. '&MerchantID=' . $result['MerchantID']
			. '&MerchantOrderNo=' . $result['MerchantOrderNo']
			. '&TradeNo=' . $result['TradeNo']
			. '&HashKey=' . self::HASH_KEY;
		$valid_check_code = \strtoupper( \hash( 'sha256', $raw ) );

		$this->assertTrue(
			$crypto->verify_check_code( $result, $valid_check_code ),
			'正確 CheckCode 應驗證通過'
		);
	}

	/**
	 * @testdox verify_check_code 對竄改的 CheckCode 回 false
	 * @return void
	 */
	public function test_verify_check_code_tampered(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		$result = [
			'Amt'             => 1000,
			'MerchantID'      => 'MS123456',
			'MerchantOrderNo' => 'PC123',
			'TradeNo'         => '26060512345678',
		];

		$this->assertFalse(
			$crypto->verify_check_code( $result, 'DEADBEEF' ),
			'竄改 CheckCode 應驗證失敗'
		);
	}

	/**
	 * @testdox 固定向量：HashKey/HashIV 不足長度時以 0 右補（與藍新 padEnd 對齊）
	 * @return void
	 */
	public function test_short_key_iv_padding(): void {
		// 短 key/iv（藍新 SDK 規格：padEnd(32/16,'0')）
		$crypto    = new TradeInfoCrypto( 'shortkey', 'shortiv' );
		$plaintext = 'Amt=1000&MerchantOrderNo=PC999';

		$encrypted = $crypto->encrypt( $plaintext );
		$decrypted = $crypto->decrypt( $encrypted );

		$this->assertSame( $plaintext, $decrypted, '短 key/iv 補 0 後 round-trip 應成功' );
	}

	/**
	 * @testdox QueryTradeInfo CheckValue 用 IV=/Key= 鍵，且與 TradeSha / CheckCode 為不同雜湊
	 * @return void
	 */
	public function test_check_value_uses_iv_key_and_differs(): void {
		$crypto = new TradeInfoCrypto( self::HASH_KEY, self::HASH_IV );

		$cv       = $crypto->generate_check_value( 'MS123456', 'PC123', 1000 );
		$expected = \strtoupper(
			\hash(
				'sha256',
				'IV=' . self::HASH_IV . '&Amt=1000&MerchantID=MS123456&MerchantOrderNo=PC123&Key=' . self::HASH_KEY
			)
		);
		$this->assertSame( $expected, $cv, 'CheckValue 公式（IV/Key 鍵）不符' );
		$this->assertMatchesRegularExpression( '/^[0-9A-F]{64}$/', $cv, 'CheckValue 應為 64 字大寫' );

		// CheckValue 必須與 TradeSha、CheckCode 不同（三者不可混用）
		$hex        = $crypto->encrypt( 'Amt=1000' );
		$trade_sha  = $crypto->generate_trade_sha( $hex );
		$check_code = $crypto->generate_check_code(
			[ 'Amt' => 1000, 'MerchantID' => 'MS123456', 'MerchantOrderNo' => 'PC123', 'TradeNo' => 'TN1' ]
		);
		$this->assertNotSame( $trade_sha, $cv, 'CheckValue 不應等於 TradeSha' );
		$this->assertNotSame( $check_code, $cv, 'CheckValue 不應等於 CheckCode' );
	}
}
