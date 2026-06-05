<?php
/**
 * 光貿中獎查詢 / 字軌取號（B6）整合測試
 *
 * 涵蓋 ApiClient::query_lottery()（lottery_status）/ get_track()（track_get）唯讀能力，
 * 以及對應 DTO 的參數驗證。MOCK 模式下走固定 fixture，不打真 API。
 *
 * 範圍：能力層（client + DTO）已完成；後台 UI / REST 整合標記為後續。
 *
 * API 出處：
 *  - 中獎查詢：amego-invoice skill §中獎發票 /json/lottery_status（Year + Period 0~5）
 *  - 字軌取號：amego-invoice skill §字軌取號 /json/track_get（Year + Period + Book）
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\LotteryQueryParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\TrackGetParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 光貿中獎 / 字軌測試類別
 *
 * @group integration
 * @group invoice
 * @group amego
 * @group query
 */
final class AmegoLotteryTrackTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
		$this->enable_provider( AmegoProvider::ID, [ 'mode' => 'test' ] );
	}

	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		parent::tear_down();
	}

	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( AmegoSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	// ========== DTO 驗證 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_lottery_期別超出範圍拋例外(): void {
		$this->expectException( \Exception::class );
		LotteryQueryParamsDTO::create_params( 2026, 9 );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_track_本數小於1拋例外(): void {
		$this->expectException( \Exception::class );
		TrackGetParamsDTO::create_params( 2026, 0, 0 );
	}

	// ========== 快樂路徑（mock）==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_query_lottery_回傳陣列(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester );

		$params = LotteryQueryParamsDTO::create_params( 2026, 0 );
		$result = $client->query_lottery( $params );

		$this->assertIsArray( $result );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_track_回傳字軌起訖(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester );

		$params = TrackGetParamsDTO::create_params( 2026, 0, 1 );
		$result = $client->get_track( $params );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'code', $result );
		$this->assertSame( 'AG', $result['code'] ?? '' );
	}
}
