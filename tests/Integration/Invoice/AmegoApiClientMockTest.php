<?php
/**
 * Amego ApiClient MOCK 模式整合測試（A2）
 *
 * 驗證 API_MODE=mock 下，光貿（Amego）發票 client 不對外發真實網路請求，
 * 而是回固定 fixture。這讓 CI 安全、測試隔離，與其餘 6 個 API client 行為一致。
 *
 * RED 條件：在加入 mock 分流前，Requester::post() 會對 invoice-api.amego.tw
 * 發真實請求，回應非確定且網路不可達時降級為 null（issue/cancel 回空陣列），
 * 無法斷言固定發票號碼。
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * AmegoApiClient MOCK 模式測試類別
 *
 * @group integration
 * @group invoice
 * @group amego
 * @group mock
 */
final class AmegoApiClientMockTest extends TestCase {

	/**
	 * 攔截到的對外 HTTP 請求 URL（驗證 mock 模式下完全沒有外呼）
	 *
	 * @var array<int, string>
	 */
	private array $http_requests = [];

	/**
	 * 每次測試前：啟用 Amego，攔截所有對外 HTTP 請求
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests = [];
		$this->enable_provider(
			AmegoProvider::ID,
			[
				'mode'    => 'prod',
				'invoice' => '12345678',
				'app_key' => 'test_app_key',
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
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * pre_http_request 攔截器：記錄 URL 並回傳一個「不可用」回應，
	 * 確保即使誤發請求也不會真的打到 amego。
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
	 * 建立一筆有商品的訂單（避免 Items 為空）
	 *
	 * @return \WC_Order
	 */
	private function create_order_with_items(): \WC_Order {
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

		return $order;
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_mock模式下開立發票回固定fixture且不外呼(): void {
		// Given: 一筆綠界（Amego）訂單
		$order     = $this->create_order_with_items();
		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester );

		// When: 開立發票（MOCK 模式應回固定 fixture）
		$response = $client->issue( AmegoProvider::ID );

		// Then: 回傳成功 DTO、含固定發票號碼，且完全沒有對外 HTTP 請求
		$this->assertNotNull( $response, 'MOCK 模式下 issue 不應回 null' );
		$this->assertTrue( $response->is_success(), 'MOCK 回應 code 應為 0（成功）' );
		$this->assertNotEmpty( $response->invoice_number, 'MOCK 應回固定發票號碼' );
		$this->assertSame( [], $this->http_requests, 'MOCK 模式不應對外發任何 HTTP 請求' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_mock模式下開立發票會寫入issued_data與provider_id(): void {
		// Given
		$order     = $this->create_order_with_items();
		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester );

		// When
		$client->issue( AmegoProvider::ID );

		// Then: meta 已寫入
		$meta_keys = new MetaKeys( $order );
		$this->assertNotEmpty( $meta_keys->get_issued_data() );
		$this->assertSame( 'amego', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_mock模式下作廢發票回固定fixture且不外呼(): void {
		// Given: 一筆已開立的訂單
		$order     = $this->create_order_with_items();
		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester );
		$client->issue( AmegoProvider::ID );

		// When: 作廢發票
		$response = $client->cancel();

		// Then: 回成功、不外呼
		$this->assertNotNull( $response, 'MOCK 模式下 cancel 不應回 null' );
		$this->assertTrue( $response->is_success(), 'MOCK 作廢回應 code 應為 0' );
		$this->assertSame( [], $this->http_requests, 'MOCK 模式不應對外發任何 HTTP 請求' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_mock模式下AmegoProvider完整issue流程可斷言固定值(): void {
		// Given: 透過 provider 入口（含冪等層）
		$order = $this->create_order_with_items();
		$this->enable_provider(
			AmegoProvider::ID,
			[
				'mode'    => 'prod',
				'invoice' => '12345678',
				'app_key' => 'test_app_key',
			]
		);
		$provider = AmegoProvider::instance();

		// When
		$result = $provider->issue( $order );

		// Then: 回固定發票號碼、無外呼
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['invoice_number'] ?? '' );
		$this->assertSame( [], $this->http_requests );
	}
}
