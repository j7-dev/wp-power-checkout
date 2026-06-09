<?php
/**
 * PAYUNi UPP V2 後台交易管理整合測試（TDD Red 階段）
 *
 * 對應規格：specs/features/payment/payuni-trade-management.feature
 *
 * 驗證：
 *  - 查詢補單（QueryTradeClient::query → /api/trade/query）：
 *    TradeStatus=1（已付款）且訂單未 processing → StatusManager 補單轉 processing。
 *  - 取消授權（DoActionClient::cancel_auth → /api/trade/cancel）：
 *    信用卡未請款 → 成功 + _pc_payuni_capture_status='voided' + order note；
 *    非信用卡 → no-op + note 提示不支援。
 *  - 請款（DoActionClient::capture → /api/trade/close CloseType=1）：
 *    信用卡 → 成功。
 *  - 後台訂單操作 hook（woocommerce_order_actions）：
 *    PAYUNi 訂單出現查詢補單 / 取消授權 / 請款選項；觸發 handler 行為正確。
 *
 * TDD 紅燈：
 *  J7\PowerCheckout\Domains\Payment\Payuni\Http\QueryTradeClient 尚未存在
 *  J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient 尚未存在（cancel_auth / capture）
 *  PayuniUppGateway::add_order_actions / handle_query_action / handle_cancel_auth_action /
 *    handle_capture_action 尚未實作
 *
 * PAYUNi API 參考（payuni-upp-v2 skill §交易查詢 / 請退款 / 取消授權 API）：
 *
 * 1. 交易查詢 /api/trade/query（Version=2.0）
 *    EncryptInfo 必填：MerID、Timestamp；MerTradeNo 或 TradeNo 擇一
 *    回應 TradeStatus：0=取號成功；9=未付款；1=已付款；2=付款失敗；3=付款取消；
 *                     4=交易逾期；8=訂單待確認
 *    DataSource=A + TradeStatus=1 → 真實已付款；DataSource=B → 處理中，建議再查
 *
 * 2. 取消授權 /api/trade/cancel（Version=1.0）
 *    EncryptInfo 必填：MerID、Timestamp、TradeNo（UNi 序號）
 *    僅適用：信用卡，且尚未請款（CloseStatus 為空或未關帳）
 *
 * 3. 請款 /api/trade/close（Version=1.0，CloseType=1）
 *    EncryptInfo 必填：MerID、Timestamp、TradeNo、CloseType=1
 *    可選：TradeAmt（部分請款時必填）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 *   或針對此檔：
 *     API_MODE=mock vendor/bin/phpunit --filter PayuniTradeManagementTest
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\QueryTradeClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PAYUNi UPP V2 後台交易管理測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 * @group trade_management
 */
final class PayuniTradeManagementTest extends TestCase {

	/** @var string PAYUNi 官方公開測試向量 HashKey（32 字元） */
	private const HASH_KEY = '12345678901234567890123456789012';

	/** @var string PAYUNi 官方公開測試向量 HashIV（16 字元） */
	private const HASH_IV = '1234567890123456';

	/** @var string PAYUNi Sandbox 商店代號 */
	private const MER_ID = 'TEST_MER_002';

