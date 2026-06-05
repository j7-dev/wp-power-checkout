<?php
/**
 * EcpayReceiptProvider 整合測試
 *
 * 驗證綠界電子收據 provider 的開立（一般 / 公益 / 政治）、冪等保護、作廢與 meta 清除。
 *
 * 注意：測試在 API_MODE=mock 下執行（見 composer test），ReceiptApiClient 於 MOCK
 * 模式回固定 fixture，不打真 API；故可驗證完整的 issue → 寫 meta → cancel → 清 meta 流程。
 */

declare( strict_types=1 );

namespace Tests\Integration\Receipt;

use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\EcpayReceiptSettingsDTO;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Services\EcpayReceiptProvider;
use J7\PowerCheckout\Domains\Receipt\Shared\Helpers\ReceiptMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EcpayReceiptProvider 測試類別
 *
 * @group integration
 * @group receipt
 * @group ecpay
 */
final class EcpayReceiptProviderTest extends TestCase {

	/**
	 * 每次測試前：重置設定單例 cache + 啟用 ecpay_receipt（測試模式）
	 */
	public function set_up(): void {
		parent::set_up();
		// 自我保護：明確設定 API_MODE=mock，不依賴其他測試類別（部分類別 tear_down 會 putenv 取消 API_MODE）。
		\putenv( 'API_MODE=mock' );
		$this->reset_settings_instance();
		$this->enable_provider(
			EcpayReceiptProvider::ID,
			[
				'mode'                 => 'test',
				'merchant_id'          => '2000132',
				'hash_key'             => 'ejCk326UnaZWKisg',
				'hash_iv'              => 'q9jcZX8Ib9LM8wYk',
				'default_receipt_type' => 1,
			]
		);
	}

