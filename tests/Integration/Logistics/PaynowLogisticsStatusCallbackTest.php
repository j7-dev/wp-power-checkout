<?php
/**
 * PayNow 物流貨態通知 Callback 整合測試（TDD Red 階段 — A-Cycle 2）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback
 *     ↳ 貨態通知方法（status-callback endpoint）
 *
 * 規格依據：
 *   - specs/features/logistics/paynow-logistics-query.feature（R1 決策背景）
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 2（StatusCallbackTest）
 *
 * R1 裁決（照此寫斷言）：
 *   handle_status_callback() 解析推送 payload：
 *   - 推送欄位：orderno / PayNowLogisticCode / Detail_Status_Description / paymentno / StoreDate / StoreTime
 *   - 以 orderno 走 PaynowLogisticsMetaKeys::get_order_by_order_no() 反查訂單（R4）
 *   - 冪等：以 "{OrderNo}:{LogisticCode}" 為 key，已處理則跳過
 *   - 更新 meta（logistic_code / delivery_status / payment_no 等）
 *   - 恆回 HTTP 200（含 \Throwable 路徑）
 *   - COD + 取貨完成貨態 → collection_paid=yes
 *
 * R4 裁決：反查走 PaynowLogisticsMetaKeys（不依賴 shared LogisticsMetaKeys）
 *
 * REST endpoint：
 *   POST /wp-json/power-checkout/paynow/logistics/status-callback（permission __return_true）
 *
 * ★ 鐵律：所有路徑（含例外）一律回 HTTP 200，避免 PayNow 重送風暴。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowLogisticsStatusCallbackTest"
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
 * PayNow 物流貨態通知 Callback 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsStatusCallbackTest extends TestCase {

	/** Callback 處理物件（在 Green 階段才存在） */
	private LogisticsCallback $callback;

	/** 每次測試前設定環境 */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		\update_option( 'woocommerce_currency', 'TWD' );
		PaynowLogisticsSettingsDTO::reset();
		$this->enable_paynow_logistics();
		$this->callback = new LogisticsCallback();
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
	 * 建立貨態通知 WP_REST_Request
	 *
	 * @param array<string, mixed> $params POST 參數（PayNow 貨態推送 payload）
	 * @return \WP_REST_Request
	 */
	private function build_status_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/paynow/logistics/status-callback' );
		$request->set_body_params( $params );
		return $request;
	}

	/**
	 * 建立標準貨態通知 payload
	 *
	 * @param string $order_no       OrderNo（PCN{order_id}）
	 * @param string $logistic_code  貨態碼
	 * @param string $description    貨態描述
	 * @return array<string, mixed>
	 */
	private function build_standard_payload(
		string $order_no,
		string $logistic_code = '3000',
		string $description = '物流配送中'
	): array {
		return [
			'orderno'                   => $order_no,
			'PayNowLogisticCode'        => $logistic_code,
			'Detail_Status_Description' => $description,
			'paymentno'                 => 'PAYNO_' . $order_no,
			'StoreDate'                 => '20260610',
			'StoreTime'                 => '123456',
		];
	}

	// =====================================================================
	// region: 基礎 — 推送解析
	// =====================================================================

	/**
	 * 合法推送 payload → 200 + 寫入貨態 meta
	 *
	 * @group happy
	 */
	public function test_valid_payload_updates_meta_and_returns_200(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_STATUS_001' );

		$logistic_code = '3000';
		$payload       = $this->build_standard_payload( $order_no, $logistic_code, '物流配送中' );
		$request       = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		// ★ 恆回 200
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '貨態通知應恆回 HTTP 200' );

		// 重讀訂單驗 meta
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );

		$this->assertSame( $logistic_code, $fresh_meta->get_logistic_code(), 'logistic_code 應寫入推送值' );
		$this->assertNotEmpty( $fresh_meta->get_delivery_status(), 'delivery_status 應寫入' );
	}

	/**
	 * 推送 payload 包含 paymentno → 寫入 payment_no meta
	 *
	 * @group happy
	 */
	public function test_valid_payload_with_paymentno_writes_payment_no_meta(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_STATUS_002' );

		$payload              = $this->build_standard_payload( $order_no );
		$payload['paymentno'] = 'PAYNO_SPECIFIC_001';
		$request              = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status() );

		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( 'PAYNO_SPECIFIC_001', $fresh_meta->get_payment_no(), 'paymentno 應寫入 payment_no meta' );
	}

	// endregion

	// =====================================================================
	// region: R4 — 以 orderno 反查（PaynowLogisticsMetaKeys 專用）
	// =====================================================================

	/**
	 * 以 orderno（PCN{order_id}）反查訂單後更新 meta
	 *
	 * @group happy
	 */
	public function test_callback_uses_order_no_to_find_order(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_R4_001' );

		$payload = $this->build_standard_payload( $order_no, '5000', '已到店' );
		$request = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status() );

		// 確認正確訂單的 meta 被更新
		$found_order = PaynowLogisticsMetaKeys::get_order_by_order_no( $order_no );
		$this->assertInstanceOf( \WC_Order::class, $found_order );
		$this->assertSame( $order->get_id(), $found_order->get_id(), '應以 orderno 找到正確訂單' );

		$found_meta = new PaynowLogisticsMetaKeys( $found_order );
		$this->assertSame( '5000', $found_meta->get_logistic_code() );
	}

	/**
	 * orderno 對應不到任何訂單時 → 恆回 200 不拋例外
	 *
	 * @group security
	 */
	public function test_callback_returns_200_when_order_not_found(): void {
		$payload = $this->build_standard_payload( 'PCN9999999', '3000', '偽造推送' );
		$request = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		// ★ 恆回 200，即使找不到訂單
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '找不到訂單時應恆回 200（不拋例外）' );
	}

	// endregion

	// =====================================================================
	// region: 冪等防重
	// =====================================================================

	/**
	 * 重複推送同一（orderno + LogisticCode）→ 第二次冪等跳過，不重複寫入
	 *
	 * @group edge
	 */
	public function test_callback_is_idempotent_on_duplicate_push(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_IDEM_001' );

		$payload = $this->build_standard_payload( $order_no, '3000', '第一次推送' );
		$request = $this->build_status_request( $payload );

		// 第一次推送
		$response1 = $this->callback->handle_status_callback( $request );
		$this->assertSame( 200, $response1->get_status() );

		// 第一次後的 delivery_status
		$after_first = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $after_first );
		$meta_after_first   = new PaynowLogisticsMetaKeys( $after_first );
		$status_after_first = $meta_after_first->get_delivery_status();

		// 第二次相同 payload 推送（模擬 PayNow 重送）
		$payload2  = $this->build_standard_payload( $order_no, '3000', '第二次重送（應被忽略）' );
		$request2  = $this->build_status_request( $payload2 );
		$response2 = $this->callback->handle_status_callback( $request2 );
		$this->assertSame( 200, $response2->get_status() );

		// delivery_status 不應被第二次覆寫成不同值
		$after_second = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $after_second );
		$meta_after_second = new PaynowLogisticsMetaKeys( $after_second );
		$this->assertSame(
			$status_after_first,
			$meta_after_second->get_delivery_status(),
			'冪等：第二次相同推送不應覆寫已有的 delivery_status'
		);

		// 驗冪等 key 已標記
		$this->assertTrue(
			$meta_after_second->is_processed( $order_no, '3000' ),
			"冪等 key {$order_no}:3000 應已標記為已處理"
		);
	}

	/**
	 * 相同訂單不同貨態碼 → 兩次都應寫入（非冪等重複）
	 *
	 * @group edge
	 */
	public function test_callback_processes_different_logistic_codes_separately(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_IDEM_002' );

		// 第一次：貨態 3000
		$request1  = $this->build_status_request( $this->build_standard_payload( $order_no, '3000', '配送中' ) );
		$response1 = $this->callback->handle_status_callback( $request1 );
		$this->assertSame( 200, $response1->get_status() );

		// 第二次：貨態 5000（不同碼）
		$request2  = $this->build_status_request( $this->build_standard_payload( $order_no, '5000', '已到店' ) );
		$response2 = $this->callback->handle_status_callback( $request2 );
		$this->assertSame( 200, $response2->get_status() );

		// 最終 logistic_code 應為 5000
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( '5000', $fresh_meta->get_logistic_code(), '最後一次非重複推送應更新 logistic_code' );
	}

	// endregion

	// =====================================================================
	// region: COD 取貨完成
	// =====================================================================

	/**
	 * COD 訂單收到取貨完成貨態 → collection_paid=yes
	 *
	 * @group happy
	 */
	public function test_cod_pickup_complete_sets_collection_paid(): void {
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'cod',
			]
		);
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_COD_CB_001' );

		// 取貨完成貨態（依 woomp 反推，具體碼 Green 階段確定）
		// 此測試預期 Provider 能識別「取貨完成」並標記 collection_paid
		$payload             = $this->build_standard_payload( $order_no, 'PICKUP_DONE', '取貨完成' );
		$payload['cod_flag'] = '1'; // 標記 COD（Green 時依實作調整）
		$request             = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status() );

		// 重讀驗 collection_paid
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( 'yes', $fresh_meta->get_collection_paid(), 'COD 取貨完成應標記 collection_paid=yes' );
	}

	// endregion

	// =====================================================================
	// region: 恆回 200（含例外路徑）
	// =====================================================================

	/**
	 * 推送 payload 完全為空時 → 恆回 200（不拋例外）
	 *
	 * @group edge
	 * @group security
	 */
	public function test_empty_payload_still_returns_200(): void {
		$request  = $this->build_status_request( [] );
		$response = $this->callback->handle_status_callback( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '空 payload 應恆回 200（不拋例外）' );
	}

	/**
	 * 推送 orderno 為空字串 → 恆回 200（不拋例外，不寫 meta）
	 *
	 * @group edge
	 */
	public function test_empty_orderno_still_returns_200(): void {
		$payload = $this->build_standard_payload( '', '3000', '偽造' );
		$request = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status(), 'orderno 為空時應恆回 200' );
	}

	/**
	 * 推送帶 \Throwable 的場景（強迫回呼路徑拋例外）仍回 200
	 *
	 * 在 callback 內以 SQL 無效操作觸發例外路徑（Green 時實作捕捉）。
	 * 此測試驗「含 \Throwable 路徑」Iron Rule。
	 *
	 * @group edge
	 * @group security
	 */
	public function test_callback_always_returns_200_even_on_exception(): void {
		// 推送一個 orderno 存在的訂單，但在 callback 中觸發例外：
		// 以超長 orderno（DB meta_key 查詢無效）模擬
		$long_order_no = str_repeat( 'X', 300 );
		$payload       = $this->build_standard_payload( $long_order_no );
		$request       = $this->build_status_request( $payload );

		$response = null;
		try {
			$response = $this->callback->handle_status_callback( $request );
		} catch ( \Throwable $e ) {
			$this->fail( '貨態 callback 不應向外拋出例外（\Throwable 應被吞掉並回 200）：' . $e->getMessage() );
		}

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '\Throwable 路徑應恆回 200' );
	}

	// endregion

	// =====================================================================
	// region: 安全性 — 偽造推送
	// =====================================================================

	/**
	 * 偽造 orderno（非 PCN 格式）推送 → 恆回 200，不修改任何訂單
	 *
	 * @group security
	 */
	public function test_forged_orderno_returns_200_without_side_effects(): void {
		// 建立一個真實訂單，確認它不被偽造推送影響
		$real_order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$real_order_no  = 'PCN' . $real_order->get_id();
		$real_meta_keys = new PaynowLogisticsMetaKeys( $real_order );
		$real_meta_keys->update_order_no( $real_order_no );

		// 偽造 orderno（不對應任何訂單）
		$payload = $this->build_standard_payload( 'FORGED_ORDER_NO_001', '9999', '偽造貨態' );
		$request = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status() );

		// 驗真實訂單未被汙染
		$fresh_real = \wc_get_order( $real_order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_real );
		$fresh_real_meta = new PaynowLogisticsMetaKeys( $fresh_real );
		$this->assertEmpty( $fresh_real_meta->get_logistic_code(), '真實訂單不應被偽造推送汙染' );
	}

	// endregion
}
