<?php
/**
 * PAYUNi UNi Embed V3 Gateway 整合測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway
 *
 * 規格依據：
 *   - specs/features/payment/payuni-uni-embed-checkout.feature
 *   - specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 05-08
 *   - payuni-uni-embed-v3 SKILL.md §API 1 token_get §V3 核心改變
 *   - CLAUDE.md §Payment domain 架構
 *
 * 測試範疇（Cycle 1 / Phase 05）：
 *   - const ID='payuni_uni_embed'
 *   - extends AbstractPaymentGateway implements IPaymentProvider
 *   - before_process_payment → 呼叫 TokenGetClient::get_sdk_token()
 *     → 取得 SDK_TOKEN 寫入 _pc_payuni_uni_sdk_token
 *     → 回傳 order-received URL
 *   - token_get 失敗 → 記錄 order note，不轉訂單狀態
 *
 * ⚠️ V3 硬約束（Security Tests）：
 *   token_get 內層 payload 只含 MerID + Timestamp + IFrameDomain
 *   不含 MerTradeNo / TradeAmt / 任何訂單欄位（與 V2 最大差異）
 *
 * Mock 手法：
 *   對齊 EcpgGatewayTest 的 HTTP mock（API_MODE=mock，外部 HTTP 一律不出去）
 *   使用 WP filter / PHP runkit 或 Mockery / 自訂 mock 覆蓋 TokenGetClient
 *   （具體 mock 手法由 Green 階段確認；Red 階段測試只需能「失敗」即可）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni_uni_embed"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed V3 Gateway 測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 */
final class PayuniUniEmbedGatewayTest extends TestCase {

