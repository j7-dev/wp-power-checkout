<?php
/**
 * ProviderRegister auto-issue / auto-cancel wrapper + 退款折讓 never-throw 測試（einvoice 第五階段，步驟 10）
 *
 * 背景（FM-07）：WC `woocommerce_order_status_{status}` action hook 不消費 callback 回傳值。
 *   先前直掛 `[$provider, 'issue'|'cancel']`，provider 回的 \WP_Error 無人讀 → 失敗無痕。
 * 本階段改為 wrapper static method（[__CLASS__, 'auto_issue_wrapper'] / 'auto_cancel_wrapper']），
 *   wrapper 內呼叫 provider → is_wp_error() 為真時記 order note（含 error_code + message）→ 絕不向 hook 拋。
 *
 * 同時補強 maybe_issue_allowance_on_refund（woocommerce_order_refunded）：
 *   折讓回 \WP_Error 時記 order note，且不中斷退款主流程。
 *
 * 錯誤注入：真實 ezPay provider + InvoiceApiClient::$mock_error_override 注入 business 錯誤碼。
 * wrapper 註冊路徑：以 reflection 呼叫 private static register_provider_hooks，確保測「真實掛載」。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c 'API_MODE=mock vendor/bin/phpunit --filter InvoiceAutoIssueWrapperTest --no-coverage'
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\ProviderRegister;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * auto-issue / auto-cancel wrapper never-throw 測試類別
 *
 * @group integration
 * @group invoice
 * @group ezpay
 * @group error
 * @group edge
 */
final class InvoiceAutoIssueWrapperTest extends TestCase {

	/**
	 * 本測試中掛載過的 hook（status, callback），tear_down 統一卸除
	 *
	 * @var array<int, array{0:string,1:callable}>
	 */
	private array $registered_hooks = [];

	/**
	 * 每次測試前：清空錯誤注入、重置設定單例
	 */
	public function set_up(): void {
		parent::set_up();
		InvoiceApiClient::$mock_error_override = null;
		$this->registered_hooks                = [];
		$this->reset_settings_instance();
	}

	/**
	 * 每次測試後：卸除掛載的 hook、清空錯誤注入與設定
	 */
	public function tear_down(): void {
		foreach ( $this->registered_hooks as [$action, $callback] ) {
			\remove_action( $action, $callback );
		}
		InvoiceApiClient::$mock_error_override = null;
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
	 * 啟用 ezPay 並透過真實 register_provider_hooks 掛載 wrapper（含 auto-issue / auto-cancel 狀態）
	 *
	 * @param array<string, mixed> $extra 額外設定（如 auto_issue_order_statuses）
	 * @return void
	 */
	private function enable_and_register( array $extra ): void {
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			\array_merge(
				[
					'mode'        => 'test',
					'merchant_id' => 'MS12345678',
					'hash_key'    => 'abcdefghijklmnopqrstuvwxyzabcdef',
					'hash_iv'     => '1234567891234567',
				],
				$extra
			)
		);

		// 以 reflection 呼叫 private static register_provider_hooks（測真實掛載路徑）.
		$ref    = new \ReflectionClass( ProviderRegister::class );
		$method = $ref->getMethod( 'register_provider_hooks' );
		$method->setAccessible( true );
		$method->invoke( null, EzpayInvoiceProvider::ID, EzpayInvoiceProvider::class );
	}

	/**
	 * 記錄一個掛載的 hook 以便 tear_down 卸除（避免污染後續測試的全域 hook）
	 *
	 * @param string   $action   action 名稱
	 * @param callable $callback callback
	 * @return void
	 */
	private function track_hook( string $action, callable $callback ): void {
		$this->registered_hooks[] = [ $action, $callback ];
	}

	/**
	 * 建立一筆有商品、可通過 dispatch 驗證的訂單（provider = ezpay）
	 *
	 * @param array<string, mixed> $issue_params 結帳填寫的發票資訊
	 * @return \WC_Order
	 */
	private function create_order( array $issue_params = [] ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'pending',
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

		$params = \array_merge( [ 'provider' => 'ezpay' ], $issue_params );
		( new MetaKeys( $order ) )->update_issue_params( $params );

		return $order;
	}

	/**
	 * 建立一筆已開立發票的 ezPay 訂單
	 *
	 * @return \WC_Order
	 */
	private function create_issued_order(): \WC_Order {
		$order     = $this->create_order();
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

	// ========================================================================
	// auto-issue wrapper：provider 回 WP_Error → 記 order note + never-throw
	// ========================================================================

	/**
	 * auto-issue：開立回 \WP_Error → 留 order note（含 error_code）+ 不向 hook 拋例外
	 *
	 * @test
	 * @group error
	 */
	public function test_auto_issue_失敗時留order_note且不拋例外(): void {
		$this->enable_and_register( [ 'auto_issue_order_statuses' => [ 'wc-processing' ] ] );
		$this->track_hook( 'woocommerce_order_status_processing', [ ProviderRegister::class, 'auto_issue_wrapper' ] );

		$order = $this->create_order();
		// 注入 AUTH 業務錯誤 → provider issue 回 WP_Error.
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '資料解密錯誤',
		];

		$thrown = null;
		try {
			\do_action( 'woocommerce_order_status_processing', $order->get_id(), \wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		$this->assertNull( $thrown, 'auto-issue wrapper 絕不可向 WC hook 拋例外（never-throw）' );

		// issued_data 未寫入（開立失敗）.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_issued_data(), '開立失敗不應寫入 issued_data' );

		// order note 記下錯誤（含正規化 error_code）.
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), ErrorCode::AUTH->value );
	}

