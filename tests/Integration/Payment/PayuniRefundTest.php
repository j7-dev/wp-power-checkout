<?php
/**
 * PAYUNi UPP V2 退款分流整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/payuni-refund.feature
 *
 * 驗證：
 *  - 退款分流依 _pc_payuni_payment_detail 的 PaymentType（非前端傳入），信用卡（PaymentType=1）
 *    → DoActionClient::refund（/api/trade/close CloseType=2）→ order note；
 *    非信用卡（PaymentType=2/3/9 等）→ WP_Error('refund_unsupported')，且不呼叫 API。
 *  - 退款金額來自 WC refund 物件（_refunded_payment 標記），純手動退款不發 API。
 *  - 退款失敗（缺 TradeNo / API 回非 SUCCESS）→ wpdb TRANSACTION ROLLBACK + 刪除 refund +
 *    order note（仿 MpgRedirectGateway / AioRedirectGateway 模式）。
 *  - DoActionClient::parse_response() 可獨立測試（解 EncryptInfo → 驗 HashInfo → Status）。
 *
 * TDD 紅燈：
 *  J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient 尚未存在
 *  PayuniUppGateway::process_refund / handle_payment_gateway_refund 尚未實作
 *
 * PAYUNi API 參考（payuni-upp-v2 skill §交易請退款 API）：
 *  - 端點：/api/trade/close（POST，Version=1.0）
 *  - CloseType：1=請款；2=退款；-1=取消請款；-2=取消退款
 *  - EncryptInfo 必填：MerID、Timestamp、TradeNo（UNi 序號，非 MerTradeNo）、CloseType
 *  - 部分退款需帶 TradeAmt
 *  - 回應（JSON）：Status / MerID / Version / EncryptInfo（解密後含 Status/Message/TradeNo/CloseType）
 *  - 僅信用卡（PaymentType=1）適用；ATM/CVS/icash/LINE Pay 等無此 API
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 *   或針對此檔：
 *     API_MODE=mock vendor/bin/phpunit --filter PayuniRefundTest
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP V2 退款分流測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 * @group refund
 */
final class PayuniRefundTest extends TestCase {

	/** @var string PAYUNi 官方公開測試向量 HashKey（32 字元） */
	private const HASH_KEY = '12345678901234567890123456789012';

	/** @var string PAYUNi 官方公開測試向量 HashIV（16 字元） */
	private const HASH_IV = '1234567890123456';

	/** @var string PAYUNi Sandbox 商店代號 */
	private const MER_ID = 'TEST_MER_001';

