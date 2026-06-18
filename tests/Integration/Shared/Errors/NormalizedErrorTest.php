<?php
/**
 * NormalizedError factory + type guard 單元測試（einvoice 導入第一階段）
 *
 * 對應 specs/features/invoice/invoice-error-model.feature
 *   「後置（回應）- 開立失敗時回傳 WP_Error 而非空陣列」
 *   「型別守衛 - 提供判斷 WP_Error 是否為正規化發票錯誤的方法」。
 *
 * 驗證：
 *   - from() 建出的 WP_Error 的 code = enum value、message 正確、$data 四鍵齊全
 *   - is_normalized_error() 對正規化 WP_Error 回 true、裸 WP_Error 回 false、array/null 回 false
 *   - get_code() / get_raw_code() round-trip
 */

declare( strict_types=1 );

namespace Tests\Integration\Shared\Errors;

use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use Tests\Integration\TestCase;

/**
 * NormalizedError 測試類別
 *
 * @group error
 * @group edge
 */
final class NormalizedErrorTest extends TestCase {

	// ========== from() 建構契約 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_from_回傳_WP_Error_實例(): void {
		$error = NormalizedError::from( ErrorCode::VALIDATION, '驗證失敗' );
		$this->assertInstanceOf( \WP_Error::class, $error );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_from_的_code_等於_enum_value(): void {
		$error = NormalizedError::from( ErrorCode::AUTH, '金鑰錯誤' );
		$this->assertSame( 'AUTH', $error->get_error_code() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_from_的_message_正確(): void {
		$error = NormalizedError::from( ErrorCode::NETWORK, '連線逾時' );
		$this->assertSame( '連線逾時', $error->get_error_message() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_from_的_data_含四個固定鍵(): void {
		$error = NormalizedError::from( ErrorCode::CONFLICT, '狀態衝突' );
		$data  = $error->get_error_data();

		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'raw_code', $data );
		$this->assertArrayHasKey( 'raw_message', $data );
		$this->assertArrayHasKey( 'provider', $data );
		$this->assertArrayHasKey( 'raw', $data );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_from_未帶_context_時四鍵皆為_null(): void {
		$error = NormalizedError::from( ErrorCode::UNKNOWN, '未知錯誤' );
		$data  = $error->get_error_data();

		$this->assertNull( $data['raw_code'] );
		$this->assertNull( $data['raw_message'] );
		$this->assertNull( $data['provider'] );
		$this->assertNull( $data['raw'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_from_帶完整_context_時四鍵正確填入(): void {
		$raw   = [ 'Status' => 'ERROR', 'Message' => 'LIB10007' ];
		$error = NormalizedError::from(
			ErrorCode::CONFLICT,
			'已開折讓',
			[
				'raw_code'    => 'LIB10007',
				'raw_message' => '已開立折讓的發票無法作廢',
				'provider'    => 'ezpay',
				'raw'         => $raw,
			]
		);
		$data = $error->get_error_data();

		$this->assertSame( 'LIB10007', $data['raw_code'] );
		$this->assertSame( '已開立折讓的發票無法作廢', $data['raw_message'] );
		$this->assertSame( 'ezpay', $data['provider'] );
		$this->assertSame( $raw, $data['raw'] );
	}

	/**
	 * context 帶了非預期的額外鍵時，$data 仍只保留四個固定鍵（結構固定，禁止走樣）。
	 *
	 * @test
	 * @group edge
	 */
	public function test_from_忽略_context_中的非預期鍵(): void {
		$error = NormalizedError::from(
			ErrorCode::PROVIDER,
			'未分類錯誤',
			[
				'raw_code' => 'LIB99999',
				'provider' => 'ezpay',
				'unwanted' => 'should_be_dropped',
				'http'     => 500,
			]
		);
		$data = $error->get_error_data();

		$this->assertSame( [ 'raw_code', 'raw_message', 'provider', 'raw' ], array_keys( $data ) );
		$this->assertArrayNotHasKey( 'unwanted', $data );
		$this->assertArrayNotHasKey( 'http', $data );
		$this->assertSame( 'LIB99999', $data['raw_code'] );
		$this->assertNull( $data['raw_message'] );
	}

	// ========== is_normalized_error() type guard ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_is_normalized_error_對正規化_WP_Error_回_true(): void {
		$error = NormalizedError::from( ErrorCode::SIGNATURE, '驗章失敗' );
		$this->assertTrue( NormalizedError::is_normalized_error( $error ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_is_normalized_error_對裸_WP_Error_回_false(): void {
		$bare = new \WP_Error( 'foo', '一般錯誤' );
		$this->assertFalse( NormalizedError::is_normalized_error( $bare ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_is_normalized_error_對_array_回_false(): void {
		$this->assertFalse( NormalizedError::is_normalized_error( [ 'invoice_number' => 'DS123' ] ) );
		$this->assertFalse( NormalizedError::is_normalized_error( [] ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_is_normalized_error_對_null_回_false(): void {
		$this->assertFalse( NormalizedError::is_normalized_error( null ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_is_normalized_error_對純量回_false(): void {
		$this->assertFalse( NormalizedError::is_normalized_error( 'AUTH' ) );
		$this->assertFalse( NormalizedError::is_normalized_error( 123 ) );
		$this->assertFalse( NormalizedError::is_normalized_error( true ) );
	}

	// ========== getter round-trip ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_get_code_取回對應_enum_case(): void {
		$error = NormalizedError::from( ErrorCode::NOT_FOUND, '查無發票' );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $error ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_get_code_對裸_WP_Error_回_null(): void {
		$bare = new \WP_Error( 'some_legacy_code', '舊式錯誤' );
		$this->assertNull( NormalizedError::get_code( $bare ) );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_get_raw_code_取回原始錯誤碼(): void {
		$error = NormalizedError::from(
			ErrorCode::AUTH,
			'金鑰錯誤',
			[ 'raw_code' => 'KEY10002' ]
		);
		$this->assertSame( 'KEY10002', NormalizedError::get_raw_code( $error ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_get_raw_code_無原始碼時回_null(): void {
		$error = NormalizedError::from( ErrorCode::VALIDATION, '驗證失敗' );
		$this->assertNull( NormalizedError::get_raw_code( $error ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_get_raw_code_對裸_WP_Error_回_null(): void {
		$bare = new \WP_Error( 'foo', '一般錯誤' );
		$this->assertNull( NormalizedError::get_raw_code( $bare ) );
	}

	/**
	 * 完整 round-trip：from() 建構 → type guard 辨識 → 取回 code 與 raw_code（對應 feature 型別守衛場景）。
	 *
	 * @test
	 * @group error
	 */
	public function test_完整_round_trip_建構辨識取值(): void {
		$error = NormalizedError::from(
			ErrorCode::PROVIDER,
			'provider 未分類錯誤',
			[
				'raw_code'    => 'LIB99999',
				'raw_message' => '未涵蓋的錯誤碼',
				'provider'    => 'ezpay',
			]
		);

		// type guard 辨識
		$this->assertTrue( NormalizedError::is_normalized_error( $error ) );

		// 取出正規化 code 與 raw_code
		$this->assertSame( ErrorCode::PROVIDER, NormalizedError::get_code( $error ) );
		$this->assertSame( 'LIB99999', NormalizedError::get_raw_code( $error ) );
	}
}
