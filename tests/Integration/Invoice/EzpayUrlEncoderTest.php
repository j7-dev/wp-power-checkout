<?php
/**
 * ezPay UrlEncoder 整合測試
 *
 * 驗證 PostData_ 組裝規則：
 *  - 中文值必須 rawurlencode（非 urlencode，空白→%20 非 +）
 *  - CarrierNum 前後不得含空白
 *  - 輸出為 query string 格式（key=value&key=value）
 *  - 組完後再交給 AesCrypto 加密
 *
 * 規格出處：ezpay-invoice skill references/concepts.md §CarrierNum + api-reference.md
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\UrlEncoder;
use Tests\Integration\TestCase;

/**
 * EzpayUrlEncoder 參數編碼測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 */
final class EzpayUrlEncoderTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_UrlEncoder_類別可實例化(): void {
		$encoder = new UrlEncoder();
		$this->assertInstanceOf( UrlEncoder::class, $encoder );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_encode_回傳字串(): void {
		$encoder = new UrlEncoder();
		$result  = $encoder->encode( [ 'RespondType' => 'JSON', 'Version' => '1.5' ] );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'RespondType=JSON', $result );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_中文ItemName用rawurlencode空白轉百分號20(): void {
		$encoder = new UrlEncoder();
		$result  = $encoder->encode( [ 'ItemName' => '測試 商品' ] );

		// rawurlencode 空白 → %20，不是 +
		$this->assertStringContainsString( '%20', $result, '空白應轉為 %20（rawurlencode）' );
		$this->assertStringNotContainsString( 'ItemName=測試', $result, '中文應被編碼' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_CarrierNum前後空白被trim(): void {
		$encoder = new UrlEncoder();
		// CarrierNum 含前後空白
		$result  = $encoder->encode( [ 'CarrierNum' => '  /ABC123  ' ] );

		$this->assertStringNotContainsString( 'CarrierNum=+', $result, 'CarrierNum 前後空白應被 trim' );
		$this->assertStringContainsString( 'CarrierNum=', $result );
		// 解析後值不含前後空白
		parse_str( $result, $parsed );
		$this->assertSame( '/ABC123', $parsed['CarrierNum'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_空陣列回傳空字串(): void {
		$encoder = new UrlEncoder();
		$result  = $encoder->encode( [] );

		$this->assertSame( '', $result );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_數值型態參數正確序列化(): void {
		$encoder = new UrlEncoder();
		$result  = $encoder->encode( [
			'TotalAmt' => 100,
			'TaxAmt'   => 5,
			'Amt'      => 95,
		] );

		parse_str( $result, $parsed );
		$this->assertSame( '100', $parsed['TotalAmt'] ?? '' );
		$this->assertSame( '5', $parsed['TaxAmt'] ?? '' );
		$this->assertSame( '95', $parsed['Amt'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_特殊字元在value中被正確編碼(): void {
		$encoder = new UrlEncoder();
		// & 和 = 是 query string 分隔符，在值中必須被編碼
		$result  = $encoder->encode( [ 'ItemName' => 'A&B=C' ] );

		parse_str( $result, $parsed );
		$this->assertSame( 'A&B=C', $parsed['ItemName'] ?? '', '& 和 = 在值中應被正確編碼後可還原' );
	}
}
