<?php
/**
 * PayNow 電子發票 Enum 整合測試
 *
 * 驗證 PayNow 發票三個 Enum 的值域、標籤與結帳類型映射：
 *  - ECarrierType：None / PhoneBarCodeCarrier / EasyCardCarrier / CitizenDigitalCardNo / BuyerSno
 *  - ETaxType：SaleTax / FreeTax / ZeroTax / MixTax
 *  - EZeroTaxReason：全 10 個零稅率原因
 *  - 結帳 individual / company / donate → carrier_type 映射
 *
 * 規格出處：
 *  - paynow skill references/invoice-api.md §10（載具 / 課稅別 / 零稅率原因全表）
 *  - specs/features/invoice/paynow-invoice-issue.feature（載具映射場景）
 *  - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §B-Cycle 0
 *
 * ⚠️ 本測試為 Red 階段：引用的 class 尚未實作，執行結果應為「class not found」失敗。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ETaxType;
use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\EZeroTaxReason;
use Tests\Integration\TestCase;

/**
 * PayNow 電子發票 Enum 測試類別
 *
 * @group happy
 * @group invoice
 * @group paynow
 */
final class PaynowInvoiceEnumTest extends TestCase {

	// ========== ECarrierType 載具類型 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_None值為None(): void {
		$this->assertSame( 'None', ECarrierType::None->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_PhoneBarCodeCarrier值正確(): void {
		$this->assertSame( 'PhoneBarCodeCarrier', ECarrierType::PhoneBarCodeCarrier->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_EasyCardCarrier值正確(): void {
		$this->assertSame( 'EasyCardCarrier', ECarrierType::EasyCardCarrier->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_CitizenDigitalCardNo值正確(): void {
		$this->assertSame( 'CitizenDigitalCardNo', ECarrierType::CitizenDigitalCardNo->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_BuyerSno值正確(): void {
		$this->assertSame( 'BuyerSno', ECarrierType::BuyerSno->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_共五個case(): void {
		$cases = ECarrierType::cases();
		$this->assertCount( 5, $cases, 'ECarrierType 應有 5 個 case（None/PhoneBarCodeCarrier/EasyCardCarrier/CitizenDigitalCardNo/BuyerSno）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ECarrierType_各case有中文標籤(): void {
		foreach ( ECarrierType::cases() as $case ) {
			$label = $case->label();
			$this->assertIsString( $label, "{$case->name} 應有 label() 方法回傳字串" );
			$this->assertNotEmpty( $label, "{$case->name} 的 label() 不應為空" );
		}
	}

	// ========== ETaxType 課稅別 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ETaxType_SaleTax值正確(): void {
		$this->assertSame( 'SaleTax', ETaxType::SaleTax->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ETaxType_FreeTax值正確(): void {
		$this->assertSame( 'FreeTax', ETaxType::FreeTax->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ETaxType_ZeroTax值正確(): void {
		$this->assertSame( 'ZeroTax', ETaxType::ZeroTax->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ETaxType_MixTax值正確(): void {
		$this->assertSame( 'MixTax', ETaxType::MixTax->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_ETaxType_共四個case(): void {
		$cases = ETaxType::cases();
		$this->assertCount( 4, $cases, 'ETaxType 應有 4 個 case（SaleTax/FreeTax/ZeroTax/MixTax）' );
	}

	// ========== EZeroTaxReason 零稅率原因 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_EZeroTaxReason_None值正確(): void {
		$this->assertSame( 'None', EZeroTaxReason::None->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_EZeroTaxReason_ExportGoods值正確(): void {
		$this->assertSame( 'ExportGoods', EZeroTaxReason::ExportGoods->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_EZeroTaxReason_ExportLabor值正確(): void {
		$this->assertSame( 'ExportLabor', EZeroTaxReason::ExportLabor->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_EZeroTaxReason_至少有None與ExportGoods兩個常用case(): void {
		$cases  = EZeroTaxReason::cases();
		$values = \array_map( static fn( $c ) => $c->value, $cases );
		$this->assertContains( 'None', $values, 'EZeroTaxReason 應包含 None' );
		$this->assertContains( 'ExportGoods', $values, 'EZeroTaxReason 應包含 ExportGoods' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_EZeroTaxReason_至少有十個case(): void {
		$cases = EZeroTaxReason::cases();
		$this->assertGreaterThanOrEqual( 10, \count( $cases ), 'EZeroTaxReason 應至少有 10 個 case（對應官方零稅率原因全表）' );
	}

	// ========== 結帳類型 → carrier_type 映射 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_結帳individual_barcode映射到PhoneBarCodeCarrier(): void {
		// individual=barcode 結帳 → PayNow carrier_type=PhoneBarCodeCarrier
		// 依 feature §「首次開立 B2C 個人手機條碼載具發票成功」
		$carrier = ECarrierType::PhoneBarCodeCarrier;
		$this->assertSame( 'PhoneBarCodeCarrier', $carrier->value );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_結帳donate映射到捐贈模式carrier_type為None或空(): void {
		// 捐贈發票：carrier_type 留空（None）並帶 npoban
		// 依 feature §「首次開立捐贈發票成功」+ invoice-api §11.3
		$carrier = ECarrierType::None;
		$this->assertSame( 'None', $carrier->value, '捐贈發票的 carrier_type 應為 None（依 paynow skill invoice-api §11.3）' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_結帳company映射到carrier_type_None加統編(): void {
		// 統編（B2B）發票：carrier_type=None（不帶載具），帶 buyer.identifier
		$carrier = ECarrierType::None;
		$this->assertSame( 'None', $carrier->value, 'B2B 統編發票的 carrier_type 應為 None' );
	}
}
