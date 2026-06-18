<?php
/**
 * ErrorCode enum 單元測試（einvoice 導入第一階段：正規化錯誤模型地基）
 *
 * 對應 specs/features/invoice/invoice-error-model.feature「正規化 code 值域」。
 * 驗證：10 個 case 的 value 正確、to_http_status() 全 10 值映射正確、tryFrom 非法值回 null。
 */

declare( strict_types=1 );

namespace Tests\Integration\Shared\Errors;

use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\TestCase;

/**
 * ErrorCode 測試類別
 *
 * @group error
 * @group edge
 */
final class ErrorCodeTest extends TestCase {

	// ========== case value 正確 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_十個_case_的_value_等於_case_名字串(): void {
		$this->assertSame( 'AUTH', ErrorCode::AUTH->value );
		$this->assertSame( 'VALIDATION', ErrorCode::VALIDATION->value );
		$this->assertSame( 'NOT_FOUND', ErrorCode::NOT_FOUND->value );
		$this->assertSame( 'CONFLICT', ErrorCode::CONFLICT->value );
		$this->assertSame( 'NUMBER_EXHAUSTED', ErrorCode::NUMBER_EXHAUSTED->value );
		$this->assertSame( 'SIGNATURE', ErrorCode::SIGNATURE->value );
		$this->assertSame( 'UNSUPPORTED', ErrorCode::UNSUPPORTED->value );
		$this->assertSame( 'NETWORK', ErrorCode::NETWORK->value );
		$this->assertSame( 'PROVIDER', ErrorCode::PROVIDER->value );
		$this->assertSame( 'UNKNOWN', ErrorCode::UNKNOWN->value );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_剛好十個_case_不多不少(): void {
		$this->assertCount( 10, ErrorCode::cases() );
	}

	// ========== to_http_status() 全 10 值映射 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_四百零一系列認證錯誤(): void {
		$this->assertSame( 401, ErrorCode::AUTH->to_http_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_四百二十二驗證錯誤(): void {
		$this->assertSame( 422, ErrorCode::VALIDATION->to_http_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_四百零四查無(): void {
		$this->assertSame( 404, ErrorCode::NOT_FOUND->to_http_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_四百零九衝突類(): void {
		$this->assertSame( 409, ErrorCode::CONFLICT->to_http_status() );
		$this->assertSame( 409, ErrorCode::NUMBER_EXHAUSTED->to_http_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_四百用戶端錯誤類(): void {
		$this->assertSame( 400, ErrorCode::SIGNATURE->to_http_status() );
		$this->assertSame( 400, ErrorCode::UNSUPPORTED->to_http_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_五百零二上游錯誤類(): void {
		$this->assertSame( 502, ErrorCode::NETWORK->to_http_status() );
		$this->assertSame( 502, ErrorCode::PROVIDER->to_http_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_to_http_status_五百未知(): void {
		$this->assertSame( 500, ErrorCode::UNKNOWN->to_http_status() );
	}

	/**
	 * 一次遍歷全部 10 case，確保每個 case 的 HTTP 狀態碼皆與值域表一致（無漏網 case）。
	 *
	 * @test
	 * @group error
	 */
	public function test_to_http_status_全部十個_case_皆有對應映射(): void {
		$expected = [
			'AUTH'             => 401,
			'VALIDATION'       => 422,
			'NOT_FOUND'        => 404,
			'CONFLICT'         => 409,
			'NUMBER_EXHAUSTED' => 409,
			'SIGNATURE'        => 400,
			'UNSUPPORTED'      => 400,
			'NETWORK'          => 502,
			'PROVIDER'         => 502,
			'UNKNOWN'          => 500,
		];

		foreach ( ErrorCode::cases() as $code ) {
			$this->assertArrayHasKey(
				$code->value,
				$expected,
				"case {$code->value} 未列入預期映射表"
			);
			$this->assertSame(
				$expected[ $code->value ],
				$code->to_http_status(),
				"case {$code->value} 的 HTTP 狀態碼映射不符"
			);
		}
	}

	// ========== tryFrom 非法值 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_tryFrom_合法值回對應_case(): void {
		$this->assertSame( ErrorCode::AUTH, ErrorCode::tryFrom( 'AUTH' ) );
		$this->assertSame( ErrorCode::UNKNOWN, ErrorCode::tryFrom( 'UNKNOWN' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_tryFrom_非法值回_null(): void {
		$this->assertNull( ErrorCode::tryFrom( 'NOT_A_REAL_CODE' ) );
		$this->assertNull( ErrorCode::tryFrom( '' ) );
		$this->assertNull( ErrorCode::tryFrom( 'auth' ) ); // 大小寫敏感
	}
}
