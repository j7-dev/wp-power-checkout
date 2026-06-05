<?php
/**
 * PAYUNi 統一金流物流 callback 整合測試（貨態 Notify + 門市回呼）
 *
 * ★ PAYUNi 鐵律（與綠界 AES-JSON 三層不同）：貨態 Notify 一律回 HTTP 200 + 純文字 "OK"，
 *   即使 HashInfo 驗簽失敗 / 例外也回 200，避免 PAYUNi 重送風暴。
 *
 * 核心斷言：
 *   - 合法 Notify（HashInfo 正確 + MerID 相符 + ApiType=ShipStatus）→ 200 + 寫入貨態 meta。
 *   - HashInfo 驗簽失敗 → 200，不寫 meta。
 *   - MerID 不符 → 200，不寫 meta。
 *   - 重複 Notify → 冪等（不重複處理）。
 *   - COD + 取貨完成（11）→ collection_paid=yes。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PayuniLogisticsCallbackTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi callback 測試類別
 *
 * @group integration
 * @group logistics
 * @group payuni
 */
final class PayuniLogisticsCallbackTest extends TestCase {

	private const MER_ID   = 'S0000000000';
	private const HASH_KEY = '12345678901234567890123456789012';
	private const HASH_IV  = '1234567890123456';

	private PayuniCrypto $crypto;

	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		$this->crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		PayuniLogisticsSettingsDTO::reset();
		$this->enable_provider(
			PayuniLogisticsProvider::ID,
			[
				'mode'           => 'test',
				'mer_id'         => self::MER_ID,
				'hash_key'       => self::HASH_KEY,
				'hash_iv'        => self::HASH_IV,
				'notify_url'     => 'https://example.com/wp-json/power-checkout/payuni/logistics/status-notify',
				'map_return_url' => 'https://example.com/wp-json/power-checkout/payuni/logistics/map-callback',
			]
		);
		PayuniLogisticsSettingsDTO::reset();
	}

	public function tear_down(): void {
		\putenv( 'API_MODE=mock' );
		PayuniLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PayuniLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立貨態 Notify request（4 欄位 Form POST）
	 *
	 * @param string      $mer_id        MerID
	 * @param string      $ship_trade_no UNi 物流序號
	 * @param string      $ship_status   貨態碼
	 * @param string|null $hash_override 覆寫 HashInfo（測驗簽失敗）
	 * @return \WP_REST_Request
	 */
	private function build_notify_request(
		string $mer_id,
		string $ship_trade_no,
		string $ship_status,
		?string $hash_override = null
	): \WP_REST_Request {
		$inner = [
			'Status'         => 'SUCCESS',
			'Message'        => "貨態狀態處理成功({$ship_status})",
			'MerID'          => $mer_id,
			'ShipTradeNo'    => $ship_trade_no,
			'LgsType'        => 'B2C',
			'ShipStatus'     => $ship_status,
			'ShipStatusDesc' => '配送中',
			'ApiType'        => 'ShipStatus',
		];

		$encrypt_info = $this->crypto->encrypt( $inner );
		$hash_info    = $hash_override ?? $this->crypto->hash_info( $encrypt_info );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/logistics/status-notify' );
		$request->set_body_params(
			[
				'MerID'       => $mer_id,
				'Version'     => '1.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => $hash_info,
			]
		);
		return $request;
	}

	// ========== 合法貨態 Notify ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_貨態Notify_合法時回200並寫入貨態(): void {
		// Given: 訂單已成立物流單
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP1234567890' );

		$request = $this->build_notify_request( self::MER_ID, 'SHIP1234567890', '31' );

		// When
		$response = PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback( $request );

		// Then: 回 200
		$this->assertSame( 200, $response->get_status() );
		// 貨態已寫入
		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '31', $fresh->get_status() );
	}

	// ========== HashInfo 驗簽失敗 ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_貨態Notify_HashInfo驗簽失敗時回200且不寫meta(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP1234567890' );

		// 竄改 HashInfo
		$request = $this->build_notify_request( self::MER_ID, 'SHIP1234567890', '31', 'TAMPERED' );

		$response = PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback( $request );

		// 仍回 200（避免重送風暴）
		$this->assertSame( 200, $response->get_status() );
		// 不寫 meta
		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh->get_status() );
	}

	// ========== MerID 不符 ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_貨態Notify_MerID不符時回200且不寫meta(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP1234567890' );

		// 偽造他人 MerID（但用正確金鑰簽，模擬內層 MerID 與本商店不符）
		$request = $this->build_notify_request( 'S9999999999', 'SHIP1234567890', '31' );

		$response = PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh->get_status() );
	}

	// ========== 冪等（重複 Notify） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_貨態Notify_重複貨態冪等不重複處理(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP1234567890' );

		// 連送兩次相同貨態
		PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback(
			$this->build_notify_request( self::MER_ID, 'SHIP1234567890', '31' )
		);
		$response = PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback(
			$this->build_notify_request( self::MER_ID, 'SHIP1234567890', '31' )
		);

		// 第二次仍回 200，且已處理碼陣列僅一筆
		$this->assertSame( 200, $response->get_status() );
		$fresh     = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$processed = $fresh->get_processed_status();
		$this->assertCount( 1, $processed );
	}

	// ========== COD 取貨完成 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_貨態Notify_COD取貨完成11時標記collection_paid(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( 'SHIP1234567890' );
		$meta->update_payment_scenario( 'cod' );

		// 貨態 11 = 已取貨
		$request  = $this->build_notify_request( self::MER_ID, 'SHIP1234567890', '11' );
		$response = PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$fresh = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertTrue( $fresh->is_collection_paid() );
	}

	// ========== 查無訂單 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_貨態Notify_查無訂單時回200不報錯(): void {
		// 不建立任何有此 ref 的訂單
		$request  = $this->build_notify_request( self::MER_ID, 'NONEXISTENT_REF', '31' );
		$response = PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	// ========== Notify URL helper ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_status_notify_url含payuni命名空間(): void {
		$url = PayuniLogisticsCallback::get_status_notify_url();
		$this->assertStringContainsString( 'power-checkout/payuni/logistics/status-notify', $url );
	}
}
