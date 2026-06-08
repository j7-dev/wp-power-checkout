<?php
/**
 * ezPay 發票查詢（唯讀）整合測試
 *
 * 涵蓋 EzpayInvoiceProvider::query_invoice()：
 *  - 未開立發票時不打 API，直接回空陣列
 *  - 已開立時呼叫 invoice_search，回傳標準化欄位：
 *    invoice_number / invoice_status / upload_status / total_amt
 *  - CheckCode 驗證失敗 → 回空陣列
 *  - 查詢為唯讀操作：不修改任何 order meta 或訂單狀態
 *  - ISupportsQuery 介面實作驗證
 *
 * 注意：測試在 API_MODE=mock 下執行，不打真 API。
 * 查詢 API 出處：ezpay-invoice skill references/api-reference.md §invoice_search（Version=1.3）
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * ezPay 發票查詢測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 * @group query
 */
final class EzpayQueryTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例 + 啟用 ezpay
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => 'MS12345678',
				'hash_key'    => 'abcdefghijklmnopqrstuvwxyzabcdef',
				'hash_iv'     => '1234567891234567',
			]
		);
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EzpayInvoiceProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 EzpaySettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( EzpaySettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 建立一筆已開立發票的訂單
	 *
	 * @return \WC_Order
	 */
	private function create_issued_order(): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
		);
		$order->set_total( 100 );
		$order->save();

		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number'   => 'EV00000001',
				'invoice_trans_no' => 'EZT0000001',
				'random_num'       => '1234',
				'invoice_date'     => '2026-01-15 10:00:00',
			]
		);
		$meta_keys->update_provider_id( EzpayInvoiceProvider::ID );

		return $order;
	}

	// ========== 型別契約（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_smoke_EzpayInvoiceProvider_實作ISupportsQuery(): void {
		$this->assertInstanceOf( ISupportsQuery::class, EzpayInvoiceProvider::instance() );
	}

	// ========== 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_query_invoice_已開發票回傳標準化明細(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$result = $provider->query_invoice( $order );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		// 標準化回傳欄位
		$this->assertArrayHasKey( 'invoice_number', $result, '必須含 invoice_number' );
		$this->assertArrayHasKey( 'invoice_status', $result, '必須含 invoice_status（開立狀態）' );
		$this->assertArrayHasKey( 'upload_status', $result, '必須含 upload_status（財政部上傳狀態）' );
		$this->assertArrayHasKey( 'total_amt', $result, '必須含 total_amt' );

		$this->assertSame( 'EV00000001', $result['invoice_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_query_invoice_upload_status為有效值(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$result = $provider->query_invoice( $order );

		// UploadStatus: 0=未上傳、1=已上傳成功、2=上傳中、3=上傳失敗、4=上傳逾時
		$valid_upload_statuses = [ '0', '1', '2', '3', '4' ];
		$this->assertContains(
			(string) ( $result['upload_status'] ?? '' ),
			$valid_upload_statuses,
			'upload_status 必須為財政部上傳狀態有效值'
		);
	}

	// ========== 錯誤處理 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_error_query_invoice_未開發票不打API直接回空陣列(): void {
		// Given: 一筆沒有開立發票的訂單
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );

		// 攔截 HTTP 確認沒有外呼
		$http_calls = [];
		$interceptor = function ( $preempt, $args, $url ) use ( &$http_calls ) {
			$http_calls[] = $url;
			return new \WP_Error( 'http_blocked', '不應有外呼' );
		};
		\add_filter( 'pre_http_request', $interceptor, 10, 3 );

		$provider = EzpayInvoiceProvider::instance();
		$result   = $provider->query_invoice( $order );

		\remove_filter( 'pre_http_request', $interceptor, 10 );

		$this->assertSame( [], $result, '未開立發票時應回空陣列' );
		$this->assertSame( [], $http_calls, '未開立時不應對外發任何 HTTP 請求' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_error_query_invoice_CheckCode驗證失敗回空陣列(): void {
		// Given: 錯誤的 hash_key 觸發 CheckCode 驗證失敗
		\delete_option( ProviderUtils::get_option_name( EzpayInvoiceProvider::ID ) );
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => 'MS12345678',
				'hash_key'    => 'WRONG_KEY_INTENTIONALLY_INVALID',
				'hash_iv'     => '1234567891234567',
			]
		);

		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$result = $provider->query_invoice( $order );

		$this->assertSame( [], $result, 'CheckCode 驗證失敗時應回空陣列' );
	}

	// ========== 唯讀保證 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_query_invoice_為唯讀操作不修改issued_data(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		// 記錄查詢前的 meta
		$before_meta = ( new MetaKeys( $order ) )->get_issued_data();

		// When: 執行查詢
		$provider->query_invoice( $order );

		// Then: issued_data 未被修改
		$after_meta = ( new MetaKeys( \wc_get_order( $order->get_id() ) ) )->get_issued_data();
		$this->assertSame( $before_meta, $after_meta, '查詢操作不應修改 issued_data' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_edge_query_invoice_為唯讀操作不修改訂單狀態(): void {
		$order    = $this->create_issued_order();
		$provider = EzpayInvoiceProvider::instance();

		$before_status = $order->get_status();

		// When: 執行查詢
		$provider->query_invoice( $order );

		// Then: 訂單狀態未被修改
		$this->assert_order_status( $order, $before_status );
	}
}
