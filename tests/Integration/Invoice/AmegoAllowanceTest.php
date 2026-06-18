<?php
/**
 * 光貿（Amego）電子發票折讓（Allowance）整合測試（B6）
 *
 * 涵蓋：
 *  - EApi 新增 g0401（開立折讓）/ g0501（作廢折讓）端點與 label
 *  - AmegoProvider::issue_allowance() / invalid_allowance()
 *  - 折讓 meta 寫入（_pc_allowance_data）
 *  - 冪等：已開折讓不重打
 *  - 金額驗證：折讓金額 > 0 且 ≤ 原發票金額
 *  - 作廢折讓清除 allowance_data
 *  - ISupportsAllowance 型別契約
 *
 * 測試在 API_MODE=mock 下執行，Requester 折讓走固定 fixture，不打真 API。
 *
 * 折讓 API 出處：
 *  - 開立折讓：amego-invoice skill §開立折讓 `/json/g0401`（data Array）
 *  - 作廢折讓：amego-invoice skill §作廢折讓 `/json/g0501`（CancelAllowanceNumber）
 *  - 折讓金額：amego-invoice skill api-reference §折讓金額（g0401，ProductItem 未稅 + Tax）
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * AmegoProvider 折讓測試類別
 *
 * @group integration
 * @group invoice
 * @group amego
 * @group allowance
 */
final class AmegoAllowanceTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例 + 啟用 amego（測試模式）
	 */
	public function set_up(): void {
		parent::set_up();
		$this->reset_settings_instance();
		$this->enable_provider(
			AmegoProvider::ID,
			[
				'mode' => 'test',
			]
		);
	}

	/**
	 * 每次測試後清理
	 */
	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 AmegoSettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( AmegoSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 建立一筆已開立發票的訂單
	 *
	 * @param array<string, mixed> $issued_data 已開立發票 meta
	 * @return \WC_Order
	 */
	private function create_issued_order( array $issued_data = [] ): \WC_Order {
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

		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_issued_data(
			\wp_parse_args(
				$issued_data,
				[
					'invoice_number' => 'AG00000001',
					'invoice_time'   => \time(),
					'random_number'  => '1234',
				]
			)
		);
		$meta_keys->update_provider_id( AmegoProvider::ID );

		return $order;
	}

	// ========== EApi enum ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_EApi_折讓case端點正確(): void {
		$this->assertSame( '/json/g0401', EApi::ALLOWANCE->value );
		$this->assertSame( '/json/g0501', EApi::ALLOWANCE_INVALID->value );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_EApi_折讓case有label(): void {
		$this->assertSame( '開立折讓', EApi::ALLOWANCE->label() );
		$this->assertSame( '作廢折讓', EApi::ALLOWANCE_INVALID->label() );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_AmegoProvider_實作ISupportsAllowance(): void {
		$provider = AmegoProvider::instance();
		$this->assertInstanceOf( ISupportsAllowance::class, $provider );
	}

	// ========== 快樂路徑：開立折讓 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_allowance_部分折讓成功寫入allowance_data(): void {
		// Given: 一筆已開立發票的訂單（原發票 100 元）
		$order    = $this->create_issued_order();
		$provider = AmegoProvider::instance();

		// When: 對 50 元開折讓單
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回傳含折讓單號，allowance meta 已寫入
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['allowance_number'] ?? '' );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();
		$this->assertNotEmpty( $allowance_data );
		$this->assertSame( 50, (int) ( $allowance_data['allowance_amount'] ?? 0 ) );
		$this->assertSame( 'AG00000001', $allowance_data['invoice_number'] ?? '' );
	}

	// ========== 冪等 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_allowance_已開折讓時冪等回傳已存在資料(): void {
		// Given: 已有折讓資料的訂單
		$order     = $this->create_issued_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_allowance_data(
			[
				'allowance_number' => 'EXISTING001',
				'allowance_amount' => 30,
			]
		);

		$provider = AmegoProvider::instance();

		// When: 再次開折讓
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then: 回傳已存在折讓單號（冪等，不重打）
		$this->assertSame( 'EXISTING001', $result['allowance_number'] ?? '' );
	}

	// ========== 金額驗證 ==========

	/**
	 * 折讓金額為零 → 正規化 VALIDATION（契約演進：原 return [] → WP_Error）
	 *
	 * @test
	 * @group error
	 */
	public function test_issue_allowance_金額為零回傳VALIDATION(): void {
		$order    = $this->create_issued_order();
		$provider = AmegoProvider::instance();

		$result = $provider->issue_allowance( $order, 0.0 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	/**
	 * 折讓金額超過原發票 → 正規化 VALIDATION（契約演進：原 return [] → WP_Error）
	 *
	 * @test
	 * @group error
	 */
	public function test_issue_allowance_金額超過原發票回傳VALIDATION(): void {
		// Given: 原發票 100 元
		$order    = $this->create_issued_order();
		$provider = AmegoProvider::instance();

		// When: 折讓 200 元（超過）
		$result = $provider->issue_allowance( $order, 200.0 );

		// Then: 拒絕（VALIDATION）
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $result ) );
	}

	/**
	 * 未開立發票就開折讓 → 正規化 NOT_FOUND（契約演進：原 return [] → WP_Error）
	 *
	 * @test
	 * @group error
	 */
	public function test_issue_allowance_未開立發票回傳NOT_FOUND(): void {
		// Given: 一筆沒有開立發票的訂單
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order->set_total( 100 );
		$order->save();

		$provider = AmegoProvider::instance();

		// When
		$result = $provider->issue_allowance( $order, 50.0 );

		// Then
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}

	// ========== 作廢折讓 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_invalid_allowance_作廢折讓成功並清除allowance_data(): void {
		// Given: 一筆已開折讓的訂單
		$order    = $this->create_issued_order();
		$provider = AmegoProvider::instance();
		$provider->issue_allowance( $order, 50.0 );

		// When: 作廢折讓
		$result = $provider->invalid_allowance( $order );

		// Then: 回成功，allowance_data 已清除
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data() );
	}

	/**
	 * 無折讓資料就作廢折讓 → 正規化 NOT_FOUND（契約演進：原 return [] → WP_Error）
	 *
	 * @test
	 * @group error
	 */
	public function test_invalid_allowance_無折讓資料回傳NOT_FOUND(): void {
		$order    = $this->create_issued_order();
		$provider = AmegoProvider::instance();

		// When: 沒有折讓資料就作廢
		$result = $provider->invalid_allowance( $order );

		// Then
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::NOT_FOUND, NormalizedError::get_code( $result ) );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_issue_allowance_用訂單id傳入仍正確(): void {
		// Given: 一筆已開立發票的訂單
		$order    = $this->create_issued_order();
		$provider = AmegoProvider::instance();

		// When: 用訂單 ID（int）傳入
		$result = $provider->issue_allowance( $order->get_id(), 50.0 );

		// Then
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['allowance_number'] ?? '' );
	}
}