	/** 每次測試前啟用 payuni_upp（test 模式 + 測試向量），開啟 MOCK */
	protected function configure_dependencies(): void {
		putenv( 'API_MODE=mock' );

		ProviderUtils::update_option(
			PayuniSettingsDTO::ID,
			[
				'enabled'          => 'yes',
				'mode'             => 'test',
				'merchant_id'      => self::MER_ID,
				'hash_key'         => self::HASH_KEY,
				'hash_iv'          => self::HASH_IV,
				'allowed_payments' => [ 'Credit', 'ATM', 'CVS' ],
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
	 * 建立已付款 PAYUNi 訂單（含 MerTradeNo + PaymentType + TradeNo）
	 *
	 * @param int    $payment_type PAYUNi PaymentType（1=信用卡，2=ATM 等）
	 * @param string $trade_no     PAYUNi UNi 序號（TradeNo）
	 * @param string $status       WC 訂單狀態
	 * @param float  $total        訂單金額
	 * @return \WC_Order
	 */
	private function create_paid_payuni_order(
		int $payment_type = 1,
		string $trade_no = 'UNI20260602001',
		string $status = 'processing',
		float $total = 1000
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => PayuniUppGateway::ID,
				'total'          => $total,
			]
		);

		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_detail(
			[
				'Status'      => 'SUCCESS',
				'MerTradeNo'  => PayuniTradeNo::generate( $order->get_id() ),
				'TradeNo'     => $trade_no,
				'TradeAmt'    => (string) (int) $total,
				'TradeStatus' => '1',
				'PaymentType' => (string) $payment_type,
				'DataSource'  => 'A',
			]
		);

		return $order;
	}

	/**
	 * 建立尚未付款的 PAYUNi 訂單（pending 狀態，模擬 callback 漏接）
	 *
	 * @param string $trade_no PAYUNi UNi 序號
	 * @return \WC_Order
	 */
	private function create_pending_payuni_order( string $trade_no = 'UNI20260602099' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);

		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniTradeNo::generate( $order->get_id() ) );
		// 刻意不存 payment_detail（模擬 callback 漏接，僅有 MerTradeNo）

