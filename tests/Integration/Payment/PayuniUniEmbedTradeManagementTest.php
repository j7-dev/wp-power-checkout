<?php
/**
 * PAYUNi UNi Embed V3 後台交易管理整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/payuni-uni-embed-trade-management.feature
 *
 * 驗證：
 *  - 查詢補單（/api/trade/query）：TradeStatus=1 + DataSource=A + 訂單未 processing → 補單轉 processing。
 *  - 請款（/api/trade/close CloseType=1）→ 寫入 _pc_payuni_uni_capture_status='captured'。
 *  - 取消授權（/api/trade/cancel）→ 寫入 _pc_payuni_uni_capture_status='voided'。
 *  - 狀態機互斥守衛：已 voided 不可請款 / 已 captured 不可取消授權。
 *  - D1 架構決策：後台 client 用 UNi Embed settings/meta（_pc_payuni_uni_*），
 *    斷言「不會抓到 UPP 的 _pc_payuni_* meta 或 UPP settings」。
 *
 * TDD 紅燈：
 *  PayuniUniEmbedGateway::handle_query_action / handle_capture_action / handle_cancel_auth_action /
 *    add_order_actions 靜態方法尚未存在（Cycle 4）。
 *  DoActionClient、QueryTradeClient 從 Payuni 目錄複用，UNi Embed 版本尚未接線。
 *
 * Mock 手法：
 *  外部 HTTP 一律透過 WP filter mock：
 *   `payuni_uni_embed_mock_query_response` — QueryTradeClient 回傳 fixture
 *   `payuni_uni_embed_mock_do_action_response` — DoActionClient 回傳 fixture（含 close/cancel）
 *   `payuni_uni_embed_mock_do_action_exception` — DoActionClient 拋例外
 *   `payuni_uni_embed_mock_query_exception` — QueryTradeClient 拋例外
 *
 * PAYUNi API 參考：
 *  - 查詢 /api/trade/query（Version=2.0）：TradeStatus=1=已付款；DataSource=A=真實；DataSource=B=處理中
 *  - 請款 /api/trade/close CloseType=1；取消授權 /api/trade/cancel
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *       --filter PayuniUniEmbedTradeManagement --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UNi Embed V3 後台交易管理測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 * @group trade_management
 */
final class PayuniUniEmbedTradeManagementTest extends TestCase {

	/** @var string PAYUNi 官方公開測試向量 HashKey（32 字元） */
	private const HASH_KEY = '12345678901234567890123456789012';

	/** @var string PAYUNi 官方公開測試向量 HashIV（16 字元） */
	private const HASH_IV = '1234567890123456';

	/** @var string Sandbox 商店代號 */
	private const MER_ID = 'UNI_EMBED_TEST_002';

