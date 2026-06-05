<?php
/**
 * Invoice MetaKeys 整合測試
 * 驗證訂單發票相關 meta 的讀寫操作（開立、作廢、發票參數）
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use Tests\Integration\TestCase;

/**
 * Invoice MetaKeys 測試類別
 *
 * @group integration
 * @group invoice
 */
final class InvoiceMetaKeysTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_InvoiceMetaKeys_可以被實例化(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$this->assertInstanceOf( MetaKeys::class, $meta_keys );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_issue_params_key_靜態方法回傳正確key(): void {
		$key = MetaKeys::get_issue_params_key();
		$this->assertSame( '_pc_issue_invoice_params', $key );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取發票開立資料(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$issued_data = [
			'invoice_number' => 'AB12345678',
			'status'         => 'success',
			'created_at'     => '2024-01-01 10:00:00',
		];

		// When: 儲存發票開立資料
		$meta_keys->update_issued_data( $issued_data );

		// Then: 可以正確讀取
		$result = $meta_keys->get_issued_data();
		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['invoice_number'] );
		$this->assertSame( 'success', $result['status'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取發票作廢資料(): void {
		// Given: 一筆已開立發票的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$cancelled_data = [
			'cancel_number' => 'AB12345678',
			'status'        => 'cancelled',
			'cancelled_at'  => '2024-01-02 10:00:00',
		];

		// When: 儲存作廢資料
		$meta_keys->update_cancelled_data( $cancelled_data );

		// Then: 可以正確讀取
		$result = $meta_keys->get_cancelled_data();
		$this->assertSame( 'AB12345678', $result['cancel_number'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取發票參數_陣列格式(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$params = [
			'invoice_type' => 'personal',
			'carrier'      => '/ABC1234',
		];

		// When: 儲存發票參數
		$meta_keys->update_issue_params( $params );

		// Then: 可以正確讀取
		$result = $meta_keys->get_issue_params();
		$this->assertIsArray( $result );
		$this->assertSame( 'personal', $result['invoice_type'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取發票Provider_ID(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When: 儲存 provider_id
		$meta_keys->update_provider_id( 'amego' );

		// Then: 可以正確讀取
		$this->assertSame( 'amego', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_clear_data清除開立相關資料(): void {
		// Given: 一筆有開立資料的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB12345678' ] );
		$meta_keys->update_issue_params( [ 'invoice_type' => 'personal' ] );
		$meta_keys->update_provider_id( 'amego' );

		// When: 清除資料
		$meta_keys->clear_data();

		// Then: 開立資料應被清除
		$issued_data = $meta_keys->get_issued_data();
		$this->assertEmpty( $issued_data, '清除後開立資料應為空' );

		// Provider ID 也應被清除
		$this->assertSame( '', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_clear_data包含作廢資料時也一起清除(): void {
		// Given: 一筆有各種發票資料的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB12345678' ] );
		$meta_keys->update_cancelled_data( [ 'cancel_number' => 'AB12345678' ] );

		// When: 清除所有資料（包含作廢資料）
		$meta_keys->clear_data( true );

		// Then: 作廢資料也應被清除
		$cancelled_data = $meta_keys->get_cancelled_data();
		$this->assertEmpty( $cancelled_data, '清除後作廢資料應為空' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_issued_data_帶key時只回傳指定欄位(): void {
		// Given: 一筆有開立資料的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number' => 'AB12345678',
				'amount'         => 1000,
			]
		);

		// When: 取得特定 key
		$result = $meta_keys->get_issued_data( 'invoice_number' );

		// Then: 只回傳指定欄位的值
		$this->assertSame( 'AB12345678', $result );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_未開立發票時get_issued_data回傳空陣列(): void {
		// Given: 一筆沒有發票資料的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When & Then
		$result = $meta_keys->get_issued_data();
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_未設定provider_id時回傳空字串(): void {
		// Given: 一筆沒有 provider_id 的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When & Then
		$this->assertSame( '', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_未設定發票參數時回傳null(): void {
		// Given: 一筆沒有發票參數的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When & Then
		$this->assertNull( $meta_keys->get_issue_params() );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_發票參數為json字串格式時自動解析(): void {
		// Given: 一筆訂單，直接用 WC 的 update_meta_data 儲存 JSON 字串
		$order    = $this->create_wc_order();
		$json_str = wp_slash(
			json_encode(
			[
				'invoice_type' => 'business',
				'tax_id'       => '12345678',
			]
			)
			);
		$order->update_meta_data( '_pc_issue_invoice_params', $json_str );
		$order->save_meta_data();

		// When: 讀取發票參數
		$order_fresh = wc_get_order( $order->get_id() );
		$meta_keys   = new MetaKeys( $order_fresh );
		$result      = $meta_keys->get_issue_params();

		// Then: 應自動解析 JSON
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'invoice_type', $result );
		$this->assertSame( 'business', $result['invoice_type'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_clear_data不包含作廢資料時作廢資料保留(): void {
		// Given: 一筆有各種發票資料的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB12345678' ] );
		$meta_keys->update_cancelled_data( [ 'cancel_number' => 'AB12345678' ] );

		// When: 清除（不含作廢資料）
		$meta_keys->clear_data( false );

		// Then: 作廢資料應被保留
		$cancelled_data = $meta_keys->get_cancelled_data();
		$this->assertNotEmpty( $cancelled_data, '清除時未含作廢資料，作廢資料應保留' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_覆寫開立資料保留最新版本(): void {
		// Given: 先儲存第一次開立資料
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB00000001' ] );

		// When: 覆寫
		$meta_keys->update_issued_data( [ 'invoice_number' => 'AB99999999' ] );

		// Then: 只保留最新版本
		$result = $meta_keys->get_issued_data();
		$this->assertSame( 'AB99999999', $result['invoice_number'] );
	}

	// ========== 安全性（Security） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_發票參數包含XSS不造成異常(): void {
		// Given: 含有 XSS 嘗試的發票參數
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$xss_params = [
			'carrier' => '<script>alert(1)</script>',
			'tax_id'  => '"><img src=x onerror=alert(1)>',
		];

		// When: 儲存
		$meta_keys->update_issue_params( $xss_params );

		// Then: 原始資料被正確儲存（輸出時由前端處理跳脫）
		$result = $meta_keys->get_issue_params();
		$this->assertIsArray( $result );
		$this->assertSame( '<script>alert(1)</script>', $result['carrier'] );
	}
}
