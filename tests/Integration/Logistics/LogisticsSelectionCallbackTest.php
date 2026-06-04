<?php
/**
 * 綠界全方位物流 選店回呼（ClientReplyURL）整合測試（階段三 — Red Gate）
 *
 * 對應 logistics-selection-callback.feature 的三個場景：
 *   1. ResultData 為空 → 記 log，回應處理完成（不拋 HTTP 500）
 *   2. ResultData 解密失敗 → 記 log，回應
 *   3. 成功 → 解 ResultData，寫 _pc_logistics_temp_id / store_id / store_name / store_addr
 *
 * 執行指令：
 *   npx wp-env run tests-cli \
 *     --env-cwd=wp-content/plugins/power-checkout \
 *     vendor/bin/phpunit --filter LogisticsSelectionCallbackTest 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 選店回呼（ClientReplyURL）測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsSelectionCallbackTest extends TestCase {

	// ---- B2C 測試帳號（selection callback 使用 account_type 對應憑證解密） ----
	private const B2C_MERCHANT_ID = '2000132';
	private const B2C_HASH_KEY    = '5294y06JbISpM5x9';
	private const B2C_HASH_IV     = 'v77hoKGq4kWxNNIS';

	/** @var AesCrypto 用於建立正確加密 ResultData */
	private AesCrypto $b2c_crypto;

	public function set_up(): void {
		parent::set_up();
		$this->b2c_crypto = new AesCrypto( self::B2C_HASH_KEY, self::B2C_HASH_IV );
		EcpayLogisticsSettingsDTO::reset();
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			[
				'mode'             => 'test',
				'account_type'     => 'b2c',
				'b2c_merchant_id'  => self::B2C_MERCHANT_ID,
				'b2c_hash_key'     => self::B2C_HASH_KEY,
				'b2c_hash_iv'      => self::B2C_HASH_IV,
				'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
				'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
			]
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	public function tear_down(): void {
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立合法的 ResultData（B2C AES 加密）
	 *
	 * @param array<string, mixed> $store_data 門市資訊
	 * @return string AES 密文
	 */
	private function build_result_data( array $store_data ): string {
		return $this->b2c_crypto->encrypt( $store_data );
	}

	/**
	 * 建立選店回呼 WP_REST_Request（Form POST 模擬）
	 *
	 * @param array<string, mixed> $body Form POST body
	 * @return \WP_REST_Request
	 */
	private function make_selection_request( array $body ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/logistics/selection-callback' );
		$request->set_body_params( $body );
		return $request;
	}

	/**
	 * 取得選店回呼 handler
	 *
	 * @return LogisticsCallback
	 */
	private function get_callback(): LogisticsCallback {
		return LogisticsCallback::instance();
	}

	// ========== 錯誤路徑 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_選店回呼_ResultData為空時記log並回應不拋500(): void {
		// Given: 空 ResultData
		$request = $this->make_selection_request( [ 'ResultData' => '' ] );

		// When
		$response = null;
		$threw    = false;
		try {
			$response = $this->get_callback()->post_logistics_selection_callback( $request );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		// Then: 不應拋例外（回 HTTP 200 正常回應）
		$this->assertFalse( $threw, 'ResultData 為空時不應拋例外' );
		$this->assertNotNull( $response, '應有 WP_REST_Response 回應' );
		$this->assertSame( 200, $response->get_status(), '應回 HTTP 200' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店回呼_ResultData解密失敗時記log並回應(): void {
		// Given: 以錯誤 HashKey 加密的 ResultData（無法被正確帳號解密）
		$wrong_crypto   = new AesCrypto( 'WRONGKEY12345678', 'WRONGIV123456789' );
		$invalid_result_data = $wrong_crypto->encrypt(
			[
				'TempLogisticsID' => '9999',
				'CVSStoreID'      => 'FAKE001',
			]
		);

		$order   = $this->create_wc_order( [ 'status' => 'pending' ] );
		$request = $this->make_selection_request(
			[
				'ResultData' => $invalid_result_data,
				'order_id'   => $order->get_id(),
			]
		);

		// When
		$response = null;
		$threw    = false;
		try {
			$response = $this->get_callback()->post_logistics_selection_callback( $request );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		// Then: 解密失敗不應拋例外（記 log 並回應）
		$this->assertFalse( $threw, '解密失敗時不應拋例外' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response->get_status() );

		// Then: 訂單 meta 不應被寫入垃圾資料
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh_meta->get_temp_id(), 'temp_id 不應被寫入（解密失敗）' );
	}

	// ========== 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_選店回呼_成功解密後寫入門市meta四欄(): void {
		// Given: 正確加密的 ResultData，含 TempLogisticsID + 門市資訊
		$order        = $this->create_wc_order( [ 'status' => 'pending' ] );
		$result_data  = $this->build_result_data(
			[
				'TempLogisticsID' => '2264',
				'CVSStoreID'      => '991182',
				'CVSStoreName'    => '全家測試門市',
				'CVSAddress'      => '台北市中山區測試路1號',
				'LogisticsSubType' => 'FAMI',
			]
		);

		$request = $this->make_selection_request(
			[
				'ResultData' => $result_data,
				'order_id'   => $order->get_id(),
			]
		);

		// When
		$response = $this->get_callback()->post_logistics_selection_callback( $request );

		// Then: 回應 HTTP 200
		$this->assertSame( 200, $response->get_status() );

		// Then: 四個 meta 寫入正確
		$fresh_order = \wc_get_order( $order->get_id() );
		$fresh_meta  = new LogisticsMetaKeys( $fresh_order );
		$this->assertSame( '2264', $fresh_meta->get_temp_id(), '_pc_logistics_temp_id 應為 2264' );
		$this->assertSame( '991182', $fresh_meta->get_store_id(), '_pc_logistics_store_id 應為 991182' );
		$this->assertSame( '全家測試門市', $fresh_meta->get_store_name(), '_pc_logistics_store_name 不符' );
		$this->assertSame( '台北市中山區測試路1號', $fresh_meta->get_store_addr(), '_pc_logistics_store_addr 不符' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_選店回呼_成功後TempLogisticsID可用於後續建單(): void {
		// Given: 正確的選店回呼
		$order       = $this->create_wc_order( [ 'status' => 'pending' ] );
		$result_data = $this->build_result_data(
			[
				'TempLogisticsID' => '8800',
				'CVSStoreID'      => 'UNIMART001',
				'CVSStoreName'    => '7-11 測試門市',
				'CVSAddress'      => '新北市板橋區測試路99號',
				'LogisticsSubType' => 'UNIMART',
			]
		);

		$request = $this->make_selection_request(
			[
				'ResultData' => $result_data,
				'order_id'   => $order->get_id(),
			]
		);

		// When
		$this->get_callback()->post_logistics_selection_callback( $request );

		// Then: temp_id 寫入，可作為 create_shipment 的前置條件
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh_meta->get_temp_id(), 'temp_id 應已寫入，可供 create_shipment 使用' );
		$this->assertSame( '8800', $fresh_meta->get_temp_id() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_選店回呼_ResultData為null時仍正常回應不拋500(): void {
		// Given: ResultData 完全不在 POST body
		$request = $this->make_selection_request( [] ); // 無 ResultData key

		// When
		$response = null;
		$threw    = false;
		try {
			$response = $this->get_callback()->post_logistics_selection_callback( $request );
		} catch ( \Throwable $e ) {
			$threw = true;
		}

		// Then
		$this->assertFalse( $threw, 'ResultData 缺失時不應拋例外' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response->get_status() );
	}
}
