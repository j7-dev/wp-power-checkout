<?php
/**
 * Logistics MetaKeys 整合測試
 *
 * 驗證物流相關 order meta 的讀寫（HPOS 相容，禁用 get_post_meta），
 * 以及以統一物流單號 _pc_logistics_ref 反查訂單（get_order_by_ref，計畫 T6），
 * 與「LogisticsID + LogisticsStatus」組合防重（is_processed / mark_processed，計畫 T7）。
 *
 * 鏡像 EcpayMetaKeys；對應計畫第一階段步驟 3。
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use Tests\Integration\TestCase;

/**
 * Logistics MetaKeys 測試類別
 *
 * @group integration
 * @group logistics
 */
final class LogisticsMetaKeysTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_LogisticsMetaKeys_可以被實例化(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$this->assertInstanceOf( LogisticsMetaKeys::class, $meta_keys );
	}

	// ========== 快樂路徑：各 meta 讀寫 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取暫存物流單號temp_id(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->update_temp_id( '987654' );

		$this->assertSame( '987654', $meta_keys->get_temp_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取統一物流單號ref(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->update_ref( '1769543' );

		$this->assertSame( '1769543', $meta_keys->get_ref() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取選定門市資訊(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->update_store_id( '991182' );
		$meta_keys->update_store_name( '統一超商城中門市' );
		$meta_keys->update_store_addr( '台北市中正區忠孝西路一段100號' );

		$this->assertSame( '991182', $meta_keys->get_store_id() );
		$this->assertSame( '統一超商城中門市', $meta_keys->get_store_name() );
		$this->assertSame( '台北市中正區忠孝西路一段100號', $meta_keys->get_store_addr() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取物流貨態status(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->update_status( '300' );

		$this->assertSame( '300', $meta_keys->get_status() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取C2C寄貨編號與驗證碼(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->update_cvs_payment_no( 'PAY123456' );
		$meta_keys->update_cvs_validation_no( 'VAL7890' );

		$this->assertSame( 'PAY123456', $meta_keys->get_cvs_payment_no() );
		$this->assertSame( 'VAL7890', $meta_keys->get_cvs_validation_no() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取COD取貨付款完成標記(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$this->assertFalse( $meta_keys->is_collection_paid(), '預設應為未付款' );

		$meta_keys->update_collection_paid( 'yes' );

		$this->assertSame( 'yes', $meta_keys->get_collection_paid() );
		$this->assertTrue( $meta_keys->is_collection_paid() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取已處理貨態陣列(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$this->assertSame( [], $meta_keys->get_processed_status(), '預設應為空陣列' );

		$meta_keys->update_processed_status( [ '1769543:300', '1769543:2067' ] );

		$result = $meta_keys->get_processed_status();
		$this->assertIsArray( $result );
		$this->assertContains( '1769543:300', $result );
		$this->assertContains( '1769543:2067', $result );
	}

	// ========== 退貨 / 逆物流單號 return_ref ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取逆物流單號return_ref(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->update_return_ref( 'RET-1769543' );

		$this->assertSame( 'RET-1769543', $meta_keys->get_return_ref() );
	}

	// ========== get_order_by_ref 反查 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_以統一物流單號反查訂單(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );
		$meta_keys->update_ref( 'REF-FIND-ME-1769543' );

		$found = LogisticsMetaKeys::get_order_by_ref( 'REF-FIND-ME-1769543' );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * 逆物流貨態通知帶 ReturnLogisticsID，須能以 return_ref 反查訂單
	 *
	 * @test
	 * @group happy
	 */
	public function test_以逆物流單號反查訂單(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );
		$meta_keys->update_return_ref( 'RET-FIND-ME-9988' );

		$found = LogisticsMetaKeys::get_order_by_ref( 'RET-FIND-ME-9988' );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_反查不存在的物流單號回傳null(): void {
		$found = LogisticsMetaKeys::get_order_by_ref( 'NON-EXISTENT-REF' );

		$this->assertNull( $found );
	}

	// ========== is_processed / mark_processed 防重（T7） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_標記與檢查已處理貨態以單號加貨態為key(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		// 尚未處理
		$this->assertFalse( $meta_keys->is_processed( '1769543', '300' ) );

		// 標記已處理 300
		$meta_keys->mark_processed( '1769543', '300' );

		// 同一單號 + 同一貨態 → 已處理
		$this->assertTrue( $meta_keys->is_processed( '1769543', '300' ) );

		// 同一單號 + 不同貨態 → 尚未處理（須以「單號+貨態」組合防重）
		$this->assertFalse( $meta_keys->is_processed( '1769543', '2067' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_重複標記同一貨態不會產生重複紀錄(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new LogisticsMetaKeys( $order );

		$meta_keys->mark_processed( '1769543', '300' );
		$meta_keys->mark_processed( '1769543', '300' );

		$processed = $meta_keys->get_processed_status();
		$count     = \count( \array_keys( $processed, '1769543:300', true ) );
		$this->assertSame( 1, $count, '同一貨態組合僅應記錄一次' );
	}
}
