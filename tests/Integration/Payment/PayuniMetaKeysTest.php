<?php
/**
 * PAYUNi Payment 版 PayuniMetaKeys 測試（HPOS-aware 訂單 meta CRUD）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys
 *
 * 設計依據：
 *   - CLAUDE.md §Order Meta Keys 定義的 PAYUNi Payment meta key：
 *     _pc_payuni_trade_no / _pc_payuni_payment_detail / _pc_payuni_payment_info /
 *     _pc_payuni_capture_status
 *   - HPOS 相容：一律透過 $order->get_meta() / update_meta_data()
 *   - 風格對齊既有 tests/Integration/Payment/MetaKeysTest.php
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --group payuni"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use Tests\Integration\TestCase;

/**
 * PayuniMetaKeys 測試類別
 *
 * @group integration
 * @group payuni
 * @group payment
 */
final class PayuniMetaKeysTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 * @group payuni
	 * @group payment
	 */
	public function test_冒煙_PayuniMetaKeys可被實例化(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$this->assertInstanceOf( PayuniMetaKeys::class, $meta_keys );
	}

	// ========== trade_no CRUD（Happy） ==========

	/**
	 * _pc_payuni_trade_no 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_trade_no_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$meta_keys->update_trade_no( 'PAY20240101001' );

		$this->assertSame( 'PAY20240101001', $meta_keys->get_trade_no() );
	}

	/**
	 * 未設定 trade_no 時回傳空字串
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_trade_no_未設定時回傳空字串(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$this->assertSame( '', $meta_keys->get_trade_no() );
	}

	// ========== payment_detail CRUD（Happy） ==========

	/**
	 * _pc_payuni_payment_detail 寫入後可正確讀取
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_detail_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$detail = [
			'Status'      => 'SUCCESS',
			'MerTradeNo'  => 'PAY20240101001',
			'TradeNo'     => 'UNI20240101001',
			'TradeAmt'    => '1000',
			'TradeStatus' => '1',
			'PaymentType' => '1',
		];

		$meta_keys->update_payment_detail( $detail );

		$result = $meta_keys->get_payment_detail();
		$this->assertSame( 'SUCCESS', $result['Status'] ?? '' );
		$this->assertSame( 'PAY20240101001', $result['MerTradeNo'] ?? '' );
		$this->assertSame( '1', $result['TradeStatus'] ?? '' );
	}

	/**
	 * 未設定 payment_detail 時回傳空陣列
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_detail_未設定時回傳空陣列(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$this->assertSame( [], $meta_keys->get_payment_detail() );
	}

	// ========== payment_info CRUD（Happy） ==========

	/**
	 * _pc_payuni_payment_info 寫入後可正確讀取
	 * 用於儲存 ATM 虛擬帳號或 CVS 繳費代碼等取號成功資訊
	 * 依 payuni-upp-v2 §TradeStatus=0（取號成功）的回傳欄位
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_info_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$info = [
			'BankCode'   => '822',
			'BankName'   => '中國信託',
			'VAccount'   => '00000000001234',
			'ExpireDate' => '2024-12-31 23:59:59',
		];

		$meta_keys->update_payment_info( $info );

		$result = $meta_keys->get_payment_info();
		$this->assertSame( '822', $result['BankCode'] ?? '' );
		$this->assertSame( '00000000001234', $result['VAccount'] ?? '' );
	}

	/**
	 * 未設定 payment_info 時回傳空陣列
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_info_未設定時回傳空陣列(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$this->assertSame( [], $meta_keys->get_payment_info() );
	}

	// ========== capture_status CRUD（Happy） ==========

	/**
	 * _pc_payuni_capture_status 寫入後可正確讀取
	 * 用於儲存請退款 / 取消授權狀態（依 payuni-upp-v2 §交易請退款 close / cancel）
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_capture_status_寫入後可正確讀取(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$meta_keys->update_capture_status( 'refunded' );

		$this->assertSame( 'refunded', $meta_keys->get_capture_status() );
	}

	/**
	 * 未設定 capture_status 時回傳空字串
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_capture_status_未設定時回傳空字串(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$this->assertSame( '', $meta_keys->get_capture_status() );
	}

	// ========== get_order_by_trade_no（Happy / Error） ==========

	/**
	 * 依 trade_no 反查訂單：找到正確訂單
	 *
	 * @test
	 * @group happy
	 * @group payuni
	 * @group payment
	 */
	public function test_get_order_by_trade_no_找到正確訂單(): void {
		// Given: 一筆有 trade_no 的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );
		$meta_keys->update_trade_no( 'PAY_SEARCH_001' );

		// When: 以 trade_no 查詢
		$found_order = PayuniMetaKeys::get_order_by_trade_no( 'PAY_SEARCH_001' );

		// Then: 找到正確訂單
		$this->assertInstanceOf( \WC_Order::class, $found_order );
		$this->assertSame( $order->get_id(), $found_order->get_id() );
	}

	/**
	 * 依 trade_no 反查：不存在時回傳 null
	 *
	 * @test
	 * @group error
	 * @group payuni
	 * @group payment
	 */
	public function test_get_order_by_trade_no_找不到時回傳null(): void {
		$found = PayuniMetaKeys::get_order_by_trade_no( 'NONEXISTENT_PAYUNI_TRADE' );

		$this->assertNull( $found );
	}

	// ========== 邊緣案例（Edge） ==========

	/**
	 * 覆寫 trade_no 只保留最新值
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_trade_no_覆寫只保留最新值(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$meta_keys->update_trade_no( 'OLD_TRADE_NO' );
		$meta_keys->update_trade_no( 'NEW_TRADE_NO' );

		$this->assertSame( 'NEW_TRADE_NO', $meta_keys->get_trade_no() );

		// 舊 trade_no 查不到訂單
		$old_result = PayuniMetaKeys::get_order_by_trade_no( 'OLD_TRADE_NO' );
		$this->assertNull( $old_result );
	}

	/**
	 * 兩個不同訂單各自存不同的 trade_no，互不干擾
	 *
	 * @test
	 * @group edge
	 * @group payuni
	 * @group payment
	 */
	public function test_多訂單各自trade_no互不干擾(): void {
		$order_a     = $this->create_wc_order();
		$meta_keys_a = new PayuniMetaKeys( $order_a );
		$meta_keys_a->update_trade_no( 'TRADE_A_001' );

		$order_b     = $this->create_wc_order();
		$meta_keys_b = new PayuniMetaKeys( $order_b );
		$meta_keys_b->update_trade_no( 'TRADE_B_001' );

		$found_a = PayuniMetaKeys::get_order_by_trade_no( 'TRADE_A_001' );
		$found_b = PayuniMetaKeys::get_order_by_trade_no( 'TRADE_B_001' );

		$this->assertInstanceOf( \WC_Order::class, $found_a );
		$this->assertInstanceOf( \WC_Order::class, $found_b );
		$this->assertSame( $order_a->get_id(), $found_a->get_id() );
		$this->assertSame( $order_b->get_id(), $found_b->get_id() );
	}

	// ========== 安全性（Security） ==========

	/**
	 * trade_no 含 SQL injection 字串不造成異常（WooCommerce HPOS meta 安全）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_trade_no_SQL_injection不造成異常(): void {
		$sql_injection = "'; DROP TABLE wp_posts; --";
		$order         = $this->create_wc_order();
		$meta_keys     = new PayuniMetaKeys( $order );

		$meta_keys->update_trade_no( $sql_injection );

		// 儲存與讀取正確，不造成 DB 錯誤
		$this->assertSame( $sql_injection, $meta_keys->get_trade_no() );
	}

	/**
	 * payment_detail 含 XSS 字串原始儲存，不造成異常（輸出時由 WP esc_* 處理）
	 *
	 * @test
	 * @group security
	 * @group payuni
	 * @group payment
	 */
	public function test_payment_detail_XSS字串原始儲存不異常(): void {
		$xss_data = [
			'Status'  => '<script>alert("xss")</script>',
			'Message' => '"><img src=x onerror=alert(1)>',
		];

		$order     = $this->create_wc_order();
		$meta_keys = new PayuniMetaKeys( $order );

		$meta_keys->update_payment_detail( $xss_data );

		$result = $meta_keys->get_payment_detail();
		$this->assertSame( '<script>alert("xss")</script>', $result['Status'] ?? '' );
	}
}
