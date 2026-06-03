<?php
/**
 * StrHelper::trim_invisible / trim_invisible_deep 單元測試
 * 對應 Issue #16 ── 修剪所有「肉眼看不見」的前後字元
 *
 * 字元集合（與前端 trimInvisible 同步）：
 *   半形空白 (\x20) / Tab (\x09) / LF (\x0A) / CR (\x0D)
 *   垂直 Tab (\x0B) / Form Feed (\x0C)
 *   不換行空白 U+00A0 / 全形空白 U+3000
 *   零寬空白 U+200B / 零寬非連接 U+200C / 零寬連接 U+200D / BOM U+FEFF
 */

declare( strict_types=1 );

namespace Tests\Integration\Shared\Utils;

use J7\PowerCheckout\Shared\Utils\StrHelper;
use Tests\Integration\TestCase;

/**
 * StrHelper trim_invisible 測試類別
 *
 * @group integration
 * @group settings
 * @group trim
 */
final class StrHelperTrimInvisibleTest extends TestCase {

	// ========== 冒煙測試 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_trim_invisible_可呼叫且回傳字串(): void {
		$this->assertSame( 'foo', StrHelper::trim_invisible( '  foo  ' ) );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_trim_invisible_deep_可呼叫(): void {
		$this->assertSame( 'foo', StrHelper::trim_invisible_deep( '  foo  ' ) );
	}

	// ========== 半形空白與 ASCII 控制字元 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪前後半形空白(): void {
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( '  sk_live_abc  ' ) );
		$this->assertSame( 'merchant_xyz', StrHelper::trim_invisible( "merchant_xyz \t" ) );
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( "\nsk_live_abc\r\n" ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪Tab與換行(): void {
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( "\tsk_live_abc\t" ) );
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( "\nsk_live_abc\n" ) );
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( "\r\nsk_live_abc\r\n" ) );
	}

	// ========== Unicode 不可見字元 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪全形空白U3000(): void {
		$ideographic_space = "\u{3000}";
		$value             = $ideographic_space . 'sk_live_abc' . $ideographic_space;
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( $value ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪不換行空白U00A0(): void {
		$nbsp  = "\u{00A0}";
		$value = $nbsp . 'sk_live_abc' . $nbsp;
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( $value ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪零寬空白U200B(): void {
		$zwsp  = "\u{200B}";
		$value = $zwsp . 'sk_live_abc' . $zwsp;
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( $value ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪零寬非連接U200C與零寬連接U200D(): void {
		$zwnj  = "\u{200C}";
		$zwj   = "\u{200D}";
		$value = $zwnj . 'sk_live_abc' . $zwj;
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( $value ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪BOM_UFEFF(): void {
		$bom   = "\u{FEFF}";
		$value = $bom . 'sk_live_abc' . $bom;
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( $value ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_修剪混合多種不可見字元(): void {
		$value = " \u{3000}\u{200B}sk_live_abc\u{200C}\t";
		$this->assertSame( 'sk_live_abc', StrHelper::trim_invisible( $value ) );
	}

	// ========== 邊界 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_保留中間的不可見字元(): void {
		$this->assertSame( 'sk_live abc 123', StrHelper::trim_invisible( 'sk_live abc 123' ) );
		$middle = 'sk_live' . "\u{3000}" . 'abc' . "\u{200B}" . '123';
		$this->assertSame( $middle, StrHelper::trim_invisible( $middle ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_純不可見字元修剪後變成空字串(): void {
		$this->assertSame( '', StrHelper::trim_invisible( '    ' ) );
		$this->assertSame( '', StrHelper::trim_invisible( "\u{3000}\u{200B}\u{FEFF}" ) );
		$this->assertSame( '', StrHelper::trim_invisible( '' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_中文字符不會被誤刪(): void {
		$this->assertSame( '我的金流標題', StrHelper::trim_invisible( '  我的金流標題  ' ) );
		$this->assertSame( '統編 12345678', StrHelper::trim_invisible( "\u{3000}統編 12345678\u{3000}" ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_emoji與特殊符號不會被誤刪(): void {
		$value = '  💳 sk_live ABC-1!@#  ';
		$this->assertSame( '💳 sk_live ABC-1!@#', StrHelper::trim_invisible( $value ) );
	}

	// ========== trim_invisible_deep 遞迴 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_trim_invisible_deep_對陣列遞迴修剪字串元素(): void {
		$input    = [ '  CreditCard  ', ' LinePay ' ];
		$expected = [ 'CreditCard', 'LinePay' ];
		$this->assertSame( $expected, StrHelper::trim_invisible_deep( $input ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_trim_invisible_deep_對巢狀陣列遞迴(): void {
		$input    = [
			'apiKey'  => '  sk_live_abc  ',
			'methods' => [ '  CreditCard  ', ' LinePay ' ],
			'options' => [
				'CreditCard' => [
					'installmentCounts' => [ ' 0 ', ' 3 ', '6' ],
				],
			],
		];
		$expected = [
			'apiKey'  => 'sk_live_abc',
			'methods' => [ 'CreditCard', 'LinePay' ],
			'options' => [
				'CreditCard' => [
					'installmentCounts' => [ '0', '3', '6' ],
				],
			],
		];
		$this->assertSame( $expected, StrHelper::trim_invisible_deep( $input ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_trim_invisible_deep_保留非字串型別原值(): void {
		$input = [
			'min_amount' => 0,
			'max_price'  => 99.5,
			'enabled'    => true,
			'disabled'   => false,
			'optional'   => null,
			'name'       => '  hello  ',
		];

		$result = StrHelper::trim_invisible_deep( $input );

		$this->assertSame( 0, $result['min_amount'] );
		$this->assertSame( 99.5, $result['max_price'] );
		$this->assertTrue( $result['enabled'] );
		$this->assertFalse( $result['disabled'] );
		$this->assertNull( $result['optional'] );
		$this->assertSame( 'hello', $result['name'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_trim_invisible_deep_對非字串非陣列直接回傳原值(): void {
		$this->assertSame( 0, StrHelper::trim_invisible_deep( 0 ) );
		$this->assertSame( 1.23, StrHelper::trim_invisible_deep( 1.23 ) );
		$this->assertTrue( StrHelper::trim_invisible_deep( true ) );
		$this->assertNull( StrHelper::trim_invisible_deep( null ) );
	}
}