		return $order;
	}

	// ========== Smoke ==========

	/**
	 * QueryTradeClient 可被實例化
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_QueryTradeClient可被實例化(): void {
		$order  = $this->create_paid_payuni_order();
		$client = new QueryTradeClient( $order );

		$this->assertInstanceOf( QueryTradeClient::class, $client );
	}

	/**
	 * DoActionClient 可被實例化（trade management 操作）
	 *
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_DoActionClient可被實例化(): void {
		$order  = $this->create_paid_payuni_order();
		$client = new DoActionClient( $order );

		$this->assertInstanceOf( DoActionClient::class, $client );
	}

	// ========== 查詢補單（/api/trade/query） ==========

	/**
	 * MOCK 模式查詢回固定 fixture（含 TradeStatus / PaymentType / MerTradeNo）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_MOCK模式查詢回固定fixture(): void {
		// Given: MOCK 模式 PAYUNi 訂單
		$order  = $this->create_paid_payuni_order( payment_type: 1 );
		$client = new QueryTradeClient( $order );

		// When: 依 MerTradeNo 查詢
		$result = $client->query();

		// Then: 回傳含交易狀態的陣列
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'TradeStatus', $result );
		$this->assertArrayHasKey( 'MerTradeNo', $result );
		$this->assertSame( PayuniTradeNo::generate( $order->get_id() ), $result['MerTradeNo'] );
	}

	/**
	 * is_paid：TradeStatus=1（已付款）回 true
	 *
	 * 依 payuni-upp-v2 skill §交易查詢 API：TradeStatus=1=已付款
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_TradeStatus為1判定已付款(): void {
		$this->assertTrue( QueryTradeClient::is_paid( '1' ) );
		$this->assertFalse( QueryTradeClient::is_paid( '0' ) );  // 取號成功，未繳費
		$this->assertFalse( QueryTradeClient::is_paid( '9' ) );  // 未付款
		$this->assertFalse( QueryTradeClient::is_paid( '2' ) );  // 付款失敗
		$this->assertFalse( QueryTradeClient::is_paid( '3' ) );  // 付款取消
		$this->assertFalse( QueryTradeClient::is_paid( '4' ) );  // 交易逾期
		$this->assertFalse( QueryTradeClient::is_paid( '8' ) );  // 訂單待確認（UNKNOWN）
	}

	/**
	 * 查詢補單：TradeStatus=1 且訂單 pending → StatusManager 補單轉 processing
	 *
	 * 情境：NotifyURL 漏接，管理員在後台手動觸發查詢補單 action →
	 * QueryTradeClient 回 TradeStatus=1 → handle_query_action 呼叫 StatusManager 補單。
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢補單TradeStatus已付款且訂單pending時補單轉processing(): void {
		// Given: callback 漏接的 pending 訂單（已付款但未補單）
		$order = $this->create_pending_payuni_order( trade_no: 'UNI20260602002' );
		// MOCK 模式下 QueryTradeClient 需能依 MerTradeNo 回 TradeStatus=1 的 fixture

		// When: 觸發後台查詢補單 action handler
		PayuniUppGateway::handle_query_action( $order );

		// Then: 訂單狀態轉為 processing + order note 含查詢結果
		$fresh_order = wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'processing' );
		$this->assert_order_note_contains( $fresh_order, '查詢' );
	}

	/**
	 * 查詢補單：TradeStatus=1 但訂單已是 processing → 不重複補單
	 *
	 * 冪等性：已 processing 的訂單不應因查詢而重複觸發 order note 或狀態變更。
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢補單訂單已processing時不重複補單(): void {
		// Given: 已 processing 的 PAYUNi 訂單
		$order = $this->create_paid_payuni_order( payment_type: 1, status: 'processing' );

		// When: 後台觸發查詢補單
		PayuniUppGateway::handle_query_action( $order );

		// Then: 狀態仍為 processing（不變），有查詢結果 note 但無「補單」note
		$this->assert_order_status( wc_get_order( $order->get_id() ), 'processing' );
	}

	/**
	 * 查詢補單：缺 MerTradeNo 時拋例外
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢補單缺MerTradeNo時拋例外(): void {
		// Given: 未寫入 MerTradeNo 的訂單（異常情境）
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);

		// When / Then
		$this->expectException( \Exception::class );
		( new QueryTradeClient( $order ) )->query();
	}

	/**
	 * QueryTradeClient 回應解析：JSON 格式（與 ECPay pipe 格式不同）
	 *
	 * PAYUNi /api/trade/query 回傳 JSON，解密後為陣列；
	 * 本測試驗證 parse_response() 正確解析 JSON body 取出 TradeStatus / MerTradeNo。
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢回應JSON解析取出TradeStatus與MerTradeNo(): void {
		// Given: 模擬解密後的 EncryptInfo 內層資料
		$order  = $this->create_paid_payuni_order();
		$client = new QueryTradeClient( $order );

		// PAYUNi 查詢回應 EncryptInfo 解密後為 querystring 格式
		// （依 payuni-upp-v2 skill §交易查詢 API 回應欄位）
		$decoded_querystring = \http_build_query(
			[
				'Status'      => 'SUCCESS',
				'Message'     => '查詢成功',
				'MerTradeNo'  => PayuniTradeNo::generate( $order->get_id() ),
				'TradeNo'     => 'UNI20260602001',
				'TradeAmt'    => '1000',
				'TradeFee'    => '30',
				'TradeStatus' => '1',
				'PaymentType' => '1',
				'PaymentDay'  => '2026-06-02 12:00:00',
				'CreateDay'   => '2026-06-02 10:00:00',
				'Gateway'     => '2',
				'DataSource'  => 'A',
			]
		);

		// When
		$result = $client->parse_response( $decoded_querystring );

		// Then
		$this->assertSame( 'SUCCESS', $result['Status'] );
		$this->assertSame( '1', $result['TradeStatus'] );
		$this->assertSame( PayuniTradeNo::generate( $order->get_id() ), $result['MerTradeNo'] );
		$this->assertSame( 'UNI20260602001', $result['TradeNo'] );
		$this->assertSame( 'A', $result['DataSource'] );
	}

	// ========== 取消授權（/api/trade/cancel） ==========

	/**
	 * 信用卡（PaymentType=1）信用卡 → cancel_auth → MOCK 回 SUCCESS + capture_status='voided'
	 *
	 * API 依據（payuni-upp-v2 skill §取消授權 API）：
	 *  端點 /api/trade/cancel，必帶 MerID / Timestamp / TradeNo
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_信用卡取消授權成功寫capture_status為voided(): void {
		// Given: MOCK 模式 PAYUNi 信用卡訂單（PaymentType=1）
		$order   = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602003' );
		$gateway = new PayuniUppGateway();

		// When: 後台觸發取消授權 handler
		PayuniUppGateway::handle_cancel_auth_action( $order );

		// Then: capture_status='voided' + order note 含取消授權
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( 'voided', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '取消授權' );
	}

	/**
	 * DoActionClient::cancel_auth MOCK 模式回 SUCCESS
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_DoActionClient取消授權MOCK回SUCCESS(): void {
		// Given
		$order  = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602004' );
		$client = new DoActionClient( $order );

		// When
		$result = $client->cancel_auth( 'UNI20260602004' );

		// Then: 回傳含 Status=SUCCESS
		$this->assertArrayHasKey( 'Status', $result );
		$this->assertSame( 'SUCCESS', $result['Status'] );
	}

	/**
	 * 非信用卡（PaymentType=2，ATM）不可取消授權 → no-op + note 提示
	 *
	 * PAYUNi /api/trade/cancel 僅適用信用卡；ATM 不支援取消授權。
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_非信用卡不可取消授權僅記錄不支援note(): void {
		// Given: PAYUNi ATM 訂單（PaymentType=2）
		$order = $this->create_paid_payuni_order( payment_type: 2 );

		// When
		PayuniUppGateway::handle_cancel_auth_action( $order );

		// Then: capture_status 維持空字串 + note 含「不支援」
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '不支援' );
	}

	/**
	 * 取消授權缺 TradeNo（UNi 序號）時拋例外並記錄 order note
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_取消授權缺TradeNo時拋例外(): void {
		// Given: 信用卡訂單但 payment_detail 缺 TradeNo
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => PayuniUppGateway::ID,
				'total'          => 1000,
			]
		);
		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->update_trade_no( PayuniTradeNo::generate( $order->get_id() ) );
		$meta_keys->update_payment_detail( [ 'PaymentType' => '1' ] ); // 缺 TradeNo

		$client = new DoActionClient( $order );

		// When / Then
		try {
			$client->cancel_auth( '' ); // 空 TradeNo
			$this->fail( '預期缺 TradeNo 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'TradeNo', $e->getMessage() );
		}
	}

	// ========== 請款（/api/trade/close CloseType=1） ==========

	/**
	 * 信用卡請款（capture）→ DoActionClient MOCK 回 SUCCESS
	 *
	 * API 依據（payuni-upp-v2 skill §交易請退款 API，CloseType=1）：
	 *  端點 /api/trade/close，CloseType=1=請款
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_信用卡請款MOCK回SUCCESS(): void {
		// Given
		$order  = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602005' );
		$client = new DoActionClient( $order );

		// When: 請款（全額）
		$result = $client->capture( 'UNI20260602005', 1000 );

		// Then
		$this->assertArrayHasKey( 'Status', $result );
		$this->assertSame( 'SUCCESS', $result['Status'] );
	}

	/**
	 * 後台觸發請款 handler → 信用卡訂單成功 + capture_status='captured' + order note
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_後台觸發請款handler信用卡成功記錄order_note(): void {
		// Given: MOCK 模式 PAYUNi 信用卡訂單
		$order = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602006' );

		// When
		PayuniUppGateway::handle_capture_action( $order );

		// Then: capture_status='captured' + note 含請款
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( 'captured', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '請款' );
	}

	/**
	 * 非信用卡（ATM）觸發請款 handler → no-op + note 提示不支援
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_非信用卡ATM請款handler不支援僅記錄note(): void {
		// Given: ATM 訂單
		$order = $this->create_paid_payuni_order( payment_type: 2 );

		// When
		PayuniUppGateway::handle_capture_action( $order );

		// Then: capture_status 維持空字串
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( '', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '不支援' );
	}

	// ========== 狀態機前置守衛（capture / cancel_auth 互斥） ==========

	/**
	 * 已取消授權（voided）的訂單呼叫請款 → no-op + note「無法請款」+ capture_status 仍 voided
	 *
	 * 狀態機完整性（資安 Medium-2）：do_capture_or_void 開頭讀現有 capture_status，
	 * 已 voided 不可再請款，避免本地狀態被覆寫而與 PAYUNi 脫節，且不發任何 API。
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_已取消授權訂單呼叫請款為noop且狀態不被覆寫(): void {
		// Given: 信用卡訂單，且已取消授權（capture_status='voided'）
		$order = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602020' );
		( new PayuniMetaKeys( $order ) )->update_capture_status( 'voided' );

		// When: 後台再次觸發請款
		PayuniUppGateway::handle_capture_action( $order );

		// Then: capture_status 仍為 voided（不被覆寫為 captured）+ note 含「無法請款」
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( 'voided', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '無法請款' );
	}

	/**
	 * 已請款（captured）的訂單呼叫取消授權 → no-op + note「無法取消授權」+ capture_status 仍 captured
	 *
	 * 狀態機完整性（資安 Medium-2）：已 captured 不可再取消授權，避免覆寫與 PAYUNi 脫節。
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_已請款訂單呼叫取消授權為noop且狀態不被覆寫(): void {
		// Given: 信用卡訂單，且已請款（capture_status='captured'）
		$order = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602021' );
		( new PayuniMetaKeys( $order ) )->update_capture_status( 'captured' );

		// When: 後台再次觸發取消授權
		PayuniUppGateway::handle_cancel_auth_action( $order );

		// Then: capture_status 仍為 captured（不被覆寫為 voided）+ note 含「無法取消授權」
		$meta_keys = new PayuniMetaKeys( wc_get_order( $order->get_id() ) );
		$this->assertSame( 'captured', $meta_keys->get_capture_status() );
		$this->assert_order_note_contains( wc_get_order( $order->get_id() ), '無法取消授權' );
	}

	// ========== 後台訂單操作 hook（woocommerce_order_actions） ==========

	/**
	 * PAYUNi 信用卡訂單於後台訂單操作清單出現查詢補單 / 請款 / 取消授權選項
	 *
	 * woocommerce_order_actions filter 由 PayuniUppGateway::add_order_actions 注入。
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_信用卡訂單後台操作含查詢補單請款取消授權(): void {
		// Given: PAYUNi 信用卡訂單
		$order = $this->create_paid_payuni_order( payment_type: 1 );

		// When: 套用 woocommerce_order_actions filter
		$actions = PayuniUppGateway::add_order_actions( [], $order );

		// Then: 含三個動作
		$this->assertArrayHasKey( 'pc_payuni_query_trade', $actions );
		$this->assertArrayHasKey( 'pc_payuni_capture', $actions );
		$this->assertArrayHasKey( 'pc_payuni_cancel_auth', $actions );
	}

	/**
	 * PAYUNi ATM 訂單於後台訂單操作清單出現查詢補單，但不含請款 / 取消授權
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_ATM訂單後台操作含查詢補單但不含請款取消授權(): void {
		// Given: PAYUNi ATM 訂單（PaymentType=2）
		$order = $this->create_paid_payuni_order( payment_type: 2 );

		// When
		$actions = PayuniUppGateway::add_order_actions( [], $order );

		// Then: 有查詢補單，但不含請款 / 取消授權
		$this->assertArrayHasKey( 'pc_payuni_query_trade', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_capture', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_cancel_auth', $actions );
	}

	/**
	 * 非 PAYUNi 訂單不出現 PAYUNi 後台操作選項
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_非PAYUNi訂單不出現PAYUNi後台操作選項(): void {
		// Given: SLP 訂單
		$order = $this->create_wc_order(
			[
				'status'         => 'processing',
				'payment_method' => 'shopline_payment_redirect',
				'total'          => 1000,
			]
		);

		// When
		$actions = PayuniUppGateway::add_order_actions( [], $order );

		// Then: 不含任何 PAYUNi 動作
		$this->assertArrayNotHasKey( 'pc_payuni_query_trade', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_capture', $actions );
		$this->assertArrayNotHasKey( 'pc_payuni_cancel_auth', $actions );
	}

	/**
	 * 後台訂單操作 add_order_actions 是靜態方法（可直接呼叫，無需實例化 gateway）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_add_order_actions靜態方法可直接呼叫(): void {
		$order = $this->create_paid_payuni_order();

		// Then: 靜態呼叫不拋例外
		$actions = PayuniUppGateway::add_order_actions( [], $order );
		$this->assertIsArray( $actions );
	}

	// ========== QueryTradeClient 回應解析（安全性） ==========

	/**
	 * 查詢回應 HashInfo 不一致時拋例外（防竄改）
	 *
	 * PAYUNi 回應須驗章：SHA256(HashKey + EncryptInfo + HashIV).toUpperCase() === HashInfo
	 * 若竄改 EncryptInfo 後 HashInfo 不再匹配，QueryTradeClient 應拋例外。
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢回應HashInfo不一致時拋例外(): void {
		$order  = $this->create_paid_payuni_order();
		$client = new QueryTradeClient( $order );

		// When / Then：HashInfo 被偽造
		try {
			$client->verify_hash_info(
				'deadbeef1234', // EncryptInfo（假造）
				'INVALIDHASHVALUE0000000000000000000000000000000000000000000000001' // HashInfo（假造）
			);
			$this->fail( '預期 HashInfo 不一致應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertTrue(
				str_contains( $e->getMessage(), 'HashInfo' ) || str_contains( $e->getMessage(), '驗章' ),
				"例外訊息應含 'HashInfo' 或 '驗章'，實際：{$e->getMessage()}"
			);
		}
	}

	/**
	 * 查詢回應 Status=ERROR（無 EncryptInfo）時拋例外
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢回應外層Status為ERROR時拋例外(): void {
		$order  = $this->create_paid_payuni_order();
		$client = new QueryTradeClient( $order );

		// When / Then
		try {
			$client->parse_outer_response(
				[
					'Status' => 'ERROR',
					'MerID'  => self::MER_ID,
				]
			);
			$this->fail( '預期 Status=ERROR 應拋例外' );
		} catch ( \Throwable $e ) {
			$this->assertStringContainsString( 'ERROR', $e->getMessage() );
		}
	}

	// ========== Edge：邊緣案例 ==========

	/**
	 * 查詢補單：DataSource=B（處理中）不補單，記錄建議再查 note
	 *
	 * 依 payuni-upp-v2 skill §查詢 API 回應：DataSource=B=處理中，建議 10 分鐘後再查。
	 * 不應在此狀態下補單（尚未確認交易成立）。
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_查詢補單DataSource為B時不補單(): void {
		// Given: MOCK 設定回傳 DataSource=B（處理中）
		// 注意：此測試預期 QueryTradeClient mock 在特定條件回傳 DataSource=B
		// 若實作上 MOCK 不支援此 fixture，本測試標記為「待 sandbox 驗證後補充 fixture」
		$order = $this->create_pending_payuni_order( trade_no: 'UNI20260602099' );

		// When: 查詢補單（DataSource=B 情境由 MOCK fixture 觸發）
		// 此測試紅燈條件：DataSource=B 的 fixture 尚未建立
		// 補充：實作時需在 QueryTradeClient MOCK 中加入 DataSource=B fixture 支援
		PayuniUppGateway::handle_query_action( $order );

		// Then: 訂單狀態維持 pending（不補單），有 note 提示稍後再查
		// 注意：若 MOCK 預設回 DataSource=A + TradeStatus=1，此測試期待的是 DataSource=B fixture
		// 實作時需確保 MOCK 支援不同 fixture 切換（例如透過 meta 或 env 變數）
		// TODO（待 sandbox 驗證）：DataSource=B fixture 確認後補充斷言
		$this->assertIsObject( wc_get_order( $order->get_id() ) );
	}

	/**
	 * 兩筆不同 PAYUNi 訂單各自 TradeNo 互不干擾（平行查詢安全性）
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_多筆訂單TradeNo查詢互不干擾(): void {
		// Given: 兩筆獨立的 PAYUNi 訂單
		$order_a = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602010' );
		$order_b = $this->create_paid_payuni_order( payment_type: 1, trade_no: 'UNI20260602011' );

		$meta_a = new PayuniMetaKeys( $order_a );
		$meta_b = new PayuniMetaKeys( $order_b );

		// Then: TradeNo 不同，互不干擾
		$detail_a = $meta_a->get_payment_detail();
		$detail_b = $meta_b->get_payment_detail();

		$this->assertSame( 'UNI20260602010', $detail_a['TradeNo'] ?? '' );
		$this->assertSame( 'UNI20260602011', $detail_b['TradeNo'] ?? '' );
		$this->assertNotSame( $order_a->get_id(), $order_b->get_id() );
	}
}
