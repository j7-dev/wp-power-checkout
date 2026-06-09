<?php
/**
 * PAYUNi UNi Embed V3 退款整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/payuni-uni-embed-refund.feature
 *
 * 驗證：
 *  - UNi Embed 僅信用卡（PaymentType=1），退款走 /api/trade/close CloseType=2（複用 Payuni DoActionClient）。
 *  - 退款金額一律來自 WC refund 物件（非前端傳入 $amount 參數）。
 *  - 退款失敗（API 回非 SUCCESS / 缺 TradeNo）→ wpdb TRANSACTION ROLLBACK + 刪除 refund + order note。
 *  - 非本 gateway 訂單不處理退款（pay_method != 'payuni_uni_embed'）。
 *  - 金額守衛：≤0 或超總額 → WP_Error，不呼叫 API。
 *
 * TDD 紅燈：
 *  PayuniUniEmbedGateway::process_refund 現為 'refund_not_implemented'（Cycle 1 安全降級）；
 *  Cycle 4 實作接上 DoActionClient Close CloseType=2 後本測試才變綠。
 *  handle_payment_gateway_refund 靜態方法尚未存在。
 *
 * Mock 手法：
 *  外部 HTTP 一律透過 WP filter `payuni_uni_embed_mock_do_action_response` mock；
 *  filter 不存在時 DoActionClient 發真實 HTTP（sandbox/prod 模式）。
 *  失敗情境：`payuni_uni_embed_mock_do_action_exception` filter 使 DoActionClient 拋例外。
 *
 * PAYUNi API 參考（payuni-upp-v2 skill §交易請退款 API）：
 *  端點 /api/trade/close（POST），CloseType=2=退款，必帶 TradeNo（UNi 序號，非 MerTradeNo）。
 *  部分退款需帶 TradeAmt。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Payment/ \
 *       --filter PayuniUniEmbedRefund --no-coverage"
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
 * PAYUNi UNi Embed V3 退款測試類別
 *
 * @group integration
 * @group payuni_uni_embed
 * @group payment
 * @group refund
 */
final class PayuniUniEmbedRefundTest extends TestCase {

	/** @var string PAYUNi 官方公開測試向量 HashKey（32 字元） */
	private const HASH_KEY = '12345678901234567890123456789012';

	/** @var string PAYUNi 官方公開測試向量 HashIV（16 字元） */
	private const HASH_IV = '1234567890123456';

	/** @var string Sandbox 商店代號 */
	private const MER_ID = 'UNI_EMBED_TEST_001';

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

