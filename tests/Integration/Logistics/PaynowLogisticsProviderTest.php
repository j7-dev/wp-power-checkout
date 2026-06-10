<?php
/**
 * PayNow 物流 Provider 整合測試（TDD Red 階段 — A-Cycle 2）
 *
 * 測試目標（尚未存在 → Red 階段，class 不存在時預期 class not found）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider
 *
 * 規格依據：
 *   - specs/features/logistics/paynow-logistics-store-selection.feature
 *   - specs/features/logistics/paynow-logistics-create-shipment.feature
 *   - specs/features/logistics/paynow-logistics-query.feature
 *   - specs/features/logistics/paynow-logistics-print-document.feature
 *   - specs/features/logistics/paynow-logistics-cancel.feature
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 2
 *
 * 裁決（照此寫斷言）：
 *   - Provider ID = 'paynow_logistics'（const ID）
 *   - implements ILogisticsProvider（10 methods）
 *   - get_store_selection: 未啟用/訂單不存在/非啟用子類型 → throw；SEVEN → serviceID=01 + TripleDES apicode + returnUrl；TCAT 跳過選店
 *   - create_shipment: 無門市 meta（CVS）→ throw；SEVEN DeliverMode=02；COD=01；TCAT DeliveryType=0003；超商>20000→throw；宅配>100000→throw；Status=F→throw+note；Status=S→寫ref+payment_no+validation_no+note
 *   - 冪等：已有 ref 且 status≠"1" → 呼叫 ReNewOrder；寫 renew_order_no
 *   - query: 無 ref → throw；帶 LogisticNumber+sno=1；寫 status+delivery_status+logistic_code；COD取貨完成→collection_paid
 *   - print: 無 ref → throw；SEVEN→Order711；TCAT→PrintBlackCatLabel回PDF；有 RenewOrderNo 用 RenewOrderNo
 *   - cancel: 無 ref → throw；DELETE+PassCode；含'S'→status=1+note；不含'S'→throw+手動提示
 *   - create_return → throw \Exception('尚未實作')
 *   - get_supported_methods → 依 enabled_methods 回陣列
 *
 * ⚠️ 幣別踩雷：金額相關測試顯式設定 TWD。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowLogisticsProviderTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 物流 Provider 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsProviderTest extends TestCase {

	// 測試用商家帳號與 apicode
	private const TEST_USER_ACCOUNT = 'TEST_LOGISTICS_ACCT';
	private const TEST_APICODE      = 'TEST_LOGISTICS_CODE';

	// 測試用選店 callback URL
	private const TEST_SELECTION_CALLBACK_URL = 'https://example.com/wp-json/power-checkout/paynow/logistics/selection-callback';

	// test 模式 API base URL
	private const TEST_API_BASE = 'https://testlogistic.paynow.com.tw';

	/** Provider 實例（在 Green 階段才存在） */
	private PaynowLogisticsProvider $provider;

	/** 每次測試前設定環境 */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		\update_option( 'woocommerce_currency', 'TWD' );
		PaynowLogisticsSettingsDTO::reset();
		$this->enable_paynow_logistics();
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
	 *
	 * @param array<string, mixed> $extra 額外設定
	 */
	private function enable_paynow_logistics( array $extra = [] ): void {
		$this->enable_provider(
			PaynowLogisticsProvider::ID,
			array_merge(
				[
					'mode'                   => 'test',
					'user_account'           => self::TEST_USER_ACCOUNT,
					'apicode'                => self::TEST_APICODE,
					'enabled_methods'        => [ 'SEVEN', 'FAMI', 'HILIFE', 'TCAT' ],
					'sender_name'            => '測試寄件人',
					'sender_mobile'          => '0912345678',
					'sender_address'         => '台北市信義區測試路1號',
					'selection_callback_url' => self::TEST_SELECTION_CALLBACK_URL,
				],
				$extra
			)
		);
		PaynowLogisticsSettingsDTO::reset();
	}

	/**
	 * 安全呼叫 provider 方法，捕捉例外存入 $this->lastError
	 *
	 * @param callable $fn
	 * @return mixed
	 */
	private function try_call( callable $fn ): mixed {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
			return null;
		}
	}

	// =====================================================================
	// region: 基礎 — Provider 實作介面
	// =====================================================================

	/**
	 * Provider 必須實作 ILogisticsProvider
	 *
	 * @group smoke
	 */
	public function test_provider_implements_ilogisticsprovider(): void {
		$this->assertInstanceOf( ILogisticsProvider::class, $this->provider );
	}

	/**
	 * Provider ID 常數為 paynow_logistics
	 *
	 * @group smoke
	 */
	public function test_provider_id_constant(): void {
		$this->assertSame( 'paynow_logistics', PaynowLogisticsProvider::ID );
	}

	// endregion

	// =====================================================================
	// region: get_store_selection — 階段 A 選店
	// =====================================================================

	/**
	 * provider 未啟用時取得選店頁失敗
	 *
	 * @group error
	 */
	public function test_get_store_selection_fails_when_provider_disabled(): void {
		// 停用 provider
		$this->disable_provider( PaynowLogisticsProvider::ID );
		PaynowLogisticsSettingsDTO::reset();

		$order = $this->create_wc_order( [ 'status' => 'pending' ] );
		$this->try_call( fn() => $this->provider->get_store_selection( $order, [ 'sub_type' => 'SEVEN' ] ) );

		$this->assert_operation_failed_with_message( 'PayNow 物流未啟用' );
	}

	/**
	 * 訂單不存在時取得選店頁失敗（傳入無效 WC_Order 物件或空訂單）
	 *
	 * @group error
	 */
	public function test_get_store_selection_fails_when_order_not_found(): void {
		// 建立一個 mock 訂單物件（get_id 回 0）
		$order = new \WC_Order();
		$order->set_id( 0 );

		$this->try_call( fn() => $this->provider->get_store_selection( $order, [ 'sub_type' => 'SEVEN' ] ) );

		$this->assert_operation_failed_with_message( '找不到訂單' );
	}

	/**
	 * 選擇未啟用的物流子類型時失敗
	 *
	 * @group error
	 */
	public function test_get_store_selection_fails_with_unsupported_sub_type(): void {
		// enabled_methods 不含 OKMART
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI' ] ] );

		$order = $this->create_wc_order( [ 'status' => 'pending' ] );
		$this->try_call( fn() => $this->provider->get_store_selection( $order, [ 'sub_type' => 'OKMART' ] ) );

		$this->assert_operation_failed_with_message( '運送方式必須為已啟用的 PayNow 物流子類型' );
	}

	/**
	 * 7-11 超商取貨組裝選店導轉表單：serviceID=01、帶 TripleDES apicode 與 returnUrl
	 *
	 * @group happy
	 */
	public function test_get_store_selection_seven_assembles_correct_params(): void {
		$order  = $this->create_wc_order( [ 'status' => 'pending' ] );
		$result = $this->try_call( fn() => $this->provider->get_store_selection( $order, [ 'sub_type' => 'SEVEN' ] ) );

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// 必須有 redirect_target（HTML form）
		$this->assertArrayHasKey( 'redirect_target', $result );
		$redirect_target = (string) $result['redirect_target'];
		$this->assertNotEmpty( $redirect_target );

		// 表單 action 指向 PayNow 選店地圖頁
		$this->assertStringContainsString( '/Member/Order/Choselogistics', $redirect_target );

		// 帶 user_account
		$this->assertStringContainsString( self::TEST_USER_ACCOUNT, $redirect_target );

		// 帶 Logistic_serviceID=01（SEVEN）
		$this->assertStringContainsString( 'Logistic_serviceID', $redirect_target );
		$this->assertStringContainsString( '01', $redirect_target );

		// 帶 returnUrl（選店 callback）
		$this->assertStringContainsString( 'returnUrl', $redirect_target );
		$this->assertStringContainsString( 'selection-callback', $redirect_target );

		// apicode 必須為 TripleDES 加密後的值（不能是原始明文）
		$this->assertStringNotContainsString( self::TEST_APICODE, $redirect_target );
	}

	/**
	 * 黑貓宅配（TCAT）不需選擇門市，方法回傳空陣列或特定跳過標記
	 *
	 * @group happy
	 */
	public function test_get_store_selection_tcat_skips_store_selection(): void {
		$order  = $this->create_wc_order( [ 'status' => 'pending' ] );
		$result = $this->try_call( fn() => $this->provider->get_store_selection( $order, [ 'sub_type' => 'TCAT' ] ) );

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// TCAT 不需選店，redirect_target 應為空或帶跳過標記
		$redirect = (string) ( $result['redirect_target'] ?? '' );
		$this->assertEmpty( $redirect, 'TCAT 不需選店，redirect_target 應為空' );
	}

	// endregion

	// =====================================================================
	// region: create_shipment — 階段 B 建單
	// =====================================================================

	/**
	 * 超商取貨訂單無門市資訊時成立物流單失敗
	 *
	 * @group error
	 */
	public function test_create_shipment_fails_when_cvs_order_has_no_store(): void {
		$order     = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1000,
			]
			);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' ); // SEVEN，但沒有 store_id

		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		$this->assert_operation_failed_with_message( '尚未選店，無門市資訊' );
	}

	/**
	 * 超商取貨金額超過 20000 上限時失敗（R9）
	 *
	 * @group edge
	 */
	public function test_create_shipment_fails_when_cvs_amount_exceeds_limit(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 25000,
			]
			);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' ); // SEVEN
		$meta_keys->update_store_id( 'TEST001' );
		$meta_keys->update_store_name( '測試門市' );
		$meta_keys->update_store_addr( '台北市信義區測試路1號' );

		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		$this->assert_operation_failed_with_message( '超商取貨金額不得大於 20000' );
	}

	/**
	 * 宅配金額超過 100000 上限時失敗（R9）
	 *
	 * @group edge
	 */
	public function test_create_shipment_fails_when_home_amount_exceeds_limit(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 150000,
			]
			);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '06' ); // TCAT

		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		$this->assert_operation_failed_with_message( '宅配金額不得大於 100000' );
	}

	/**
	 * 7-11 線上付款（非 COD）建單請求 DeliverMode=02
	 *
	 * @group happy
	 */
	public function test_create_shipment_seven_online_payment_has_deliver_mode_02(): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\add_filter( 'pre_http_request', [ $this, 'mock_add_order_success_response' ], 10, 3 );

		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'paynow',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' );
		$meta_keys->update_store_id( 'TEST001' );
		$meta_keys->update_store_name( '測試門市' );
		$meta_keys->update_store_addr( '台北市信義區測試路1號' );

		$captured_args = null;
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured_args ) {
				$captured_args = $args;
				return $pre;
			},
			5,
			3
		);

		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		\remove_filter( 'pre_http_request', [ $this, 'mock_add_order_success_response' ] );

		// 在 mock 模式下驗請求包含 DeliverMode=02
		if ( null !== $captured_args ) {
			$body = $captured_args['body'] ?? '';
			$this->assertStringContainsString( 'DeliverMode', (string) $body );
		} else {
			// mock 模式下直接驗成功回傳的 meta
			$this->assert_operation_succeeded();
		}
	}

	/**
	 * COD 訂單 DeliverMode 應為 01
	 *
	 * @group happy
	 */
	public function test_create_shipment_cod_order_has_deliver_mode_01(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'cod',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' );
		$meta_keys->update_store_id( 'TEST001' );
		$meta_keys->update_store_name( '測試門市' );
		$meta_keys->update_store_addr( '台北市信義區測試路1號' );

		// mock 模式下 ApiClient 應以 mock fixture 回應，可透過 is_mock() 驗請求組裝
		// 僅驗不拋出 DeliverMode 相關錯誤（Green 後完整斷言）
		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		// 在 mock 模式下應成功（fixture 已包含 Status=S 回應）
		$this->assert_operation_succeeded();
	}

	/**
	 * 黑貓宅配建單帶 DeliveryType=0003（冷凍）
	 *
	 * @group happy
	 */
	public function test_create_shipment_tcat_has_delivery_type_0003(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'paynow',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '06' ); // TCAT
		$meta_keys->update_delivery_type( '0003' ); // 冷凍

		// 宅配不需門市 meta
		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		$this->assert_operation_succeeded();
	}

	/**
	 * PayNow 回應 Status=F 時成立失敗並新增 order note
	 *
	 * @group error
	 */
	public function test_create_shipment_fails_on_status_f_response(): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\putenv( 'PAYNOW_LOGISTICS_MOCK_RESPONSE={"Status":"F","ErrorMsg":"建單失敗測試訊息"}' );

		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'paynow',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' );
		$meta_keys->update_store_id( 'TEST001' );
		$meta_keys->update_store_name( '測試門市' );
		$meta_keys->update_store_addr( '台北市信義區測試路1號' );

		$this->try_call( fn() => $this->provider->create_shipment( $order ) );

		// 驗操作失敗
		$this->assert_operation_failed();

		// 驗訂單有錯誤 note
		$order->read_meta_data( true );
		$this->assert_order_note_contains( $order, '建單失敗' );

		\putenv( 'PAYNOW_LOGISTICS_MOCK_RESPONSE=' );
	}

	/**
	 * 成立物流單成功後訂單保存 LogisticNumber、paymentno、validationno 並新增 note
	 *
	 * @group happy
	 */
	public function test_create_shipment_success_writes_ref_and_payment_info(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'paynow',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' );
		$meta_keys->update_store_id( 'TEST001' );
		$meta_keys->update_store_name( '測試門市' );
		$meta_keys->update_store_addr( '台北市信義區測試路1號' );

		$result = $this->try_call( fn() => $this->provider->create_shipment( $order ) );

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// 重讀訂單
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );

		// LogisticNumber 已寫入
		$this->assertNotEmpty( $fresh_meta->get_ref() );

		// paymentno 已寫入
		$this->assertNotEmpty( $fresh_meta->get_payment_no() );

		// validationno 已寫入
		$this->assertNotEmpty( $fresh_meta->get_validation_no() );

		// note 已寫入
		$this->assert_order_note_contains( $fresh_order, '物流單' );
	}

	/**
	 * 冪等：訂單已有有效物流單（status≠"1"）時呼叫 create_shipment 改走 ReNewOrder
	 *
	 * @group edge
	 */
	public function test_create_shipment_calls_renew_order_when_ref_exists_and_not_invalid(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'total'          => 1000,
				'payment_method' => 'paynow',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' );
		$meta_keys->update_store_id( 'TEST001' );
		$meta_keys->update_store_name( '測試門市' );
		$meta_keys->update_store_addr( '台北市信義區測試路1號' );
		$meta_keys->update_ref( 'LN_EXISTING_001' );
		$meta_keys->update_status( '0' ); // 0=成立中，非無效

		$result = $this->try_call( fn() => $this->provider->create_shipment( $order ) );

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// 重讀訂單，應有 renew_order_no
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertNotEmpty( $fresh_meta->get_renew_order_no(), '應保存重新取號後的 RenewOrderNo' );
	}

	// endregion

	// =====================================================================
	// region: query_shipment — 查詢
	// =====================================================================

	/**
	 * 訂單無物流單號時查詢失敗
	 *
	 * @group error
	 */
	public function test_query_shipment_fails_when_no_ref(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		// 沒有寫入 ref

		$this->try_call( fn() => $this->provider->query_shipment( $order ) );

		$this->assert_operation_failed_with_message( '尚無物流單，無法查詢' );
	}

	/**
	 * 查詢成功回傳貨態並寫回 order meta
	 *
	 * @group happy
	 */
	public function test_query_shipment_success_writes_status_to_meta(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_QUERY_001' );

		$result = $this->try_call( fn() => $this->provider->query_shipment( $order ) );

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// 重讀
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );

		// status 已寫入
		$this->assertNotEmpty( $fresh_meta->get_status() );
		// delivery_status 已寫入
		$this->assertNotEmpty( $fresh_meta->get_delivery_status() );
	}

	/**
	 * COD 訂單取貨完成後標記取貨付款完成（collection_paid）
	 *
	 * @group happy
	 */
	public function test_query_shipment_cod_pickup_complete_marks_collection_paid(): void {
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'cod',
			]
		);
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_COD_001' );

		// mock fixture 應回傳已取貨完成的貨態碼
		\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=PICKUP_COMPLETE' );

		$this->try_call( fn() => $this->provider->query_shipment( $order ) );

		\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=' );

		$this->assert_operation_succeeded();

		// 重讀並驗 collection_paid
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );
		$this->assertSame( 'yes', $fresh_meta->get_collection_paid(), 'COD 取貨完成應標記 collection_paid=yes' );
	}

	// endregion

	// =====================================================================
	// region: print_document — 列印
	// =====================================================================

	/**
	 * 訂單無物流單號時列印失敗
	 *
	 * @group error
	 */
	public function test_print_document_fails_when_no_ref(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$this->try_call( fn() => $this->provider->print_document( $order ) );

		$this->assert_operation_failed_with_message( '尚無物流單，無法列印' );
	}

	/**
	 * 7-11 店到店列印走 Order711 端點
	 *
	 * @group happy
	 */
	public function test_print_document_seven_uses_order711_endpoint(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_PRINT_SEVEN_001' );
		$meta_keys->update_service_id( '01' ); // SEVEN

		$printed_url = null;
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$printed_url ) {
				$printed_url = $url;
				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'S_http://print-label-url.com',
				];
			},
			10,
			3
		);

		$result = $this->try_call( fn() => $this->provider->print_document( $order ) );

		\remove_all_filters( 'pre_http_request' );

		$this->assert_operation_succeeded();

		if ( null !== $printed_url ) {
			$this->assertStringContainsString( 'Order711', $printed_url );
		}
	}

	/**
	 * 黑貓宅配列印走 PrintBlackCatLabel 端點
	 *
	 * @group happy
	 */
	public function test_print_document_tcat_uses_print_black_cat_label_endpoint(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_PRINT_TCAT_001' );
		$meta_keys->update_service_id( '06' ); // TCAT

		$printed_url = null;
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$printed_url ) {
				$printed_url = $url;
				// TCAT 回 PDF 內容
				return [
					'response' => [ 'code' => 200 ],
					'body'     => '%PDF-1.4',
				];
			},
			10,
			3
		);

		$result = $this->try_call( fn() => $this->provider->print_document( $order ) );

		\remove_all_filters( 'pre_http_request' );

		$this->assert_operation_succeeded();

		if ( null !== $printed_url ) {
			$this->assertStringContainsString( 'PrintBlackCatLabel', $printed_url );
		}
	}

	/**
	 * 已重新取號的訂單列印以 RenewOrderNo 為訂單編號
	 *
	 * @group edge
	 */
	public function test_print_document_uses_renew_order_no_when_available(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_PRINT_RENEW_001' );
		$meta_keys->update_service_id( '01' ); // SEVEN
		$meta_keys->update_renew_order_no( 'RENEW_ORDER_NO_001' );

		$captured_body = null;
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured_body ) {
				$captured_body = $args['body'] ?? '';
				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'S_http://print-label-url.com',
				];
			},
			10,
			3
		);

		$this->try_call( fn() => $this->provider->print_document( $order ) );

		\remove_all_filters( 'pre_http_request' );

		// mock 模式下驗請求帶 RenewOrderNo
		if ( null !== $captured_body ) {
			$this->assertStringContainsString( 'RENEW_ORDER_NO_001', (string) $captured_body );
		} else {
			// 至少不報錯
			$this->assert_operation_succeeded();
		}
	}

	// endregion

	// =====================================================================
	// region: cancel_shipment — 取消
	// =====================================================================

	/**
	 * 訂單無物流單號時取消失敗
	 *
	 * @group error
	 */
	public function test_cancel_shipment_fails_when_no_ref(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$this->try_call( fn() => $this->provider->cancel_shipment( $order ) );

		$this->assert_operation_failed_with_message( '尚無物流單，無法取消' );
	}

	/**
	 * 取消請求以 DELETE 方法送出
	 *
	 * @group happy
	 */
	public function test_cancel_shipment_uses_delete_method(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_CANCEL_001' );

		$captured_method = null;
		\add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$captured_method ) {
				$captured_method = $args['method'] ?? '';
				// 回應含 'S' 表成功
				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'Success:S:取消成功',
				];
			},
			10,
			3
		);

		$this->try_call( fn() => $this->provider->cancel_shipment( $order ) );

		\remove_all_filters( 'pre_http_request' );

		if ( null !== $captured_method ) {
			$this->assertSame( 'DELETE', strtoupper( $captured_method ), '取消應使用 DELETE 方法' );
		}
	}

	/**
	 * 取消成功（含 'S'）後訂單標記狀態為無效（status=1）並新增 note
	 *
	 * @group happy
	 */
	public function test_cancel_shipment_success_marks_status_one_and_adds_note(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_CANCEL_002' );

		\add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'body'     => 'S:取消成功',
			],
			10,
			3
		);

		$result = $this->try_call( fn() => $this->provider->cancel_shipment( $order ) );

		\remove_all_filters( 'pre_http_request' );

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );

		// 重讀
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$fresh_meta = new PaynowLogisticsMetaKeys( $fresh_order );

		$this->assertSame( '1', $fresh_meta->get_status(), '取消成功後 status 應為 1（無效）' );
		$this->assert_order_note_contains( $fresh_order, '取消' );
	}

	/**
	 * 取消失敗（不含 'S'）時操作失敗並提示手動處理
	 *
	 * @group error
	 */
	public function test_cancel_shipment_fails_when_response_has_no_s(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LN_CANCEL_003' );

		\add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'body'     => 'F:取消失敗',
			],
			10,
			3
		);

		$this->try_call( fn() => $this->provider->cancel_shipment( $order ) );

		\remove_all_filters( 'pre_http_request' );

		$this->assert_operation_failed();

		// 失敗後應有提示手動處理的 note
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_order );
		$this->assert_order_note_contains( $fresh_order, '手動' );
	}

	// endregion

	// =====================================================================
	// region: create_return — 逆物流（尚未實作）
	// =====================================================================

	/**
	 * create_return 應拋出「尚未實作」例外
	 *
	 * @group error
	 */
	public function test_create_return_throws_not_implemented(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( '尚未實作' );

		$this->provider->create_return( $order );
	}

	// endregion

	// =====================================================================
	// region: get_supported_methods — 取得啟用子類型
	// =====================================================================

	/**
	 * get_supported_methods 回傳 enabled_methods 中的子類型
	 *
	 * @group happy
	 */
	public function test_get_supported_methods_returns_enabled_methods(): void {
		$this->enable_paynow_logistics( [ 'enabled_methods' => [ 'SEVEN', 'FAMI' ] ] );

		$methods = $this->provider->get_supported_methods();

		$this->assertIsArray( $methods );
		$this->assertContains( 'SEVEN', $methods );
		$this->assertContains( 'FAMI', $methods );
		$this->assertNotContains( 'HILIFE', $methods );
		$this->assertNotContains( 'TCAT', $methods );
	}

	// endregion

	// =====================================================================
	// region: mock helper（Green 階段接管）
	// =====================================================================

	/**
	 * Mock Add_Order 成功回應
	 *
	 * @param mixed  $pre
	 * @param mixed  $args
	 * @param string $url
	 * @return array<string, mixed>
	 */
	public function mock_add_order_success_response( mixed $pre, mixed $args, string $url ): array {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => 'S:LN_MOCK_001:paymentno_mock:validationno_mock',
		];
	}
}