	/** 每次測試前啟用 payuni_upp（test 模式 + 測試向量），開啟 MOCK */
	protected function configure_dependencies(): void {
		putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'merchant_id' => self::MER_ID,
				'hash_key'    => self::HASH_KEY,
				'hash_iv'     => self::HASH_IV,
			]
		);
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		putenv( 'API_MODE' );
		delete_option( ProviderUtils::get_option_name( PayuniSettingsDTO::ID ) );
		if ( \method_exists( PayuniSettingsDTO::class, 'reset' ) ) {
			PayuniSettingsDTO::reset();
		}
		parent::tear_down();
	}

	/**
	 * 建立 PAYUNi 訂單並以 meta fixture 設定付款明細（PaymentType / TradeNo）
	 *
	 * PaymentType 值依 payuni-upp-v2 §PaymentType：
	 *  1=信用卡（含 Apple/Google Pay）；2=ATM；3=超商代碼；9=LINE Pay；11=街口支付
	 *
	 * @param int                  $payment_type PAYUNi PaymentType（1=信用卡，2=ATM 等）
	 * @param string               $trade_no     PAYUNi UNi 序號（TradeNo，非 MerTradeNo）
	 * @param float                $total        訂單金額
	 * @return \WC_Order
	 */
	private function create_payuni_order(
		int $payment_type = 1,
		string $trade_no = 'UNI20260601001',
		float $total = 1000
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => $total,
			]
		);

		$meta_keys = new PayuniMetaKeys( $order );
		// 冪等鍵（MerTradeNo）
		$meta_keys->update_trade_no( PayuniTradeNo::generate( $order->get_id() ) );
		// 付款明細（含 PAYUNi 回傳的 TradeNo / PaymentType）
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => PayuniTradeNo::generate( $order->get_id() ),
				'TradeNo'     => $trade_no,
				'TradeAmt'    => (string) (int) $total,
				'TradeStatus' => '1',
				'PaymentType' => (string) $payment_type,
			]
		);

		return $order;
	}

	/**
	 * 建立一筆 gateway 退款（標記 refunded_payment=true），不觸發 WC 內建 gateway 流程
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
		$refund = wc_create_refund(
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
	 * PAYUNi DoActionClient 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_冒煙_DoActionClient可被實例化(): void {
		$order  = $this->create_payuni_order();
		$client = new DoActionClient( $order );

		$this->assertInstanceOf( DoActionClient::class, $client );
	}

	// ========== 退款分流：前置驗證（訂單必須使用 PAYUNi 付款） ==========

	/**
	 * 非 PAYUNi 訂單呼叫退款 handler 時靜默略過，不拋例外
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_非PAYUNi訂單handle靜默略過不拋例外(): void {
		// Given: 一筆 SLP 訂單（非 PAYUNi）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000,
			]
		);
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new PayuniUppGateway();

		// When: 對非 PAYUNi 訂單呼叫 PAYUNi 退款 handler
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: refund 仍存在（未被刪除），無 PAYUNi 退款 note
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );
	}

	// ========== Happy Path：信用卡（PaymentType=1）→ API 退款 ==========

	/**
	 * 信用卡（PaymentType=1）process_refund 判定為可退款，回 true
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡process_refund回true(): void {
		// Given: PAYUNi 信用卡訂單（PaymentType=1）
		$order   = $this->create_payuni_order( payment_type: 1 );
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 500, '部分退款' );

		// Then: 信用卡支援 API 退款
		$this->assertTrue( $result );
	}

	/**
	 * 信用卡全額退款 → DoActionClient（/api/trade/close CloseType=2）成功 + order note 含金額與 TradeNo
	 *
	 * API 依據（payuni-upp-v2 skill §交易請退款 API）：
	 *  端點 /api/trade/close，CloseType=2，必帶 TradeNo（UNi 序號）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡全額退款成功並記錄order_note含金額(): void {
		// Given: MOCK 模式 PAYUNi 信用卡訂單（PaymentType=1）
		$order   = $this->create_payuni_order( payment_type: 1, trade_no: 'UNI20260601001', total: 1000 );
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new PayuniUppGateway();

		// When: 全額退款
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 退款成功 note 含金額與 TradeNo；refund 未被刪除
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '退款成功' );
		$this->assert_order_note_contains( $fresh_order, '1000' );
		$this->assert_order_note_contains( $fresh_order, 'UNI20260601001' );
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $refund->get_id() ) );
	}

	/**
	 * 信用卡部分退款 → DoActionClient 帶 TradeAmt 部分金額 + order note 含部分金額
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡部分退款成功並記錄order_note含部分金額(): void {
		// Given: PAYUNi 信用卡訂單（原 1000，退 300）
		$order   = $this->create_payuni_order( payment_type: 1, trade_no: 'UNI20260601002', total: 1000 );
		$refund  = $this->create_gateway_refund( $order, 300 );
		$gateway = new PayuniUppGateway();

		// When: 部分退款 300
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 退款成功 note 含 300
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款成功' );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '300' );
	}

	/**
	 * 信用卡退款成功後 _pc_payuni_capture_status 寫入 'refunded'
	 *
	 * 狀態機完整性（資安 Low-1）：退款成功（COMMIT）後，capture_status 應更新為 'refunded'，
	 * 供對帳 / 顯示用（WC refund 物件仍是退款冪等真實來源，此 meta 不參與冪等判斷）。
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_信用卡退款成功後capture_status為refunded(): void {
		// Given: MOCK 模式 PAYUNi 信用卡訂單（PaymentType=1）
		$order  = $this->create_payuni_order( payment_type: 1, trade_no: 'UNI20260601009', total: 1000 );
		$refund = $this->create_gateway_refund( $order, 1000 );

		// 退款前 capture_status 為空字串
		$this->assertSame( '', ( new PayuniMetaKeys( $order ) )->get_capture_status() );

		// When: 全額退款成功
		$gateway = new PayuniUppGateway();
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: capture_status 更新為 'refunded'
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( 'refunded', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款成功' );
	}

	// ========== Error：非信用卡（PaymentType=2/3/9）→ WP_Error ==========

	/**
	 * ATM（PaymentType=2）process_refund 回 WP_Error('refund_unsupported')，不呼叫 API
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_ATM付款方式process_refund回WP_Error不呼叫API(): void {
		// Given: PAYUNi ATM 訂單（PaymentType=2）
		$order   = $this->create_payuni_order( payment_type: 2 );
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '測試退款' );

		// Then: 回正規化 UNSUPPORTED \WP_Error（取代舊 refund_unsupported）
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
		$this->assertStringContainsString( '人工處理', $result->get_error_message() );
	}

	/**
	 * 超商代碼（PaymentType=3）process_refund 回 WP_Error，不呼叫 API
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_超商代碼付款方式process_refund回WP_Error(): void {
		// Given: PAYUNi CVS 訂單（PaymentType=3）
		$order   = $this->create_payuni_order( payment_type: 3 );
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '測試退款' );

		// Then（正規化 UNSUPPORTED，取代舊 refund_unsupported）
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * LINE Pay（PaymentType=9）process_refund 回 WP_Error，不呼叫 API
	 *
	 * LINE Pay 在 PAYUNi 需走另一 API（/api/trade/common/refund/linepay），
	 * 本 gateway 現階段不實作，故回 refund_unsupported（Phase 5 範疇）。
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_LINE_Pay付款方式process_refund回WP_Error(): void {
		// Given: PAYUNi LINE Pay 訂單（PaymentType=9）
		$order   = $this->create_payuni_order( payment_type: 9 );
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '測試退款' );

		// Then（正規化 UNSUPPORTED，取代舊 refund_unsupported）
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $result->get_error_code() );
	}

	/**
	 * 非信用卡訂單 handle 不呼叫 DoActionClient，僅記錄人工提示（雙重防禦）
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_非信用卡訂單handle不發API僅記錄人工提示(): void {
		// Given: PAYUNi ATM 訂單
		$order   = $this->create_payuni_order( payment_type: 2 );
		$refund  = $this->create_gateway_refund( $order, 1000 );
		$gateway = new PayuniUppGateway();

		// When: 觸發 handle（即使 process_refund 已擋下，此處作雙重驗證）
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: 記錄人工提示，無「退款成功」note
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assert_order_note_contains( $fresh_order, '人工處理' );
	}

	// ========== Error：退款失敗 → Rollback + 刪除 refund ==========

	/**
	 * 缺 TradeNo（UNi 序號）時退款失敗 + 刪除 refund（模擬 ROLLBACK）
	 *
	 * TradeNo 是 PAYUNi /api/trade/close 的必填欄位（非 MerTradeNo），
	 * 若 payment_detail 未含 TradeNo 則無法退款。
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_缺TradeNo時退款失敗並刪除refund(): void {
		// Given: payment_detail 刻意不含 TradeNo（僅有 PaymentType=1）
		$order     = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);
		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniTradeNo::generate( $order->get_id() ) );
		// 刻意只存 PaymentType，不存 TradeNo
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'PaymentType' => '1', // 信用卡，但缺 TradeNo
			]
		);

		$refund    = $this->create_gateway_refund( $order, 1000 );
		$refund_id = $refund->get_id();
		$gateway   = new PayuniUppGateway();

		// When
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 退款失敗 note + refund 被刪除（ROLLBACK）
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( wc_get_order( $refund_id ) );
	}

	/**
	 * DoActionClient API 回應 Status != SUCCESS 時退款失敗 + 刪除 refund
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_API回應Status非SUCCESS時退款失敗並刪除refund(): void {
		// Given: MOCK 模式設定回傳 FAIL（putenv 覆寫 fixture）
		putenv( 'API_MODE=mock_fail' ); // 依實作的 mock 失敗模式
		$order     = $this->create_payuni_order( payment_type: 1, trade_no: 'UNI20260601003' );
		$refund    = $this->create_gateway_refund( $order, 1000 );
		$refund_id = $refund->get_id();
		$gateway   = new PayuniUppGateway();

		// When
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund_id );

		// Then: 失敗 note + refund 被刪除
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款失敗' );
		$this->assertFalse( wc_get_order( $refund_id ) );
	}

	// ========== Security：退款金額來自 WC refund 物件（非前端） ==========

	/**
	 * 手動退款（無 _refunded_payment 標記）不發 API（安全防禦）
	 *
	 * 純手動退款（管理員在後台輸入金額，不觸發 gateway）不應呼叫 PAYUNi API；
	 * handle_payment_gateway_refund 需檢查 refund->get_refunded_payment() 為 true。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_手動退款無標記不發API(): void {
		// Given: 建立「手動」退款（refunded_payment=false，預設值）
		$order         = $this->create_payuni_order( payment_type: 1 );
		$manual_refund = wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 500,
				'reason'   => '純手動退款，不發 API',
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $manual_refund );
		// 注意：不呼叫 set_refunded_payment(true)，保持 false 預設值

		$gateway = new PayuniUppGateway();

		// When: 呼叫 handle
		$gateway->handle_payment_gateway_refund( $order->get_id(), $manual_refund->get_id() );

		// Then: 不應有退款成功 note；refund 仍存在
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		foreach ( $notes as $note ) {
			$this->assertStringNotContainsString( '退款成功', $note->content, '手動退款不應觸發 API 退款成功 note' );
		}
		$this->assertInstanceOf( \WC_Order_Refund::class, wc_get_order( $manual_refund->get_id() ) );
	}

	/**
	 * 退款金額僅接受 WC refund 物件金額（不信任前端傳入的 $amount 參數）
	 *
	 * process_refund 若忽略 $amount 參數、改讀 wc_get_order($refund_id)->get_amount()，
	 * 則前端竄改金額無效。本測試驗證：即使傳入不同的 $amount，
	 * 實際退款金額以 refund 物件為準。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_退款金額來自WC_refund物件非前端參數(): void {
		// Given: 建立 refund 金額為 300 的退款
		$order  = $this->create_payuni_order( payment_type: 1, total: 1000 );
		$refund = $this->create_gateway_refund( $order, 300 );

		// When: 呼叫 handle（gateway 應讀 refund 物件，而非外部傳入的任意金額）
		$gateway = new PayuniUppGateway();
		$gateway->handle_payment_gateway_refund( $order->get_id(), $refund->get_id() );

		// Then: note 中含 '300'（依 refund 物件金額），不含竄改值
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '300' );
	}

	// ========== DoActionClient parse_response 獨立測試 ==========

	/**
	 * parse_response 解析 SUCCESS 回應：Status=SUCCESS 為成功
	 *
	 * PAYUNi 回傳 JSON 外層 Status=SUCCESS 後，解密 EncryptInfo 取得內層資料；
	 * MOCK 模式 DoActionClient 直接回傳解密後的 fixture 陣列（省去真實 AES-256-GCM 解密）。
	 * 本測試驗證 parse_response() 從外層 JSON 字串解析為陣列、判定 Status。
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_parse_response_Status為SUCCESS解析成功(): void {
		// Given: PAYUNi /api/trade/close 成功回應的 MOCK 外層 JSON
		// （EncryptInfo 已含解密內容，MOCK 模式不實際加密）
		$order  = $this->create_payuni_order( payment_type: 1 );
		$client = new DoActionClient( $order );

		// When: 解析成功回應（Status=SUCCESS）
		$result = $client->parse_response(
			\json_encode(
				[
					'Status'  => 'SUCCESS',
					'MerID'   => self::MER_ID,
					'Version' => '1.0',
					// EncryptInfo 解密後：包含 Status/Message/TradeNo/CloseType
					'_parsed' => [
						'Status'    => 'SUCCESS',
						'Message'   => '退款成功',
						'TradeNo'   => 'UNI20260601001',
						'CloseType' => '2',
					],
				]
			)
		);

		// Then: 解析結果含 Status=SUCCESS
		$this->assertSame( 'SUCCESS', $result['Status'] );
	}

	/**
	 * parse_response 解析失敗回應：Status=ERROR 拋例外並記錄 order note
	 *
	 * PAYUNi 回傳 Status=ERROR 時無 EncryptInfo（依 skill §共用請求格式）。
	 * DoActionClient::parse_response 需偵測並拋出 \RuntimeException / \Exception。
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_parse_response_Status為ERROR時拋例外(): void {
		// Given: PAYUNi 外層 Status=ERROR 回應（無 EncryptInfo）
		$order  = $this->create_payuni_order( payment_type: 1 );
		$client = new DoActionClient( $order );

		// When / Then
		try {
			$client->parse_response(
				\json_encode(
					[
						'Status' => 'ERROR',
						'MerID'  => self::MER_ID,
					]
				)
			);
			$this->fail( '預期 Status=ERROR 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'ERROR', $e->getMessage() );
		}
		// 例外後應有失敗 order note
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '退款失敗' );
	}

	/**
	 * HashInfo 不一致時 parse_response 拋例外（防竄改）
	 *
	 * PAYUNi 回應必須驗章：SHA256(HashKey + EncryptInfo + HashIV).toUpperCase() === HashInfo
	 * 若驗章失敗代表資料遭竄改，DoActionClient 應拋例外。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_HashInfo不一致時parse_response拋例外(): void {
		// Given: HashInfo 被竄改（使用錯誤雜湊值）
		$order  = $this->create_payuni_order( payment_type: 1 );
		$client = new DoActionClient( $order );

		// When / Then：偽造 HashInfo 應觸發例外
		try {
			$client->parse_response(
				\json_encode(
					[
						'Status'      => 'SUCCESS',
						'MerID'       => self::MER_ID,
						'Version'     => '1.0',
						'EncryptInfo' => 'deadbeef1234',
						'HashInfo'    => 'INVALIDHASHVALUE0000000000000000000000000000000000000000000000001',
					]
				)
			);
			$this->fail( '預期 HashInfo 不一致應拋例外' );
		} catch ( \Throwable $e ) {
			// 例外訊息含 'HashInfo' 或 '驗章'（驗章失敗）
			$this->assertTrue(
				str_contains( $e->getMessage(), 'HashInfo' ) || str_contains( $e->getMessage(), '驗章' ),
				"例外訊息應含 'HashInfo' 或 '驗章'，實際：{$e->getMessage()}"
			);
		}
	}

	// ========== Edge：邊緣案例 ==========

	/**
	 * 零金額退款（$amount=0）不呼叫 API
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_零金額退款不呼叫API(): void {
		// Given: 信用卡訂單，嘗試退款 0 元
		$order   = $this->create_payuni_order( payment_type: 1 );
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 0, '測試零金額' );

		// Then: 不允許（WP_Error 或 false），不呼叫 API
		$this->assertFalse( $result instanceof \WC_Order_Refund || $result === true );
	}

	/**
	 * 退款金額超過訂單金額時 process_refund 回 WP_Error 或 false
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_退款金額超過訂單金額時回WP_Error(): void {
		// Given: 訂單金額 1000，嘗試退款 2000
		$order   = $this->create_payuni_order( payment_type: 1, total: 1000 );
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 2000, '超額退款' );

		// Then: 不允許（WP_Error 或 false）
		$this->assertTrue( \is_wp_error( $result ) || false === $result );
	}

	/**
	 * _pc_payuni_payment_detail 為空陣列時 process_refund 回 WP_Error / false
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 * @group refund
	 */
	public function test_payment_detail為空時退款失敗(): void {
		// Given: 未設定 payment_detail 的 PAYUNi 訂單（callback 可能漏單）
		$order   = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);
		$gateway = new PayuniUppGateway();

		// When
		$result = $gateway->process_refund( $order->get_id(), 1000, '無付款明細' );

		// Then: 回 WP_Error 或 false
		$this->assertTrue( \is_wp_error( $result ) || false === $result );
	}
}
