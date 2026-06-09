<?php
/**
 * PAYUNi UNi Embed V3 前端 create-payment REST 端點整合測試（TDD Red 階段 — Cycle 2 / Phase 06）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedFrontendApi
 *
 * 規格依據：
 *   - specs/features/payment/payuni-uni-embed-create-payment.feature
 *   - specs/activities/PAYUNi統一金流UNiEmbed內嵌付款流程.activity（STEP:3 / DECISION:3a）
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 06
 *   - payuni-uni-embed-v3 SKILL.md §API 2 merchant_trade §回傳（API3D=1）
 *   - 範本：tests/Integration/Payment/EcpgFrontendApiTest.php（order_key auth 403/404/400 分流）
 *
 * 測試範疇（Cycle 2 / Phase 06）：
 *   - POST /power-checkout/payuni/uni-embed/create-payment（order_key auth，比照 ECPG）
 *   - order_key 正確 + _pc_payuni_uni_sdk_token 存在 → 觸發 merchant_trade
 *   - order_key hash_equals 比對 $order->get_order_key()，不符回 403
 *   - 缺 _pc_payuni_uni_sdk_token → 400 拒絕（token_get 未走）
 *   - 訂單不存在 → 404
 *   - 付款方式非 payuni_uni_embed → 400
 *   - merchant_trade 回 3D 導頁 URL → 回前端 need_3ds=true + three_d_url
 *   - merchant_trade 回非 3D（Status=SUCCESS，無 URL）→ 回前端 need_3ds=false
 *
 * Mock 手法：
 *   使用 WP filter payuni_uni_embed_mock_merchant_trade_response / _exception
 *   比照 Cycle 1 的 payuni_uni_embed_mock_token_get_response 慣例
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ --filter PayuniUniEmbed --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedFrontendApi;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed V3 前端 create-payment 端點測試類別（Cycle 2）
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedFrontendApiTest extends TestCase {

	/** 每次測試前啟用 payuni_uni_embed（test 模式） */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			'payuni_uni_embed',
			[
				'enabled'       => 'yes',
				'mode'          => 'test',
				'merchant_id'   => 'UNI_TEST_MER',
				'hash_key'      => '12345678901234567890123456789012',
				'hash_iv'       => '1234567890123456',
				'iframe_domain' => 'https://localhost',
			]
		);

		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
	}

	/** 每次測試後清理設定 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\delete_option( ProviderUtils::get_option_name( 'payuni_uni_embed' ) );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立已完成 token_get 的 payuni_uni_embed 訂單（含 _pc_payuni_uni_sdk_token）
	 *
	 * @param float  $total    訂單金額
	 * @param string $sdk_token 模擬的 SDK_TOKEN
	 * @return \WC_Order
	 */
	private function create_uni_embed_order_with_sdk_token(
		float $total = 1000.0,
		string $sdk_token = 'MOCK_SDK_TOKEN_ABCDEF'
	): \WC_Order {
		$order     = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_uni_embed',
				'total'          => $total,
			]
		);
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( $sdk_token );
		$order->save();
		return $order;
	}

	/**
	 * 建立沒有 SDK_TOKEN 的 payuni_uni_embed 訂單（模擬 token_get 未走）
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_uni_embed_order_without_sdk_token( float $total = 1000.0 ): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_uni_embed',
				'total'          => $total,
			]
		);
	}

	/**
	 * 組裝 create-payment REST 請求
	 *
	 * @param int    $order_id   訂單 ID
	 * @param string $order_key  訂單 key
	 * @param string $trade_result 前端 SDK getTradeResult 回傳的綁定結果（mock）
	 * @return \WP_REST_Request
	 */
	private function build_request(
		int $order_id,
		string $order_key,
		string $trade_result = 'MOCK_TRADE_RESULT_TOKEN'
	): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/uni-embed/create-payment' );
		$request->set_body_params(
			[
				'order_id'     => $order_id,
				'order_key'    => $order_key,
				'trade_result' => $trade_result,
			]
		);
		return $request;
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniUniEmbedFrontendApi 類別存在且可實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedFrontendApi類別存在(): void {
		$this->assertTrue(
			\class_exists( PayuniUniEmbedFrontendApi::class ),
			'PayuniUniEmbedFrontendApi 類別尚不存在（Red 階段預期失敗）'
		);
	}

	/**
	 * create-payment 端點 URL 包含正確路徑段
	 * 比照 ECPG 的 EcpgFrontendApi::get_create_payment_url() 設計
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_create_payment端點URL包含正確路徑(): void {
		$this->assertStringContainsString(
			'power-checkout/payuni/uni-embed/create-payment',
			PayuniUniEmbedFrontendApi::get_create_payment_url()
		);
	}

	// ========== 安全性：order_key 驗證（Security） ==========

	/**
	 * order_key 錯誤時回 403，不送出 merchant_trade
	 *
	 * 依規格：前端請求須通過 order_key 驗證（hash_equals 比對 $order->get_order_key()，不符回 403）
	 * 以 hash_equals 比對確保 timing-safe，防止 timing attack
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_order_key不符時回403不送merchant_trade(): void {
		// Given: 訂單有 sdk_token，但請求帶錯誤的 order_key
		$order   = $this->create_uni_embed_order_with_sdk_token();
		$request = $this->build_request( $order->get_id(), 'wc_order_WRONG_KEY_XXXXXXXXXX' );

		// 確認 merchant_trade 未被呼叫（若被呼叫，filter 計數器會 +1）
		$merchant_trade_called = 0;
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function ( array $response ) use ( &$merchant_trade_called ): array {
				$merchant_trade_called++;
				return $response;
			}
		);

		// When
		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		// Then: 403 拒絕
		$this->assertSame( 403, $response->get_status(), 'order_key 不符時應回 403' );
		$this->assertSame( 'error', $response->get_data()['code'] );
		$this->assertSame( 0, $merchant_trade_called, 'order_key 不符時不應呼叫 merchant_trade' );
	}

	/**
	 * order_key 為空字串時回 403，不送出 merchant_trade
	 *
	 * 空字串同樣必須被 hash_equals 拒絕（空字串不等同有效 key）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_order_key為空時回403(): void {
		$order   = $this->create_uni_embed_order_with_sdk_token();
		$request = $this->build_request( $order->get_id(), '' );

		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		$this->assertSame( 403, $response->get_status(), 'order_key 空字串時應回 403' );
	}

	/**
	 * order_key 驗證使用 hash_equals 而非 == 比對（timing-safe）
	 *
	 * 依規格：hash_equals 比對 $order->get_order_key()
	 * 驗證方式：反射取得方法，或確認 403 在 key 部分相符但大小寫不同時也被拒絕
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_order_key大小寫不同時回403(): void {
		$order = $this->create_uni_embed_order_with_sdk_token();
		// 取真實 key 並故意改大小寫（== 比對可能通過，hash_equals 仍嚴格）
		$wrong_case_key = \strtoupper( $order->get_order_key() );

		// 僅在大小寫確實不同時進行測試（key 全大寫與原始不同才有意義）
		if ( $wrong_case_key === $order->get_order_key() ) {
			$this->markTestSkipped( '訂單 key 已全為大寫，無法驗證大小寫差異' );
		}

		$request  = $this->build_request( $order->get_id(), $wrong_case_key );
		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		$this->assertSame( 403, $response->get_status(), '大小寫不符的 order_key 應回 403（hash_equals 嚴格比對）' );
	}

	// ========== 錯誤處理（Error） ==========

	/**
	 * 訂單不存在時回 404
	 *
	 * 依規格：前置（狀態）- 訂單必須存在且付款方式為 payuni_uni_embed
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_錯誤_訂單不存在時回404(): void {
		$request = $this->build_request( 999999999, 'wc_order_anything' );

		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		$this->assertSame( 404, $response->get_status(), '訂單不存在時應回 404' );
	}

	/**
	 * 訂單付款方式非 payuni_uni_embed 時回 400
	 *
	 * 依規格：前置（狀態）- 訂單必須存在且付款方式為 payuni_uni_embed
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_錯誤_付款方式非payuni_uni_embed時回400(): void {
		$order   = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'other_gateway',
				'total'          => 1000,
			]
		);
		$request = $this->build_request( $order->get_id(), $order->get_order_key() );

		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		$this->assertSame( 400, $response->get_status(), '付款方式非 payuni_uni_embed 時應回 400' );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}

	/**
	 * 訂單無 _pc_payuni_uni_sdk_token（token_get 未走）時回 400 拒絕
	 *
	 * 依規格：前置（狀態）- 訂單須已有 _pc_payuni_uni_sdk_token（未走過 token_get 則流程異常拒絕）
	 * 此情境代表前端直接 POST create-payment 跳過 token_get，屬於流程攻擊
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_錯誤_無SDK_TOKEN時拒絕不呼叫merchant_trade(): void {
		$order   = $this->create_uni_embed_order_without_sdk_token();
		$request = $this->build_request( $order->get_id(), $order->get_order_key() );

		$merchant_trade_called = 0;
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function ( array $response ) use ( &$merchant_trade_called ): array {
				$merchant_trade_called++;
				return $response;
			}
		);

		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		// 應回 400（流程異常）
		$this->assertSame( 400, $response->get_status(), '無 SDK_TOKEN 時應回 400（流程異常）' );
		$this->assertSame( 'error', $response->get_data()['code'] );
		$this->assertSame( 0, $merchant_trade_called, '無 SDK_TOKEN 時不應呼叫 merchant_trade' );
	}

	/**
	 * trade_result 參數為空字串時回 400
	 *
	 * 比照 EcpgFrontendApiTest::test_pay_token為空時回400()
	 * trade_result = 前端 SDK getTradeResult 取得的綁定結果，空代表前端未完成綁卡
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_錯誤_trade_result為空時回400(): void {
		$order   = $this->create_uni_embed_order_with_sdk_token();
		$request = $this->build_request( $order->get_id(), $order->get_order_key(), '' );

		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		$this->assertSame( 400, $response->get_status(), 'trade_result 空時應回 400' );
	}

	// ========== 快樂路徑（Happy） ==========

	/**
	 * 合法 order_key + 有 SDK_TOKEN → 觸發 merchant_trade → 回 need_3ds=false（非 3D 直接授權）
	 *
	 * 依規格：回應為非 3D 直接授權（Status=SUCCESS）時不導頁，等待 NotifyURL 幕後確認
	 * 依 payuni-uni-embed-v3 §非 3D 直接授權成功：Status=SUCCESS，無 URL 欄位
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_快樂路徑_合法請求觸發merchant_trade_非3D回need_3ds_false(): void {
		// Given: 訂單已有 SDK_TOKEN
		$order = $this->create_uni_embed_order_with_sdk_token();

		// Mock merchant_trade 回應：非 3D 直接授權成功（不含 URL）
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function () use ( $order ): array {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'MerID'       => 'UNI_TEST_MER',
					'MerTradeNo'  => 'PCE' . $order->get_id(),
					'Gateway'     => '9',
					'TradeNo'     => 'UNI20260609000001',
					'TradeAmt'    => 1000,
					'TradeStatus' => '1',
					'PaymentType' => '1',
					'AuthCode'    => '123456',
					// 注意：非 3D 不帶 URL 欄位
				];
			}
		);

		$request  = $this->build_request( $order->get_id(), $order->get_order_key() );
		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		// Then: 200 success，need_3ds=false
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertFalse( $data['data']['need_3ds'], '非 3D 授權時 need_3ds 應為 false' );
		$this->assertArrayNotHasKey( 'three_d_url', $data['data'], '非 3D 授權時不應帶 three_d_url' );
	}

	/**
	 * 合法 order_key + 有 SDK_TOKEN → merchant_trade 回 3D 導頁 URL → 回前端 need_3ds=true + three_d_url
	 *
	 * 依規格：回應為 3D 交易（含導頁 URL 或 API3D=1 強制 3D）時前端導向銀行 3D 驗證頁
	 * 依 payuni-uni-embed-v3 §回傳（API3D=1）：Status=SUCCESS + Message=建立幕後3D成功 + URL
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_快樂路徑_merchant_trade回3D_URL時回need_3ds_true與three_d_url(): void {
		$order = $this->create_uni_embed_order_with_sdk_token();

		// Mock merchant_trade 回應：API3D=1 強制 3D（含 URL）
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function (): array {
				return [
					'Status'  => 'SUCCESS',
					'Message' => '建立幕後3D成功',
					'MerID'   => 'UNI_TEST_MER',
					'URL'     => 'https://sandbox-api.payuni.com.tw/3DVerify/MOCK_3D_TOKEN',
				];
			}
		);

		$request  = $this->build_request( $order->get_id(), $order->get_order_key() );
		$response = PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		// Then: 200 success，need_3ds=true，帶 three_d_url
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertTrue( $data['data']['need_3ds'], '3D 授權時 need_3ds 應為 true' );
		$this->assertArrayHasKey( 'three_d_url', $data['data'], '3D 授權時應帶 three_d_url' );
		$this->assertNotEmpty( $data['data']['three_d_url'], 'three_d_url 不應為空' );
		$this->assertStringContainsString( '3DVerify', $data['data']['three_d_url'] );
	}

	/**
	 * 合法請求成功時 MerTradeNo 寫入 _pc_payuni_uni_trade_no
	 *
	 * 依規格：後置（狀態）- 建單時寫入冪等鍵 MerTradeNo 至 order meta _pc_payuni_uni_trade_no
	 * 格式：PCE + order_id（例：PCE100）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_快樂路徑_merchant_trade成功時MerTradeNo寫入meta(): void {
		$order = $this->create_uni_embed_order_with_sdk_token();

		// Mock 非 3D 授權成功
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function () use ( $order ): array {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'MerTradeNo'  => 'PCE' . $order->get_id(),
					'Gateway'     => '9',
					'TradeStatus' => '1',
					'PaymentType' => '1',
				];
			}
		);

		$request = $this->build_request( $order->get_id(), $order->get_order_key() );
		PayuniUniEmbedFrontendApi::instance()->post_uni_embed_create_payment_callback( $request );

		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertNotNull( $fresh_order );
		$meta_keys = new PayuniUniEmbedMetaKeys( $fresh_order );
		$trade_no  = $meta_keys->get_trade_no();

		$this->assertSame(
			'PCE' . $order->get_id(),
			$trade_no,
			'merchant_trade 成功後應寫入 MerTradeNo 至 _pc_payuni_uni_trade_no'
		);
	}
}
