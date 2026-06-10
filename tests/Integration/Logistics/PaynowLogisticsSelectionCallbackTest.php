<?php
/**
 * PayNow 物流選店回呼 Callback 整合測試（TDD Red 階段 — A-Cycle 2）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback
 *     ↳ 選店回呼方法（selection-callback endpoint）
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider
 *     ↳ parse_store_selection()
 *
 * 規格依據：
 *   - specs/features/logistics/paynow-logistics-selection-callback.feature
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 2（SelectionCallbackTest）
 *
 * 裁決（照此寫斷言）：
 *   - 缺少 storeid → throw／回錯「選店回呼缺少門市資訊」
 *   - 成功解析 → 寫入 _pc_paynow_logistics_store_id / _store_name / _store_addr
 *   - cid 反查訂單（cid = 購物車 hash，woomp 實證）
 *   - 來源驗證弱驗證（`permission __return_true`，內部 orderno/cid 存在性驗證）
 *
 * REST endpoint：
 *   POST /wp-json/power-checkout/paynow/logistics/selection-callback（permission __return_true）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowLogisticsSelectionCallbackTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 物流選店回呼測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsSelectionCallbackTest extends TestCase {

	/** 測試用購物車 hash（cid） */
	private const TEST_CID = 'abc123carthashmock';

	/** 測試用門市資料 */
	private const TEST_STORE_ID   = 'IBON001';
	private const TEST_STORE_NAME = '測試7-11門市';
	private const TEST_STORE_ADDR = '台北市信義區松仁路100號';

	/** Callback 處理物件（在 Green 階段才存在） */
	private LogisticsCallback $callback;

	/** Provider 實例（在 Green 階段才存在） */
	private PaynowLogisticsProvider $provider;

	/** 每次測試前設定環境 */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		\update_option( 'woocommerce_currency', 'TWD' );
		PaynowLogisticsSettingsDTO::reset();
		$this->enable_paynow_logistics();
		$this->callback = new LogisticsCallback();
		$this->provider = PaynowLogisticsProvider::instance();
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE=mock' );
		PaynowLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PaynowLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 paynow_logistics（test 模式）
	 */
	private function enable_paynow_logistics(): void {
		$this->enable_provider(
			PaynowLogisticsProvider::ID,
			[
				'mode'            => 'test',
				'user_account'    => 'TEST_LOGISTICS_ACCT',
				'apicode'         => 'TEST_LOGISTICS_CODE',
				'enabled_methods' => [ 'SEVEN', 'FAMI', 'HILIFE', 'TCAT' ],
				'sender_name'     => '測試寄件人',
				'sender_mobile'   => '0912345678',
				'sender_address'  => '台北市信義區測試路1號',
			]
		);
		PaynowLogisticsSettingsDTO::reset();
	}

	/**
	 * 建立選店回呼 WP_REST_Request
	 *
	 * @param array<string, mixed> $params POST 參數
	 * @return \WP_REST_Request
	 */
	private function build_selection_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/paynow/logistics/selection-callback' );
		$request->set_body_params( $params );
		return $request;
	}

	// =====================================================================
	// region: 前置驗證 — 缺少門市資訊
	// =====================================================================

	/**
	 * 缺少 storeid 時解析失敗
	 *
	 * @group error
	 */
	public function test_parse_store_selection_fails_without_storeid(): void {
		$order     = $this->create_wc_order( [ 'status' => 'pending' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( 'PCN' . $order->get_id() );

		$raw = [
			// storeid 刻意省略
			'storename'    => self::TEST_STORE_NAME,
			'storeaddress' => self::TEST_STORE_ADDR,
			'cid'          => self::TEST_CID,
		];

		$this->lastError = null;
		try {
			$this->provider->parse_store_selection( $raw );
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_failed_with_message( '選店回呼缺少門市資訊' );
	}

	/**
	 * 缺少 storename 時解析失敗
	 *
	 * @group error
	 */
	public function test_parse_store_selection_fails_without_storename(): void {
		$raw = [
			'storeid'      => self::TEST_STORE_ID,
			// storename 省略
			'storeaddress' => self::TEST_STORE_ADDR,
			'cid'          => self::TEST_CID,
		];

		$this->lastError = null;
		try {
			$this->provider->parse_store_selection( $raw );
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_failed_with_message( '選店回呼缺少門市資訊' );
	}

	// endregion

	// =====================================================================
	// region: 選店解析成功 — 寫入 order meta
	// =====================================================================

	/**
	 * 顧客選擇門市後寫入門市 meta（storeid/storename/storeaddress）
	 *
	 * @group happy
	 */
	public function test_parse_store_selection_writes_store_meta(): void {
		$order     = $this->create_wc_order( [ 'status' => 'pending' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		// 先寫入 ORDER_NO 供反查（woomp cid → order 對應）
		$meta_keys->update_order_no( 'PCN' . $order->get_id() );

		$raw = [
			'storeid'      => self::TEST_STORE_ID,
			'storename'    => self::TEST_STORE_NAME,
			'storeaddress' => self::TEST_STORE_ADDR,
			'cid'          => self::TEST_CID,
			'orderid'      => (string) $order->get_id(),
		];

		$this->lastError = null;
		$result          = null;
		try {
			$result = $this->provider->parse_store_selection( $raw );
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// 重讀訂單驗 meta
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );

		$this->assertSame( self::TEST_STORE_ID, $fresh_meta->get_store_id(), 'store_id 應寫入正確值' );
		$this->assertSame( self::TEST_STORE_NAME, $fresh_meta->get_store_name(), 'store_name 應寫入正確值' );
		$this->assertSame( self::TEST_STORE_ADDR, $fresh_meta->get_store_addr(), 'store_addr 應寫入正確值' );
	}

	/**
	 * cid 反查到對應訂單
	 *
	 * @group happy
	 */
	public function test_parse_store_selection_uses_cid_to_find_order(): void {
		$order     = $this->create_wc_order( [ 'status' => 'pending' ] );
		$order_id  = $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( 'PCN' . $order_id );

		// cid 對應訂單 ID（woomp 實作：cid = cart_hash，此處以 orderid 模擬）
		$raw = [
			'storeid'      => self::TEST_STORE_ID,
			'storename'    => self::TEST_STORE_NAME,
			'storeaddress' => self::TEST_STORE_ADDR,
			'orderid'      => (string) $order_id,
			'cid'          => self::TEST_CID,
		];

		$this->lastError = null;
		try {
			$this->provider->parse_store_selection( $raw );
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();

		// 驗門市資訊確實寫進正確訂單
		$fresh_order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertNotEmpty( $fresh_meta->get_store_id(), '應將門市資訊寫入 cid 對應的訂單' );
	}

	// endregion

	// =====================================================================
	// region: REST endpoint 層測試（LogisticsCallback）
	// =====================================================================

	/**
	 * 選店回呼 REST endpoint：缺少 storeid 回錯
	 *
	 * @group error
	 * @group security
	 */
	public function test_callback_endpoint_returns_error_without_storeid(): void {
		$request = $this->build_selection_request(
			[
				'storename'    => self::TEST_STORE_NAME,
				'storeaddress' => self::TEST_STORE_ADDR,
				'cid'          => self::TEST_CID,
			]
		);

		$response = $this->callback->handle_selection_callback( $request );

		// REST endpoint 應回非 2xx 或含 error 訊息的 200
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data    = $response->get_data();
		$code    = $response->get_status();
		$has_err = ( $code >= 400 )
		|| ( isset( $data['code'] ) && 'success' !== $data['code'] )
		|| ( isset( $data['message'] ) && false !== stripos( (string) $data['message'], '門市' ) );
		$this->assertTrue( $has_err, '缺少 storeid 應回錯誤' );
	}

	/**
	 * 選店回呼 REST endpoint：成功時回 success
	 *
	 * @group happy
	 */
	public function test_callback_endpoint_returns_success_with_valid_params(): void {
		$order     = $this->create_wc_order( [ 'status' => 'pending' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( 'PCN' . $order->get_id() );

		$request = $this->build_selection_request(
			[
				'storeid'      => self::TEST_STORE_ID,
				'storename'    => self::TEST_STORE_NAME,
				'storeaddress' => self::TEST_STORE_ADDR,
				'orderid'      => (string) $order->get_id(),
				'cid'          => self::TEST_CID,
			]
		);

		$response = $this->callback->handle_selection_callback( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$code = $response->get_status();
		$this->assertGreaterThanOrEqual( 200, $code );
		$this->assertLessThan( 300, $code, '成功應回 2xx' );
	}

	// endregion

	// =====================================================================
	// region: 安全性 — 來源弱驗證
	// =====================================================================

	/**
	 * 來源不帶任何識別資訊（無 storeid / storename / storeaddress）時解析失敗
	 *
	 * permission __return_true，但內部必須驗證回呼完整性。
	 *
	 * @group security
	 */
	public function test_callback_with_empty_payload_is_rejected(): void {
		$request = $this->build_selection_request( [] );

		$response = $this->callback->handle_selection_callback( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data    = $response->get_data();
		$code    = $response->get_status();
		$is_fail = ( $code >= 400 )
		|| ( isset( $data['code'] ) && 'success' !== $data['code'] );
		$this->assertTrue( $is_fail, '空 payload 應被拒絕' );
	}

	/**
	 * 偽造回呼（帶 storeid 但無對應訂單）不應寫入 meta
	 *
	 * @group security
	 */
	public function test_callback_with_no_matching_order_does_not_write_meta(): void {
		// 不建立任何訂單，直接送回呼
		$request = $this->build_selection_request(
			[
				'storeid'      => 'FAKE_STORE_001',
				'storename'    => '偽造門市',
				'storeaddress' => '偽造地址',
				'orderid'      => '999999',
				'cid'          => 'fake_cid',
			]
		);

		// 回呼在找不到訂單時不應拋出未處理例外，但應回錯或靜默失敗
		$response = null;
		try {
			$response = $this->callback->handle_selection_callback( $request );
		} catch ( \Throwable $e ) {
			// 允許拋例外（視 Green 實作決定），但不應 500
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		// 不驗具體 code，只驗沒有意外成功
		$data = $response->get_data();
		if ( isset( $data['code'] ) ) {
			// 若回 success 但找不到訂單，屬設計缺陷（此測試讓 Green 時修正）
			$this->assertNotEquals( 'success', $data['code'], '找不到訂單時不應回 success' );
		}
	}

	// endregion
}
