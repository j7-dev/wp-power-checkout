<?php
/**
 * Logistics 列舉（Enums）整合測試
 *
 * 驗證 Logistics domain 的 backed enum 值域，與「取件完成」判定集中於
 * LogisticsStatus::is_pickup_completed()（貨態碼集中此判定，不硬編完整表 — 計畫 T1）。
 *
 * 對應計畫第一階段步驟 2（Enums ×5）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsAccountType;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsStatus;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsSubType;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsTemperature;
use Tests\Integration\TestCase;

/**
 * Logistics Enums 測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsStatusTest extends TestCase {

	// ========== LogisticsStatus::is_pickup_completed ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_貨態碼2067為超商取件完成(): void {
		$this->assertTrue(
			LogisticsStatus::is_pickup_completed( '2067' ),
			'7-ELEVEN 取件完成碼 2067 應判定為取件完成'
		);
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_其他貨態碼不是取件完成(): void {
		// 300 = 物流單已建立 / 已出貨（代表性），非取件完成
		$this->assertFalse(
			LogisticsStatus::is_pickup_completed( '300' ),
			'已出貨碼 300 不應判定為取件完成'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_未知貨態碼不是取件完成(): void {
		$this->assertFalse(
			LogisticsStatus::is_pickup_completed( 'unknown_code' ),
			'未知貨態碼不應判定為取件完成'
		);
	}

	// ========== LogisticsSubType ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LogisticsSubType_backed_value(): void {
		$this->assertSame( 'FAMI', LogisticsSubType::FAMI->value );
		$this->assertSame( 'UNIMART', LogisticsSubType::UNIMART->value );
		$this->assertSame( 'HILIFE', LogisticsSubType::HILIFE->value );
		$this->assertSame( 'HOME', LogisticsSubType::HOME->value );
	}

	// ========== LogisticsAccountType ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LogisticsAccountType_backed_value(): void {
		$this->assertSame( 'b2c', LogisticsAccountType::B2C->value );
		$this->assertSame( 'c2c', LogisticsAccountType::C2C->value );
	}

	// ========== LogisticsTemperature ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LogisticsTemperature_backed_value(): void {
		$this->assertSame( '0001', LogisticsTemperature::NORMAL->value );
		$this->assertSame( '0002', LogisticsTemperature::REFRIGERATED->value );
		$this->assertSame( '0003', LogisticsTemperature::FROZEN->value );
	}

	// ========== LogisticsPaymentScenario ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_LogisticsPaymentScenario_backed_value(): void {
		$this->assertSame( 'online', LogisticsPaymentScenario::ONLINE->value );
		$this->assertSame( 'cod', LogisticsPaymentScenario::COD->value );
	}
}