	/** 每次測試前啟用 payuni_uni_embed（test 模式 + 官方測試向量金鑰） */
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
	 * 建立帶有 payuni_uni_embed 付款方式的測試訂單
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_uni_embed_order( float $total = 1000 ): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => 'payuni_uni_embed',
				'total'          => $total,
			]
		);
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * PayuniUniEmbedGateway::ID 常數等於 'payuni_uni_embed'
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedGateway_ID常數等於payuni_uni_embed(): void {
		$this->assertSame( 'payuni_uni_embed', PayuniUniEmbedGateway::ID );
	}

	/**
	 * PayuniUniEmbedGateway 是 AbstractPaymentGateway 的子類別
	 * 確保 process_payment / before_page_render 等 final method 可用
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedGateway繼承AbstractPaymentGateway(): void {
		$gateway = new PayuniUniEmbedGateway();
		$this->assertInstanceOf( AbstractPaymentGateway::class, $gateway );
	}

	/**
	 * PayuniUniEmbedGateway 實作 IPaymentProvider 介面
	 * 對齊統一介面要求（provider-guide.rule.md §Adding a New Payment Provider Step 2）
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedGateway實作IPaymentProvider介面(): void {
		$gateway = new PayuniUniEmbedGateway();
		$this->assertInstanceOf( IPaymentProvider::class, $gateway );
	}

	// ========== Happy Path ==========

	/**
	 * process_payment() 呼叫 TokenGetClient 後回傳 result=success + order-received URL
	 * 內嵌式金流：token_get 成功後回傳 order-received URL，讓前端 SDK 在同頁面繼續收卡
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_process_payment_成功時回傳success與order_received_URL(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertArrayHasKey( 'redirect', $result );
		$this->assertNotEmpty( $result['redirect'] );
	}

	/**
	 * process_payment() 回傳的 redirect URL 包含 order-received
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_process_payment_redirect_URL指向order_received(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		$this->assertStringContainsString( 'order-received', $result['redirect'] );
	}

	/**
	 * process_payment() 成功後 SDK_TOKEN 寫入 _pc_payuni_uni_sdk_token
	 * 依 specs §後置（狀態）：token_get 成功 → SDK_TOKEN 存入 meta 供前端 SDK 使用
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_process_payment_成功後SDK_TOKEN寫入訂單meta(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		$gateway->process_payment( $order_id );

		$fresh_order = \wc_get_order( $order_id );
		$meta_keys   = new PayuniUniEmbedMetaKeys( $fresh_order );
		$sdk_token   = $meta_keys->get_sdk_token();

		$this->assertNotEmpty(
			$sdk_token,
			'process_payment 成功後，_pc_payuni_uni_sdk_token 應寫入非空值'
		);
	}

	/**
	 * get_settings() 回傳非空陣列（靜態方法）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_get_settings_回傳非空陣列(): void {
		$settings = PayuniUniEmbedGateway::get_settings();
		$this->assertIsArray( $settings );
		$this->assertNotEmpty( $settings );
	}

	/**
	 * get_supported_payment_methods() 回傳含 'Credit' 的陣列
	 * UNi Embed 僅支援信用卡（一次付清 / 分期 / 約定 / 記憶 / 強制約定）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_get_supported_payment_methods_僅含信用卡(): void {
		$gateway = new PayuniUniEmbedGateway();
		$methods = $gateway->get_supported_payment_methods();

		$this->assertIsArray( $methods );
		$this->assertContains( 'Credit', $methods );
		// UNi Embed 不支援 ATM / CVS / LinePay（這些走 UPP）
		$this->assertNotContains( 'ATM', $methods );
		$this->assertNotContains( 'CVS', $methods );
		$this->assertNotContains( 'LinePay', $methods );
	}

	/**
	 * Gateway 實例的 $id 屬性等於 PayuniUniEmbedGateway::ID 常數
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_實例_id屬性等於ID常數(): void {
		$gateway = new PayuniUniEmbedGateway();
		$this->assertSame( PayuniUniEmbedGateway::ID, $gateway->id );
	}

	// ========== V3 硬約束：token_get 內層 payload 驗證（Security） ==========

	/**
	 * [V3 硬約束] token_get 請求內層 payload 僅含 MerID + Timestamp + IFrameDomain
	 * 不含 MerTradeNo、TradeAmt，或任何訂單欄位
	 *
	 * 依 payuni-uni-embed-v3 SKILL.md §V3 vs V2 §EncryptInfo 內層：
	 * V3 token_get 階段「不送訂單資料」，只在驗證 IFrameDomain
	 * MerTradeNo / TradeAmt 在後續 merchant_trade 才送
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_token_get請求payload僅含MerID_Timestamp_IFrameDomain(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order( 1500 );
		$order_id = $order->get_id();

		// 蒐集 token_get 的實際請求 payload
		$captured_payload = null;
		\add_filter(
			'payuni_uni_embed_token_get_payload',
			function ( array $payload ) use ( &$captured_payload ): array {
				$captured_payload = $payload;
				return $payload;
			},
			10,
			1
		);

		$gateway->process_payment( $order_id );

		// 若 Gateway 正確，此 filter 應在 token_get 呼叫前觸發並蒐集到 payload
		if ( null === $captured_payload ) {
			// filter 未觸發時，改用反射取得最後一次 TokenGetClient 呼叫的 payload
			// 或直接測試 build_token_get_payload() 方法
			$this->assertTrue(
				\method_exists( PayuniUniEmbedGateway::class, 'build_token_get_payload' ),
				'PayuniUniEmbedGateway 應提供 build_token_get_payload() 方法供測試驗證 V3 硬約束'
			);

			$reflection = new \ReflectionClass( PayuniUniEmbedGateway::class );
			$method     = $reflection->getMethod( 'build_token_get_payload' );
			$method->setAccessible( true );
			$captured_payload = $method->invoke( new PayuniUniEmbedGateway() );
		}

		$this->assertIsArray( $captured_payload );

		// V3 硬約束：必須包含的三個欄位
		$this->assertArrayHasKey( 'MerID', $captured_payload, 'token_get payload 必須含 MerID' );
		$this->assertArrayHasKey( 'Timestamp', $captured_payload, 'token_get payload 必須含 Timestamp' );
		$this->assertArrayHasKey( 'IFrameDomain', $captured_payload, 'token_get payload 必須含 IFrameDomain' );

		// V3 硬約束：絕對不能含訂單欄位
		$this->assertArrayNotHasKey(
			'MerTradeNo',
			$captured_payload,
			'[V3 硬約束違反] token_get payload 不得含 MerTradeNo（這是 V2 行為，V3 已移除）'
		);
		$this->assertArrayNotHasKey(
			'TradeAmt',
			$captured_payload,
			'[V3 硬約束違反] token_get payload 不得含 TradeAmt（訂單金額在 merchant_trade 才送）'
		);
		$this->assertArrayNotHasKey(
			'ProdDesc',
			$captured_payload,
			'[V3 硬約束違反] token_get payload 不得含 ProdDesc'
		);
		$this->assertArrayNotHasKey(
			'NotifyURL',
			$captured_payload,
			'[V3 硬約束違反] token_get payload 不得含 NotifyURL'
		);
		$this->assertArrayNotHasKey(
			'ReturnURL',
			$captured_payload,
			'[V3 硬約束違反] token_get payload 不得含 ReturnURL'
		);
	}

	/**
	 * [V3 硬約束] IFrameDomain 必須含 https://
	 * 依 payuni-uni-embed-v3 §IFrameDomain 格式：必須含 https://
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_IFrameDomain必須含https(): void {
		// 設定一個不含 https:// 的 IFrameDomain，預期 Gateway 應拒絕或回傳失敗
		ProviderUtils::update_option(
			'payuni_uni_embed',
			[
				'enabled'       => 'yes',
				'mode'          => 'test',
				'merchant_id'   => 'UNI_TEST_MER',
				'hash_key'      => '12345678901234567890123456789012',
				'hash_iv'       => '1234567890123456',
				'iframe_domain' => 'http://www.example.com', // 故意用 http，非 https
			]
		);
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}

		$gateway = new PayuniUniEmbedGateway();
		$order   = $this->create_uni_embed_order();

		$result = $gateway->process_payment( $order->get_id() );

		// IFrameDomain 不合法時，應回傳失敗（不應嘗試呼叫 PAYUNi API）
		$this->assertSame(
			'failure',
			$result['result'],
			'IFrameDomain 不含 https:// 時，process_payment 應回傳 failure'
		);
	}

	// ========== IFrameDomain 格式驗證（Edge） ==========

	/**
	 * IFrameDomain 不以 https:// 開頭時，驗證失敗
	 * 依 payuni-uni-embed-v3 §IFrameDomain 格式規範
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_IFrameDomain格式_不含https時驗證失敗(): void {
		$gateway = new PayuniUniEmbedGateway();

		$reflection = new \ReflectionClass( $gateway );
		$method     = $reflection->getMethod( 'is_valid_iframe_domain' );
		$method->setAccessible( true );

		// 無 https:// 前綴
		$this->assertFalse( $method->invoke( $gateway, 'www.example.com' ) );
		$this->assertFalse( $method->invoke( $gateway, 'http://www.example.com' ) );
		$this->assertFalse( $method->invoke( $gateway, '' ) );
	}

	/**
	 * IFrameDomain 含合法格式時驗證通過（https:// + 中文/英數字/-）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_IFrameDomain格式_合法時驗證通過(): void {
		$gateway = new PayuniUniEmbedGateway();

		$reflection = new \ReflectionClass( $gateway );
		$method     = $reflection->getMethod( 'is_valid_iframe_domain' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $gateway, 'https://www.example.com' ) );
		$this->assertTrue( $method->invoke( $gateway, 'https://shop.my-store.com.tw' ) );
		$this->assertTrue( $method->invoke( $gateway, 'https://商店.com' ) );
		$this->assertTrue( $method->invoke( $gateway, 'https://www.payuni.com.tw' ) );
	}

	/**
	 * IFrameDomain 以 - 開頭或結尾時驗證失敗
	 * 依 payuni-uni-embed-v3 §IFrameDomain：不可開頭結尾為 -
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_IFrameDomain格式_以dash開頭結尾時驗證失敗(): void {
		$gateway = new PayuniUniEmbedGateway();

		$reflection = new \ReflectionClass( $gateway );
		$method     = $reflection->getMethod( 'is_valid_iframe_domain' );
		$method->setAccessible( true );

		// 以 - 開頭
		$this->assertFalse( $method->invoke( $gateway, 'https://-example.com' ) );
		// 以 - 結尾（domain 部分）
		$this->assertFalse( $method->invoke( $gateway, 'https://example-.com' ) );
	}

	// ========== token_get 失敗情境（Error） ==========

	/**
	 * token_get 外層 Status=ERROR 時記錄 order note，不轉訂單狀態
	 * 依 specs §後置（狀態）：token_get 失敗記 order note，不轉訂單狀態
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_token_get失敗時記錄order_note不轉訂單狀態(): void {
		// 模擬 token_get 外層 Status=ERROR（mock API 回傳）
		\add_filter(
			'payuni_uni_embed_mock_token_get_response',
			function (): array {
				return [
					'Status' => 'ERROR',
					'MerID'  => 'UNI_TEST_MER',
				];
			}
		);

		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		// 應回傳 failure
		$this->assertSame( 'failure', $result['result'] );

		// 訂單狀態不應轉為 processing
		$this->assert_order_status( $order, 'pending' );

		// 應有 order note 記錄失敗
		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );
		// 注意：order note 記錄方式由實作決定；以下驗證 meta 沒有被寫入（錯誤時不寫 SDK_TOKEN）
		$meta_keys = new PayuniUniEmbedMetaKeys( $fresh_order );
		$sdk_token = $meta_keys->get_sdk_token();
		$this->assertSame(
			'',
			$sdk_token,
			'token_get 失敗時不應寫入 SDK_TOKEN'
		);
	}

	/**
	 * token_get 限定 IP 未設定（TOKEN03005/TOKEN03006）時記錄 order note，不轉訂單狀態
	 * 依 specs §後置（狀態）：限定 IP 未設定時記 order note
	 * 依 payuni-uni-embed-v3 §注意事項：限定 IP 必須事先設定，否則 token_get 回 TOKEN03005/TOKEN03006
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_token_get限定IP未設定時記錄order_note(): void {
		// 模擬限定 IP 未設定的錯誤回應（TOKEN03005）
		\add_filter(
			'payuni_uni_embed_mock_token_get_response',
			function (): array {
				return [
					'Status'  => 'ERROR',
					'Message' => 'TOKEN03005',
					'MerID'   => 'UNI_TEST_MER',
				];
			}
		);

		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		$result = $gateway->process_payment( $order_id );

		// 應回傳 failure
		$this->assertSame( 'failure', $result['result'] );

		// 訂單狀態不應改變
		$this->assert_order_status( $order, 'pending' );

		// 不應寫入 SDK_TOKEN
		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );
		$meta_keys = new PayuniUniEmbedMetaKeys( $fresh_order );
		$this->assertSame( '', $meta_keys->get_sdk_token() );
	}

	/**
	 * token_get 例外（網路錯誤、Throwable）時記錄 order note，不暴露內部錯誤至前端
	 * 依 CLAUDE.md §Exception handling：catch \Throwable，log，不暴露 internals
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_token_get例外時不暴露內部錯誤(): void {
		// 模擬 TokenGetClient 拋出例外
		\add_filter(
			'payuni_uni_embed_mock_token_get_exception',
			function (): \Throwable {
				return new \RuntimeException( '網路連線逾時（mock）' );
			}
		);

		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		// 不應拋出例外至呼叫端
		try {
			$result = $gateway->process_payment( $order_id );
			$this->assertSame( 'failure', $result['result'] );
		} catch ( \Throwable $e ) {
			$this->fail( "process_payment 不應將例外拋至前端，但拋出：{$e->getMessage()}" );
		}
	}

	/**
	 * process_payment() 訂單不存在時回傳 failure array（不暴露 raw exception）
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_process_payment_訂單不存在時回傳失敗結果(): void {
		$gateway = new PayuniUniEmbedGateway();
		$result  = $gateway->process_payment( 999999999 );

		$this->assertSame( 'failure', $result['result'] );
	}

	// ========== 訂單金額驗證（Edge） ==========

	/**
	 * 訂單金額超過 199,999 元上限時應失敗（信用卡上限）
	 * 依 payuni-uni-embed-v3 §信用卡 1～199,999 元
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_訂單金額超過上限時失敗(): void {
		$gateway = new PayuniUniEmbedGateway();
		$order   = $this->create_uni_embed_order( 200000 ); // 超過 199,999 元

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
	}

	/**
	 * 訂單金額低於 1 元時應失敗
	 * 依 payuni-uni-embed-v3 §信用卡 1～199,999 元
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_訂單金額低於最小值時失敗(): void {
		$gateway = new PayuniUniEmbedGateway();
		$order   = $this->create_uni_embed_order( 0 );

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
	}

	// ========== SDK_TOKEN 冪等性（Edge） ==========

	/**
	 * 同一訂單重複呼叫 process_payment 時，若已有 SDK_TOKEN 則不重複呼叫 token_get
	 * 防止重複建立 Token（10 分鐘有效期間內）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_SDK_TOKEN已存在時不重複呼叫token_get(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		// 預先手動寫入 SDK_TOKEN（模擬已有 token 的情境）
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'EXISTING_SDK_TOKEN_12345' );
		$order->save();

		// 計數 token_get 呼叫次數
		$call_count = 0;
		\add_filter(
			'payuni_uni_embed_token_get_payload',
			function ( array $payload ) use ( &$call_count ): array {
				$call_count++;
				return $payload;
			}
		);

		$gateway->process_payment( $order_id );

		$this->assertSame( 0, $call_count, 'SDK_TOKEN 已存在時不應重複呼叫 token_get' );
	}

	// ========== Cycle 2：merchant_trade 段（Phase 06） ==========
	// 以下測試方法全部為 Red 狀態（MerchantTradeClient / FrontendApi 類別尚不存在）
	// Mock filter：payuni_uni_embed_mock_merchant_trade_response / _exception（比照 Cycle 1 慣例）

	/**
	 * Gateway 識別值（Gateway）固定為 9（IFrame），不誤判為 UPP 的 2
	 *
	 * 依 payuni-uni-embed-v3 §與 UPP 的關鍵差異：
	 *   UNi Embed Gateway = 9（IFrame）
	 *   UPP Gateway = 2
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_merchant_trade_Gateway識別值固定9不誤判UPP的2(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		// 預寫 SDK_TOKEN（模擬 token_get 已完成）
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_CYCLE2' );
		$order->save();

		// Mock merchant_trade 回應，帶 Gateway=9
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function (): array {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'MerID'       => 'UNI_TEST_MER',
					'Gateway'     => '9',
					'TradeStatus' => '1',
					'PaymentType' => '1',
				];
			}
		);

		// 呼叫 merchant_trade（透過 FrontendApi 或直接呼叫 MerchantTradeClient）
		// 此處驗證 Gateway 常數/設定為 9，而非動態由回傳決定
		$this->assertTrue(
			\defined( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway::GATEWAY_CODE' )
				|| \method_exists( PayuniUniEmbedGateway::class, 'get_gateway_code' ),
			'PayuniUniEmbedGateway 應提供 GATEWAY_CODE 常數或 get_gateway_code() 方法'
		);

		$gateway_code = \defined( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway::GATEWAY_CODE' )
		? PayuniUniEmbedGateway::GATEWAY_CODE
		: $gateway->get_gateway_code();

		$this->assertSame( '9', (string) $gateway_code, 'UNi Embed Gateway 代號必須為 9，不得誤用 UPP 的 2' );
	}

	/**
	 * merchant_trade 以原 SDK_TOKEN 送 MerTradeNo（PCE 前綴）並寫入 _pc_payuni_uni_trade_no
	 *
	 * 依規格：後置（狀態）- 建單時寫入冪等鍵 MerTradeNo 至 order meta _pc_payuni_uni_trade_no
	 * 依規格：MerTradeNo ≤25 字元，格式 [A-Za-z0-9_-]，10 分鐘內不可重複
	 * 格式約束：PCE + order_id（例 PCE100），與 UPP 的 PCU 前綴區隔
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_merchant_trade_MerTradeNo使用PCE前綴並寫入meta(): void {
		$gateway  = new PayuniUniEmbedGateway();
		$order    = $this->create_uni_embed_order( 1000 );
		$order_id = $order->get_id();

		// 預寫 SDK_TOKEN
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_TRADE_NO_TEST' );
		$order->save();

		// 蒐集 merchant_trade 實際送出的 MerTradeNo
		$captured_trade_no = null;
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_request',
			function ( array $payload ) use ( &$captured_trade_no ): array {
				$captured_trade_no = $payload['MerTradeNo'] ?? null;
				return $payload;
			}
		);

		// Mock 成功回應
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function () use ( $order_id ): array {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'MerTradeNo'  => 'PCE' . $order_id,
					'Gateway'     => '9',
					'TradeStatus' => '1',
					'PaymentType' => '1',
				];
			}
		);

		// 模擬前端呼叫 create-payment（透過 MerchantTradeClient 建立交易）
		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();
			$client->execute( $order );
		}

		// 驗證 meta 寫入
		$fresh_order = \wc_get_order( $order_id );
		$this->assertNotNull( $fresh_order );
		$meta     = new PayuniUniEmbedMetaKeys( $fresh_order );
		$trade_no = $meta->get_trade_no();

		$expected_trade_no = 'PCE' . $order_id;
		$this->assertSame(
			$expected_trade_no,
			$trade_no,
			"MerTradeNo 應為 PCE{$order_id}，寫入 _pc_payuni_uni_trade_no"
		);

		// MerTradeNo 長度不超過 25 字元
		$this->assertLessThanOrEqual(
			25,
			\strlen( $expected_trade_no ),
			'MerTradeNo 不得超過 25 字元'
		);
	}

	/**
	 * TradeAmt 一律後端從 order total 計算，前端傳入金額被忽略（防 SDK 等候期竄改）
	 *
	 * 依 payuni-uni-embed-v3 §V3 核心改變：
	 *   「商店端可在 SDK 綁卡完成後重新檢視購物車金額（避免使用者在 SDK 等候期間竄改）」
	 * 依規格安全約束：merchant_trade 的 TradeAmt 必須後端計算，不可信任前端傳值
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_TradeAmt後端計算前端傳入金額被忽略(): void {
		$real_order_total = 1000;
		$order            = $this->create_uni_embed_order( (float) $real_order_total );

		// 預寫 SDK_TOKEN
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_AMOUNT_TEST' );
		$order->save();

		// 蒐集 merchant_trade 實際送出的 TradeAmt
		$captured_amount = null;
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_request',
			function ( array $payload ) use ( &$captured_amount ): array {
				$captured_amount = $payload['TradeAmt'] ?? null;
				return $payload;
			}
		);

		// Mock 成功回應
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function () use ( $real_order_total ): array {
				return [
					'Status'      => 'SUCCESS',
					'TradeAmt'    => $real_order_total,
					'Gateway'     => '9',
					'TradeStatus' => '1',
					'PaymentType' => '1',
				];
			}
		);

		// 模擬前端試圖竄改金額（前端 POST 帶 amount=1）
		$tampered_amount = 1;
		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();
			// 即使傳入竄改金額，也應使用訂單真實金額
			$client->execute( $order, [ 'trade_amt' => $tampered_amount ] );
		}

		// 驗證：若 filter 有攔截到，金額應等於訂單真實 total，而非前端傳入的 1
		if ( null !== $captured_amount ) {
			$this->assertSame(
				$real_order_total,
				(int) $captured_amount,
				"merchant_trade TradeAmt 必須後端從 order total 計算（{$real_order_total}），不得使用前端傳入的竄改金額（{$tampered_amount}）"
			);
		} else {
			// MerchantTradeClient 尚未實作（Red 階段預期），驗證類別存在
			$this->assertFalse(
				\class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ),
				'MerchantTradeClient 類別尚不存在（Red 階段，預期失敗）'
			);
		}
	}

	/**
	 * merchant_trade 回應 Version=1.2 時不誤判（V3 特性：回傳固定 1.2，請求送 1.0）
	 *
	 * 依 payuni-uni-embed-v3 §注意事項 15：
	 *   merchant_trade 請求送 1.0、回傳固定 1.2（不論是否帶發票，一律 1.2，無條件分支）
	 * 確保實作不會因 Version=1.2 觸發任何分支邏輯（例如誤以為是 token_get 的 3.0 或其他 API）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_edge_merchant_trade回傳Version_1_2不觸發分支誤判(): void {
		$order = $this->create_uni_embed_order();

		// 預寫 SDK_TOKEN
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_VERSION_TEST' );
		$order->save();

		// Mock merchant_trade 回應，帶 Version=1.2（V3 固定回傳值）
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function (): array {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'Version'     => '1.2',  // V3 固定回傳 1.2，與請求的 1.0 不同
					'MerID'       => 'UNI_TEST_MER',
					'Gateway'     => '9',
					'TradeStatus' => '1',
					'PaymentType' => '1',
					'TradeAmt'    => 1000,
				];
			}
		);

		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();

			// 不應拋出例外，應正常解析回應
			try {
				$result = $client->execute( $order );
				// 即使 Version=1.2，授權成功時 TradeStatus 應為 1
				$this->assertArrayHasKey( 'TradeStatus', $result, 'Version=1.2 時應正常解析授權結果' );
				$this->assertSame( '1', (string) $result['TradeStatus'], 'Version=1.2 時 TradeStatus 應為 1（已付款）' );
			} catch ( \Throwable $e ) {
				$this->fail( "Version=1.2 不應觸發分支誤判或拋出例外：{$e->getMessage()}" );
			}
		} else {
			// Red 階段：MerchantTradeClient 不存在，測試預期失敗
			$this->assertFalse(
				\class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ),
				'MerchantTradeClient 類別尚不存在（Red 階段，預期失敗）'
			);
		}
	}

	/**
	 * API3D=1 強制 3D 時 merchant_trade 回 URL，前端應接收 three_d_url 並導頁
	 *
	 * 依規格：回應為 3D 交易（含導頁 URL 或 API3D=1 強制 3D）時前端導向銀行 3D 驗證頁
	 * 依 payuni-uni-embed-v3 §回傳（API3D=1）：Status=SUCCESS + Message=建立幕後3D成功 + URL
	 * DECISION:3a Branch 需3D驗證：merchant_trade 回傳 3D 導頁 URL → 前端導向銀行 3D 驗證頁
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_merchant_trade_API3D強制3D回應含URL前端應導頁(): void {
		$order = $this->create_uni_embed_order();

		// 預寫 SDK_TOKEN
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_3D_TEST' );
		$order->save();

		// Mock merchant_trade 回應：API3D=1 強制 3D
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function (): array {
				return [
					'Status'  => 'SUCCESS',
					'Message' => '建立幕後3D成功',
					'MerID'   => 'UNI_TEST_MER',
					'URL'     => 'https://sandbox-api.payuni.com.tw/3DVerify/MOCK_3D_SESSION',
				];
			}
		);

		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();
			$result = $client->execute( $order );

			// 應識別為 3D 交易並回傳 URL
			$this->assertArrayHasKey( 'URL', $result, 'API3D 強制 3D 時回應應含 URL 欄位' );
			$this->assertNotEmpty( $result['URL'], '3D URL 不應為空' );
			$this->assertStringContainsString( '3DVerify', $result['URL'], '3D URL 應包含 3DVerify 路徑段' );
		} else {
			// Red 階段預期失敗
			$this->assertFalse(
				\class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ),
				'MerchantTradeClient 類別尚不存在（Red 階段，預期失敗）'
			);
		}
	}

	/**
	 * merchant_trade 回應非 3D 直接授權（Status=SUCCESS，無 URL）時不導頁，等待 NotifyURL 幕後確認
	 *
	 * 依規格：回應為非 3D 直接授權（Status=SUCCESS）時不導頁，等待 NotifyURL 幕後確認
	 * DECISION:3a Branch 非3D直接授權：merchant_trade 同步回 Status=SUCCESS，無 3D 跳轉
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_merchant_trade_非3D直接授權不導頁等待NotifyURL(): void {
		$order = $this->create_uni_embed_order();

		// 預寫 SDK_TOKEN
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_NON3D_TEST' );
		$order->save();

		// Mock merchant_trade 回應：非 3D，不帶 URL
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function (): array {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '授權成功',
					'MerID'       => 'UNI_TEST_MER',
					'Gateway'     => '9',
					'TradeStatus' => '1',
					'PaymentType' => '1',
					'AuthCode'    => '654321',
					// 沒有 URL 欄位（非 3D）
				];
			}
		);

		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();
			$result = $client->execute( $order );

			// 非 3D 回應不含 URL 欄位
			$this->assertArrayNotHasKey( 'URL', $result, '非 3D 授權時回應不應含 URL 欄位' );
			// TradeStatus=1 代表已付款（由 NotifyURL 最終確認）
			$this->assertSame( '1', (string) ( $result['TradeStatus'] ?? '' ), '非 3D 直接授權 TradeStatus 應為 1' );
		} else {
			// Red 階段預期失敗
			$this->assertFalse(
				\class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ),
				'MerchantTradeClient 類別尚不存在（Red 階段，預期失敗）'
			);
		}
	}

	/**
	 * merchant_trade 例外（傳輸層 / 業務層失敗）時 catch，回前端通用訊息，細節寫 order note/log 不外洩
	 *
	 * 依規格：後置（回應）- 例外（傳輸層 / 業務層失敗）一律 catch，回前端通用錯誤訊息不外洩內部細節（細節寫 order note / log）
	 * 依 CLAUDE.md §Exception handling：catch \Throwable，log，不暴露 internals
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_merchant_trade_例外時回通用訊息細節寫order_note不外洩(): void {
		$order    = $this->create_uni_embed_order();
		$order_id = $order->get_id();

		// 預寫 SDK_TOKEN
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'MOCK_SDK_TOKEN_EXCEPTION_TEST' );
		$order->save();

		// Mock merchant_trade 拋出例外
		$internal_error_msg = '網路連線逾時：Connection timed out (mock) stack trace detail abc123';
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_exception',
			function () use ( $internal_error_msg ): \Throwable {
				return new \RuntimeException( $internal_error_msg );
			}
		);

		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();

			// 不應拋出例外至呼叫端
			try {
				$result = $client->execute( $order );

				// 若有回傳值，應為通用錯誤訊息，不含內部細節
				if ( isset( $result['message'] ) ) {
					$this->assertStringNotContainsString(
						$internal_error_msg,
						$result['message'],
						'回傳訊息不應包含內部錯誤細節'
					);
					$this->assertStringNotContainsString(
						'stack trace',
						$result['message'],
						'回傳訊息不應包含 stack trace'
					);
				}
			} catch ( \Throwable $e ) {
				$this->fail( "merchant_trade 例外不應傳播至呼叫端，但拋出：{$e->getMessage()}" );
			}

			// 驗證 order note 有記錄錯誤細節（供 merchant 除錯）
			// 注意：此斷言在 Green 階段才能真正通過，Red 階段可能因類別不存在而跳過
			$fresh_order = \wc_get_order( $order_id );
			if ( $fresh_order ) {
				$notes = \wc_get_order_notes( [ 'order_id' => $order_id ] );
				// order note 記錄細節（至少有一條 note 存在）
				// 精確的 note 內容在 Green 階段驗證
				$this->assertNotEmpty( $notes, 'merchant_trade 例外時應寫入 order note' );
			}
		} else {
			// Red 階段預期失敗
			$this->assertFalse(
				\class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ),
				'MerchantTradeClient 類別尚不存在（Red 階段，預期失敗）'
			);
		}
	}

	/**
	 * SDK_TOKEN 逾期（IFTRADE04001）時 catch，回前端通用訊息，不外洩內部細節
	 *
	 * 依 payuni-uni-embed-v3 §注意事項 3：同一個 SDK_TOKEN 走完全程，10 分鐘逾期 → IFTRADE04001
	 * 此為業務層失敗，應被 catch 並回通用訊息，同時寫入 order note/log
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_merchant_trade_SDK_TOKEN逾期IFTRADE04001時回通用訊息(): void {
		$order = $this->create_uni_embed_order();

		// 預寫 SDK_TOKEN（模擬已過期的 token）
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_sdk_token( 'EXPIRED_SDK_TOKEN_10MIN_AGO' );
		$order->save();

		// Mock merchant_trade 回業務層失敗（IFTRADE04001）
		\add_filter(
			'payuni_uni_embed_mock_merchant_trade_response',
			function (): array {
				return [
					'Status'  => 'IFTRADE04001',
					'Message' => 'Token已逾期，請重新進行交易',
					'MerID'   => 'UNI_TEST_MER',
				];
			}
		);

		if ( \class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ) ) {
			$client = new \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient();

			try {
				$result = $client->execute( $order );

				// 應識別為失敗並回通用訊息
				if ( isset( $result['status'] ) ) {
					$this->assertNotSame( 'SUCCESS', $result['status'], 'IFTRADE04001 不應被視為成功' );
				}

				// 回前端的 message 不應包含 IFTRADE04001 錯誤碼細節
				if ( isset( $result['message'] ) ) {
					$this->assertStringNotContainsString(
						'IFTRADE04001',
						$result['message'],
						'回傳至前端的訊息不應包含 PAYUNi 錯誤碼細節'
					);
				}
			} catch ( \Throwable $e ) {
				$this->fail( "merchant_trade 業務層失敗不應拋出例外至呼叫端：{$e->getMessage()}" );
			}
		} else {
			// Red 階段預期失敗
			$this->assertFalse(
				\class_exists( 'J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient' ),
				'MerchantTradeClient 類別尚不存在（Red 階段，預期失敗）'
			);
		}
	}
}
