<?php
/**
 * PayNow 物流 Enum 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段，class 不存在時預期 class not found）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowDeliverMode
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticsStatus
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 3
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 0 EnumTest
 *   - woomp grounding：
 *       ../woomp/.../utils/class-paynow-shipping-logistic-service.php（01-06, 21-24 常數）
 *
 * PaynowLogisticService 值（10 值）：
 *   01=7-11 店到店, 02=7-11 大宗, 03=全家店到店, 04=全家大宗
 *   05=HiLife 店到店, 06=黑貓宅配
 *   21=7-11 交貨便冷凍C2C, 22=7-11 大宗冷凍
 *   23=全家店到店冷凍C2C, 24=全家大宗冷凍
 *   is_cvs(): 01-05 / 21-24 = true，06 = false
 *
 * PaynowDeliverMode 值（2 值）：
 *   01=COD 取貨付款, 02=取貨不付款
 *
 * PaynowLogisticsStatus 值（物流單狀態）：
 *   0=成立中, 1=無效；另有貨態碼常數
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowLogisticsEnumTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowDeliverMode;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticsStatus;
use Tests\Integration\TestCase;

/**
 * PayNow 物流 Enum 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsEnumTest extends TestCase {

	// ========== PaynowLogisticService 值域（Happy） ==========

	/**
	 * PaynowLogisticService 包含 10 個值（01-06 + 21-24）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_PaynowLogisticService_包含10個值(): void {
		$cases = PaynowLogisticService::cases();
		$this->assertCount( 10, $cases, 'PaynowLogisticService 應包含 10 個 case（01-06 + 21-24）' );
	}

	/**
	 * Seven (7-11 店到店) 值為 '01'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Seven值為01(): void {
		$this->assertSame( '01', PaynowLogisticService::Seven->value );
	}

	/**
	 * SevenBulk (7-11 大宗) 值為 '02'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_SevenBulk值為02(): void {
		$this->assertSame( '02', PaynowLogisticService::SevenBulk->value );
	}

	/**
	 * Fami (全家 店到店) 值為 '03'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Fami值為03(): void {
		$this->assertSame( '03', PaynowLogisticService::Fami->value );
	}

	/**
	 * FamiBulk (全家 大宗) 值為 '04'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_FamiBulk值為04(): void {
		$this->assertSame( '04', PaynowLogisticService::FamiBulk->value );
	}

	/**
	 * Hilife (HiLife 店到店) 值為 '05'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Hilife值為05(): void {
		$this->assertSame( '05', PaynowLogisticService::Hilife->value );
	}

	/**
	 * Tcat (黑貓宅配) 值為 '06'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Tcat值為06(): void {
		$this->assertSame( '06', PaynowLogisticService::Tcat->value );
	}

	/**
	 * SevenFrozenC2c (7-11 交貨便冷凍 C2C) 值為 '21'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_SevenFrozenC2c值為21(): void {
		$this->assertSame( '21', PaynowLogisticService::SevenFrozenC2c->value );
	}

	/**
	 * SevenFrozen (7-11 大宗冷凍) 值為 '22'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_SevenFrozen值為22(): void {
		$this->assertSame( '22', PaynowLogisticService::SevenFrozen->value );
	}

	/**
	 * FamiFrozenC2c (全家 店到店冷凍 C2C) 值為 '23'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_FamiFrozenC2c值為23(): void {
		$this->assertSame( '23', PaynowLogisticService::FamiFrozenC2c->value );
	}

	/**
	 * FamiFrozen (全家 大宗冷凍) 值為 '24'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_FamiFrozen值為24(): void {
		$this->assertSame( '24', PaynowLogisticService::FamiFrozen->value );
	}

	// ========== is_cvs() 分類（Happy） ==========

	/**
	 * Seven (01) is_cvs() = true（超商取貨）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Seven_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::Seven->is_cvs() );
	}

	/**
	 * SevenBulk (02) is_cvs() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_SevenBulk_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::SevenBulk->is_cvs() );
	}

	/**
	 * Fami (03) is_cvs() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Fami_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::Fami->is_cvs() );
	}

	/**
	 * FamiBulk (04) is_cvs() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_FamiBulk_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::FamiBulk->is_cvs() );
	}

	/**
	 * Hilife (05) is_cvs() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Hilife_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::Hilife->is_cvs() );
	}

	/**
	 * Tcat (06) is_cvs() = false（黑貓宅配不是超商取貨）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_Tcat_is_cvs為false(): void {
		$this->assertFalse( PaynowLogisticService::Tcat->is_cvs() );
	}

	/**
	 * SevenFrozenC2c (21) is_cvs() = true（冷凍超商也是超商取貨）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_SevenFrozenC2c_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::SevenFrozenC2c->is_cvs() );
	}

	/**
	 * FamiFrozen (24) is_cvs() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_FamiFrozen_is_cvs為true(): void {
		$this->assertTrue( PaynowLogisticService::FamiFrozen->is_cvs() );
	}

	/**
	 * tryFrom 能以字串值反查 case（API 回傳字串 '01' 可轉為 enum）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticService_tryFrom_以字串反查(): void {
		$case = PaynowLogisticService::tryFrom( '01' );
		$this->assertSame( PaynowLogisticService::Seven, $case );

		$case_tcat = PaynowLogisticService::tryFrom( '06' );
		$this->assertSame( PaynowLogisticService::Tcat, $case_tcat );

		$invalid = PaynowLogisticService::tryFrom( '99' );
		$this->assertNull( $invalid, '無效代碼應回傳 null' );
	}

	// ========== PaynowDeliverMode 值域（Happy） ==========

	/**
	 * PaynowDeliverMode 包含 2 個值（01=COD, 02=取貨不付款）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_PaynowDeliverMode_包含2個值(): void {
		$cases = PaynowDeliverMode::cases();
		$this->assertCount( 2, $cases, 'PaynowDeliverMode 應包含 2 個 case（01=COD, 02=取貨不付款）' );
	}

	/**
	 * Cod（取貨付款）值為 '01'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_DeliverMode_Cod值為01(): void {
		$this->assertSame( '01', PaynowDeliverMode::Cod->value );
	}

	/**
	 * NoCod（取貨不付款）值為 '02'
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_DeliverMode_NoCod值為02(): void {
		$this->assertSame( '02', PaynowDeliverMode::NoCod->value );
	}

	// ========== PaynowLogisticsStatus 值域（Happy） ==========

	/**
	 * PaynowLogisticsStatus 具備「成立中」與「無效」兩個主要值
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_PaynowLogisticsStatus_包含成立中與無效(): void {
		// 成立中 = '0'
		$active = PaynowLogisticsStatus::tryFrom( '0' );
		$this->assertNotNull( $active, 'PaynowLogisticsStatus 應有 value="0"（成立中）' );

		// 無效 = '1'
		$invalid = PaynowLogisticsStatus::tryFrom( '1' );
		$this->assertNotNull( $invalid, 'PaynowLogisticsStatus 應有 value="1"（無效）' );
	}

	/**
	 * 成立中 (Active/'0') is_active() = true
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticsStatus_Active_is_active為true(): void {
		$status = PaynowLogisticsStatus::Active;
		$this->assertSame( '0', $status->value );
		$this->assertTrue( $status->is_active() );
	}

	/**
	 * 無效 (Invalid/'1') is_active() = false
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_LogisticsStatus_Invalid_is_active為false(): void {
		$status = PaynowLogisticsStatus::Invalid;
		$this->assertSame( '1', $status->value );
		$this->assertFalse( $status->is_active() );
	}
}
