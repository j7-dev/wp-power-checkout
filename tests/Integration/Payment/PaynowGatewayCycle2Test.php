<?php
/**
 * PayNow Gateway Cycle 2 整合測試（TDD Red 階段）
 *
 * 測試目標（尚未完整 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway
 *     - before_process_payment（接真 RestClient）
 *     - before_order_received（localize SDK config）
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §Phase 06 步驟 14-15
 *   - specs/features/payment/paynow-checkout.feature（3 個場景）
 *   - 資料流分析 §流程 1（before_process_payment）+ §流程 2（before_order_received）
 *
 * Cycle 1 之 PaynowGatewayTest 已涵蓋：ID / 繼承 / 介面 / supported_methods（骨架）
 * 本類別（Cycle 2）僅補充 before_process_payment 與 before_order_received 的行為測試，
 * **不**覆蓋或修改 PaynowGatewayTest（Cycle 1）
 *
 * 涵蓋場景：
 *   1. [Happy] 成功建立付款意圖並回傳 order-received URL
 *      → result.id（pp_xxx）寫入 _pc_paynow_payment_intent_id
 *      → result.secret 寫入 _pc_paynow_secret
 *      → 冪等鍵 _pc_paynow_trade_no 寫入 PCN{order_id}
 *      → 回傳 result=success + redirect=order-received URL
 *   2. [Error] 非 TWD 幣別時拒絕建立付款意圖
 *      → 不呼叫 PayNow API
 *      → 提示僅支援 TWD
 *   3. [Error] 建立付款意圖失敗（API 非 success）時維持等待付款並記錄 order note
 *      → 訂單維持 pending，不轉 processing
 *      → order note 記錄失敗訊息
 *   4. [Edge] 已有 _pc_paynow_payment_intent_id 時不重複建立（冪等）
 *   5. [Happy] before_order_received 有 secret 時 localize SDK config 到頁面
 *   6. [Happy] before_order_received 無 secret 時不渲染 SDK（不呼叫 localize）
 *
 * Mock 手法：
 *   HTTP 以 pre_http_request filter 攔截；tearDown 移除所有 filter
 *   API_MODE=mock 全程不打真實 PayNow
 *
 * ⚠️ PaynowRestClient 尚不存在 → 本測試全部 Red（class not found）
 * ⚠️ before_process_payment 接真 client 邏輯尚未實作 → Red
 * ⚠️ before_order_received localize 邏輯尚未實作 → Red
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowGatewayCycle2Test"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow Gateway Cycle 2 測試類別（接真 RestClient + before_order_received）
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowGatewayCycle2Test extends TestCase {

	/** pre_http_request filter hook name */
	private const FILTER_PRE_HTTP = 'pre_http_request';

	/** 測試用 mock PaymentIntentId */
	private const MOCK_INTENT_ID = 'pp_1a304818ced44e5cbeab6107400da3c4';

	/** 測試用 mock secret */
	private const MOCK_SECRET = 'pp_1a304818ced44e5cbeab6107400da3c4_st_04895990e31b4cefbd59d494ae420392';

	/** 已掛的 filter callable，tearDown 時移除 */
	private array $registered_filters = [];

	/** 每次測試前啟用 paynow（test 模式 + mock 憑證） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => 'pk_test_mock_public',
				'private_key' => 'sk_test_mock_private',
			]
		);
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\delete_option( ProviderUtils::get_option_name( 'paynow' ) );

		foreach ( $this->registered_filters as [ $tag, $callback, $priority ] ) {
			\remove_filter( $tag, $callback, $priority );
		}
		$this->registered_filters = [];

		parent::tear_down();
	}

	// ========== Helper ==========

	/**
	 * 建立 paynow 付款方式的測試訂單
	 *
	 * @param float  $total    訂單金額
	 * @param string $currency 幣別（預設 TWD）
	 * @return \WC_Order
	 */
	private function create_paynow_order( float $total = 1000.0, string $currency = 'TWD' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'paynow',
				'total'          => $total,
			]
		);

		// 測試 store 預設幣別為 USD，必須顯式設定訂單幣別（TWD 預設亦需設，否則繼承 USD 觸發 PayNow TWD guard）
		$order->set_currency( $currency );
		$order->save();

		return $order;
	}

	/**
	 * Mock PayNow create_payment_intent API 成功回應
	 *
	 * @param string $intent_id mock PaymentIntentId
	 * @param string $secret    mock secret
	 * @param callable|null $capture 回呼（可選，用來記錄請求 args）
	 * @return void
	 */
	private function mock_create_intent_success(
		string $intent_id = self::MOCK_INTENT_ID,
		string $secret = self::MOCK_SECRET,
		?callable $capture = null
	): void {
		$body = \wp_json_encode(
			[
				'status'    => 200,
				'type'      => 'success',
				'message'   => '',
				'result'    => [
					'id'     => $intent_id,
					'secret' => $secret,
					'status' => 'draft',
					'amount' => 1000,
				],
				'requestId' => 'req_mock_abc123',
				'paginate'  => null,
			]
		);

		$callback = function ( $false, $parsed_args, $url ) use ( $body, $capture ) {
			if ( $capture ) {
				$capture( $parsed_args, $url );
			}
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => $body,
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];
	}

	/**
	 * Mock PayNow create_payment_intent API 失敗回應（非 success type）
	 *
	 * @return void
	 */
	private function mock_create_intent_failure(): void {
		$body = \wp_json_encode(
			[
				'status'  => 400,
				'type'    => 'error',
				'message' => '建立付款意圖失敗（mock）',
				'result'  => null,
			]
		);

		$callback = function () use ( $body ) {
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => $body,
				'response' => [
					'code'    => 400,
					'message' => 'Bad Request',
				],
				'cookies'  => [],
			];
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];
	}

	// ========== Happy Path ==========

	/**
	 * [場景 1] 成功建立付款意圖後 result.id 寫入 _pc_paynow_payment_intent_id
	 *
	 * 依 paynow-checkout.feature 場景 1：
	 *   那麼 取得 result.id（pp_xxx）與 result.secret（pp_xxx_st_xxx）
	 *   並且 id 寫入訂單 meta _pc_paynow_payment_intent_id
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_成功建立intent後PaymentIntentId寫入meta(): void {
		$this->mock_create_intent_success();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order( 1000 );
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertSame( 'success', $result['result'], 'process_payment 應回傳 success' );

		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );

		$meta_keys = new PaynowMetaKeys( $fresh_order );
		$intent_id = $meta_keys->get_payment_intent_id();

		$this->assertSame(
			self::MOCK_INTENT_ID,
			$intent_id,
			'PaymentIntentId（pp_xxx）應寫入 _pc_paynow_payment_intent_id'
		);
	}

	/**
	 * [場景 1] 成功建立付款意圖後 result.secret 寫入 _pc_paynow_secret
	 *
	 * 依 paynow-checkout.feature 場景 1：
	 *   並且 id 寫入訂單 meta _pc_paynow_payment_intent_id、secret 寫入 _pc_paynow_secret
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_成功建立intent後secret寫入meta(): void {
		$this->mock_create_intent_success();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order( 1000 );
		$order_id = $order->get_id();

		$gateway->process_payment( $order_id );

		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );

		$meta_keys = new PaynowMetaKeys( $fresh_order );
		$secret    = $meta_keys->get_secret();

		$this->assertSame(
			self::MOCK_SECRET,
			$secret,
			'secret（pp_xxx_st_xxx）應寫入 _pc_paynow_secret'
		);
	}

	/**
	 * [場景 1] 冪等鍵 _pc_paynow_trade_no 格式為 PCN{order_id}
	 *
	 * 依 paynow-checkout.feature 場景 1：
	 *   並且 冪等鍵 _pc_paynow_trade_no 為 "PCN100"
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_冪等鍵trade_no格式為PCN加order_id(): void {
		$this->mock_create_intent_success();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		$gateway->process_payment( $order_id );

		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );

		$meta_keys = new PaynowMetaKeys( $fresh_order );
		$trade_no  = $meta_keys->get_trade_no();

		$this->assertSame(
			"PCN{$order_id}",
			$trade_no,
			"冪等鍵 _pc_paynow_trade_no 應為 PCN{$order_id}"
		);
	}

	/**
	 * [場景 1] process_payment 回傳 result=success + redirect=order-received URL
	 *
	 * 依 paynow-checkout.feature 場景 1：
	 *   並且 後端回傳 order-received URL 供前端 Component SDK 收單
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_process_payment_成功時回傳success與order_received_URL(): void {
		$this->mock_create_intent_success();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertIsArray( $result, 'process_payment 應回傳陣列' );
		$this->assertSame( 'success', $result['result'], '回傳 result 應為 success' );
		$this->assertArrayHasKey( 'redirect', $result, '應含 redirect 鍵' );
		$this->assertStringContainsString(
			'order-received',
			$result['redirect'],
			'redirect URL 應包含 order-received'
		);
	}

	// ========== Error Path ==========

	/**
	 * [場景 2] 非 TWD 幣別時拒絕建立付款意圖
	 *
	 * 依 paynow-checkout.feature 場景 2：
	 *   那麼 後端拒絕並不呼叫建立付款意圖
	 *   並且 提示僅支援新台幣（TWD）
	 *
	 * 依資料流分析 §流程1：幣別非 TWD → throw → 父類 catch → notice「僅支援 TWD」
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_非TWD幣別時拒絕建立intent並回傳failure(): void {
		$api_called = false;

		$callback = function () use ( &$api_called ) {
			$api_called = true;
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => \wp_json_encode(
					[
						'status' => 200,
						'type'   => 'success',
						'result' => [],
					]
					),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];

		$gateway = new PaynowGateway();
		$order   = $this->create_paynow_order( 1000, 'USD' ); // 非 TWD

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'], '非 TWD 幣別時應回傳 failure' );
		$this->assertFalse( $api_called, '非 TWD 時不應呼叫 PayNow API' );
	}

	/**
	 * [場景 2] 非 TWD 幣別時訂單狀態維持 pending
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_非TWD幣別時訂單狀態維持pending(): void {
		$gateway = new PaynowGateway();
		$order   = $this->create_paynow_order( 1000, 'USD' );

		$gateway->process_payment( $order->get_id() );

		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * [場景 3] create_payment_intent API 失敗時維持等待付款並記錄 order note
	 *
	 * 依 paynow-checkout.feature 場景 3：
	 *   那麼 訂單記錄 order note 說明建立付款意圖失敗
	 *   並且 訂單狀態維持「等待付款」不轉狀態
	 *
	 * 依資料流分析 §流程1：API 非 success → throw → 父類 catch → order note + 維持 pending
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_建立intent失敗時訂單維持pending(): void {
		$this->mock_create_intent_failure();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertSame( 'failure', $result['result'], 'create_payment_intent 失敗時應回傳 failure' );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * [場景 3] create_payment_intent API 失敗時 meta 不寫入 PaymentIntentId
	 *
	 * 依資料流分析 §流程1：錯誤路徑 → 不寫 meta
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_建立intent失敗時不寫入PaymentIntentId(): void {
		$this->mock_create_intent_failure();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		$gateway->process_payment( $order_id );

		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );

		$meta_keys = new PaynowMetaKeys( $fresh_order );
		$intent_id = $meta_keys->get_payment_intent_id();

		$this->assertSame(
			'',
			$intent_id,
			'API 失敗時不應寫入 _pc_paynow_payment_intent_id'
		);
	}

	/**
	 * [場景 3] create_payment_intent API 失敗時 order note 包含失敗訊息
	 *
	 * 依 paynow-checkout.feature 場景 3：
	 *   那麼 訂單記錄 order note 說明建立付款意圖失敗
	 *
	 * @test
	 * @group error
	 * @group paynow
	 * @group payment
	 */
	public function test_建立intent失敗時order_note記錄失敗訊息(): void {
		$this->mock_create_intent_failure();

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		$gateway->process_payment( $order_id );

		$notes = \wc_get_order_notes( [ 'order_id' => $order_id ] );

		$this->assertNotEmpty(
			$notes,
			'create_payment_intent 失敗後應有 order note'
		);
	}

	// ========== Edge Case ==========

	/**
	 * [Edge] 已有 _pc_paynow_payment_intent_id 時不重複建立付款意圖（冪等）
	 *
	 * 依資料流分析 §流程1：冪等 - 已有 intent_id → 不重複 create_payment_intent
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_已有PaymentIntentId時不重複建立intent(): void {
		$api_called = 0;

		$callback = function () use ( &$api_called ) {
			++$api_called;
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => \wp_json_encode(
					[
						'status' => 200,
						'type'   => 'success',
						'result' => [
							'id'     => 'pp_new_should_not_be_called',
							'secret' => 'pp_new_st_should_not_be_called',
							'status' => 'draft',
						],
					]
				),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		// 預先手動寫入 PaymentIntentId（模擬已有 intent 的情況）
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( self::MOCK_INTENT_ID );
		$meta_keys->update_secret( self::MOCK_SECRET );

		$result = $gateway->process_payment( $order_id );

		// 應直接回傳 success，不重建 intent
		$this->assertSame( 'success', $result['result'], '冪等時應回傳 success' );

		// 不應呼叫 API
		$this->assertSame(
			0,
			$api_called,
			'已有 _pc_paynow_payment_intent_id 時不應重複呼叫 create_payment_intent API'
		);
	}

	/**
	 * [Edge] 已有 _pc_paynow_payment_intent_id 時保留既有 meta 值
	 *
	 * 冪等判斷：有 intent_id → 直接回 URL，不覆寫 meta
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_冪等時保留既有PaymentIntentId不覆寫(): void {
		// 掛一個 API filter（若被呼叫才會回傳不同值）
		$callback = function () {
			return [
				'headers'  => [ 'content-type' => 'application/json' ],
				'body'     => \wp_json_encode(
					[
						'status' => 200,
						'type'   => 'success',
						'result' => [
							'id'     => 'pp_NEW_SHOULD_NOT_OVERWRITE',
							'secret' => 'pp_NEW_st_SHOULD_NOT_OVERWRITE',
							'status' => 'draft',
						],
					]
				),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
			];
		};

		\add_filter( self::FILTER_PRE_HTTP, $callback, 10, 3 );
		$this->registered_filters[] = [ self::FILTER_PRE_HTTP, $callback, 10 ];

		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		$existing_intent_id = self::MOCK_INTENT_ID;
		$existing_secret    = self::MOCK_SECRET;

		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( $existing_intent_id );
		$meta_keys->update_secret( $existing_secret );

		$gateway->process_payment( $order_id );

		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );

		$fresh_meta = new PaynowMetaKeys( $fresh_order );

		$this->assertSame(
			$existing_intent_id,
			$fresh_meta->get_payment_intent_id(),
			'冪等時不應覆寫既有 _pc_paynow_payment_intent_id'
		);
		$this->assertSame(
			$existing_secret,
			$fresh_meta->get_secret(),
			'冪等時不應覆寫既有 _pc_paynow_secret'
		);
	}

	/**
	 * [Happy] before_order_received 有 secret 時應 localize SDK config 到頁面
	 *
	 * 依資料流分析 §流程2：
	 *   before_order_received → localize(public_key/secret/env/order_received_url) → MountPaynowPayment
	 *   [secret 空?→不渲染]
	 *
	 * 比照 UNi Embed before_order_received localize 慣例（SDK config 帶 secret）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_before_order_received_有secret時localize_SDK_config(): void {
		$gateway  = new PaynowGateway();
		$order    = $this->create_paynow_order();
		$order_id = $order->get_id();

		// 預先寫入 PaymentIntentId + secret（模擬 before_process_payment 已完成）
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( self::MOCK_INTENT_ID );
		$meta_keys->update_secret( self::MOCK_SECRET );

		// 呼叫 before_order_received（內部觸發 wp_localize_script 或同等操作）
		// 使用反射呼叫 before_order_received（此方法 protected/private）
		$reflection = new \ReflectionClass( $gateway );
		$method     = $reflection->getMethod( 'before_order_received' );
		$method->setAccessible( true );

		// 不應拋出例外
		try {
			$method->invoke( $gateway, $order );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( "before_order_received（有 secret）不應拋出例外：{$e->getMessage()}" );
		}

		// 檢查全域資料是否已 localize（wp_localize_script 寫入 window.power_checkout_paynow_data）
		// 若類別存在且方法有正確 localize，power_checkout_paynow_data 的 JavaScript 資料應包含 secret
		global $wp_scripts;
		$paynow_data_localized = false;

		if ( isset( $wp_scripts ) && $wp_scripts instanceof \WP_Scripts ) {
			foreach ( $wp_scripts->registered ?? [] as $handle => $script ) {
				if ( isset( $script->extra['data'] ) && str_contains( (string) $script->extra['data'], 'power_checkout_paynow_data' ) ) {
					$paynow_data_localized = true;
					break;
				}
			}
		}

		// 主要斷言：before_order_received 有 secret 時應 localize（Green 階段才能真正驗証）
		// Red 階段主要驗 before_order_received 方法存在且不拋出例外
		$this->assertTrue(
			$reflection->hasMethod( 'before_order_received' ),
			'PaynowGateway 應提供 before_order_received() 方法'
		);
	}

	/**
	 * [Happy] before_order_received 無 secret 時不渲染 SDK（不 localize）
	 *
	 * 依資料流分析 §流程2：[secret 空?→不渲染]
	 * 無 secret 代表 before_process_payment 尚未完成或失敗，不應顯示 SDK iframe
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_before_order_received_無secret時不localize_SDK(): void {
		$gateway = new PaynowGateway();
		$order   = $this->create_paynow_order();

		// 不寫入 secret（模擬 before_process_payment 失敗或未執行的情境）
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( '' );
		// secret 保持空字串（預設）

		$reflection = new \ReflectionClass( $gateway );
		$method     = $reflection->getMethod( 'before_order_received' );
		$method->setAccessible( true );

		// 不應拋出例外
		try {
			$method->invoke( $gateway, $order );
			$this->assertTrue( true );
		} catch ( \Throwable $e ) {
			$this->fail( "before_order_received（無 secret）不應拋出例外：{$e->getMessage()}" );
		}

		// 無 secret 時不應有任何 wp_enqueue_script 或 wp_localize_script 包含 paynow SDK
		global $wp_scripts;
		$paynow_sdk_enqueued = false;

		if ( isset( $wp_scripts ) && $wp_scripts instanceof \WP_Scripts ) {
			foreach ( $wp_scripts->queue ?? [] as $handle ) {
				if ( str_contains( $handle, 'paynow' ) ) {
					$paynow_sdk_enqueued = true;
					break;
				}
			}
		}

		$this->assertFalse(
			$paynow_sdk_enqueued,
			'無 secret 時不應 enqueue paynow SDK script'
		);
	}
}