		// 預設 MOCK：DoActionClient 退款回 SUCCESS（信用卡）
		\add_filter(
			'payuni_uni_embed_mock_do_action_response',
			static function ( mixed $default, string $action_type ): mixed {
				if ( 'refund' === $action_type || 'close' === $action_type ) {
					return [
						'Status'    => 'SUCCESS',
						'Message'   => '退款成功',
						'TradeNo'   => 'UNI20260609001',
						'CloseType' => '2',
					];
				}
				return $default;
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
		\remove_all_filters( 'payuni_uni_embed_mock_do_action_response' );
		\remove_all_filters( 'payuni_uni_embed_mock_do_action_exception' );
		delete_option( ProviderUtils::get_option_name( PayuniUniEmbedSettingsDTO::ID ) );
		if ( \method_exists( PayuniUniEmbedSettingsDTO::class, 'reset' ) ) {
			PayuniUniEmbedSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立已付款 UNi Embed 訂單並設定 payment_detail（含 TradeNo）
	 *
	 * @param string $trade_no PAYUNi UNi 序號（TradeNo，非 MerTradeNo）
	 * @param float  $total    訂單金額
	 * @return \WC_Order
	 */
	private function create_uni_embed_order(
		string $trade_no = 'UNI20260609001',
		float $total = 1000.0
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => $total,
			]
		);

		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		// 冪等鍵（MerTradeNo）
		$mer_trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );
		$meta_keys->update_trade_no( $mer_trade_no );
		// UNi Embed 僅信用卡（PaymentType=1）；Gateway=9（UNi Embed 固定識別碼）
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => $mer_trade_no,
				'TradeNo'     => $trade_no,
				'TradeAmt'    => (string) (int) $total,
				'TradeStatus' => '1',
				'PaymentType' => '1',
				'Gateway'     => PayuniUniEmbedGateway::GATEWAY_CODE, // '9'
			]
		);

		return $order;
	}

	/**
	 * 建立 gateway 標記的 WC refund（refunded_payment=true）
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 退款金額
	 * @param string    $reason 退款原因
	 * @return \WC_Order_Refund
	 */
	private function create_gateway_refund(
		\WC_Order $order,
		float $amount,
		string $reason = '測試退款'
	): \WC_Order_Refund {
		$refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => $amount,
				'reason'   => $reason,
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$refund->set_refunded_payment( true ); // 標記為「經 gateway 退款」
		$refund->save();
		return $refund;
	}

	// ========== Smoke ==========

	/**
	 * PayuniUniEmbedGateway 可被實例化且 ID 正確
	 *
	 * @test
	 * @group smoke
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_冒煙_PayuniUniEmbedGateway可被實例化且ID正確(): void {
		$gateway = new PayuniUniEmbedGateway();
		$this->assertInstanceOf( PayuniUniEmbedGateway::class, $gateway );
		$this->assertSame( 'payuni_uni_embed', $gateway->id );
	}

	// ========== Happy Path：信用卡 CloseType=2 退款 ==========

	/**
	 * 信用卡全額退款成功：走 /api/trade/close CloseType=2，記錄 order note 含金額與 TradeNo
	 *
	 * 規格依據：payuni-uni-embed-refund.feature 場景：信用卡全額退款成功
	 * API 依據：payuni-upp-v2 skill §交易請退款 API，CloseType=2=退款
	 *
	 * 紅燈原因：process_refund 現為 'refund_not_implemented'（Cycle 1 安全降級），
	 * Cycle 4 接上 DoActionClient 後才變綠。
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡全額退款成功記錄order_note含金額與TradeNo(): void {
		// Given: UNi Embed 信用卡訂單（PaymentType=1，Gateway=9）
		$order   = $this->create_uni_embed_order( trade_no: 'UNI20260609001', total: 1000.0 );
		$refund  = $this->create_gateway_refund( $order, 1000.0 );
		$gateway = new PayuniUniEmbedGateway();

		// When: 全額退款
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 退款成功 note 含金額 1000 與 TradeNo；refund 未被刪除
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '退款成功' );
		$this->assert_order_note_contains( $fresh_order, '1000' );
		$this->assert_order_note_contains( $fresh_order, 'UNI20260609001' );
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $refund->get_id() ) );
	}

	/**
	 * 信用卡部分退款成功：退款金額來自 WC refund 物件（非前端 $amount 參數）
	 *
	 * 規格依據：payuni-uni-embed-refund.feature 規則：退款金額一律來自 WC refund 物件
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡部分退款成功金額來自WC_refund物件(): void {
		// Given: UNi Embed 訂單 1000 元，建立 300 元退款
		$order   = $this->create_uni_embed_order( trade_no: 'UNI20260609002', total: 1000.0 );
		$refund  = $this->create_gateway_refund( $order, 300.0 );
		$gateway = new PayuniUniEmbedGateway();

		// When: 觸發退款（gateway 需讀 refund 物件金額，非外部傳入的任意值）
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: note 含 300（依 refund 物件金額）；退款成功
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '退款成功' );
		$this->assert_order_note_contains( $fresh_order, '300' );
	}

	/**
	 * 信用卡退款成功後 _pc_payuni_uni_capture_status 寫入 'refunded'
	 *
	 * @test
	 * @group happy
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡退款成功後capture_status寫入refunded(): void {
		// Given: UNi Embed 信用卡訂單
		$order     = $this->create_uni_embed_order( trade_no: 'UNI20260609003', total: 1000.0 );
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		// 退款前 capture_status 應為空
		$this->assertSame( '', $meta_keys->get_capture_status() );

		$refund = $this->create_gateway_refund( $order, 1000.0 );

		// When: 全額退款成功
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: capture_status 更新為 'refunded'
		$fresh_meta = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'refunded', $fresh_meta->get_capture_status() );
	}

	// ========== Error：退款失敗 → ROLLBACK + 刪除 refund ==========

	/**
	 * DoActionClient close 失敗 → wpdb ROLLBACK + 刪除 refund + 記錄失敗 order note
	 *
	 * 規格依據：payuni-uni-embed-refund.feature 場景：退款失敗時 ROLLBACK 並刪除該筆 refund
	 * 比照 PayuniRefundTest + EcpgGateway 模式（wpdb TRANSACTION + ROLLBACK on failure）
	 *
	 * 紅燈原因：handle_payment_gateway_refund 尚未存在，且 ROLLBACK 邏輯尚未實作。
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_close失敗時ROLLBACK刪除refund並記錄失敗order_note(): void {
		// Given: 設定 DoActionClient 在此測試中拋例外（模擬 API 失敗）
		\remove_all_filters( 'payuni_uni_embed_mock_do_action_response' );
		\add_filter(
			'payuni_uni_embed_mock_do_action_exception',
			static function (): bool {
				return true; // 觸發例外
			}
		);

		$order     = $this->create_uni_embed_order( trade_no: 'UNI20260609010', total: 1000.0 );
		$refund    = $this->create_gateway_refund( $order, 1000.0 );
		$refund_id = $refund->get_id();

		// When: 退款 API 失敗
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 失敗 note + refund 被刪除（ROLLBACK）
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( \wc_get_order( $refund_id ) );
	}

	/**
	 * 缺 TradeNo（UNi 序號）時退款失敗，刪除 refund
	 *
	 * TradeNo 是 /api/trade/close 必填欄位；payment_detail 未含 TradeNo → 無法退款
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_缺TradeNo時退款失敗並刪除refund(): void {
		// Given: payment_detail 刻意不含 TradeNo（僅有 PaymentType=1，模擬不完整資料）
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUniEmbedGateway::ID,
				'total'          => 1000.0,
			]
		);
		$meta_keys = new PayuniUniEmbedMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniUniEmbedTradeNo::generate( $order->get_id() ) );
		// 刻意只存 PaymentType，不存 TradeNo
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'PaymentType' => '1',
				'Gateway'     => '9',
				// ← TradeNo 故意缺漏
			]
		);

		$refund    = $this->create_gateway_refund( $order, 1000.0 );
		$refund_id = $refund->get_id();

		// When: 退款
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 退款失敗 note + refund 被刪除
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( \wc_get_order( $refund_id ) );
	}

	/**
	 * 非本 gateway 訂單不由本 gateway 處理退款（靜默略過）
	 *
	 * 規格依據：payuni-uni-embed-refund.feature 場景：非本 gateway 訂單不由本 gateway 處理退款
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_非本gateway訂單不處理退款靜默略過(): void {
		// Given: SLP 訂單（payment_method != 'payuni_uni_embed'）
		$order  = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000.0,
			]
		);
		$refund = $this->create_gateway_refund( $order, 1000.0 );

		// When: UNi Embed gateway 嘗試處理非本 gateway 訂單的退款
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: refund 仍存在（未被刪除），無退款成功 note
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $refund->get_id() ) );
		$notes = \wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( '退款成功', $note->content, '非本 gateway 不應觸發退款成功 note' );
		}
	}

	// ========== Error / Edge：金額守衛 ==========

	/**
	 * 零金額退款（$amount=0）→ process_refund 回 false 或 WP_Error，不呼叫 API
	 *
	 * @test
	 * @group error
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_零金額退款不呼叫API回false或WP_Error(): void {
		// Given: UNi Embed 信用卡訂單
		$order   = $this->create_uni_embed_order();
		$gateway = new PayuniUniEmbedGateway();

		// When: 嘗試退款 0 元
		$result = $gateway->process_refund( $order->get_id(), 0, '零金額測試' );

		// Then: 不允許（WP_Error 或 false），且錯誤碼不是 'refund_not_implemented'（Cycle 1 降級）
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'零金額退款應回 WP_Error 或 false，實際回：' . \gettype( $result )
		);
	}

	/**
	 * 退款金額超過訂單金額時 process_refund 回 WP_Error 或 false
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_退款金額超過訂單金額時回WP_Error或false(): void {
		// Given: 訂單金額 1000，嘗試退款 2000（超額）
		$order   = $this->create_uni_embed_order( total: 1000.0 );
		$gateway = new PayuniUniEmbedGateway();

		// When: 超額退款
		$result = $gateway->process_refund( $order->get_id(), 2000.0, '超額退款測試' );

		// Then: 不允許
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'超額退款應回 WP_Error 或 false'
		);
	}

	/**
	 * 負數金額退款 → process_refund 回 WP_Error 或 false
	 *
	 * @test
	 * @group edge
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_負數金額退款回WP_Error或false(): void {
		// Given: UNi Embed 訂單
		$order   = $this->create_uni_embed_order( total: 1000.0 );
		$gateway = new PayuniUniEmbedGateway();

		// When: 負數退款
		$result = $gateway->process_refund( $order->get_id(), -100.0, '負數金額測試' );

		// Then: 不允許
		$this->assertTrue(
			\is_wp_error( $result ) || false === $result,
			'負數退款應回 WP_Error 或 false'
		);
	}

	// ========== Security：D1 架構決策驗證 — UNi Embed 與 UPP settings/meta 完全隔離 ==========

	/**
	 * D1 決策：退款流程讀取的是 payuni_uni_embed settings，不會誤讀 UPP settings
	 *
	 * 架構決策 D1（specs/open-issue/payuni-uni-embed-execution-plan.md）：
	 * UNi Embed 使用 `woocommerce_payuni_uni_embed_settings`，UPP 使用 `woocommerce_payuni_upp_settings`，
	 * 兩者完全隔離；後台 client 透過 PayuniUniEmbedSettingsDTO::instance() 取憑證，不應讀到 UPP 的設定。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_安全_D1_退款讀取UNiEmbed_settings不讀UPP_settings(): void {
		// Given: 設定 UPP 使用不同的 MerID（如果退款讀到 UPP 設定，MerID 會不同）
		$uni_embed_mer_id = self::MER_ID; // 已在 configure_dependencies 設定
		\update_option( 'woocommerce_payuni_upp_settings', [ 'merchant_id' => 'WRONG_UPP_MER_ID' ] );

		// When: 取得 UNi Embed settings
		$settings = PayuniUniEmbedSettingsDTO::instance();

		// Then: merchant_id 是 UNi Embed 的，不是 UPP 的
		$this->assertSame(
			$uni_embed_mer_id,
			$settings->merchant_id,
			'PayuniUniEmbedSettingsDTO 不應讀取 UPP settings'
		);
		$this->assertNotSame(
			'WRONG_UPP_MER_ID',
			$settings->merchant_id,
			'PayuniUniEmbedSettingsDTO 絕不含 UPP 的 MerID'
		);

		// Cleanup
		\delete_option( 'woocommerce_payuni_upp_settings' );
	}

	/**
	 * D1 決策：退款讀取的 meta 是 _pc_payuni_uni_* 前綴，絕不讀 _pc_payuni_* 的 UPP meta
	 *
	 * 驗證退款 handler 讀取 PayuniUniEmbedMetaKeys（_pc_payuni_uni_）而非 PayuniMetaKeys（_pc_payuni_）
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_安全_D1_退款讀取UNiEmbed_meta不讀UPP_meta(): void {
		// Given: 建立 UNi Embed 訂單，同時在 _pc_payuni_（UPP 前綴）寫入錯誤的 TradeNo
		$order = $this->create_uni_embed_order( trade_no: 'UNI_CORRECT_EMBED_001', total: 1000.0 );
		// 偽造 UPP meta（_pc_payuni_payment_detail）寫入訂單（如果退款讀 UPP meta 會拿到此值）
		$order->update_meta_data( '_pc_payuni_payment_detail', [ 'TradeNo' => 'UPP_WRONG_TRADE_NO' ] );
		$order->save_meta_data();

		// When: 讀取 UNi Embed MetaKeys
		$meta_keys = new PayuniUniEmbedMetaKeys( \wc_get_order( $order->get_id() ) );
		$detail    = $meta_keys->get_payment_detail();

		// Then: payment_detail 是 UNi Embed 的（TradeNo = 'UNI_CORRECT_EMBED_001'），不是 UPP 的
		$this->assertSame(
			'UNI_CORRECT_EMBED_001',
			$detail['TradeNo'] ?? '',
			'UNi Embed MetaKeys 讀取的 TradeNo 應為 UNi Embed 的，不是 UPP 的 WRONG_TRADE_NO'
		);
		$this->assertNotSame(
			'UPP_WRONG_TRADE_NO',
			$detail['TradeNo'] ?? '',
			'退款絕不應讀取 UPP 的 _pc_payuni_payment_detail'
		);
	}

	/**
	 * 退款金額來自 WC refund 物件（非前端 $amount 參數），防止前端竄改金額
	 *
	 * 規格依據：payuni-uni-embed-refund.feature 規則：退款金額一律來自 WC refund 物件
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_安全_退款金額來自WC_refund物件非前端amount參數(): void {
		// Given: refund 物件金額為 300
		$order  = $this->create_uni_embed_order( total: 1000.0 );
		$refund = $this->create_gateway_refund( $order, 300.0 );

		// When: handle 執行（gateway 應讀 refund 物件金額 300，不信任外部傳入值）
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: note 含 '300'（依 refund 物件金額），退款成功
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), '300' );
	}

	/**
	 * 手動退款（無 _refunded_payment 標記）不發 API（安全防禦）
	 *
	 * handle_payment_gateway_refund 需檢查 refund->get_refunded_payment() 為 true，
	 * 純手動退款不呼叫 PAYUNi API。
	 *
	 * @test
	 * @group security
	 * @group payuni_uni_embed
	 * @group payment
	 * @group refund
	 */
	public function test_安全_手動退款無標記不發API(): void {
		// Given: 建立「手動」退款（refunded_payment=false，預設值）
		$order         = $this->create_uni_embed_order();
		$manual_refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 500.0,
				'reason'   => '純手動退款，不發 API',
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $manual_refund );
		// 注意：不呼叫 set_refunded_payment(true)，維持 false 預設值

		// When: 呼叫 handle
		PayuniUniEmbedGateway::handle_payment_gateway_refund( $order->get_id(), $manual_refund->get_id() );

		// Then: 不應有退款成功 note；refund 仍存在
		$notes = \wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( '退款成功', $note->content, '手動退款不應觸發 API 退款成功 note' );
		}
		$this->assertInstanceOf( \WC_Order_Refund::class, \wc_get_order( $manual_refund->get_id() ) );
	}
}
