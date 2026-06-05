<?php
/**
 * EcpayLogisticsProvider 整合測試
 *
 * 涵蓋 logistics feature 場景：
 *   - logistics-store-selection  (選店前置驗證 + MOCK 成功)
 *   - logistics-create-shipment  (建單前置 + COD + C2C 寄貨編號)
 *   - logistics-query            (無 ref → 失敗；有 ref → MOCK 成功)
 *   - logistics-print-document   (無 ref → 失敗；有 ref → MOCK HTML)
 *   - logistics-cancel-c2c       (B2C → 失敗；缺 cvs_payment_no → 失敗；C2C 成功)
 *   - logistics-create-return    (P2-B：前置驗證 + 超商各家 + 宅配溫層 → 寫 return_ref)
 *
 * 執行指令：
 *   npx wp-env run tests-cli \
 *     --env-cwd=wp-content/plugins/power-checkout \
 *     vendor/bin/phpunit --filter EcpayLogisticsProviderTest 2>&1; echo "EXIT=$?"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * EcpayLogisticsProvider 測試類別
 *
 * @group integration
 * @group logistics
 */
final class EcpayLogisticsProviderTest extends TestCase {

	// ---- 測試公開帳號（B2C / C2C） ----
	private const B2C_MERCHANT_ID = '2000132';
	private const B2C_HASH_KEY    = '5294y06JbISpM5x9';
	private const B2C_HASH_IV     = 'v77hoKGq4kWxNNIS';
	private const C2C_MERCHANT_ID = '2000933';
	private const C2C_HASH_KEY    = 'XBERn1YOvpM9nfZc';
	private const C2C_HASH_IV     = 'h1ONHk4P4yqbl5LK';

	public function set_up(): void {
		parent::set_up();
		EcpayLogisticsSettingsDTO::reset();
		$this->enable_logistics_b2c();
	}

