<?php
/**
 * PayNow（立吉富）後台交易管理整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/paynow-trade-management.feature
 *
 * 驗證：
 *  - 補查付款意圖（GET /api/v1/payment-intents/:id）：
 *    status=success + 本地訂單未 processing → 金額防竄改 + 冪等 → 補單轉 processing。
 *  - 補查付款意圖 status=draft → 不補單，維持現有狀態 + 記錄 order note。
 *  - 退款查詢（GET /api/v1/refunds/:uuid）：
 *    退款狀態寫回 _pc_paynow_refund_detail + order note。
 *  - query_trade catch 例外 → 回空陣列不 throw（IPaymentProvider 契約）。
 *  - capture / void_auth 維持 no-op（PayNow 體系 1 無對應端點；呼叫不報錯、不改狀態）。
 *  - add_order_actions 在 PayNow 訂單上含 pc_paynow_query_trade / pc_paynow_refund_query。
 *  - add_order_actions 不在非本 gateway 訂單出現 PayNow 操作選項。
 *
 * TDD 紅燈：
 *  PaynowGateway::handle_query_action / handle_refund_query_action / add_order_actions
 *    靜態方法尚未存在（Cycle 4）；
 *  PaynowRestClient::retrieve_payment_intent / retrieve_refund 尚未接線（Cycle 4）。
 *  query_trade 方法尚未覆寫（仍用父類預設，應覆寫為 catch→回陣列）。
 *
 * Mock 手法：
 *  外部 HTTP 一律透過 WP filter mock：
 *   `paynow_mock_retrieve_payment_intent_response` — RestClient::retrieve_payment_intent 回傳 fixture
 *   `paynow_mock_retrieve_refund_response` — RestClient::retrieve_refund 回傳 fixture
 *   `paynow_mock_retrieve_exception` — RestClient 拋例外
 *
 * PayNow REST API 參考（payment-rest-api.md §4.2 §5.3）：
 *  - GET /api/v1/payment-intents/:id：status: draft/processing/pending_review/success/canceled
 *  - GET /api/v1/refunds/:uuid：type: success/failed/rejected/processing
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *       --filter PaynowTradeManagement --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowTradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 後台交易管理測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowTradeManagementTest extends TestCase {

	/** @var string PayNow 測試用 PrivateKey */
	private const PRIVATE_KEY = 'test_private_key_paynow_trade_002';

	/** @var string PayNow 測試用 PublicKey */
	private const PUBLIC_KEY = 'test_public_key_paynow_trade_002';

	/**
	 * 每次測試前啟用 paynow（test 模式）+ 設定 mock 補查回應（success）
	 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			PaynowSettingsDTO::ID,
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => self::PUBLIC_KEY,
				'private_key' => self::PRIVATE_KEY,
			]
		);

		// 預設 MOCK：retrieve_payment_intent 回 status=success（已付款，補查補單用）
		\add_filter(
			'paynow_mock_retrieve_payment_intent_response',
			static function ( mixed $default ): mixed {
				return [
					'status'  => 200,
					'type'    => 'success',
					'message' => '查詢成功',
					'result'  => [
						'id'     => 'pp_test_mock_001',
						'status' => 'success',
						'amount' => 1000,
					],
				];
			}
		);

		// 預設 MOCK：retrieve_refund 回 type=success
		\add_filter(
			'paynow_mock_retrieve_refund_response',
			static function ( mixed $default ): mixed {
				return [
					'status'  => 200,
					'type'    => 'success',
					'message' => '退款查詢成功',
					'result'  => [
						'id'     => 'rf_test_mock_001',
						'type'   => 'success',
						'amount' => 1000,
					],
				];
			}
		);
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\remove_all_filters( 'paynow_mock_retrieve_payment_intent_response' );
		\remove_all_filters( 'paynow_mock_retrieve_refund_response' );
		\remove_all_filters( 'paynow_mock_retrieve_exception' );
		\delete_option( ProviderUtils::get_option_name( PaynowSettingsDTO::ID ) );
		parent::tear_down();
	}

	/**
	 * 建立 PayNow 已付款訂單（含 PaymentIntentId + payment_detail + TWD 幣別）
	 *
	 * @param string $payment_intent_id PaymentIntentId
	 * @param string $status            WC 訂單狀態
	 * @param float  $total             訂單金額
	 * @param string $payment_type      付款方式
	 * @return \WC_Order
	 */
	private function create_paynow_order(
		string $payment_intent_id = 'pp_test_trade_001',
		string $status = 'processing',
		float $total = 1000.0,
		string $payment_type = 'CreditCard'
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => PaynowGateway::ID,
				'total'          => $total,
			]
		);

		// ⚠️ store 預設幣別 USD，PayNow 僅支援 TWD
		$order->set_currency( 'TWD' );
		$order->save();

		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_trade_no( PaynowTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_intent_id( $payment_intent_id );
		$meta_keys->update_payment_detail(
			[
				'PaymentIntentId' => $payment_intent_id,
				'PaymentType'     => $payment_type,
				'Amount'          => (int) $total,
				'Status'          => 'success',
			]
		);

		return $order;
	}

	/**
	 * 建立尚未付款的 PayNow 訂單（pending，模擬 Webhook 漏接）
	 *
	 * @param string $payment_intent_id PaymentIntentId（只寫 intent_id，不寫 payment_detail）
	 * @param float  $total             訂單金額
	 * @return \WC_Order
	 */
	private function create_pending_paynow_order(
		string $payment_intent_id = 'pp_test_pending_001',
		float $total = 1000.0
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PaynowGateway::ID,
				'total'          => $total,
			]
		);

		$order->set_currency( 'TWD' );
		$order->save();

		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_trade_no( PaynowTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_intent_id( $payment_intent_id );
		// 刻意不寫 payment_detail（模擬 Webhook 漏接）

		return $order;
	}

	// ========== Smoke ==========

	/**
	 * PaynowGateway 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway可被實例化(): void {
		$gateway = new PaynowGateway();
		$this->assertInstanceOf( PaynowGateway::class, $gateway );
	}

	/**
	 * PaynowGateway 有靜態方法 handle_query_action（Cycle 4 待實作）
	 *
	 * 紅燈原因：handle_query_action Cycle 4 才新增
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway有handle_query_action靜態方法(): void {
		$this->assertTrue(
			\method_exists( PaynowGateway::class, 'handle_query_action' ),
			'PaynowGateway::handle_query_action 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	/**
	 * PaynowGateway 有靜態方法 handle_refund_query_action（Cycle 4 待實作）
	 *
	 * 紅燈原因：handle_refund_query_action Cycle 4 才新增
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway有handle_refund_query_action靜態方法(): void {
		$this->assertTrue(
			\method_exists( PaynowGateway::class, 'handle_refund_query_action' ),
			'PaynowGateway::handle_refund_query_action 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	/**
	 * PaynowGateway 有靜態方法 add_order_actions（Cycle 4 待實作）
	 *
	 * @test
	 * @group smoke
	 * @group paynow
	 * @group payment
	 */
	public function test_冒煙_PaynowGateway有add_order_actions靜態方法(): void {
		$this->assertTrue(
			\method_exists( PaynowGateway::class, 'add_order_actions' ),
			'PaynowGateway::add_order_actions 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	// ========== Happy Path：補查付款意圖 ==========

	/**
	 * 補查付款意圖確認已付款後補單（Webhook 漏收場景）
	 *
	 * 規格依據：paynow-trade-management.feature 場景：補查付款意圖確認已付款後補單
	 * API：GET /api/v1/payment-intents/pp_test100 → status=success
	 *
	 * 紅燈原因：handle_query_action + retrieve_payment_intent Cycle 4 才實作
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_補查付款意圖status為success且訂單pending時補單轉processing(): void {
		// Given: Webhook 漏接的 pending 訂單（PaymentIntentId 已寫入）
		$order = $this->create_pending_paynow_order(
			payment_intent_id: 'pp_test100',
			total: 1000.0
		);

		// When: 後台觸發補查付款意圖 action handler
		PaynowGateway::handle_query_action( $order );

		// Then: 訂單狀態轉為 processing + order note 含查詢結果
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'processing' );
		$this->assert_order_note_contains( $fresh_order, '查詢' );
	}

	/**
	 * 補查付款意圖已 processing → 不重複補單（冪等）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_補查付款意圖訂單已processing時不重複補單(): void {
		// Given: 已 processing 的訂單
		$order = $this->create_paynow_order( status: 'processing' );

		// When: 後台觸發補查
		PaynowGateway::handle_query_action( $order );

		// Then: 狀態仍為 processing（不變）
		$this->assert_order_status( \wc_get_order( $order->get_id() ), 'processing' );
	}

	/**
	 * 補查付款意圖 status=draft → 不補單，記錄 order note 說明目前狀態
	 *
	 * 規格依據：paynow-trade-management.feature 場景：補查付款意圖狀態尚未成功時不補單
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_補查付款意圖status為draft時不補單並記錄order_note(): void {
		// Given: 改 mock 回 status=draft（付款意圖尚未完成）
		\remove_all_filters( 'paynow_mock_retrieve_payment_intent_response' );
		\add_filter(
			'paynow_mock_retrieve_payment_intent_response',
			static function ( mixed $default ): mixed {
				return [
					'status'  => 200,
					'type'    => 'success',
					'message' => '查詢成功',
					'result'  => [
						'id'     => 'pp_test101',
						'status' => 'draft',
						'amount' => 1000,
					],
				];
			}
		);

		$order = $this->create_pending_paynow_order( payment_intent_id: 'pp_test101' );

		// When: 補查
		PaynowGateway::handle_query_action( $order );

		// Then: 訂單維持「等待付款」不補單 + order note 說明目前狀態
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'pending' );
		$this->assert_order_note_contains( $fresh_order, 'draft' );
	}

	/**
	 * 補查付款意圖時金額防竄改守衛：API 回應 amount 不符本地訂單 → 不補單
	 *
	 * 規格依據：paynow-trade-management.feature 規則：通過金額防竄改與冪等檢查後訂單補單轉「處理中」
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_補查付款意圖金額不符本地訂單時不補單(): void {
		// Given: 改 mock 回 amount=9999（與訂單 1000 不符）
		\remove_all_filters( 'paynow_mock_retrieve_payment_intent_response' );
		\add_filter(
			'paynow_mock_retrieve_payment_intent_response',
			static function ( mixed $default ): mixed {
				return [
					'status'  => 200,
					'type'    => 'success',
					'message' => '查詢成功',
					'result'  => [
						'id'     => 'pp_test_amount_mismatch',
						'status' => 'success',
						'amount' => 9999, // 金額與本地訂單 1000 不符
					],
				];
			}
		);

		$order = $this->create_pending_paynow_order(
			payment_intent_id: 'pp_test_amount_mismatch',
			total: 1000.0
		);

		// When
		PaynowGateway::handle_query_action( $order );

		// Then: 金額不符，訂單維持 pending（不補單）
		$this->assert_order_status( \wc_get_order( $order->get_id() ), 'pending' );
	}

	// ========== Happy Path：退款查詢 ==========

	/**
	 * 退款查詢回傳退款狀態並寫回 _pc_paynow_refund_detail + order note
	 *
	 * 規格依據：paynow-trade-management.feature 場景：退款查詢回傳退款狀態並寫回明細
	 * API：GET /api/v1/refunds/rf_test100
	 *
	 * 紅燈原因：handle_refund_query_action + retrieve_refund Cycle 4 才實作
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_退款查詢回傳退款狀態並寫回refund_detail與order_note(): void {
		// Given: 訂單 _pc_paynow_refund_detail 含退款 uuid（rf_test100）
		$order     = $this->create_paynow_order( payment_intent_id: 'pp_test_refund_query_001' );
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_refund_detail(
			[
				'id'     => 'rf_test100',
				'type'   => 'processing', // 目前為處理中
				'amount' => 1000,
			]
		);

		// When: 後台觸發退款查詢
		PaynowGateway::handle_refund_query_action( $order );

		// Then: 退款狀態寫回 _pc_paynow_refund_detail + order note 含退款查詢結果
		$fresh_order    = \wc_get_order( $order->get_id() );
		$fresh_meta     = new PaynowMetaKeys( $fresh_order );
		$updated_refund = $fresh_meta->get_refund_detail();

		$this->assertNotEmpty( $updated_refund, '退款查詢結果應寫回 _pc_paynow_refund_detail' );
		$this->assert_order_note_contains( $fresh_order, '退款查詢' );
	}

	/**
	 * 退款查詢無 refund uuid → 拒絕查詢，記錄 note 提示
	 *
	 * 規格依據：paynow-trade-management.feature 規則：退款查詢必須有退款 uuid
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_退款查詢無uuid時拒絕並記錄order_note(): void {
		// Given: 訂單沒有 _pc_paynow_refund_detail（或 refund_detail 無 id）
		$order = $this->create_paynow_order( payment_intent_id: 'pp_test_no_refund_uuid' );
		// 不寫 refund_detail（或 detail 無 id）

		// When
		PaynowGateway::handle_refund_query_action( $order );

		// Then: order note 含查詢失敗提示（無 uuid 不可查）
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款' );
	}

	// ========== Edge：capture / void_auth 維持 no-op ==========

	/**
	 * capture（請款）呼叫不報錯、不改訂單狀態（no-op）
	 *
	 * 規格依據：paynow-trade-management.feature 規則（Q4）：capture/void_auth 維持 AbstractPaymentGateway no-op
	 * 實作計劃：spec/open-issue/paynow-implementation-plan.md「capture/void_auth 不覆寫」
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_capture呼叫不報錯且不改訂單狀態(): void {
		// Given: PayNow 訂單（processing）
		$order   = $this->create_paynow_order( status: 'processing' );
		$gateway = new PaynowGateway();

		// When: 呼叫 capture
		$result = $gateway->capture( $order );

		// Then: 不報錯（不拋例外、不回 WP_Error）；狀態仍為 processing
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assert_order_status( \wc_get_order( $order->get_id() ), 'processing' );
	}

	/**
	 * void_auth（取消授權）呼叫不報錯、不改訂單狀態（no-op）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_void_auth呼叫不報錯且不改訂單狀態(): void {
		// Given: PayNow 訂單（processing）
		$order   = $this->create_paynow_order( status: 'processing' );
		$gateway = new PaynowGateway();

		// When: 呼叫 void_auth
		$result = $gateway->void_auth( $order );

		// Then: 不報錯；狀態仍為 processing
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assert_order_status( \wc_get_order( $order->get_id() ), 'processing' );
	}

	/**
	 * query_trade catch 例外 → 回空陣列不 throw（IPaymentProvider 契約）
	 *
	 * 規格依據：paynow-implementation-plan.md 錯誤處理登記表：query_trade catch → 回空陣列
	 *
	 * 紅燈原因：query_trade 尚未覆寫（父類預設；Cycle 4 需覆寫為 catch→回陣列）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_query_trade連線例外時回空陣列不throw(): void {
		// Given: 設定 RestClient 拋例外
		\add_filter(
			'paynow_mock_retrieve_exception',
			static function (): bool {
				return true;
			}
		);

		$order   = $this->create_paynow_order( payment_intent_id: 'pp_test_query_exception' );
		$gateway = new PaynowGateway();

		// When: query_trade（IPaymentProvider 契約：catch → 回陣列，不 throw）
		$result = null;
		try {
			$result = $gateway->query_trade( $order );
		} catch ( \Throwable $e ) {
			$this->fail( 'query_trade 不應 throw，但拋出：' . $e->getMessage() );
		}

		// Then: 回空陣列（非 false / null / WP_Error）
		$this->assertIsArray( $result, 'query_trade 應回陣列，不 throw' );
	}

	// ========== Happy Path：後台操作選項 ==========

	/**
	 * PayNow 訂單後台操作含補查付款意圖 + 退款查詢
	 *
	 * 規格依據：paynow-trade-management.feature 規則：後台操作選項包含補查付款意圖與退款查詢
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_PayNow訂單後台操作含補查付款意圖與退款查詢(): void {
		// Given: PayNow 訂單
		$order = $this->create_paynow_order();

		// When: 套用 woocommerce_order_actions filter
		$actions = PaynowGateway::add_order_actions( [], $order );

		// Then: 含補查付款意圖 + 退款查詢（PayNow 專屬 action key，前綴 pc_paynow_）
		$this->assertArrayHasKey( 'pc_paynow_query_trade', $actions, '應含補查付款意圖 action' );
		$this->assertArrayHasKey( 'pc_paynow_refund_query', $actions, '應含退款查詢 action' );
	}

	/**
	 * PayNow 後台操作不含 capture / void_auth（PayNow 體系 1 無此端點）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_PayNow後台操作不含capture與void_auth(): void {
		// Given: PayNow 訂單
		$order   = $this->create_paynow_order();
		$actions = PaynowGateway::add_order_actions( [], $order );

		// Then: 不含 capture / void_auth（PayNow 體系 1 無請款 / 取消授權端點）
		$this->assertArrayNotHasKey( 'pc_paynow_capture', $actions );
		$this->assertArrayNotHasKey( 'pc_paynow_cancel_auth', $actions );
		$this->assertArrayNotHasKey( 'pc_paynow_void_auth', $actions );
	}

	/**
	 * 非 PayNow 訂單不出現 PayNow 後台操作選項
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_非PayNow訂單不出現PayNow後台操作選項(): void {
		// Given: SLP 訂單
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000.0,
			]
		);

		// When
		$actions = PaynowGateway::add_order_actions( [], $order );

		// Then: 不含任何 PayNow 動作
		$this->assertArrayNotHasKey( 'pc_paynow_query_trade', $actions );
		$this->assertArrayNotHasKey( 'pc_paynow_refund_query', $actions );
	}

	/**
	 * PayNow 後台操作 action key 前綴為 pc_paynow_（不與其他 gateway 衝突）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_PayNow後台action_key前綴為pc_paynow_(): void {
		// Given: PayNow 訂單
		$order   = $this->create_paynow_order();
		$actions = PaynowGateway::add_order_actions( [], $order );

		// Then: 所有 action key 前綴為 pc_paynow_（不是 pc_payuni_ / pc_payuni_uni_ 等）
		foreach ( \array_keys( $actions ) as $key ) {
			$this->assertStringStartsWith(
				'pc_paynow_',
				$key,
				"PayNow action key '{$key}' 前綴應為 pc_paynow_，以免與其他 gateway 衝突"
			);
		}
	}
}
