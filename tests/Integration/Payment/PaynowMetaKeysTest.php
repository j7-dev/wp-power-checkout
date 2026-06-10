<?php
/**
 * PayNow MetaKeys 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys
 *
 * 規格依據：
 *   - specs/open-issue/paynow-implementation-plan.md §步驟 7
 *   - CLAUDE.md §Order Meta Keys：6 個 _pc_paynow_* key
 *   - specs/open-issue/paynow-execution-plan.md：反查主鍵 = PaymentIntentId（_pc_paynow_payment_intent_id）
 *
 * 6 個 meta key 常數：
 *   TRADE_NO             → _pc_paynow_trade_no
 *   PAYMENT_INTENT_ID    → _pc_paynow_payment_intent_id   ← Webhook 反查主鍵
 *   SECRET               → _pc_paynow_secret
 *   PAYMENT_DETAIL       → _pc_paynow_payment_detail
 *   PAYMENT_INFO         → _pc_paynow_payment_info        ← 離線付款待繳資訊
 *   REFUND_DETAIL        → _pc_paynow_refund_detail
 *
 * ⚠️ 反查主鍵為 PaymentIntentId（pp_xxx），不是 TradeNo（PCN{order_id}）
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowMetaKeysTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use Tests\Integration\TestCase;

/**
 * PaynowMetaKeys 測試類別
 *
 * @group integration
 * @group paynow
 * @group payment
 */
final class PaynowMetaKeysTest extends TestCase {

	// ========== 常數值正確性（Happy） ==========

	/**
	 * TRADE_NO 常數值等於 _pc_paynow_trade_no
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_常數_TRADE_NO等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_paynow_trade_no', PaynowMetaKeys::TRADE_NO );
	}

	/**
	 * PAYMENT_INTENT_ID 常數值等於 _pc_paynow_payment_intent_id
	 * ⚠️ 這是 Webhook 反查主鍵（pp_xxx 格式），不是 PCN{order_id}
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_常數_PAYMENT_INTENT_ID等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_paynow_payment_intent_id', PaynowMetaKeys::PAYMENT_INTENT_ID );
	}

	/**
	 * SECRET 常數值等於 _pc_paynow_secret
	 * 供前端 SDK 使用的 PaymentIntent secret（pp_xxx_st_xxx）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_常數_SECRET等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_paynow_secret', PaynowMetaKeys::SECRET );
	}

	/**
	 * PAYMENT_DETAIL 常數值等於 _pc_paynow_payment_detail
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_常數_PAYMENT_DETAIL等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_paynow_payment_detail', PaynowMetaKeys::PAYMENT_DETAIL );
	}

	/**
	 * PAYMENT_INFO 常數值等於 _pc_paynow_payment_info
	 * 離線付款（ATM/超商代碼）待繳資訊
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_常數_PAYMENT_INFO等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_paynow_payment_info', PaynowMetaKeys::PAYMENT_INFO );
	}

	/**
	 * REFUND_DETAIL 常數值等於 _pc_paynow_refund_detail
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_常數_REFUND_DETAIL等於正確的meta_key字串(): void {
		$this->assertSame( '_pc_paynow_refund_detail', PaynowMetaKeys::REFUND_DETAIL );
	}

	/**
	 * 所有 6 個 meta key 前綴一律為 _pc_paynow_
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_所有meta_key前綴均為_pc_paynow_(): void {
		$keys = [
			PaynowMetaKeys::TRADE_NO,
			PaynowMetaKeys::PAYMENT_INTENT_ID,
			PaynowMetaKeys::SECRET,
			PaynowMetaKeys::PAYMENT_DETAIL,
			PaynowMetaKeys::PAYMENT_INFO,
			PaynowMetaKeys::REFUND_DETAIL,
		];

		foreach ( $keys as $key ) {
			$this->assertStringStartsWith(
				'_pc_paynow_',
				$key,
				"meta key '{$key}' 前綴不是 _pc_paynow_"
			);
		}
	}

	// ========== CRUD 行為（Happy） ==========

	/**
	 * payment_intent_id 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_payment_intent_id_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowMetaKeys( $order );

		$meta_keys->update_payment_intent_id( 'pp_1a304818ced44e5cbeab6107400da3c4' );

		$this->assertSame( 'pp_1a304818ced44e5cbeab6107400da3c4', $meta_keys->get_payment_intent_id() );
	}

	/**
	 * payment_intent_id 未設定時回傳空字串
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_payment_intent_id_未設定時回傳空字串(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowMetaKeys( $order );

		$this->assertSame( '', $meta_keys->get_payment_intent_id() );
	}

	/**
	 * secret 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_secret_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowMetaKeys( $order );

		$meta_keys->update_secret( 'pp_xxx_st_abc123' );

		$this->assertSame( 'pp_xxx_st_abc123', $meta_keys->get_secret() );
	}

	/**
	 * payment_info 寫入後可正確讀取（離線付款待繳資訊）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_payment_info_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowMetaKeys( $order );

		$info = [
			'PaymentType'    => 'ATM',
			'VirtualAccount' => '12345678',
			'ExpireDate'     => '2026-12-31',
		];
		$meta_keys->update_payment_info( $info );
		$result = $meta_keys->get_payment_info();

		$this->assertSame( 'ATM', $result['PaymentType'] ?? '' );
		$this->assertSame( '12345678', $result['VirtualAccount'] ?? '' );
	}

	// ========== 依 PaymentIntentId 反查（Happy / Edge） ==========

	/**
	 * 依 PaymentIntentId 反查訂單：找到正確訂單
	 * ⚠️ 反查主鍵是 PaymentIntentId（pp_xxx），不是 TradeNo
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_get_order_by_payment_intent_id_找到正確訂單(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( 'pp_search_test_001' );

		$found_order = PaynowMetaKeys::get_order_by_payment_intent_id( 'pp_search_test_001' );

		$this->assertInstanceOf( \WC_Order::class, $found_order );
		$this->assertSame( $order->get_id(), $found_order->get_id() );
	}

	/**
	 * 依 PaymentIntentId 反查：不存在時回傳 null
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_get_order_by_payment_intent_id_找不到時回傳null(): void {
		$found = PaynowMetaKeys::get_order_by_payment_intent_id( 'pp_nonexistent_12345' );
		$this->assertNull( $found );
	}

	/**
	 * 空字串守衛：空字串 PaymentIntentId 回傳 null（不查資料庫）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group payment
	 */
	public function test_get_order_by_payment_intent_id_空字串守衛回null(): void {
		$found = PaynowMetaKeys::get_order_by_payment_intent_id( '' );
		$this->assertNull( $found, '空字串 PaymentIntentId 應回 null，不查資料庫' );
	}

	/**
	 * 使用 HPOS $order->get_meta() 讀取（不應直接走 get_post_meta）
	 * 驗證：寫入後可重新從 DB 讀取（確保走 WC_Order CRUD）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group payment
	 */
	public function test_HPOS相容性_寫入後可從DB讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowMetaKeys( $order );
		$meta_keys->update_payment_intent_id( 'pp_hpos_test_456' );

		// 重新從資料庫讀取訂單
		$fresh_order     = wc_get_order( $order->get_id() );
		$fresh_meta_keys = new PaynowMetaKeys( $fresh_order );

		$this->assertSame( 'pp_hpos_test_456', $fresh_meta_keys->get_payment_intent_id() );
	}
}