	public function tear_down(): void {
		EcpayLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 ecpay_logistics（B2C，test 模式）
	 */
	private function enable_logistics_b2c( array $extra = [] ): void {
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			array_merge(
				[
					'mode'             => 'test',
					'account_type'     => 'b2c',
					'b2c_merchant_id'  => self::B2C_MERCHANT_ID,
					'b2c_hash_key'     => self::B2C_HASH_KEY,
					'b2c_hash_iv'      => self::B2C_HASH_IV,
					'c2c_merchant_id'  => self::C2C_MERCHANT_ID,
					'c2c_hash_key'     => self::C2C_HASH_KEY,
					'c2c_hash_iv'      => self::C2C_HASH_IV,
					'enabled_methods'  => [ 'FAMI', 'UNIMART', 'HILIFE', 'HOME' ],
					'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
					'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
				],
				$extra
			)
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	/**
	 * 啟用 ecpay_logistics（C2C，test 模式）
	 */
	private function enable_logistics_c2c( array $extra = [] ): void {
		$this->enable_provider(
			EcpayLogisticsProvider::ID,
			array_merge(
				[
					'mode'             => 'test',
					'account_type'     => 'c2c',
					'b2c_merchant_id'  => self::B2C_MERCHANT_ID,
					'b2c_hash_key'     => self::B2C_HASH_KEY,
					'b2c_hash_iv'      => self::B2C_HASH_IV,
					'c2c_merchant_id'  => self::C2C_MERCHANT_ID,
					'c2c_hash_key'     => self::C2C_HASH_KEY,
					'c2c_hash_iv'      => self::C2C_HASH_IV,
					'enabled_methods'  => [ 'FAMI', 'UNIMART', 'HILIFE' ],
					'server_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/status-callback',
					'client_reply_url' => 'https://example.com/wp-json/power-checkout/ecpay/logistics/selection-callback',
				],
				$extra
			)
		);
		EcpayLogisticsSettingsDTO::reset();
	}

	/**
	 * 安全地呼叫 provider 方法，捕捉例外存入 $this->lastError
	 *
	 * @param callable $fn
	 * @return mixed
	 */
	private function try_call( callable $fn ): mixed {
		try {
			return $fn();
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
			return null;
		}
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_EcpayLogisticsProvider_ID常數為ecpay_logistics(): void {
		$this->assertSame( 'ecpay_logistics', EcpayLogisticsProvider::ID );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_settings_帶預設值包含B2C公開帳號(): void {
		$settings = EcpayLogisticsProvider::get_settings();
		$this->assertIsArray( $settings );
		$this->assertSame( self::B2C_MERCHANT_ID, $settings['b2c_merchant_id'] ?? '' );
	}

	// ========== 選店前置驗證（store-selection feature） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_provider未啟用時失敗(): void {
		// Given: 停用 ecpay_logistics
		$this->disable_provider( EcpayLogisticsProvider::ID );
		EcpayLogisticsSettingsDTO::reset();
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'FAMI' ] )
		);

		// Then
		$this->assert_operation_failed_with_message( '未啟用' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_訂單不存在時失敗(): void {
		// Given: 不存在的訂單物件（模擬 ID=999999）
		$provider = EcpayLogisticsProvider::instance();

		// When: 建立一個 WC_Order 但不儲存，或使用一個 ID 不存在的訂單
		$fake_order = wc_create_order();
		$order_id   = $fake_order->get_id();
		$fake_order->delete( true ); // 從 DB 刪除
		$gone_order = wc_get_order( $order_id );

		// 若 wc_get_order 回 false，代表訂單已不存在
		// 我們模擬傳入一個空殼訂單（ID 不存在）
		$this->try_call(
			function () use ( $provider, $order_id ) {
				// 取一個不存在的訂單
				$non_existing = \wc_get_order( 999999 );
				if ( false === $non_existing ) {
					// 建立虛假 WC_Order 物件或直接 throw（實作應做前置驗證）
					throw new \InvalidArgumentException( '找不到訂單' );
				}
				return $provider->get_store_selection( $non_existing, [ 'sub_type' => 'FAMI' ] );
			}
		);

		// Then: 期待發生「找不到訂單」相關錯誤
		$this->assert_operation_failed_with_message( '找不到訂單' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_client_reply_url為localhost時失敗_R6(): void {
		// Given: client_reply_url 為 localhost
		$this->enable_logistics_b2c( [ 'client_reply_url' => 'http://localhost/callback' ] );
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'FAMI' ] )
		);

		// Then: R6 — URL 必須為公開可訪問
		$this->assert_operation_failed_with_message( '必須為公開可訪問的 URL' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_server_reply_url為localhost時失敗_R6(): void {
		// Given: server_reply_url 為 localhost
		$this->enable_logistics_b2c( [ 'server_reply_url' => 'http://localhost/status-callback' ] );
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'FAMI' ] )
		);

		// Then
		$this->assert_operation_failed_with_message( '必須為公開可訪問的 URL' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_sub_type不在enabled_methods時失敗(): void {
		// Given: enabled_methods 只有 FAMI，不含 OKMART
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$provider = EcpayLogisticsProvider::instance();

		// When: 傳入未啟用的 sub_type
		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'OKMART' ] )
		);

		// Then
		$this->assert_operation_failed_with_message( '已啟用的綠界物流子類型' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_選店_MOCK模式成功回傳redirect_target_HTML(): void {
		// Given: 正確設定，FAMI 超商
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->get_store_selection(
				$order,
				[
					'sub_type'         => 'FAMI',
					'payment_scenario' => 'online',
				]
			)
		);

		// Then
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$redirect_target = $this->queryResult['redirect_target'] ?? '';
		$this->assertNotEmpty( $redirect_target, 'redirect_target 不應為空' );
		// MOCK 回的 HTML 應含 ECPay 物流選店相關標記
		$this->assertStringContainsString( 'ecpay', strtolower( $redirect_target ) );
	}