	/**
	 * 每次測試後：清理設定與單例 cache，並還原套件預設 API_MODE=mock
	 */
	public function tear_down(): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EcpayReceiptProvider::ID ) );
		\putenv( 'API_MODE=mock' );
		parent::tear_down();
	}

	/**
	 * 透過 reflection 重置 EcpayReceiptSettingsDTO 的 static $instance
	 */
	private function reset_settings_instance(): void {
		$ref  = new \ReflectionClass( EcpayReceiptSettingsDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * 以指定收據類型重新啟用 provider（覆寫 default_receipt_type）
	 *
	 * @param int                  $receipt_type 收據類型
	 * @param array<string, mixed> $extra        額外設定
	 */
	private function enable_with_receipt_type( int $receipt_type, array $extra = [] ): void {
		$this->reset_settings_instance();
		\delete_option( ProviderUtils::get_option_name( EcpayReceiptProvider::ID ) );
		$this->enable_provider(
			EcpayReceiptProvider::ID,
			\array_merge(
				[
					'mode'                 => 'test',
					'default_receipt_type' => $receipt_type,
				],
				$extra
			)
		);
	}

	/**
	 * 建立一筆訂單（只設總額，不掛實體商品）
	 *
	 * 刻意不使用 WC_Product_Simple + add_product：WP 測試套件的 wp_wc_order_product_lookup
	 * 表在交易回滾下並非完全隔離（bootstrap 會出現 "Multiple primary key" warning），
	 * 大量建立含商品訂單時會在套件後段污染、導致 get_items() 不穩定。
	 * 本 provider 測試只需驗證 issue → mock → 寫 meta 流程，ReceiptIssueParams::from_order
	 * 於無明細時會以訂單總額補一筆 fallback item，故僅設總額即可且穩定。
	 *
	 * @param float $total 訂單金額
	 * @return \WC_Order
	 */
	private function create_order_with_items( float $total = 100 ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => $total,
			]
		);

		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->save();

		// 防禦：WP/WC 測試套件的 HPOS order/meta 表在交易回滾下並非完全隔離，
		// 套件後段 wc_create_order() 可能回收到曾被前面測試寫過收據 meta 的 order id，
		// 導致 issue() 誤判為「已開立」走冪等早退。全新訂單本就無收據，這裡顯式清乾淨，
		// 讓 issue/cancel 流程測試不受套件執行順序影響（不是掩蓋程式錯誤，是隔離 infra 噪音）。
		( new ReceiptMetaKeys( $order ) )->clear_data( true );

		return $order;
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_EcpayReceiptProvider_ID常數正確(): void {
		$this->assertSame( 'ecpay_receipt', EcpayReceiptProvider::ID );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_settings_帶預設值時包含測試帳號(): void {
		$settings = EcpayReceiptProvider::get_settings();
		$this->assertIsArray( $settings );
		$this->assertSame( '2000132', $settings['merchant_id'] ?? '' );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_一般收據成功寫入issued_data與provider_id(): void {
		$order    = $this->create_order_with_items();
		$provider = EcpayReceiptProvider::instance();

		$result = $provider->issue( $order );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['receipt_number'] ?? '' );
		$this->assertSame( 1, $result['receipt_type'] ?? null );

		$meta_keys = new ReceiptMetaKeys( $order );
		$this->assertNotEmpty( $meta_keys->get_issued_data() );
		$this->assertSame( 'ecpay_receipt', $meta_keys->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_公益收據成功開立(): void {
		$this->enable_with_receipt_type( 2, [ 'donor_type' => '1' ] );

		$order    = $this->create_order_with_items();
		$provider = EcpayReceiptProvider::instance();

		$result = $provider->issue( $order );

		$this->assertNotEmpty( $result['receipt_number'] ?? '' );
		$this->assertSame( 2, $result['receipt_type'] ?? null );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_政治獻金收據成功開立(): void {
		$this->enable_with_receipt_type(
			4,
			[
				'merchant_id'    => '3002607',
				'hash_key'       => 'pwFHCqoQZGmho4w6',
				'hash_iv'        => 'EkRm7iFT261dpevs',
				'donor_type'     => '1',
				'payment_method' => '1',
			]
		);

		$order    = $this->create_order_with_items();
		$provider = EcpayReceiptProvider::instance();

		$result = $provider->issue( $order );

		$this->assertNotEmpty( $result['receipt_number'] ?? '' );
		$this->assertSame( 4, $result['receipt_type'] ?? null );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_已開立過時冪等回傳已存在資料且不重打API(): void {
		$order = $this->create_order_with_items();
		( new ReceiptMetaKeys( $order ) )->update_issued_data(
			[
				'receipt_number' => 'Sale2026010100000001',
				'receipt_type'   => 1,
			]
		);

		$provider = EcpayReceiptProvider::instance();
		$result   = $provider->issue( $order );

		$this->assertSame( 'Sale2026010100000001', $result['receipt_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_cancel_作廢後寫入cancelled_data並清除開立資料(): void {
		$order     = $this->create_order_with_items();
		$meta_keys = new ReceiptMetaKeys( $order );
		$meta_keys->update_issued_data(
			[
				'receipt_number' => 'Sale2026010100000001',
				'receipt_type'   => 1,
			]
		);
		$meta_keys->update_provider_id( 'ecpay_receipt' );

		$provider = EcpayReceiptProvider::instance();
		$result   = $provider->cancel( $order );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$fresh = new ReceiptMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_cancelled_data() );
		$this->assertEmpty( $fresh->get_issued_data() );
		$this->assertSame( '', $fresh->get_provider_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_cancel_已作廢過時冪等回傳已存在資料(): void {
		$order     = $this->create_order_with_items();
		$meta_keys = new ReceiptMetaKeys( $order );
		$meta_keys->update_cancelled_data(
			[
				'rtn_msg' => '作廢成功',
				'status'  => 'cancelled',
			]
		);

		$provider = EcpayReceiptProvider::instance();
		$result   = $provider->cancel( $order );

		$this->assertSame( 'cancelled', $result['status'] ?? '' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_get_receipt_number_已開立時回傳收據編號(): void {
		$order = $this->create_order_with_items();
		( new ReceiptMetaKeys( $order ) )->update_issued_data( [ 'receipt_number' => 'Sale2026010100000099' ] );

		$provider = EcpayReceiptProvider::instance();
		$this->assertSame( 'Sale2026010100000099', $provider->get_receipt_number( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_issue_用訂單id而非物件傳入冪等仍正確(): void {
		$order = $this->create_order_with_items();
		( new ReceiptMetaKeys( $order ) )->update_issued_data( [ 'receipt_number' => 'Sale2026010100000001' ] );

		$provider = EcpayReceiptProvider::instance();
		$result   = $provider->issue( $order->get_id() );

		$this->assertSame( 'Sale2026010100000001', $result['receipt_number'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_issue_政治獻金匿名超過一萬時驗證失敗不開立(): void {
		$this->enable_with_receipt_type(
			4,
			[
				'merchant_id'    => '3002607',
				'hash_key'       => 'pwFHCqoQZGmho4w6',
				'hash_iv'        => 'EkRm7iFT261dpevs',
				'donor_type'     => '5', // 匿名
				'payment_method' => '1',
			]
		);

		// 金額 20000 > 匿名上限 10000
		$order    = $this->create_order_with_items( 20000 );
		$provider = EcpayReceiptProvider::instance();

		$result = $provider->issue( $order );

		// 驗證失敗：回空陣列，且未寫入 issued_data
		$this->assertSame( [], $result );
		$this->assertEmpty( ( new ReceiptMetaKeys( $order ) )->get_issued_data() );
	}
}
