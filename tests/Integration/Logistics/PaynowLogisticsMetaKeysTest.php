<?php
/**
 * PayNow 物流 MetaKeys 測試（TDD Red 階段）
 *
 * 測試目標（尚未存在 → Red 階段，class 不存在時預期 class not found）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys
 *
 * 規格依據：
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 4
 *   - specs/open-issue/paynow-logistics-invoice-implementation-plan.md §R4 meta key 決策
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 0 MetaKeysTest
 *   - CLAUDE.md §Order Meta Keys：前綴 _pc_paynow_logistics_
 *
 * R4 裁決：
 *   PayNow 物流自建 PaynowLogisticsMetaKeys（前綴 _pc_paynow_logistics_）
 *   不復用 shared LogisticsMetaKeys（前綴 _pc_logistics_）
 *   原因：LogisticNumber/sno/paymentno/validationno 語意與 ECPay TempLogisticsID/CVSPaymentNo 不同
 *
 * Meta Key 清單（全 14 個，規格 R4 表）：
 *   _pc_paynow_logistics_provider_id
 *   _pc_paynow_logistics_service_id
 *   _pc_paynow_logistics_store_id / _store_name / _store_addr
 *   _pc_paynow_logistics_ref           （LogisticNumber，下游主鍵）
 *   _pc_paynow_logistics_sno           （物流單序號，預設 "1"）
 *   _pc_paynow_logistics_payment_no    （物流商託運單號）
 *   _pc_paynow_logistics_validation_no （物流商驗證碼）
 *   _pc_paynow_logistics_renew_order_no（重新取號 OrderNo，列印用）
 *   _pc_paynow_logistics_status        （0=成立中/1=無效）
 *   _pc_paynow_logistics_delivery_status（描述）
 *   _pc_paynow_logistics_logistic_code （貨態碼）
 *   _pc_paynow_logistics_delivery_type （黑貓溫層）
 *   _pc_paynow_logistics_collection_paid（COD 取貨付款完成，yes）
 *   _pc_paynow_logistics_processed_status（冪等防重陣列）
 *
 * 反查方法：
 *   get_order_by_order_no(string $order_no): ?\WC_Order   ← 貨態 callback 主鍵（woomp R1）
 *   get_order_by_ref(string $logistic_number): ?\WC_Order ← 保留（LogisticNumber）
 *
 * 冪等方法：
 *   is_processed(string $order_no, string $logistic_code): bool
 *   mark_processed(string $order_no, string $logistic_code): void
 *   防重 key 格式："{OrderNo}:{LogisticCode}"
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowLogisticsMetaKeysTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use Tests\Integration\TestCase;

/**
 * PayNow 物流 MetaKeys 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsMetaKeysTest extends TestCase {

	// ========== Happy：實例化 ==========

	/**
	 * PaynowLogisticsMetaKeys 可以被實例化
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_PaynowLogisticsMetaKeys_可以被實例化(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$this->assertInstanceOf( PaynowLogisticsMetaKeys::class, $meta_keys );
	}

	// ========== Happy：前綴驗證（R4） ==========

	/**
	 * 所有 meta key 常數前綴均為 _pc_paynow_logistics_（R4 裁決）
	 * 確保不與 shared LogisticsMetaKeys（_pc_logistics_）混淆
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_所有meta_key常數前綴為_pc_paynow_logistics_(): void {
		$keys = [
			PaynowLogisticsMetaKeys::PROVIDER_ID,
			PaynowLogisticsMetaKeys::SERVICE_ID,
			PaynowLogisticsMetaKeys::STORE_ID,
			PaynowLogisticsMetaKeys::STORE_NAME,
			PaynowLogisticsMetaKeys::STORE_ADDR,
			PaynowLogisticsMetaKeys::REF,
			PaynowLogisticsMetaKeys::SNO,
			PaynowLogisticsMetaKeys::PAYMENT_NO,
			PaynowLogisticsMetaKeys::VALIDATION_NO,
			PaynowLogisticsMetaKeys::RENEW_ORDER_NO,
			PaynowLogisticsMetaKeys::STATUS,
			PaynowLogisticsMetaKeys::DELIVERY_STATUS,
			PaynowLogisticsMetaKeys::LOGISTIC_CODE,
			PaynowLogisticsMetaKeys::DELIVERY_TYPE,
			PaynowLogisticsMetaKeys::COLLECTION_PAID,
			PaynowLogisticsMetaKeys::PROCESSED_STATUS,
		];

		foreach ( $keys as $key ) {
			$this->assertStringStartsWith(
				'_pc_paynow_logistics_',
				$key,
				"meta key '{$key}' 前綴不是 _pc_paynow_logistics_（R4 裁決：不可復用 shared _pc_logistics_ 前綴）"
			);
		}
	}

	/**
	 * PaynowLogisticsMetaKeys 前綴與 shared LogisticsMetaKeys 前綴不同（R4 獨立）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_PayNow前綴與shared前綴不同(): void {
		// R4 裁決：PayNow 使用獨立前綴，不與 shared _pc_logistics_ 共用
		$this->assertStringNotContainsString(
			'_pc_logistics_ref',
			PaynowLogisticsMetaKeys::REF,
			'PayNow 物流單號 meta key 不應與 shared LogisticsMetaKeys::REF 相同'
		);
		$this->assertSame( '_pc_paynow_logistics_ref', PaynowLogisticsMetaKeys::REF );
	}

	// ========== Happy：基本 getter/setter ==========

	/**
	 * provider_id 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_provider_id_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_provider_id( 'paynow_logistics' );
		$this->assertSame( 'paynow_logistics', $meta_keys->get_provider_id() );
	}

	/**
	 * service_id 寫入後可正確讀取（Logistic_serviceID）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_service_id_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '01' );
		$this->assertSame( '01', $meta_keys->get_service_id() );
	}

	/**
	 * 選店門市資訊三欄位寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_選店門市資訊三欄位寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_store_id( '991182' );
		$meta_keys->update_store_name( '統一超商城中門市' );
		$meta_keys->update_store_addr( '台北市中正區忠孝西路一段100號' );
		$this->assertSame( '991182', $meta_keys->get_store_id() );
		$this->assertSame( '統一超商城中門市', $meta_keys->get_store_name() );
		$this->assertSame( '台北市中正區忠孝西路一段100號', $meta_keys->get_store_addr() );
	}

	/**
	 * ref (LogisticNumber) 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_ref_物流單號寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LG20261001001' );
		$this->assertSame( 'LG20261001001', $meta_keys->get_ref() );
	}

	/**
	 * sno 序號寫入後可正確讀取（預設 "1"）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_sno_序號寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_sno( '1' );
		$this->assertSame( '1', $meta_keys->get_sno() );
	}

	/**
	 * payment_no 與 validation_no 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_payment_no與validation_no寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_payment_no( 'PAY123456' );
		$meta_keys->update_validation_no( 'VAL7890' );
		$this->assertSame( 'PAY123456', $meta_keys->get_payment_no() );
		$this->assertSame( 'VAL7890', $meta_keys->get_validation_no() );
	}

	/**
	 * renew_order_no 寫入後可正確讀取（ReNewOrder 後 PayNow 訂單編號，列印用）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_renew_order_no_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_renew_order_no( 'PN20261001RENEW' );
		$this->assertSame( 'PN20261001RENEW', $meta_keys->get_renew_order_no() );
	}

	/**
	 * status 寫入後可正確讀取（0=成立中/1=無效）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_status_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_status( '0' );
		$this->assertSame( '0', $meta_keys->get_status() );
	}

	/**
	 * delivery_status 與 logistic_code 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_delivery_status與logistic_code寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_delivery_status( '商品已到超商，請憑取貨碼取貨' );
		$meta_keys->update_logistic_code( '5000' );
		$this->assertSame( '商品已到超商，請憑取貨碼取貨', $meta_keys->get_delivery_status() );
		$this->assertSame( '5000', $meta_keys->get_logistic_code() );
	}

	/**
	 * collection_paid COD 取貨付款完成標記
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_collection_paid_COD取貨付款完成標記(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$this->assertFalse( $meta_keys->is_collection_paid(), '預設應為未付款（false）' );
		$meta_keys->update_collection_paid( 'yes' );
		$this->assertTrue( $meta_keys->is_collection_paid() );
		$this->assertSame( 'yes', $meta_keys->get_collection_paid() );
	}

	// ========== Happy：依 OrderNo 反查訂單（R1 / R4 主鍵） ==========

	/**
	 * 依 OrderNo 反查訂單：找到正確訂單
	 * （貨態 callback 用 orderno 反查，woomp R1 實證）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_get_order_by_order_no_找到正確訂單(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		// 儲存 OrderNo（測試時用 PCN{order_id} 格式）
		$order_no = 'PCN' . $order->get_id();
		$meta_keys->update_order_no( $order_no );

		$found = PaynowLogisticsMetaKeys::get_order_by_order_no( $order_no );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * 依 OrderNo 反查：不存在時回傳 null
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_get_order_by_order_no_找不到時回傳null(): void {
		$found = PaynowLogisticsMetaKeys::get_order_by_order_no( 'PCN_NON_EXISTENT_12345' );
		$this->assertNull( $found );
	}

	/**
	 * 依 OrderNo 反查：空字串守衛回 null（不查資料庫）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_get_order_by_order_no_空字串守衛回null(): void {
		$found = PaynowLogisticsMetaKeys::get_order_by_order_no( '' );
		$this->assertNull( $found, '空字串 OrderNo 應回 null，不查資料庫' );
	}

	// ========== Happy：依 LogisticNumber 反查訂單（保留方法） ==========

	/**
	 * 依 ref (LogisticNumber) 反查訂單：找到正確訂單
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_get_order_by_ref_找到正確訂單(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LG_REF_TEST_001' );

		$found = PaynowLogisticsMetaKeys::get_order_by_ref( 'LG_REF_TEST_001' );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * 依 ref 反查：不存在時回傳 null
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_get_order_by_ref_找不到時回傳null(): void {
		$found = PaynowLogisticsMetaKeys::get_order_by_ref( 'LG_NON_EXISTENT_99999' );
		$this->assertNull( $found );
	}

	// ========== Edge：冪等防重（is_processed / mark_processed） ==========

	/**
	 * 標記後 is_processed() = true（防重格式 "{OrderNo}:{LogisticCode}"）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_mark_processed後is_processed為true(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );

		// 未處理前
		$this->assertFalse( $meta_keys->is_processed( 'PCN001', '5000' ) );

		// 標記後
		$meta_keys->mark_processed( 'PCN001', '5000' );
		$this->assertTrue( $meta_keys->is_processed( 'PCN001', '5000' ) );
	}

	/**
	 * 同一 OrderNo 不同 LogisticCode → is_processed() = false（需以 code 區分）
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_同一OrderNo不同LogisticCode視為不同(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );

		$meta_keys->mark_processed( 'PCN001', '5000' );

		// 同 OrderNo 但不同 code
		$this->assertTrue( $meta_keys->is_processed( 'PCN001', '5000' ) );
		$this->assertFalse( $meta_keys->is_processed( 'PCN001', '5201' ), '不同 LogisticCode 應視為未處理' );
	}

	/**
	 * 重複 mark_processed 同一組合不產生重複紀錄
	 *
	 * @test
	 * @group edge
	 * @group paynow
	 * @group logistics
	 */
	public function test_重複mark_processed不產生重複紀錄(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );

		$meta_keys->mark_processed( 'PCN001', '5000' );
		$meta_keys->mark_processed( 'PCN001', '5000' );

		$processed = $meta_keys->get_processed_status();
		$count     = \count( \array_keys( $processed, 'PCN001:5000', true ) );
		$this->assertSame( 1, $count, '重複標記同一組合僅應記錄一次' );
	}

	/**
	 * HPOS 相容性：寫入後可從 DB 重新讀取
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_HPOS相容性_寫入後可從DB讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'LG_HPOS_TEST_456' );

		// 重新從 DB 讀取
		$fresh_order     = wc_get_order( $order->get_id() );
		$fresh_meta_keys = new PaynowLogisticsMetaKeys( $fresh_order );

		$this->assertSame( 'LG_HPOS_TEST_456', $fresh_meta_keys->get_ref() );
	}

	/**
	 * 幣別設為 TWD 的環境下 meta 讀寫正常（防禦性設定）
	 *
	 * @test
	 * @group happy
	 * @group paynow
	 * @group logistics
	 */
	public function test_TWD幣別環境下meta讀寫正常(): void {
		\update_option( 'woocommerce_currency', 'TWD' );

		$order     = $this->create_wc_order( [ 'total' => '1000' ] );
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_service_id( '03' );

		$this->assertSame( '03', $meta_keys->get_service_id() );
	}
}
