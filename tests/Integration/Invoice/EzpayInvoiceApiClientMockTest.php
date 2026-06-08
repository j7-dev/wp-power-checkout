<?php
/**
 * ezPay InvoiceApiClient MOCK 模式整合測試
 *
 * 驗證 API_MODE=mock 下，ezPay 發票 client 不對外發真實網路請求，
 * 而是回固定 fixture。這讓 CI 安全、測試隔離，與其餘 API client 行為一致。
 *
 * RED 條件：在加入 mock 分流前，ApiClient 會對 inv.ezpay.com.tw 發真實請求，
 * 回應非確定且網路不可達時降級為 null，無法斷言固定發票號碼。
 *
 * 攔截器 pre_http_request：攔截任何對外 HTTP 請求，確保 MOCK 模式下完全沒有外呼。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EzpayInvoiceApiClient MOCK 模式測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 * @group mock
 */
final class EzpayInvoiceApiClientMockTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求 URL（驗證 mock 模式下完全沒有外呼）
	 *
	 * @var array<int, string>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 ezpay，攔截所有對外 HTTP 請求
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests = [];
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'        => 'test',
				'merchant_id' => 'MS12345678',
				'hash_key'    => 'abcdefghijklmnopqrstuvwxyzabcdef',
				'hash_iv'     => '1234567891234567',
			]
		);

		// 攔截：任何對外請求都記錄並短路（若 mock 分流正確，這裡完全不會被觸發）
		\add_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10, 3 );
	}

	/**
	 * 每次測試後：移除攔截、清理設定
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_http' ], 10 );
		\delete_option( ProviderUtils::get_option_name( EzpayInvoiceProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * pre_http_request 攔截器：記錄 URL 並回傳一個「不可用」回應，
	 * 確保即使誤發請求也不會真的打到 ezpay。
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt 短路值
	 * @param array<string, mixed>                 $args    請求參數
	 * @param string                               $url     請求 URL
	 * @return \WP_Error
	 */
	public function intercept_http( $preempt, $args, $url ): \WP_Error {
		$this->http_requests[] = $url;
		return new \WP_Error( 'http_blocked', '測試環境禁止對外請求' );
	}

	/**
	 * 建立一筆有商品的訂單
	 *
	 * @param array<string, mixed> $issue_params 發票資訊
	 * @return \WC_Order
	 */
	private function create_order_with_items( array $issue_params = [] ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 100,
			]
		);

		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_regular_price( '100' );
		$product->save();

		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->set_total( 100 );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->save();

		if ( $issue_params ) {
			( new MetaKeys( $order ) )->update_issue_params( $issue_params );
		}

		return $order;
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下開立發票回固定fixture且不外呼(): void {
		// Given: 一筆 ezPay 訂單
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		// When: 開立發票（MOCK 模式應回固定 fixture）
		$response = $client->issue( EzpayInvoiceProvider::ID );

		// Then: 回傳成功，含固定發票號碼，且完全沒有對外 HTTP 請求
		$this->assertNotNull( $response, 'MOCK 模式下 issue 不應回 null' );
		$this->assertTrue( $response->is_success(), 'MOCK 回應 Status 應為 SUCCESS' );
		$this->assertNotEmpty( $response->invoice_number, 'MOCK 應回固定發票號碼' );
		$this->assertSame( [], $this->http_requests, 'MOCK 模式不應對外發任何 HTTP 請求' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下開立發票寫入issued_data含ezpay特有欄位(): void {
		// Given
		$order  = $this->create_order_with_items();
		$client = new InvoiceApiClient( $order );

		// When
		$client->issue( EzpayInvoiceProvider::ID );

		// Then: meta 已寫入，且包含 ezPay 特有欄位（invoice_trans_no / random_num）
		$meta_keys = new MetaKeys( $order );
		$issued    = $meta_keys->get_issued_data();
		$this->assertNotEmpty( $issued );
		$this->assertArrayHasKey( 'invoice_trans_no', $issued, 'ezPay 必須寫入 invoice_trans_no' );
		$this->assertArrayHasKey( 'random_num', $issued, 'ezPay 必須寫入 random_num' );
		$this->assertSame( 'ezpay', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_happy_mock模式下作廢發票回固定fixture且不外呼(): void {
		// Given: 一筆已開立的訂單
		$order     = $this->create_order_with_items();
		$client    = new InvoiceApiClient( $order );
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'invoice_number'   => 'EV00000001',
				'invoice_trans_no' => 'EZT0000001',
				'random_num'       => '1234',
				'invoice_date'     => '2026-01-15',
			]
		);

		// When: 作廢發票
		$response = $client->cancel();

		// Then: 回成功、不外呼
		$this->assertNotNull( $response, 'MOCK 模式下 cancel 不應回 null' );
		$this->assertTrue( $response->is_success(), 'MOCK 作廢回應 Status 應為 SUCCESS' );
		$this->assertSame( [], $this->http_requests, 'MOCK 模式不應對外發任何 HTTP 請求' );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_smoke_mock模式下EzpayProvider完整issue流程可斷言固定值(): void {
		// Given: 透過 provider 入口（含冪等層）
		$order    = $this->create_order_with_items();
		$provider = EzpayInvoiceProvider::instance();

		// When
		$result = $provider->issue( $order );

		// Then: 回固定發票號碼、無外呼
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );
		$this->assertSame( [], $this->http_requests );
	}
}
