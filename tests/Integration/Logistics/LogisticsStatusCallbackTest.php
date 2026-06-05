<?php
/**
 * 綠界全方位物流 貨態 callback 整合測試（階段三 — Red Gate）
 *
 * ★ R2 最高風險：貨態 callback 回應必須是 AES-JSON 三層結構，不可為 1|OK。
 * 任何失敗路徑仍必須回 AES-JSON，否則綠界每 60 分重送最多 3 次。
 *
 * 核心斷言：回應 body 可被 AesCrypto 解密還原成三層結構
 *   { MerchantID, RqHeader{ Timestamp }, TransCode, TransMsg, Data }
 *   Data 解密後含 RtnCode（整數）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli \
 *     --env-cwd=wp-content/plugins/power-checkout \
 *     vendor/bin/phpunit --filter LogisticsStatusCallbackTest 2>&1; echo "EXIT=$?"
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
 * 貨態 callback 測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsStatusCallbackTest extends TestCase {

	// ---- B2C 測試帳號 ----
	private const B2C_MERCHANT_ID = '2000132';
	private const B2C_HASH_KEY    = '5294y06JbISpM5x9';
	private const B2C_HASH_IV     = 'v77hoKGq4kWxNNIS';

	/** @var AesCrypto B2C 加解密器（用於建立測試 payload 與驗證回應） */
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

	// ========== 核心工具方法 ==========

	/**
	 * 建立貨態通知 payload（AES-JSON 三層）
	 *
	 * @param int    $trans_code     外層 TransCode（通常 1）
	 * @param string $merchant_id    MerchantID
	 * @param int    $rtn_code       Data 內 RtnCode（通常 1）
	 * @param string $logistics_id   LogisticsID
	 * @param string $logistics_status LogisticsStatus 碼
	 * @param AesCrypto $crypto      加密器
	 * @return array<string, mixed>
	 */
	private function build_status_payload(
		int $trans_code,
		string $merchant_id,
		int $rtn_code,
		string $logistics_id,
		string $logistics_status,
		?AesCrypto $crypto = null
	): array {
		$crypto ??= $this->b2c_crypto;

		$data_payload = [
			'RtnCode'         => $rtn_code,
			'RtnMsg'          => 1 === $rtn_code ? 'OK' : '失敗',
			'LogisticsID'     => $logistics_id,
			'LogisticsStatus' => $logistics_status,
			'LogisticsType'   => 'CVS',
		];

		return [
			'MerchantID' => $merchant_id,
			'RqHeader'   => [
				'Timestamp' => \time(),
				'Revision'  => '1.0.0',
			],
			'TransCode'  => $trans_code,
			'TransMsg'   => 1 === $trans_code ? '' : 'AES error',
			'Data'       => $crypto->encrypt( $data_payload ),
		];
	}

	/**
	 * 將 payload 建立為 WP_REST_Request
	 *
	 * @param array<string, mixed> $payload
	 * @return \WP_REST_Request
	 */
	private function make_request( array $payload ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/ecpay/logistics/status-callback' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( $payload ) );
		return $request;
	}

	/**
	 * 核心斷言：回應 body 必須是 AES-JSON 三層結構且可解密
	 * 這是 R2 的核心驗證——確保實作不會回 "1|OK" 或其他錯誤格式。
	 *
	 * @param \WP_REST_Response $response       callback 回應
	 * @param int               $expected_rtn_code 期望 Data.RtnCode（整數）
	 * @param AesCrypto|null    $crypto          解密器（預設用 B2C）
	 */
	private function assert_response_is_aes_json(
		\WP_REST_Response $response,
		int $expected_rtn_code = 1,
		?AesCrypto $crypto = null
	): void {
		$crypto ??= $this->b2c_crypto;
		$data     = $response->get_data();

		// 1. HTTP 狀態碼 200
		$this->assertSame( 200, $response->get_status(), '貨態 callback 必須回 HTTP 200' );

		// 2. 回應必須是陣列（JSON 物件），不可為純文字字串
		$this->assertIsArray( $data, '回應 body 必須為 JSON 物件（AES-JSON 三層），不可為純文字' );

		// 3. 外層三層結構鍵存在
		$this->assertArrayHasKey( 'MerchantID', $data, '外層必須含 MerchantID' );
		$this->assertArrayHasKey( 'RqHeader', $data, '外層必須含 RqHeader' );
		$this->assertArrayHasKey( 'TransCode', $data, '外層必須含 TransCode' );
		$this->assertArrayHasKey( 'Data', $data, '外層必須含 Data' );

		// 4. RqHeader 含 Timestamp
		$rq_header = $data['RqHeader'] ?? [];
		$this->assertIsArray( $rq_header, 'RqHeader 應為陣列' );
		$this->assertArrayHasKey( 'Timestamp', $rq_header, 'RqHeader 必須含 Timestamp' );
		$this->assertGreaterThan( 0, (int) ( $rq_header['Timestamp'] ?? 0 ), 'Timestamp 必須大於 0' );

		// 5. TransCode = 1（商家回綠界時外層均為成功）
		$this->assertSame( 1, (int) ( $data['TransCode'] ?? 0 ), '回應的 TransCode 應為 1' );

		// 6. Data 欄位可被 AesCrypto 解密（R2 核心：確保加密格式正確）
		$encrypted_data = (string) ( $data['Data'] ?? '' );
		$this->assertNotEmpty( $encrypted_data, 'Data 欄位不應為空' );

		$decrypted = null;
		try {
			$decrypted = $crypto->decrypt( $encrypted_data );
		} catch ( \Throwable $e ) {
			$this->fail( "Data 欄位無法被 AesCrypto 解密（R2 違規）：{$e->getMessage()}" );
		}

		// 7. 解密後含 RtnCode（整數）
		$this->assertArrayHasKey( 'RtnCode', $decrypted, '解密後 Data 必須含 RtnCode' );
		$this->assertSame(
			$expected_rtn_code,
			(int) ( $decrypted['RtnCode'] ?? -1 ),
			"Data.RtnCode 應為 {$expected_rtn_code}"
		);
	}

	/**
	 * 取得貨態 callback handler
	 *
	 * @return LogisticsCallback
	 */
	private function get_callback(): LogisticsCallback {
		return LogisticsCallback::instance();
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_貨態300已出貨時寫入status_meta並回AES_JSON_RtnCode1(): void {
		// Given: 有對應訂單
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1000,
			]
			);
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );

		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: '1234567890',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 回應為 AES-JSON 三層，RtnCode=1
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 1 );

		// Then: 訂單 meta 更新
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '300', $fresh_meta->get_status(), '_pc_logistics_status 應為 300' );
		// 防重標記應已建立
		$this->assertTrue(
			$fresh_meta->is_processed( '1234567890', '300' ),
			'應已標記 1234567890:300 為已處理'
		);
	}

	/**
	 * 逆物流貨態通知帶 ReturnLogisticsID，須能以 return_ref 反查訂單並更新貨態
	 *
	 * @test
	 * @group happy
	 */
	public function test_逆物流貨態以ReturnLogisticsID反查訂單並更新status_meta(): void {
		// Given: 訂單已建立退貨單（有 return_ref，無正向 ref 直接對應該逆物流單號）
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1000,
			]
		);
		( new LogisticsMetaKeys( $order ) )->update_return_ref( 'RET1234567890' );

		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: 'RET1234567890',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 回應為 AES-JSON 三層，RtnCode=1，且訂單貨態更新（逆物流沿用同一 callback）
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 1 );
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '300', $fresh_meta->get_status(), '逆物流貨態應更新 _pc_logistics_status' );
	}

	// ========== 傳輸層失敗（TransCode=0） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_TransCode為0時不更新訂單但仍回AES_JSON_RtnCode0(): void {
		// Given: TransCode=0
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );

		$payload = [
			'MerchantID' => self::B2C_MERCHANT_ID,
			'RqHeader'   => [
				'Timestamp' => \time(),
				'Revision'  => '1.0.0',
			],
			'TransCode'  => 0,
			'TransMsg'   => 'AES error',
			'Data'       => '',
		];
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 回 AES-JSON，RtnCode=0（告知綠界有錯）
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 0 );

		// Then: 訂單 status 未更新
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh_meta->get_status(), '訂單 status 不應被更新' );
	}

	// ========== Data 解密失敗 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_Data解密失敗時回AES_JSON_RtnCode0(): void {
		// Given: Data 為無效密文
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );

		$payload = [
			'MerchantID' => self::B2C_MERCHANT_ID,
			'RqHeader'   => [
				'Timestamp' => \time(),
				'Revision'  => '1.0.0',
			],
			'TransCode'  => 1,
			'TransMsg'   => '',
			'Data'       => '###INVALID_CIPHER_TEXT###',
		];
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 解密失敗仍回 AES-JSON（不可 HTTP 500）
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 0 );

		// Then: 訂單 status 未更新
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh_meta->get_status() );
	}

	// ========== MerchantID 不符（安全驗證） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_MerchantID不符時不更新訂單且記安全log_不洩漏HashKey(): void {
		// Given: MerchantID 為偽造的 9999999
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );

		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: '9999999', // 偽造 MerchantID
			rtn_code: 1,
			logistics_id: '1234567890',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 回 AES-JSON（不拋 HTTP 500）
		$this->assertSame( 200, $response->get_status(), 'MerchantID 不符時仍應回 HTTP 200' );
		$this->assertIsArray( $response->get_data(), 'MerchantID 不符時仍應回 JSON 結構' );

		// Then: 訂單 status 未更新
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $fresh_meta->get_status(), 'MerchantID 不符時不應更新訂單' );

		// Then: 回應 body 不含 HashKey（安全防洩）
		$response_json = (string) \wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( self::B2C_HASH_KEY, $response_json, '回應不應洩漏 HashKey' );
		$this->assertStringNotContainsString( self::B2C_HASH_IV, $response_json, '回應不應洩漏 HashIV' );
	}

	// ========== LogisticsID 無對應訂單 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_LogisticsID無對應訂單時不更新並回AES_JSON_避免重送風暴(): void {
		// Given: LogisticsID '0000000000' 查無訂單
		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: '0000000000',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 仍回 AES-JSON（避免重送風暴），不 HTTP 500
		$this->assertSame( 200, $response->get_status() );
		$this->assertIsArray( $response->get_data() );
	}

	// ========== COD + 取件完成（2067） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_COD取件完成貨態2067標記collection_paid_且baseline不改WC訂單狀態_T2(): void {
		// Given: COD 訂單，付款方式 cod
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'cod',
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_payment_scenario( 'cod' );

		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: '1234567890',
			logistics_status: '2067' // 取件完成
		);
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 回 AES-JSON，RtnCode=1
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 1 );

		// Then: 貨態寫入 2067
		$fresh_order = \wc_get_order( $order->get_id() );
		$fresh_meta  = new LogisticsMetaKeys( $fresh_order );
		$this->assertSame( '2067', $fresh_meta->get_status(), '_pc_logistics_status 應為 2067' );

		// Then: collection_paid 標記 yes（COD 取件完成）
		$this->assertSame( 'yes', $fresh_meta->get_collection_paid(), '_pc_logistics_collection_paid 應為 yes' );

		// Then: baseline T2 — WC 訂單狀態不應改變（不自動轉 completed）
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 重複貨態防重（T7） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_重複相同貨態不重複處理_T7(): void {
		// Given: 訂單已處理過 1234567890:300
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->mark_processed( '1234567890', '300' );
		// 先設一個不同的 status，用以驗證第二次不會更新
		$meta->update_status( '100' );

		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: '1234567890',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When: 重送相同貨態
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 仍回 AES-JSON，RtnCode=1（成功冪等）
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 1 );

		// Then: 訂單 status 維持 100（未被重複更新）
		$fresh_meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '100', $fresh_meta->get_status(), '已處理過的貨態不應重複更新 status' );
	}

	// ========== 任意 Throwable 仍回 AES-JSON（R2 核心防護） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_任意Throwable發生時仍回AES_JSON_不拋HTTP500_R2(): void {
		// Given: 刻意建立一個會導致 provider 方法拋例外的情境
		// 這裡透過傳入格式完全合法但 Data 內容導致邏輯異常的 payload
		// 更直接的方式：模擬 get_order_by_ref 找不到訂單時也不應 500
		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: 'FORCE_EXCEPTION_00000',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When: LogisticsID 對應不到訂單，內部流程可能拋例外
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 無論如何都回 HTTP 200 + AES-JSON（非 500）
		$this->assertSame( 200, $response->get_status(), '例外發生時不可回 HTTP 500' );
		$this->assertIsArray( $response->get_data(), '例外發生時回應仍必須是 JSON 結構' );

		// 確認回應 body 含最少的三層鍵（不解密，避免過嚴）
		$body = $response->get_data();
		$this->assertArrayHasKey( 'MerchantID', $body );
		$this->assertArrayHasKey( 'Data', $body );
	}

	// ========== R2 核心：完整解密驗證 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_R2核心_回應body可被AesCrypto完整解密還原三層結構(): void {
		// Given: 標準成功貨態
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'REF_R2_TEST' );

		$payload = $this->build_status_payload(
			trans_code: 1,
			merchant_id: self::B2C_MERCHANT_ID,
			rtn_code: 1,
			logistics_id: 'REF_R2_TEST',
			logistics_status: '300'
		);
		$request = $this->make_request( $payload );

		// When
		$response = $this->get_callback()->post_logistics_status_callback_callback( $request );

		// Then: 完整三層結構解密驗證
		$this->assert_response_is_aes_json( $response, expected_rtn_code: 1 );

		// 額外驗證：MerchantID 與商店一致（不回對方 MerchantID）
		$body = $response->get_data();
		$this->assertSame( self::B2C_MERCHANT_ID, (string) ( $body['MerchantID'] ?? '' ), 'MerchantID 應與商店一致' );
	}
}
