<?php
/**
 * 發票 dispatch 級統一驗證層單元測試
 *
 * 測試 InvoiceParamsValidator::validate_for_dispatch()——各 provider issue() 第一步呼叫的
 * 跨 provider 一致執行期驗證。失敗回 NormalizedError（WP_Error，code=VALIDATION），不打第三方 API。
 *
 * 與既有 checkout 表單級 InvoiceParamsValidatorTest（throw \InvalidArgumentException）並存：
 *   - 表單級：驗 invoiceType / 載具格式 / 統編 8 碼格式 / 捐贈碼格式，throw
 *   - dispatch 級（本檔）：補三項不變式 UBN checksum / 載具捐贈互斥 / 金額守恆，回 WP_Error
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter InvoiceDispatchValidatorTest' 2>&1; echo "EXIT=$?"
 *
 * @group integration
 * @group invoice
 * @group error
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\InvoiceParamsValidator;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use Tests\Integration\TestCase;

/** 發票 dispatch 級驗證測試類別 */
final class InvoiceDispatchValidatorTest extends TestCase {

	// ========================================================================
	// 不變式 1：財政部統一編號（UBN）checksum
	// ========================================================================

	/**
	 * UBN 通過 checksum（sum=40，0-indexed 第 6 碼=5 走簡單規則 40%5===0）→ 驗證通過
	 *
	 * @test
	 * @group happy
	 */
	public function test_統編_04595257_通過checksum_回null(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'      => 'ezpay',
				'companyId'     => '04595257',
				'salesAmount'   => 952,
				'taxAmount'     => 48,
				'totalAmount'   => 1000,
			]
		);

		$this->assertNull( $result, 'UBN 04595257 通過財政部 checksum（sum=40），dispatch 驗證應回 null（通過）' );
	}

	/**
	 * UBN 8 碼但 checksum 不合法（sum=42，第 6 碼=7 特例 42%5≠0 且 43%5≠0）→ VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_統編_12345678_checksum不合法_回VALIDATION(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'      => 'ezpay',
				'companyId'     => '12345678',
				'salesAmount'   => 952,
				'taxAmount'     => 48,
				'totalAmount'   => 1000,
			]
		);

		$this->assertNormalizedValidationError( $result );
	}

	/**
	 * UBN 非 8 碼（含英文 / 長度錯）→ VALIDATION（dispatch 級即使被前置表單級漏放也須擋）
	 *
	 * @test
	 * @group error
	 */
	public function test_統編_非8碼_回VALIDATION(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'      => 'ezpay',
				'companyId'     => '1234567', // 7 碼
				'salesAmount'   => 952,
				'taxAmount'     => 48,
				'totalAmount'   => 1000,
			]
		);

		$this->assertNormalizedValidationError( $result );
	}

	/**
	 * B2C（無買方統編）→ 不驗 checksum，僅看其餘不變式（守恆通過）→ 回 null
	 *
	 * @test
	 * @group edge
	 */
	public function test_B2C無統編_不驗checksum_回null(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'      => 'ezpay',
				'companyId'     => '', // B2C：無統編
				'salesAmount'   => 1000,
				'taxAmount'     => 0,
				'totalAmount'   => 1000,
			]
		);

		$this->assertNull( $result, 'B2C 無統編時 dispatch 驗證不應驗 checksum，金額守恆下應回 null' );
	}

	/**
	 * 邊界：UBN 第 7 碼（0-indexed 6）為 7 的進位特例對照組
	 *
	 * 用 04595257（第 6 碼=5，sum=40）作為「非 7 特例」的合法對照，
	 * 並以 12345678（第 6 碼=7，sum=42）作為「7 特例但仍不合法」對照，
	 * 證明第 6 碼=7 時 (sum%5===0 || (sum+1)%5===0) 的進位分支確實生效。
	 *
	 * @test
	 * @group edge
	 */
	public function test_統編_第7碼為7的進位特例(): void {
		// 第 6 碼=7 但 sum=42 / 43 皆非 5 倍數 → 即使套用進位特例仍不合法
		$invalid_seven = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'companyId'   => '12345678',
				'salesAmount' => 1000,
				'taxAmount'   => 0,
				'totalAmount' => 1000,
			]
		);
		$this->assertNormalizedValidationError( $invalid_seven );

		// 對照：第 6 碼=5 走簡單規則 sum=40 → 合法
		$valid_non_seven = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'companyId'   => '04595257',
				'salesAmount' => 1000,
				'taxAmount'   => 0,
				'totalAmount' => 1000,
			]
		);
		$this->assertNull( $valid_non_seven, '04595257 sum=40 合法對照組應回 null' );
	}

	// ========================================================================
	// 不變式 2：載具 / 捐贈互斥
	// ========================================================================

	/**
	 * 只帶載具（無捐贈碼）→ 互斥規則通過 → 回 null
	 *
	 * @test
	 * @group happy
	 */
	public function test_只帶載具_互斥通過_回null(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'carrier'     => '/ABC1234',
				'donateCode'  => '',
				'salesAmount' => 1000,
				'taxAmount'   => 0,
				'totalAmount' => 1000,
			]
		);

		$this->assertNull( $result, '只帶載具時 dispatch 驗證應回 null（通過）' );
	}

	/**
	 * 只帶捐贈碼（無載具）→ 互斥規則通過 → 回 null
	 *
	 * @test
	 * @group happy
	 */
	public function test_只帶捐贈碼_互斥通過_回null(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'carrier'     => '',
				'donateCode'  => '7788',
				'salesAmount' => 1000,
				'taxAmount'   => 0,
				'totalAmount' => 1000,
			]
		);

		$this->assertNull( $result, '只帶捐贈碼時 dispatch 驗證應回 null（通過）' );
	}

	/**
	 * 同時帶載具與捐贈碼 → 互斥違反 → VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_同時帶載具與捐贈碼_回VALIDATION(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'carrier'     => '/ABC1234',
				'donateCode'  => '7788',
				'salesAmount' => 1000,
				'taxAmount'   => 0,
				'totalAmount' => 1000,
			]
		);

		$this->assertNormalizedValidationError( $result );
	}

	/**
	 * 跨 provider 一致：同一筆互斥違反參數對 Amego 與 ezPay 都失敗
	 *
	 * @test
	 * @group edge
	 */
	public function test_互斥違反_跨provider一致皆失敗(): void {
		$base = [
			'carrier'     => '/ABC1234',
			'donateCode'  => '7788',
			'salesAmount' => 1000,
			'taxAmount'   => 0,
			'totalAmount' => 1000,
		];

		$amego_result = InvoiceParamsValidator::validate_for_dispatch(
			array_merge( $base, [ 'provider' => 'amego' ] )
		);
		$ezpay_result = InvoiceParamsValidator::validate_for_dispatch(
			array_merge( $base, [ 'provider' => 'ezpay' ] )
		);

		$this->assertNormalizedValidationError( $amego_result );
		$this->assertNormalizedValidationError( $ezpay_result );
	}

	// ========================================================================
	// 不變式 3：金額守恆 salesAmount + taxAmount === totalAmount
	// ========================================================================

	/**
	 * 守恆（952 + 48 = 1000）→ 回 null
	 *
	 * @test
	 * @group happy
	 */
	public function test_金額守恆_952加48等於1000_回null(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'salesAmount' => 952,
				'taxAmount'   => 48,
				'totalAmount' => 1000,
			]
		);

		$this->assertNull( $result, '952 + 48 = 1000 守恆，dispatch 驗證應回 null' );
	}

	/**
	 * 不守恆（宣稱 total=1000 但 sales=900 + tax=50 = 950 ≠ 1000）→ VALIDATION
	 *
	 * @test
	 * @group error
	 */
	public function test_金額不守恆_900加50不等於宣稱的1000_回VALIDATION(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'salesAmount' => 900,
				'taxAmount'   => 50,
				'totalAmount' => 1000, // 實際 900+50=950，與宣稱 total 不符
			]
		);

		$this->assertNormalizedValidationError( $result );
	}

	// ========================================================================
	// 回傳型別契約
	// ========================================================================

	/**
	 * 失敗回傳的是 is_normalized_error()===true 且 code===VALIDATION 的 WP_Error
	 *
	 * @test
	 * @group error
	 */
	public function test_失敗回傳型別為正規化VALIDATION錯誤(): void {
		$result = InvoiceParamsValidator::validate_for_dispatch(
			[
				'provider'    => 'ezpay',
				'salesAmount' => 900,
				'taxAmount'   => 50,
				'totalAmount' => 1000,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertTrue(
			NormalizedError::is_normalized_error( $result ),
			'失敗回傳須為正規化錯誤（is_normalized_error 為 true）'
		);
		$this->assertSame(
			ErrorCode::VALIDATION,
			NormalizedError::get_code( $result ),
			'失敗回傳的正規化 code 須為 VALIDATION'
		);
		$this->assertSame(
			'ezpay',
			$result->get_error_data()['provider'] ?? null,
			'失敗 WP_Error 的 data 須帶 provider'
		);
	}

	// ========================================================================
	// 私有斷言 helper
	// ========================================================================

	/**
	 * 斷言回傳值為正規化 VALIDATION 錯誤
	 *
	 * @param mixed $result validate_for_dispatch 回傳值
	 */
	private function assertNormalizedValidationError( mixed $result ): void {
		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'dispatch 驗證失敗應回 WP_Error'
		);
		$this->assertTrue(
			NormalizedError::is_normalized_error( $result ),
			'回傳須通過 is_normalized_error 型別守衛'
		);
		$this->assertSame(
			ErrorCode::VALIDATION,
			NormalizedError::get_code( $result ),
			'dispatch 驗證失敗的正規化 code 須為 VALIDATION'
		);
	}
}