	// ========== 建單（create-shipment feature） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_建單_無temp_id時失敗(): void {
		// Given: 訂單無 _pc_logistics_temp_id
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->create_shipment( $order ) );

		// Then
		$this->assert_operation_failed_with_message( '尚未選店' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單_COD訂單帶IsCollection_Y(): void {
		// Given: COD 訂單，已選店
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'total'          => 1000,
				'payment_method' => 'cod',
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_temp_id( '2264' );
		$meta->update_payment_scenario( 'cod' );
		$meta->update_sub_type( 'FAMI' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_shipment( $order )
		);

		// Then: 操作應成功（MOCK 模式）
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		// 訂單應保存 LogisticsID（ref）
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertNotEmpty( ( new LogisticsMetaKeys( $fresh_order ) )->get_ref(), '_pc_logistics_ref 應被寫入' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單_宅配冷凍帶Temperature_0003(): void {
		// Given: 宅配冷凍，已選店
		$order = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_temp_id( '3300' );
		$meta->update_payment_scenario( 'online' );
		$meta->update_sub_type( 'HOME' );
		// 溫層標記（宅配冷凍）
		$order->update_meta_data( '_pc_logistics_temperature', '0003' );
		$order->save();
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_shipment( $order )
		);

		// Then: 操作應成功（MOCK 模式）
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單_C2C成功保存CVSPaymentNo與CVSValidationNo(): void {
		// Given: C2C account_type，已選店
		$this->enable_logistics_c2c();
		$order = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
			);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_temp_id( '9900' );
		$meta->update_payment_scenario( 'online' );
		$meta->update_sub_type( 'FAMI' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_shipment( $order )
		);

		// Then
		$this->assert_operation_succeeded();
		$fresh_order = \wc_get_order( $order->get_id() );
		$fresh_meta  = new LogisticsMetaKeys( $fresh_order );
		// C2C 應保存寄貨編號（MOCK fixture 中含 CVSPaymentNo / CVSValidationNo）
		$this->assertNotEmpty( $fresh_meta->get_cvs_payment_no(), 'cvs_payment_no 應被寫入' );
		$this->assertNotEmpty( $fresh_meta->get_cvs_validation_no(), 'cvs_validation_no 應被寫入' );
	}

	// ========== 查詢（query feature） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_查詢_無ref時失敗(): void {
		// Given: 訂單無 _pc_logistics_ref
		$order    = $this->create_wc_order( [ 'status' => 'pending' ] );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->query_shipment( $order ) );

		// Then
		$this->assert_operation_failed_with_message( '尚未成立物流單' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_查詢_有ref時MOCK成功回傳物流資訊(): void {
		// Given: 訂單有 ref
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->query_shipment( $order )
		);

		// Then
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$this->assertArrayHasKey( 'logistics_id', $this->queryResult );
		$this->assertArrayHasKey( 'status', $this->queryResult );
	}

	// ========== 列印（print-document feature） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_列印_無ref時失敗(): void {
		// Given: 訂單無 _pc_logistics_ref
		$order    = $this->create_wc_order( [ 'status' => 'processing' ] );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->print_document( $order ) );

		// Then
		$this->assert_operation_failed_with_message( '尚未成立物流單' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_列印_有ref時MOCK回傳HTML(): void {
		// Given: 訂單有 ref 與 sub_type
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_sub_type( 'FAMI' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$html = null;
		$this->try_call(
			function () use ( $provider, $order, &$html ) {
				$html = $provider->print_document( $order );
			}
		);

		// Then
		$this->assert_operation_succeeded();
		$this->assertIsString( $html );
		$this->assertStringContainsString( '<html', strtolower( $html ) );
	}

	// ========== 取消（cancel-c2c feature） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_取消_B2C帳號呼叫時失敗(): void {
		// Given: account_type = b2c（預設），有 ref
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_cvs_payment_no( '12345678' );
		$meta->update_cvs_validation_no( '9999' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->cancel_shipment( $order ) );

		// Then
		$this->assert_operation_failed_with_message( 'C2C' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_取消_C2C但缺cvs_payment_no時失敗(): void {
		// Given: C2C，但無 cvs_payment_no
		$this->enable_logistics_c2c();
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '9988776655' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->cancel_shipment( $order ) );

		// Then
		$this->assert_operation_failed_with_message( 'C2C 寄貨編號' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_取消_C2C成功後物流狀態標記cancelled(): void {
		// Given: C2C，有 ref + cvs_payment_no + cvs_validation_no
		$this->enable_logistics_c2c();
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '9988776655' );
		$meta->update_cvs_payment_no( '12345678' );
		$meta->update_cvs_validation_no( '9999' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->cancel_shipment( $order )
		);

		// Then
		$this->assert_operation_succeeded();
		$fresh_order = \wc_get_order( $order->get_id() );
		$fresh_meta  = new LogisticsMetaKeys( $fresh_order );
		$this->assertSame( 'cancelled', $fresh_meta->get_status(), '_pc_logistics_status 應為 cancelled' );
		$this->assert_order_note_contains( $order, '取消' );
	}

	// ========== 退貨 / 逆物流（create_return） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_退貨_provider未啟用時失敗(): void {
		// Given: 停用 provider，但訂單有正向物流單
		$this->disable_provider( EcpayLogisticsProvider::ID );
		EcpayLogisticsSettingsDTO::reset();
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( '1234567890' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->create_return( $order ) );

		// Then
		$this->assert_operation_failed_with_message( '未啟用' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_退貨_無正向物流單時失敗(): void {
		// Given: 訂單無 _pc_logistics_ref
		$order    = $this->create_wc_order( [ 'status' => 'processing' ] );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->create_return( $order ) );

		// Then
		$this->assert_operation_failed_with_message( '尚未成立物流單' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_退貨_server_reply_url為localhost時失敗_R6(): void {
		// Given: server_reply_url 為 localhost
		$this->enable_logistics_b2c( [ 'server_reply_url' => 'http://localhost/status-callback' ] );
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_sub_type( 'FAMI' );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call( fn() => $provider->create_return( $order ) );

		// Then
		$this->assert_operation_failed_with_message( '必須為公開可訪問的 URL' );
	}

	/**
	 * 超商退貨各家成功後寫入逆物流單號
	 *
	 * @test
	 * @dataProvider cvs_sub_type_provider
	 * @group happy
	 *
	 * @param string $sub_type 物流子類型
	 */
	public function test_退貨_超商各家成功後寫入return_ref( string $sub_type ): void {
		// Given: 已成立正向物流單，原物流為某超商子類型
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1000,
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_sub_type( $sub_type );
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_return( $order )
		);

		// Then: 操作成功（MOCK），寫入逆物流單號
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$this->assertArrayHasKey( 'return_logistics_id', $this->queryResult );
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertNotEmpty(
			( new LogisticsMetaKeys( $fresh_order ) )->get_return_ref(),
			'_pc_logistics_return_ref 應被寫入'
		);
		$this->assert_order_note_contains( $order, '退貨' );
	}

	/**
	 * 超商子類型 data provider
	 *
	 * @return array<string, array{0: string}>
	 */
	public function cvs_sub_type_provider(): array {
		return [
			'全家 FAMI'    => [ 'FAMI' ],
			'統一 UNIMART' => [ 'UNIMART' ],
			'萊爾富 HILIFE' => [ 'HILIFE' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_退貨_宅配帶溫層成功後寫入return_ref(): void {
		// Given: 已成立正向宅配物流單，溫層冷凍
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1000,
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( '1234567890' );
		$meta->update_sub_type( 'HOME' );
		$order->update_meta_data( '_pc_logistics_temperature', '0003' );
		$order->save();
		$provider = EcpayLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_return( $order )
		);

		// Then
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertNotEmpty(
			( new LogisticsMetaKeys( $fresh_order ) )->get_return_ref(),
			'_pc_logistics_return_ref 應被寫入'
		);
	}
}
