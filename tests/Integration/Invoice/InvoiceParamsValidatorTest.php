<?php
/**
 * 發票參數後端驗證 + sanitize 單元測試（block 結帳發票表單後端第二道防線）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     env API_MODE=mock vendor/bin/phpunit --filter InvoiceParamsValidatorTest 2>&1; echo "EXIT=$?"
 *
 * @group integration
 * @group invoice
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\InvoiceParamsValidator;
use Tests\Integration\TestCase;

/** 發票參數驗證測試類別 */
final class InvoiceParamsValidatorTest extends TestCase {

	// ========== happy ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_個人雲端發票_通過且清空多餘欄位(): void {
		$result = InvoiceParamsValidator::validate(
			[
				'provider'    => 'ecpay',
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
				'carrier'     => '/SHOULD_CLEAR', // 雲端不需載具，應清空
				'companyId'   => '12345678',      // 應清空
			]
		);
		$this->assertSame( 'individual', $result['invoiceType'] );
		$this->assertSame( 'cloud', $result['individual'] );
		$this->assertSame( '', $result['carrier'], '雲端發票載具應清空' );
		$this->assertSame( '', $result['companyId'], '個人發票統編應清空' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_自然人憑證_格式正確通過(): void {
		$result = InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'moica',
				'moica'       => 'AB12345678901234',
			]
		);
		$this->assertSame( 'moica', $result['individual'] );
		$this->assertSame( 'AB12345678901234', $result['moica'] );
		$this->assertSame( '', $result['carrier'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_sanitize_去除html標籤與前後空白(): void {
		$result = InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyId'   => '12345678',
				'companyName' => '  <script>X</script>測試公司  ',
			]
		);
		// sanitize_text_field 去標籤 + trim
		$this->assertStringNotContainsString( '<script>', $result['companyName'] );
		$this->assertStringNotContainsString( '  ', $result['companyName'] );
	}

	// ========== error path ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_缺provider_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'invoiceType' => 'donate',
				'donateCode'  => '123',
			]
			);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_發票類型不合法_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'xxx',
			]
			);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_手機條碼格式錯誤_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'barcode',
				'carrier'     => 'NO_SLASH',
			]
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_自然人憑證格式錯誤_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'individual',
				'individual'  => 'moica',
				'moica'       => 'X1', // 太短
			]
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_統編非8碼_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyId'   => '1234567', // 7 碼
				'companyName' => 'X',
			]
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_公司名稱空白_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'company',
				'companyId'   => '12345678',
				'companyName' => '',
			]
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_捐贈碼非數字_拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		InvoiceParamsValidator::validate(
			[
				'provider'    => 'amego',
				'invoiceType' => 'donate',
				'donateCode'  => 'ABC',
			]
		);
	}
}