	/**
	 * auto-issue：開立成功 → 正常寫入 issued_data，不留多餘錯誤 note
	 *
	 * @test
	 * @group happy
	 */
	public function test_auto_issue_成功時正常開立(): void {
		$this->enable_and_register( [ 'auto_issue_order_statuses' => [ 'wc-processing' ] ] );
		$this->track_hook( 'woocommerce_order_status_processing', [ ProviderRegister::class, 'auto_issue_wrapper' ] );

		$order = $this->create_order(
			[
				'invoiceType' => 'individual',
				'individual'  => 'cloud',
			]
		);

		$thrown = null;
		try {
			\do_action( 'woocommerce_order_status_processing', $order->get_id(), \wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		$this->assertNull( $thrown );
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), 'auto-issue 成功須寫入 issued_data' );
	}

	// ========================================================================
	// auto-cancel wrapper：provider 回 WP_Error → 記 order note + never-throw
	// ========================================================================

	/**
	 * auto-cancel：作廢回 \WP_Error → 留 order note + 不向 hook 拋例外，且 issued_data 不被清除
	 *
	 * @test
	 * @group error
	 */
	public function test_auto_cancel_失敗時留order_note且不拋例外(): void {
		$this->enable_and_register( [ 'auto_cancel_order_statuses' => [ 'wc-cancelled' ] ] );
		$this->track_hook( 'woocommerce_order_status_cancelled', [ ProviderRegister::class, 'auto_cancel_wrapper' ] );

		$order = $this->create_issued_order();
		// 注入業務錯誤 → provider cancel 回 WP_Error.
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '資料解密錯誤',
		];

		$thrown = null;
		try {
			\do_action( 'woocommerce_order_status_cancelled', $order->get_id(), \wc_get_order( $order->get_id() ) );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		$this->assertNull( $thrown, 'auto-cancel wrapper 絕不可向 WC hook 拋例外（never-throw）' );

		// 作廢失敗不清除 issued_data.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_issued_data(), '作廢失敗時 issued_data 不應被清除' );
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), ErrorCode::AUTH->value );
	}

	// ========================================================================
	// maybe_issue_allowance_on_refund：折讓回 WP_Error → order note + 不中斷退款
	// ========================================================================

	/**
	 * 退款自動折讓：折讓回 \WP_Error 時記 order note，且退款流程不中斷（不拋例外）
	 *
	 * 設計：發票已開立、auto_allowance_on_refund=yes、部分退款 → 觸發折讓；
	 * 但注入業務錯誤使 issue_allowance 回 WP_Error → 須記 note 且不拋。
	 *
	 * @test
	 * @group error
	 */
	public function test_退款折讓_失敗時記order_note且不中斷退款(): void {
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'                     => 'test',
				'merchant_id'              => 'MS12345678',
				'hash_key'                 => 'abcdefghijklmnopqrstuvwxyzabcdef',
				'hash_iv'                  => '1234567891234567',
				'auto_allowance_on_refund' => 'yes',
			]
		);
		ProviderUtils::$container[ EzpayInvoiceProvider::ID ] = EzpayInvoiceProvider::instance();

		$order = $this->create_issued_order();

		// 部分退款 50（訂單 100，退款後剩 50 → 開折讓）.
		$refund    = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 50.0,
			]
		);
		$refund_id = ( $refund instanceof \WC_Order_Refund ) ? $refund->get_id() : 0;

		// 注入業務錯誤 → issue_allowance 回 WP_Error.
		InvoiceApiClient::$mock_error_override = [
			'Status'  => 'KEY10002',
			'Message' => '資料解密錯誤',
		];

		$thrown = null;
		try {
			ProviderRegister::maybe_issue_allowance_on_refund( $order->get_id(), $refund_id );
		} catch ( \Throwable $e ) {
			$thrown = $e;
		}

		$this->assertNull( $thrown, '退款折讓失敗不可中斷退款主流程（never-throw）' );

		// 折讓未成功寫入.
		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertEmpty( $fresh->get_allowance_data(), '折讓失敗不應寫入 allowance_data' );
		// order note 記下錯誤（含正規化 error_code）.
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), ErrorCode::AUTH->value );
	}

	/**
	 * 退款自動折讓：折讓成功（無錯誤注入）→ allowance_data 寫入（契約不退化）
	 *
	 * @test
	 * @group happy
	 */
	public function test_退款折讓_成功時寫入allowance_data(): void {
		$this->reset_settings_instance();
		$this->enable_provider(
			EzpayInvoiceProvider::ID,
			[
				'mode'                     => 'test',
				'merchant_id'              => 'MS12345678',
				'hash_key'                 => 'abcdefghijklmnopqrstuvwxyzabcdef',
				'hash_iv'                  => '1234567891234567',
				'auto_allowance_on_refund' => 'yes',
			]
		);
		ProviderUtils::$container[ EzpayInvoiceProvider::ID ] = EzpayInvoiceProvider::instance();

		$order = $this->create_issued_order();

		$refund    = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 50.0,
			]
		);
		$refund_id = ( $refund instanceof \WC_Order_Refund ) ? $refund->get_id() : 0;

		ProviderRegister::maybe_issue_allowance_on_refund( $order->get_id(), $refund_id );

		$fresh = new MetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertNotEmpty( $fresh->get_allowance_data(), '部分退款折讓成功須寫入 allowance_data' );
	}
}
