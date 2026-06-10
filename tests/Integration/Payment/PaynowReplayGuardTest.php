<?php
/**
 * PayNow 重放攻擊防禦測試（TDD Red 階段）
 *
 * 測試目標（資安加固 F-1）：終態訂單不得被合法 Webhook 重放「復活」
 *
 * 現況漏洞：
 *   StatusManager::handle_success() 的冪等守衛只檢查 has_status('processing')；
 *   PaynowCallback::handle_notify() 的冪等守衛也只檢查 has_status('processing')。
 *   若訂單已轉終態（refunded / cancelled / completed），重放當初合法 Webhook
 *   （簽章正確、金額正確）會通過冪等 → 再次 payment_complete() → 訂單被「復活」。
 *
 * 要鎖定的行為（加固後期望）：
 *   - 訂單已 refunded  + 合法 Success payload → 仍為 refunded（不轉 processing）
 *   - 訂單已 cancelled + 合法 Success payload → 仍為 cancelled
 *   - 訂單已 completed + 合法 Success payload → 仍為 completed
 *   - 訂單已 pending   + 合法 Success payload → 正常轉 processing（對照組，不過度防禦）
 *   - PaynowCallback 層：已 refunded 訂單收到合法簽章 Webhook → 回 HTTP 200 且訂單維持 refunded
 *
 * Red 階段說明：
 *   目前 production code 尚未實作終態守衛，以下測試全部應失敗（訂單被「復活」為 processing）。
 *   加固實作後測試將轉綠。
 *
 * 設計依據：
 *   - specs/features/payment/paynow-callback.feature
 *   - inc/classes/Domains/Payment/Paynow/Managers/StatusManager.php（handle_success 冪等守衛）
 *   - inc/classes/Domains/Payment/Paynow/Http/PaynowCallback.php（handle_notify 冪等守衛）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowReplayGuardTest"
 *
 * @group integration
 * @group paynow
 * @group payment
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowCallback;
use J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 重放攻擊防禦測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowReplayGuardTest extends TestCase {

	// ========== 測試常數 ==========

	/** 測試用 PrivateKey（HMAC 驗簽 key，與 PaynowCallbackTest 使用相同規格） */
	private const PRIVATE_KEY = 'test_private_key_replay_guard_xyz987';

	/** 測試用 PublicKey */
	private const PUBLIC_KEY = 'pk_test_replay_guard_dummy';

	// =====================================================================
	// 生命週期：configure_dependencies / tear_down
	// =====================================================================

	/**
	 * 每個測試前：注入 PayNow 設定（enabled + private_key）
	 *
	 * @return void
	 */
	protected function configure_dependencies(): void {
		ProviderUtils::update_option(
			'paynow',
			[
				'enabled'     => 'yes',
				'mode'        => 'test',
				'public_key'  => self::PUBLIC_KEY,
				'private_key' => self::PRIVATE_KEY,
			]
		);
	}

	/**
	 * 每個測試後：清除 PayNow 設定
	 *
	 * @return void
	 */
	public function tear_down(): void {
		parent::tear_down();
		\delete_option( ProviderUtils::get_option_name( 'paynow' ) );
	}

	// =====================================================================
	// 輔助方法
	// =====================================================================

	/**
	 * 建立 PayNow 測試訂單（TWD 幣別 + PaymentIntentId）
	 *
	 * @param string $intent_id PaymentIntentId（冪等主鍵）
	 * @param int    $total     訂單金額
	 * @param string $status    WC 訂單初始狀態
	 * @return \WC_Order
	 */
	private function create_paynow_order(
		string $intent_id,
		int $total = 1000,
		string $status = 'pending'
	): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => 'paynow',
				'total'          => $total,
			]
		);

		// ⚠️ 幣別守衛：store 預設幣別 USD；成功路徑訂單必須 set_currency('TWD') + save()
		$order->set_currency( 'TWD' );
		$order->save();

		( new PaynowMetaKeys( $order ) )->update_payment_intent_id( $intent_id );

		return $order;
	}

	/**
	 * 建立即時付款成功的 Webhook payload（Status=Success + CreditCard）
	 *
	 * ⚠️ 終態守衛測試的 payload 金額必須與訂單相符，
	 *    才能證明擋下的是「重放」而非「金額不符」。
	 *
	 * @param string $intent_id    PaymentIntentId
	 * @param int    $amount       金額（必須與訂單相符）
	 * @param string $payment_type 付款方式
	 * @return array<string, mixed>
	 */
	private function make_success_payload(
		string $intent_id,
		int $amount = 1000,
		string $payment_type = 'CreditCard'
	): array {
		return [
			'ConnectId'       => '26c06b86-1324-48b6-8017-29e4efa649e6',
			'RequestId'       => '09020f76-1405-4db2-b30a-ba30de629c05',
			'Status'          => 'Success',
			'OrderNo'         => 'REPLAY_GUARD_TEST_001',
			'PaymentIntentId' => $intent_id,
			'TransactionNo'   => 'TXN_REPLAY_001',
			'Amount'          => (string) $amount,
			'Currency'        => 'TWD',
			'PaymentType'     => $payment_type,
		];
	}

	/**
	 * 計算 HMAC-SHA256 簽章（strtoupper，與 PaynowCallbackTest 相同手法）
	 *
	 * @param string $raw_body    raw JSON body
	 * @param string $private_key 私鑰
	 * @return string
	 */
	private function build_signature(
		string $raw_body,
		string $private_key = self::PRIVATE_KEY
	): string {
		return \strtoupper( \hash_hmac( 'sha256', $raw_body, $private_key ) );
	}

	/**
	 * 分發 Notify 請求至 PaynowCallback
	 *
	 * @param string $raw_body  raw JSON（驗簽對象）
	 * @param string $signature HMAC-SHA256 簽章
	 * @return \WP_REST_Response
	 */
	private function dispatch_notify( string $raw_body, string $signature ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/paynow/notify' );
		$request->set_body( $raw_body );
		$request->set_header( 'X-Payment-Center-Hmac-Sha256', $signature );

		return PaynowCallback::instance()->post_notify_callback( $request );
	}

	// =====================================================================
	// F-1 Security：終態訂單重放防禦（StatusManager 層）
	// =====================================================================

	/**
	 * 訂單已 refunded + 合法 Success payload（金額相符）→ StatusManager 後訂單仍為 refunded
	 *
	 * 現況漏洞：handle_success() 冪等只檢查 has_status('processing')；
	 * refunded 不在檢查範圍，重放會呼叫 payment_complete() 讓訂單「復活」為 processing。
	 *
	 * 加固期望：終態訂單（refunded）不得被重放轉回 processing。
	 *
	 * Red 狀態：目前此測試會失敗（實際狀態為 processing，不符預期 refunded）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_重放防禦_已refunded訂單收到合法Success_仍為refunded(): void {
		$intent_id = 'pp_replay_guard_refunded_001';
		// 建立一個已 refunded 的訂單（模擬退款後重放）
		$order = $this->create_paynow_order( $intent_id, 1000, 'refunded' );

		// payload 金額刻意與訂單相符（確保是「重放」情境，而非「金額不符」被攔截）
		$payload = $this->make_success_payload( $intent_id, 1000 );

		// 直接呼叫 StatusManager（測試 StatusManager 層守衛）
		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 終態守衛應攔截：訂單仍為 refunded，不被「復活」為 processing
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'refunded' );
	}

	/**
	 * 訂單已 refunded + 合法 Success payload → StatusManager 應記錄 order note（疑似重送/重放）
	 *
	 * 加固期望：終態訂單被重放時應留下可審計的 order note，說明守衛攔截了重放。
	 *
	 * Red 狀態：目前此測試會失敗（無終態守衛 note，或訂單已被復活為 processing）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_重放防禦_已refunded訂單重放_記錄order_note(): void {
		$intent_id = 'pp_replay_guard_refunded_note_002';
		$order     = $this->create_paynow_order( $intent_id, 1000, 'refunded' );
		$payload   = $this->make_success_payload( $intent_id, 1000 );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 應有 order note 說明重放被攔截（「重放」或「終態」或「refunded」等關鍵字）
		$fresh_order      = \wc_get_order( $order->get_id() );
		$notes            = \wc_get_order_notes( [ 'order_id' => $fresh_order->get_id() ] );
		$found_guard_note = false;
		foreach ( $notes as $note ) {
			// 終態守衛 note 應包含「重放」或「終態」或「refunded」或「已退款」等關鍵字
			if (
				\str_contains( $note->content, '重放' ) ||
				\str_contains( $note->content, '終態' ) ||
				\str_contains( $note->content, 'refunded' ) ||
				\str_contains( $note->content, '已退款' ) ||
				\str_contains( $note->content, '重送' )
			) {
				$found_guard_note = true;
				break;
			}
		}
		$this->assertTrue(
			$found_guard_note,
			'終態訂單（refunded）被重放時，StatusManager 應記錄重放防禦 order note'
		);
	}

	/**
	 * 訂單已 cancelled + 合法 Success payload（金額相符）→ 仍為 cancelled
	 *
	 * Red 狀態：目前此測試會失敗（訂單被復活為 processing）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_重放防禦_已cancelled訂單收到合法Success_仍為cancelled(): void {
		$intent_id = 'pp_replay_guard_cancelled_003';
		$order     = $this->create_paynow_order( $intent_id, 1000, 'cancelled' );

		// payload 金額與訂單相符（確保是「重放」情境）
		$payload = $this->make_success_payload( $intent_id, 1000 );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 終態守衛應攔截：訂單仍為 cancelled
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'cancelled' );
	}

	/**
	 * 訂單已 completed + 合法 Success payload（金額相符）→ 仍為 completed
	 *
	 * Red 狀態：目前此測試會失敗（訂單被復活為 processing）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_重放防禦_已completed訂單收到合法Success_仍為completed(): void {
		$intent_id = 'pp_replay_guard_completed_004';
		$order     = $this->create_paynow_order( $intent_id, 1000, 'completed' );

		// payload 金額與訂單相符（確保是「重放」情境）
		$payload = $this->make_success_payload( $intent_id, 1000 );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// 終態守衛應攔截：訂單仍為 completed
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'completed' );
	}

	/**
	 * 對照組：訂單 pending + 合法 Success payload → 正常轉 processing（不過度防禦）
	 *
	 * 此測試確保終態守衛不會誤擋正常付款流程（pending → processing 應照常運作）。
	 *
	 * 注意：此測試描述現有期望行為，加固後不應被守衛誤擋。
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_對照組_pending訂單合法Success_正常轉processing(): void {
		$intent_id = 'pp_replay_guard_pending_005';
		$order     = $this->create_paynow_order( $intent_id, 1000, 'pending' );
		$payload   = $this->make_success_payload( $intent_id, 1000 );

		$manager = new StatusManager( $payload, $order );
		$manager->update_order_status();

		// pending → processing（正常付款流程，終態守衛不得干擾）
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'processing' );
	}

	// =====================================================================
	// F-1 Security：終態訂單重放防禦（PaynowCallback 層）
	// =====================================================================

	/**
	 * PaynowCallback 層：已 refunded 訂單收到合法簽章 Webhook → 回 HTTP 200 + 訂單維持 refunded
	 *
	 * 測試整個 Webhook 處理鏈：
	 *   POST → PaynowCallback → handle_notify → 終態守衛（加固後）→ 跳過 StatusManager
	 *
	 * 現況漏洞：PaynowCallback::handle_notify() 步驟 7 冪等只檢查 has_status('processing')；
	 *   refunded 不在範圍，會繼續走到 StatusManager → payment_complete() → 訂單被復活。
	 *
	 * Red 狀態：目前此測試會失敗（訂單被復活為 processing）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_重放防禦_Callback層_已refunded訂單合法Webhook_回200且維持refunded(): void {
		$intent_id = 'pp_replay_guard_cb_refunded_006';
		$order     = $this->create_paynow_order( $intent_id, 2000, 'refunded' );

		// 合法 Webhook payload（簽章正確、金額相符）
		$payload   = $this->make_success_payload( $intent_id, 2000 );
		$raw_body  = (string) \wp_json_encode( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		// Callback 一律回 HTTP 200（不讓 PayNow 重送風暴）
		$this->assertSame( 200, $response->get_status() );

		// 訂單應維持 refunded，不被復活為 processing
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'refunded' );
	}

	/**
	 * PaynowCallback 層：已 cancelled 訂單收到合法簽章 Webhook → 回 HTTP 200 + 訂單維持 cancelled
	 *
	 * Red 狀態：目前此測試會失敗（訂單被復活為 processing）。
	 *
	 * @test
	 * @group security
	 * @group paynow
	 * @group payment
	 */
	public function test_重放防禦_Callback層_已cancelled訂單合法Webhook_回200且維持cancelled(): void {
		$intent_id = 'pp_replay_guard_cb_cancelled_007';
		$order     = $this->create_paynow_order( $intent_id, 1500, 'cancelled' );

		$payload   = $this->make_success_payload( $intent_id, 1500 );
		$raw_body  = (string) \wp_json_encode( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		$this->assertSame( 200, $response->get_status() );

		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'cancelled' );
	}

	/**
	 * PaynowCallback 層：對照組 — already processing 訂單收到合法 Webhook → 回 200 + 冪等 skip
	 *
	 * 此為既有行為（processing 冪等）的回歸確認，確保加固後仍正常運作。
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_對照組_Callback層_已processing訂單合法Webhook_回200且冪等skip(): void {
		$intent_id = 'pp_replay_guard_cb_processing_008';
		$order     = $this->create_paynow_order( $intent_id, 1000, 'processing' );

		$payload   = $this->make_success_payload( $intent_id, 1000 );
		$raw_body  = (string) \wp_json_encode( $payload );
		$signature = $this->build_signature( $raw_body );

		$response = $this->dispatch_notify( $raw_body, $signature );

		// 一律回 200
		$this->assertSame( 200, $response->get_status() );

		// 仍為 processing（冪等 skip，不重複 payment_complete）
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assert_order_status( $fresh_order, 'processing' );
	}
}