	/**
	 * 每次測試前啟用 payuni_uni_embed（test 模式 + 測試向量），開啟 MOCK
	 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			PayuniUniEmbedSettingsDTO::ID,
			[
				'enabled'       => 'yes',
				'mode'          => 'test',
				'merchant_id'   => self::MER_ID,
				'hash_key'      => self::HASH_KEY,
				'hash_iv'       => self::HASH_IV,
				'iframe_domain' => 'https://localhost',
			]
		);

		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}

		// 預設 MOCK：查詢補單回 TradeStatus=1 + DataSource=A（已付款）
		\add_filter(
			'payuni_uni_embed_mock_query_response',
			static function ( mixed $default ): mixed {
				return [
					'Status'      => 'SUCCESS',
					'Message'     => '查詢成功',
					'TradeStatus' => '1',
					'DataSource'  => 'A',
					'PaymentType' => '1',
				];
			}
		);

		// 預設 MOCK：DoActionClient close/cancel 回 SUCCESS
		\add_filter(
			'payuni_uni_embed_mock_do_action_response',
			static function ( mixed $default, string $action_type ): mixed {
				return [
					'Status'  => 'SUCCESS',
					'Message' => '操作成功',
				];
			},
			10,
			2
		);
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );
		\remove_all_filters( 'payuni_uni_embed_mock_query_response' );
		\remove_all_filters( 'payuni_uni_embed_mock_do_action_response' );
		\remove_all_filters( 'payuni_uni_embed_mock_do_action_exception' );
		\remove_all_filters( 'payuni_uni_embed_mock_query_exception' );
		delete_option( ProviderUtils::get_option_name( PayuniUniEmbedSettingsDTO::ID ) );
		\delete_option( 'woocommerce_payuni_upp_settings' );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立已付款 UNi Embed 訂單（含 TradeNo / PaymentType=1 / Gateway=9）
	 *
	 * @param string $trade_no PAYUNi UNi 序號
	 * @param string $status   WC 訂單狀態
	 * @param float  $total    訂單金額
	 * @return \WC_Order
	 */
	private function create_paid_uni_embed_order(
		string $trade_no = 'UNI20260609100',
		string $status = 'processing',
		float $total = 1000.0
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => $total,
			]
		);

		$meta_keys    = new PayuniUniEmbedMetaKeys( $order );
		$mer_trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );
		$meta_keys->update_trade_no( $mer_trade_no );
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => $mer_trade_no,
				'TradeNo'     => $trade_no,
				'TradeAmt'    => (string) (int) $total,
				'TradeStatus' => '1',
				'PaymentType' => '1',
				'Gateway'     => PayuniUniEmbedGateway::GATEWAY_CODE,
				'DataSource'  => 'A',
			]
		);

		return $order;
	}

	/**
	 * 建立尚未付款的 UNi Embed 訂單（pending，模擬 callback 漏接）
	 *
	 * @param string $mer_trade_no MerTradeNo
	 * @return \WC_Order
	 */
	private function create_pending_uni_embed_order( string $mer_trade_no = '' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => 1000.0,
			]
		);

		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$key       = '' !== $mer_trade_no ? $mer_trade_no : PayuniUniEmbedTradeNo::generate( $order->get_id() );
		$meta_keys->update_trade_no( $key );
		// 刻意不存 payment_detail（模擬 callback 漏接）

		return $order;
	}

	// ========== Smoke ==========

	/**
	 * PayuniUniEmbedGateway 有靜態方法 handle_query_action（Cycle 4 待實作）
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedGateway有handle_query_action靜態方法(): void {
		// 紅燈原因：此靜態方法 Cycle 4 才新增
		$this->assertTrue(
			\method_exists( PayuniUniEmbedGateway::class, 'handle_query_action' ),
			'PayuniUniEmbedGateway::handle_query_action 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	/**
	 * PayuniUniEmbedGateway 有靜態方法 handle_capture_action（Cycle 4 待實作）
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedGateway有handle_capture_action靜態方法(): void {
		// 紅燈原因：此靜態方法 Cycle 4 才新增
		$this->assertTrue(
			\method_exists( PayuniUniEmbedGateway::class, 'handle_capture_action' ),
			'PayuniUniEmbedGateway::handle_capture_action 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	/**
	 * PayuniUniEmbedGateway 有靜態方法 handle_cancel_auth_action（Cycle 4 待實作）
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_冒煙_PayuniUniEmbedGateway有handle_cancel_auth_action靜態方法(): void {
		$this->assertTrue(
			\method_exists( PayuniUniEmbedGateway::class, 'handle_cancel_auth_action' ),
			'PayuniUniEmbedGateway::handle_cancel_auth_action 靜態方法尚未存在，Cycle 4 才實作'
		);
	}

	// ========== Happy Path：查詢補單（/api/trade/query） ==========

	/**
	 * 查詢補單：TradeStatus=1 + DataSource=A + 訂單 pending → 補單轉 processing
	 *
	 * 規格依據：payuni-uni-embed-trade-management.feature 場景：查詢補單（callback 漏接時對帳）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_查詢補單TradeStatus已付款且訂單pending時補單轉processing(): void {
		// Given: callback 漏接的 pending 訂單
		$order = $this->create_pending_uni_embed_order();

		// When: 後台觸發查詢補單 action handler
		PayuniUniEmbedGateway::handle_query_action( $order );

		// Then: 訂單狀態轉為 processing + order note 含查詢結果
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'processing' );
		$this->assert_order_note_contains( $fresh_order, '查詢' );
	}

	/**
	 * 查詢補單：訂單已是 processing → 不重複補單（冪等）
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_查詢補單訂單已processing時不重複補單(): void {
		// Given: 已 processing 的訂單
		$order = $this->create_paid_uni_embed_order( status: 'processing' );

		// When: 後台觸發查詢補單
		PayuniUniEmbedGateway::handle_query_action( $order );

		// Then: 狀態仍為 processing（不變）
		$this->assert_order_status( \wc_get_order( $order->get_id() ), 'processing' );
	}

	// ========== Happy Path：請款（CloseType=1） ==========

	/**
	 * 信用卡請款成功 → 寫入 _pc_payuni_uni_capture_status='captured' + order note
	 *
	 * 規格依據：payuni-uni-embed-trade-management.feature 場景：信用卡請款成功
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_請款成功寫入capture_status為captured並記錄order_note(): void {
		// Given: UNi Embed 信用卡訂單（未請款，capture_status=''）
		$order = $this->create_paid_uni_embed_order( trade_no: 'UNI20260609101' );

		// When: 後台觸發請款
		PayuniUniEmbedGateway::handle_capture_action( $order );

		// Then: _pc_payuni_uni_capture_status='captured' + order note 含「請款」
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'captured', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '請款' );
	}

	// ========== Happy Path：取消授權（/api/trade/cancel） ==========

	/**
	 * 取消授權成功 → 寫入 _pc_payuni_uni_capture_status='voided' + order note
	 *
	 * 規格依據：payuni-uni-embed-trade-management.feature 場景：信用卡取消授權成功
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_取消授權成功寫入capture_status為voided並記錄order_note(): void {
		// Given: UNi Embed 信用卡訂單（未請款）
		$order = $this->create_paid_uni_embed_order( trade_no: 'UNI20260609102' );

		// When: 後台觸發取消授權
		PayuniUniEmbedGateway::handle_cancel_auth_action( $order );

		// Then: _pc_payuni_uni_capture_status='voided' + order note 含「取消授權」
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'voided', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '取消授權' );
	}

	// ========== Edge：狀態機互斥守衛 ==========

	/**
	 * 已 voided 的訂單呼叫請款 → no-op + note「無法請款」+ capture_status 仍 voided
	 *
	 * 規格依據：trade-management 規則：狀態機互斥（已取消授權不可請款）
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_已voided訂單請款為noop且狀態不被覆寫(): void {
		// Given: 已取消授權（voided）的訂單
		$order = $this->create_paid_uni_embed_order( trade_no: 'UNI20260609110' );
		( new PayuniUniEmbedMetaKeys( $order ) )->update_capture_status( 'voided' );

		// When: 後台再次觸發請款
		PayuniUniEmbedGateway::handle_capture_action( $order );

		// Then: capture_status 仍為 voided（不被覆寫為 captured）+ note 含「無法請款」
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'voided', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '無法請款' );
	}

	/**
	 * 已 captured 的訂單呼叫取消授權 → no-op + note「無法取消授權」+ capture_status 仍 captured
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_已captured訂單取消授權為noop且狀態不被覆寫(): void {
		// Given: 已請款（captured）的訂單
		$order = $this->create_paid_uni_embed_order( trade_no: 'UNI20260609111' );
		( new PayuniUniEmbedMetaKeys( $order ) )->update_capture_status( 'captured' );

		// When: 後台再次觸發取消授權
		PayuniUniEmbedGateway::handle_cancel_auth_action( $order );

		// Then: capture_status 仍為 captured（不被覆寫為 voided）+ note 含「無法取消授權」
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'captured', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '無法取消授權' );
	}

	// ========== Security / Edge：D1 架構決策驗證 ==========

	/**
	 * D1 決策：後台操作的設定來源是 woocommerce_payuni_uni_embed_settings，不是 UPP settings
	 *
	 * 架構決策 D1：UNi Embed 使用獨立 settings option，
	 * 斷言「不會抓到 UPP 的 settings」。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_D1_後台操作讀取UNiEmbed_settings不讀UPP_settings(): void {
		// Given: 設定 UPP 使用不同的 MerID
		\update_option( 'woocommerce_payuni_upp_settings', [ 'merchant_id' => 'WRONG_UPP_MER_ID_TM' ] );

		// When: 取得 UNi Embed settings（後台操作用此設定）
		$settings = PayuniUniEmbedSettingsDTO::instance();

		// Then: merchant_id 是 UNi Embed 的（self::MER_ID），不是 UPP 的
		$this->assertSame(
			self::MER_ID,
			$settings->merchant_id,
			'後台操作不應讀取 UPP settings'
		);
		$this->assertNotSame( 'WRONG_UPP_MER_ID_TM', $settings->merchant_id );
	}

	/**
	 * D1 決策：後台操作讀取的 meta 是 _pc_payuni_uni_* 前綴，不讀 UPP 的 _pc_payuni_* meta
	 *
	 * 斷言請款後 capture_status 寫入 _pc_payuni_uni_capture_status（不是 _pc_payuni_capture_status）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_D1_請款寫入UNiEmbed_capture_status_meta不寫UPP_meta(): void {
		// Given: UNi Embed 訂單
		$order = $this->create_paid_uni_embed_order( trade_no: 'UNI20260609120' );

		// When: 請款成功
		PayuniUniEmbedGateway::handle_capture_action( $order );

		$fresh_order = \wc_get_order( $order->get_id() );

		// Then: _pc_payuni_uni_capture_status = 'captured'（UNi Embed meta）
		$uni_meta = new PayuniUniEmbedMetaKeys( $fresh_order );
		$this->assertSame(
			'captured',
			$uni_meta->get_capture_status(),
			'_pc_payuni_uni_capture_status 應為 captured'
		);

		// And: _pc_payuni_capture_status（UPP meta key）不應被寫入
		$upp_capture_status = $fresh_order->get_meta( '_pc_payuni_capture_status' );
		$this->assertNotSame(
			'captured',
			$upp_capture_status,
			'UPP 的 _pc_payuni_capture_status 不應被 UNi Embed 請款覆寫'
		);
	}

	/**
	 * D1 決策：查詢補單讀取的是 _pc_payuni_uni_trade_no，不是 UPP 的 _pc_payuni_trade_no
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_D1_查詢補單用UNiEmbed_trade_no_meta不用UPP_meta(): void {
		// Given: 建立 UNi Embed 訂單，同時在 _pc_payuni_trade_no（UPP）寫入不同的值
		$order        = $this->create_pending_uni_embed_order();
		$uni_trade_no = ( new PayuniUniEmbedMetaKeys( $order ) )->get_trade_no();
		// 偽造 UPP meta（_pc_payuni_trade_no）與 UNi Embed 不同
		$order->update_meta_data( '_pc_payuni_trade_no', 'WRONG_UPP_TRADE_NO' );
		$order->save_meta_data();

		// When: 讀取 UNi Embed MetaKeys 的 trade_no
		$meta_keys       = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$actual_trade_no = $meta_keys->get_trade_no();

		// Then: 讀到的是 _pc_payuni_uni_trade_no 的值，不是 UPP 的 WRONG_UPP_TRADE_NO
		$this->assertSame(
			$uni_trade_no,
			$actual_trade_no,
			'查詢補單應讀取 _pc_payuni_uni_trade_no，不是 UPP 的 _pc_payuni_trade_no'
		);
		$this->assertNotSame(
			'WRONG_UPP_TRADE_NO',
			$actual_trade_no,
			'絕不應讀取 UPP 的 _pc_payuni_trade_no'
		);
	}

	// ========== Happy Path：後台訂單操作 hook ==========

	/**
	 * UNi Embed 訂單後台操作清單含查詢補單 / 請款 / 取消授權
	 *
	 * 規格依據：trade-management 規則：後置（狀態）- 查詢/請款/取消授權結果記錄 order note
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_UNiEmbed訂單後台操作含查詢補單請款取消授權(): void {
		// Given: UNi Embed 信用卡訂單
		$order = $this->create_paid_uni_embed_order();

		// When: 套用 woocommerce_order_actions filter
		$actions = PayuniUniEmbedGateway::add_order_actions( [], $order );

		// Then: 含三個動作（UNi Embed 專屬 action key，前綴 pc_payuni_uni_）
		$this->assertArrayHasKey( 'pc_payuni_uni_query_trade', $actions );
		$this->assertArrayHasKey( 'pc_payuni_uni_capture', $actions );
		$this->assertArrayHasKey( 'pc_payuni_uni_cancel_auth', $actions );
	}

	/**
	 * 非 UNi Embed 訂單不出現 UNi Embed 後台操作選項
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_非UNiEmbed訂單不出現UNiEmbed後台操作選項(): void {
		// Given: SLP 訂單
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000.0,
			]
		);

		// When: 套用 filter
		$actions = PayuniUniEmbedGateway::add_order_actions( [], $order );

		// Then: 不含任何 UNi Embed 動作
		$this->assertArrayNotHasKey( 'pc_payuni_uni_query_trade', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_uni_capture', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_uni_cancel_auth', $actions );
	}

	/**
	 * UNi Embed 後台操作的 action key 前綴為 pc_payuni_uni_（不與 UPP 的 pc_payuni_ 衝突）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 */
	public function test_安全_D1_UNiEmbed後台action_key前綴與UPP不衝突(): void {
		// Given: UNi Embed 訂單
		$order = $this->create_paid_uni_embed_order();

		// When
		$actions = PayuniUniEmbedGateway::add_order_actions( [], $order );

		// Then: 所有 action key 前綴為 pc_payuni_uni_（非 pc_payuni_）
		foreach ( \array_keys( $actions ) as $key ) {
			$this->assertStringStartsWith(
				'pc_payuni_uni_',
				$key,
				"UNi Embed action key '{$key}' 前綴應為 pc_payuni_uni_，以免與 UPP 的 pc_payuni_ 衝突"
			);
		}

		// 且不含 UPP 的 action key
		$this->assertArrayNotHasKey( 'pc_payuni_query_trade', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_capture', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_cancel_auth', $actions );
	}
}
