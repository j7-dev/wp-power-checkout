<?php
/**
 * 綠界 CheckMacValue / UrlEncoder 整合測試
 * 以綠界官方黃金 test vector 驗證搬遷後的演算法正確性
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\UrlEncoder;
use Tests\Integration\TestCase;

/**
 * 綠界 CheckMacValue 測試類別
 *
 * @group integration
 * @group payment
 * @group ecpay
 */
final class EcpayCheckMacValueTest extends TestCase {

	/** @var string 綠界 AIO 測試環境 HashKey */
	private const HASH_KEY = 'pwFHCqoQZGmho4w6';

	/** @var string 綠界 AIO 測試環境 HashIV */
	private const HASH_IV = 'EkRm7iFT261dpevs';

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_CheckMacValue_可以產生大寫hex字串(): void {
		// Given: 一組最小參數
		$args = [
			'MerchantID'  => '3002607',
			'TotalAmount' => '100',
		];

		// When: 產生 CheckMacValue
		$cmv = CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV );

		// Then: 為 64 字元大寫 hex（SHA256）
		$this->assertMatchesRegularExpression( '/^[A-F0-9]{64}$/', $cmv );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * 綠界官方黃金 test vector — AIO 金流 SHA256 基線
	 *
	 * @test
	 * @group happy
	 */
	public function test_官方黃金vector_AIO金流SHA256基線(): void {
		// Given: 綠界官方文件範例參數（test-vectors/checkmacvalue.json 第一筆）
		$args = [
			'MerchantID'        => '3002607',
			'MerchantTradeNo'   => 'Test1234567890',
			'MerchantTradeDate' => '2025/01/01 12:00:00',
			'PaymentType'       => 'aio',
			'TotalAmount'       => '100',
			'TradeDesc'         => '測試',
			'ItemName'          => '測試商品',
			'ReturnURL'         => 'https://example.com/notify',
			'ChoosePayment'     => 'ALL',
			'EncryptType'       => '1',
		];

		// When: 以官方測試帳號 HashKey / HashIV 計算
		$cmv = CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV, 'sha256' );

		// Then: 與官方預期值一致
		$this->assertSame(
			'291CBA324D31FB5A4BBBFDF2CFE5D32598524753AFD4959C3BF590C5B2F57FB2',
			$cmv
		);
	}

	/**
	 * 黃金 vector — 特殊字元 ' 與 ~
	 *
	 * @test
	 * @group happy
	 */
	public function test_官方黃金vector_特殊字元處理(): void {
		// Given & When & Then: 單引號
		$this->assertSame(
			'CF0A3D4901D99459D8641516EC57210700E8A5C9AB26B1D021301E9CB93EF78D',
			CheckMacValueService::get_check_value(
				[
					'MerchantID'  => '3002607',
					'ItemName'    => "Tom's Shop",
					'TotalAmount' => '100',
				],
				self::HASH_KEY,
				self::HASH_IV
			)
		);

		// 波浪號 ~
		$this->assertSame(
			'CEEAE01D2F9A8E74D4AC0DCE7735B046D73F35A5EC99558A31A2EE03159DA1C9',
			CheckMacValueService::get_check_value(
				[
					'MerchantID'  => '3002607',
					'ItemName'    => 'Test~Product',
					'TotalAmount' => '200',
				],
				self::HASH_KEY,
				self::HASH_IV
			)
		);
	}

	/**
	 * 黃金 vector — 空格必須編碼為 + 而非 %20
	 *
	 * @test
	 * @group happy
	 */
	public function test_官方黃金vector_空格編碼為加號(): void {
		$cmv = CheckMacValueService::get_check_value(
			[
				'MerchantID'  => '3002607',
				'ItemName'    => 'My Test Product',
				'TotalAmount' => '300',
			],
			self::HASH_KEY,
			self::HASH_IV
		);

		// Then: 正確值（空格 → +）
		$this->assertSame( '7712A5E6EDC3B57086063C88568084C66CE882A21D40E74DE5ACA3B478C6F316', $cmv );
		// 不應為空格 → %20 的錯誤值
		$this->assertNotSame( '13F7A6B69BF856B5203212AC5F3202B6140D8E2B4316A62851712BF2AF7812D0', $cmv );
	}

	/**
	 * 黃金 vector — Callback 驗證（CheckMacValue 欄位本身不參與計算）
	 *
	 * @test
	 * @group happy
	 */
	public function test_官方黃金vector_Callback驗證且忽略既有CheckMacValue(): void {
		$args = [
			'MerchantID'      => '3002607',
			'MerchantTradeNo' => 'Test1234567890',
			'RtnCode'         => '1',
			'RtnMsg'          => 'Succeeded',
			'TradeNo'         => '2301011234567890',
			'TradeAmt'        => '100',
			'PaymentDate'     => '2025/01/01 12:05:00',
			'PaymentType'     => 'Credit_CreditCard',
			'TradeDate'       => '2025/01/01 12:00:00',
			'SimulatePaid'    => '0',
			// 故意塞入一個舊的 CheckMacValue，驗證它被忽略
			'CheckMacValue'   => 'SHOULD_BE_IGNORED',
		];

		$cmv = CheckMacValueService::get_check_value( $args, self::HASH_KEY, self::HASH_IV );

		$this->assertSame( '2AB536D86AFF8E1086744D59175040A32538C96B1C28C4135B551BD728E913B8', $cmv );
	}

	/**
	 * 黃金 vector — MD5 演算法（國內物流）
	 *
	 * @test
	 * @group happy
	 */
	public function test_官方黃金vector_MD5演算法(): void {
		$args = [
			'MerchantID'        => '2000132',
			'LogisticsType'     => 'CVS',
			'LogisticsSubType'  => 'UNIMART',
			'MerchantTradeDate' => '2025/01/01 12:00:00',
		];

		$cmv = CheckMacValueService::get_check_value( $args, '5294y06JbISpM5x9', 'v77hoKGq4kWxNNIS', 'md5' );

		$this->assertSame( '545E6146FD45BDA683C88454DB34CE8D', $cmv );
	}

	// ========== UrlEncoder 已知映射 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_UrlEncoder_保留11個還原字元(): void {
		// 綠界 .NET urlencode 會將這些字元的編碼還原為原字元
		$this->assertSame( '-_.*!()', UrlEncoder::encode( '-_.*!()' ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_UrlEncoder_空格編碼為加號(): void {
		$this->assertSame( 'a+b', UrlEncoder::encode( 'a b' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_UrlEncoder_波浪號編碼為百分7e(): void {
		// PHP urlencode 將 ~ 編碼為 %7E，且不在 11 個還原字元內，故保留編碼形式
		$this->assertSame( '%7E', UrlEncoder::encode( '~' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_UrlEncoder_中文以百分號UTF8編碼(): void {
		$this->assertSame( '%E6%B8%AC%E8%A9%A6', UrlEncoder::encode( '測試' ) );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_不支援的雜湊演算法拋出例外(): void {
		$this->expectException( \InvalidArgumentException::class );

		CheckMacValueService::get_check_value(
			[ 'MerchantID' => '3002607' ],
			self::HASH_KEY,
			self::HASH_IV,
			'sha1' // 不支援
		);
	}
}
