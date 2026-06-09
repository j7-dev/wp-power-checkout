<?php
/**
 * PayuniLogisticsProvider 整合測試
 *
 * 驗證 PAYUNi provider 落在統一 ILogisticsProvider 介面（與綠界並存可切換）。
 * 涵蓋 ship_map 單段選店流程（與綠界三段暫存單不同）：
 *   - logistics-store-selection  (ship_map 前置驗證 + MOCK 成功；單段)
 *   - logistics-parse-selection  (解析 MapJson → 門市資訊，無 TempLogisticsID)
 *   - logistics-create-shipment  (商店組完整收件人 trade 建單；無需 temp-id)
 *   - logistics-query            (無 ref → 失敗；有 ref → MOCK 成功)
 *   - logistics-print-document   (無 ref → 失敗；有 ref → MOCK HTML)
 *   - logistics-cancel           (PAYUNi CVS 無直接取消 API → throw 明確訊息)
 *   - logistics-create-return    (C2B 退貨便：前置驗證 + 寫 return_ref)
 *   - logistics-supported-methods(enabled_methods 子集)
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter PayuniLogisticsProviderTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayuniLogisticsProvider 測試類別
 *
 * @group integration
 * @group logistics
 * @group payuni
 */
final class PayuniLogisticsProviderTest extends TestCase {

	// PAYUNi 官方文件公開測試向量金鑰（AES-256-GCM）
	private const MER_ID   = 'S0000000000';
	private const HASH_KEY = '12345678901234567890123456789012';
	private const HASH_IV  = '1234567890123456';

	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		PayuniLogisticsSettingsDTO::reset();
		$this->enable_payuni();
	}

	public function tear_down(): void {
		\putenv( 'API_MODE=mock' );
		PayuniLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PayuniLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 payuni_logistics（test 模式）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 */
	private function enable_payuni( array $extra = [] ): void {
		$this->enable_provider(
			PayuniLogisticsProvider::ID,
			array_merge(
				[
					'mode'            => 'test',
					'mer_id'          => self::MER_ID,
					'hash_key'        => self::HASH_KEY,
					'hash_iv'         => self::HASH_IV,
					'cvs_lgs_type'    => 'B2C',
					'enabled_methods' => [ 'SEVEN', 'HOME' ],
					'sender_name'     => '寄件人',
					'sender_mobile'   => '0912345678',
					'notify_url'      => 'https://example.com/wp-json/power-checkout/payuni/logistics/status-notify',
					'map_return_url'  => 'https://example.com/wp-json/power-checkout/payuni/logistics/map-callback',
				],
				$extra
			)
		);
		PayuniLogisticsSettingsDTO::reset();
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
	public function test_冒煙_PayuniLogisticsProvider_ID常數為payuni_logistics(): void {
		$this->assertSame( 'payuni_logistics', PayuniLogisticsProvider::ID );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_落在統一ILogisticsProvider介面(): void {
		$this->assertInstanceOf( ILogisticsProvider::class, PayuniLogisticsProvider::instance() );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_settings帶預設值包含測試金鑰(): void {
		$settings = PayuniLogisticsProvider::get_settings();
		$this->assertIsArray( $settings );
		$this->assertSame( self::MER_ID, $settings['mer_id'] ?? '' );
	}

	// ========== ship_map 選店（單段，與綠界三段不同） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_provider未啟用時失敗(): void {
		$this->disable_provider( PayuniLogisticsProvider::ID );
		PayuniLogisticsSettingsDTO::reset();
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'SEVEN' ] )
		);

		$this->assert_operation_failed_with_message( '未啟用' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_map_return_url為localhost時失敗(): void {
		$this->enable_payuni( [ 'map_return_url' => 'http://localhost/callback' ] );
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'SEVEN' ] )
		);

		$this->assert_operation_failed_with_message( '必須為公開可訪問的 URL' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選店_sub_type不在enabled_methods時失敗(): void {
		$this->enable_payuni( [ 'enabled_methods' => [ 'HOME' ] ] );
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call(
			fn() => $provider->get_store_selection( $order, [ 'sub_type' => 'SEVEN' ] )
		);

		$this->assert_operation_failed_with_message( '已啟用的 PAYUNi 物流子類型' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_選店_MOCK模式成功回傳redirect_target_HTML(): void {
		$order    = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call(
			fn() => $this->queryResult = $provider->get_store_selection(
				$order,
				[
					'sub_type'         => 'SEVEN',
					'payment_scenario' => 'online',
				]
			)
		);

		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$redirect_target = $this->queryResult['redirect_target'] ?? '';
		$this->assertNotEmpty( $redirect_target, 'redirect_target 不應為空' );
		$this->assertStringContainsString( 'payuni', strtolower( $redirect_target ) );
	}

	// ========== 解析 MapJson 門市回呼（無 TempLogisticsID） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_解析選店_MapJson門市資訊正確解出(): void {
		// Given: 商店端用測試金鑰加密一個含 MapJson 的 ship_map 回呼
		$crypto       = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$map_json     = wp_json_encode(
			[
				'StoreType' => 'SEVEN',
				'StoreID'   => '916712',
				'StoreName' => '敦安門市',
				'Address'   => '台北市大安區安和路一段27號',
			]
		);
		$encrypt_info = $crypto->encrypt(
			[
				'Status'  => 'SUCCESS',
				'MerID'   => self::MER_ID,
				'MapJson' => $map_json,
			]
		);
		$raw          = [
			'MerID'       => self::MER_ID,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $crypto->hash_info( $encrypt_info ),
		];

		$provider = PayuniLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->parse_store_selection( $raw )
		);

		// Then: 解出門市資訊；PAYUNi 無 TempLogisticsID（temp_id 為空）
		$this->assert_operation_succeeded();
		$this->assertSame( '916712', $this->queryResult['store_id'] ?? '' );
		$this->assertSame( '敦安門市', $this->queryResult['store_name'] ?? '' );
		$this->assertSame( '', $this->queryResult['temp_id'] ?? 'NOT_EMPTY' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_解析選店_HashInfo驗簽失敗時拋例外(): void {
		$crypto       = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$encrypt_info = $crypto->encrypt( [ 'Status' => 'SUCCESS' ] );
		$raw          = [
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => 'TAMPERED_HASH',
		];

		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->parse_store_selection( $raw ) );

		$this->assert_operation_failed_with_message( '驗簽' );
	}

	// ========== 建單（trade；無需 temp-id，商店組完整收件人） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_建單_無門市代碼時失敗(): void {
		// Given: 訂單未選店（無 store_id），且非宅配
		$order = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_sub_type( 'SEVEN' );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->create_shipment( $order ) );

		$this->assert_operation_failed_with_message( '尚未選店' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單_超商選店後MOCK成功寫入ShipTradeNo(): void {
		// Given: 已選店（有 store_id），收件人資訊
		$order = $this->create_wc_order(
			[
				'status' => 'pending',
				'total'  => 1000,
			]
		);
		$order->set_billing_first_name( '王' );
		$order->set_billing_last_name( '小明' );
		$order->set_billing_phone( '0987654321' );
		$order->save();

		$meta = new LogisticsMetaKeys( $order );
		$meta->update_sub_type( 'SEVEN' );
		$meta->update_store_id( '916712' );
		$meta->update_payment_scenario( 'online' );

		$provider = PayuniLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_shipment( $order )
		);

		// Then: 操作成功（MOCK），寫入統一物流單號 _pc_logistics_ref（= ShipTradeNo）
		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$this->assertArrayHasKey( 'logistics_id', $this->queryResult );
		$fresh_order = \wc_get_order( $order->get_id() );
		$this->assertNotEmpty(
			( new LogisticsMetaKeys( $fresh_order ) )->get_ref(),
			'_pc_logistics_ref 應被寫入'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_建單_COD取貨付款訂單成功(): void {
		// Given: COD 訂單已選店
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'total'          => 1000,
				'payment_method' => 'cod',
			]
		);
		$order->set_billing_first_name( '王' );
		$order->set_billing_last_name( '小明' );
		$order->set_billing_phone( '0987654321' );
		$order->save();

		$meta = new LogisticsMetaKeys( $order );
		$meta->update_sub_type( 'SEVEN' );
		$meta->update_store_id( '916712' );
		$meta->update_payment_scenario( 'cod' );

		$provider = PayuniLogisticsProvider::instance();

		$this->try_call(
			fn() => $this->queryResult = $provider->create_shipment( $order )
		);

		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
	}

	// ========== 查詢（query） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_查詢_無ref時失敗(): void {
		$order    = $this->create_wc_order( [ 'status' => 'pending' ] );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->query_shipment( $order ) );

		$this->assert_operation_failed_with_message( '尚未成立物流單' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_查詢_有ref時MOCK成功回傳物流資訊(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( 'SHIP1234567890' );
		$meta->update_sub_type( 'SEVEN' );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call(
			fn() => $this->queryResult = $provider->query_shipment( $order )
		);

		$this->assert_operation_succeeded();
		$this->assertIsArray( $this->queryResult );
		$this->assertArrayHasKey( 'logistics_id', $this->queryResult );
		$this->assertArrayHasKey( 'status', $this->queryResult );
	}

	// ========== 列印（print_label） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_列印_無ref時失敗(): void {
		$order    = $this->create_wc_order( [ 'status' => 'processing' ] );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->print_document( $order ) );

		$this->assert_operation_failed_with_message( '尚未成立物流單' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_列印_有ref時MOCK回傳HTML(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( 'SHIP1234567890' );
		$meta->update_sub_type( 'SEVEN' );
		$provider = PayuniLogisticsProvider::instance();

		$html = null;
		$this->try_call(
			function () use ( $provider, $order, &$html ) {
				$html = $provider->print_document( $order );
			}
		);

		$this->assert_operation_succeeded();
		$this->assertIsString( $html );
		$this->assertStringContainsString( '<html', strtolower( $html ) );
	}

	// ========== 取消（PAYUNi CVS 無直接取消 API） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_取消_PAYUNi不支援直接取消時拋明確訊息(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP1234567890' );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->cancel_shipment( $order ) );

		$this->assert_operation_failed_with_message( '不支援' );
	}

	// ========== 退貨 / 逆物流（C2B 退貨便） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_退貨_provider未啟用時失敗(): void {
		$this->disable_provider( PayuniLogisticsProvider::ID );
		PayuniLogisticsSettingsDTO::reset();
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP1234567890' );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->create_return( $order ) );

		$this->assert_operation_failed_with_message( '未啟用' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_退貨_無正向物流單時失敗(): void {
		$order    = $this->create_wc_order( [ 'status' => 'processing' ] );
		$provider = PayuniLogisticsProvider::instance();

		$this->try_call( fn() => $provider->create_return( $order ) );

		$this->assert_operation_failed_with_message( '尚未成立物流單' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_退貨_C2B退貨便成功後寫入return_ref(): void {
		// Given: 已成立正向 B2C 物流單
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 1000,
			]
		);
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_ref( 'SHIP1234567890' );
		$meta->update_sub_type( 'SEVEN' );
		$provider = PayuniLogisticsProvider::instance();

		// When
		$this->try_call(
			fn() => $this->queryResult = $provider->create_return( $order )
		);

		// Then: 操作成功（MOCK），寫入逆物流單號（RefundODNO）
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

	// ========== 支援的物流子類型 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_支援子類型_回傳enabled_methods子集(): void {
		$this->enable_payuni( [ 'enabled_methods' => [ 'SEVEN' ] ] );
		$provider = PayuniLogisticsProvider::instance();

		$methods = $provider->get_supported_methods();

		$this->assertContains( 'SEVEN', $methods );
		$this->assertNotContains( 'HOME', $methods );
	}
}
