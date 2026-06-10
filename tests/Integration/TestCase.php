<?php
/**
 * 整合測試基礎類別
 * 所有 Power Checkout 整合測試必須繼承此類別
 */

declare( strict_types=1 );

namespace Tests\Integration;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys as InvoiceMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys as PaymentMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * Class TestCase
 * 整合測試基礎類別，提供共用 helper methods
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * 最後發生的錯誤（用於驗證操作是否失敗）
	 *
	 * @var \Throwable|null
	 */
	protected ?\Throwable $lastError = null;

	/**
	 * 查詢結果（用於驗證 Query 操作的回傳值）
	 *
	 * @var mixed
	 */
	protected mixed $queryResult = null;

	/**
	 * ID 映射表（名稱 → ID 等）
	 *
	 * @var array<string, int>
	 */
	protected array $ids = [];

	/**
	 * Repository 容器
	 *
	 * @var \stdClass
	 */
	protected \stdClass $repos;

	/**
	 * Service 容器
	 *
	 * @var \stdClass
	 */
	protected \stdClass $services;

	/**
	 * 設定（每個測試前執行）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->lastError   = null;
		$this->queryResult = null;
		$this->ids         = [];
		$this->repos       = new \stdClass();
		$this->services    = new \stdClass();

		// 防禦性清理：在 configure_dependencies 之前刪除各 gateway / provider 設定選項，
		// 確保每個測試自乾淨狀態開始。原因：退款 handler 採原生 $wpdb COMMIT / ROLLBACK 發 API，
		// 會「提前提交 / 還原」WP_UnitTestCase 包覆每個測試的外層交易，導致前一測試 update_option
		// 寫入的 provider 啟用狀態跨測試殘留，污染 is_enabled 判定（例：PayuniUniEmbedRegisterTest
		// 斷言 payuni_uni_embed 未啟用卻讀到殘留 'yes'）。在 set_up 主動清空可免疫交易破壞時序。
		foreach ( self::PROVIDER_OPTION_IDS as $provider_id ) {
			\delete_option( ProviderUtils::get_option_name( $provider_id ) );
		}

		$this->configure_dependencies();
	}

	/**
	 * 清理（每個測試後執行）
	 */
	public function tear_down(): void {
		// 清空 ProviderUtils 容器，避免測試之間互相影響
		ProviderUtils::$container = [];

		// 防禦性清理：刪除各 gateway / provider 設定選項（與 set_up 對稱，雙保險）。
		// 詳見 set_up 內說明（退款 handler 原生 COMMIT / ROLLBACK 破壞 WP_UnitTestCase 交易隔離）。
		foreach ( self::PROVIDER_OPTION_IDS as $provider_id ) {
			\delete_option( ProviderUtils::get_option_name( $provider_id ) );
		}

		parent::tear_down();
	}

	/**
	 * 測試結束時需主動清理設定選項的 provider id 清單
	 *
	 * 涵蓋所有會在測試中以 update_option 啟用、且其退款 handler 可能 COMMIT 而破壞
	 * 交易隔離的 gateway / provider，確保啟用狀態不跨測試殘留。
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_OPTION_IDS = [
		'shopline_payment_redirect',
		'ecpay_aio',
		'ecpay_ecpg',
		'newebpay_mpg',
		'payuni_upp',
		'payuni_uni_embed',
		'paynow',
	];

	/**
	 * 初始化依賴（子類別可選擇覆寫）
	 * 在此方法中初始化 $this->repos 和 $this->services
	 */
	protected function configure_dependencies(): void {
		// 預設空實作，子類別自行覆寫
	}

	// ========== Provider 設定 Helper ==========

	/**
	 * 啟用指定的 Provider
	 *
	 * @param string $provider_id Provider ID（例如：'amego', 'shopline_payment_redirect'）
	 * @param array<string, mixed> $extra_settings 額外設定
	 */
	protected function enable_provider( string $provider_id, array $extra_settings = [] ): void {
		$settings = array_merge( [ 'enabled' => 'yes' ], $extra_settings );
		ProviderUtils::update_option( $provider_id, $settings );
	}

	/**
	 * 停用指定的 Provider
	 *
	 * @param string $provider_id Provider ID
	 */
	protected function disable_provider( string $provider_id ): void {
		ProviderUtils::update_option( $provider_id, 'enabled', 'no' );
	}

	// ========== WooCommerce 訂單 Helper ==========

	/**
	 * 建立測試 WooCommerce 訂單
	 *
	 * @param array<string, mixed> $args 訂單設定
	 * @return \WC_Order 訂單物件
	 */
	protected function create_wc_order( array $args = [] ): \WC_Order {
		$order = wc_create_order();

		if ( isset( $args['status'] ) ) {
			$order->update_status( $args['status'] );
		}

		if ( isset( $args['payment_method'] ) ) {
			$order->set_payment_method( $args['payment_method'] );
		}

		if ( isset( $args['total'] ) ) {
			$order->set_total( $args['total'] );
		}

		$order->save();
		return $order;
	}

	/**
	 * 建立 WooCommerce 訂單並設定付款識別碼（tradeOrderId）
	 *
	 * @param string $trade_order_id SLP tradeOrderId
	 * @param string $status 訂單狀態
	 * @return \WC_Order 訂單物件
	 */
	protected function create_order_with_payment_identity( string $trade_order_id, string $status = 'pending' ): \WC_Order {
		$order     = $this->create_wc_order( [ 'status' => $status ] );
		$meta_keys = new PaymentMetaKeys( $order );
		$meta_keys->update_payment_identity( $trade_order_id );
		return $order;
	}

	/**
	 * 建立已開立發票的訂單
	 *
	 * @param array<string, mixed> $issued_data 發票開立資料
	 * @return \WC_Order 訂單物件
	 */
	protected function create_order_with_issued_invoice( array $issued_data ): \WC_Order {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta_keys = new InvoiceMetaKeys( $order );
		$meta_keys->update_issued_data( $issued_data );
		return $order;
	}

	/**
	 * 取得訂單的付款詳情
	 *
	 * @param \WC_Order $order 訂單物件
	 * @return array<string, mixed> 付款詳情
	 */
	protected function get_payment_detail( \WC_Order $order ): array {
		$meta_keys = new PaymentMetaKeys( $order );
		return $meta_keys->get_payment_detail();
	}

	/**
	 * 取得訂單的發票開立資料
	 *
	 * @param \WC_Order $order 訂單物件
	 * @return mixed 發票開立資料
	 */
	protected function get_issued_invoice_data( \WC_Order $order ): mixed {
		$meta_keys = new InvoiceMetaKeys( $order );
		return $meta_keys->get_issued_data();
	}

	/**
	 * 取得訂單的發票作廢資料
	 *
	 * @param \WC_Order $order 訂單物件
	 * @return array<string, mixed> 發票作廢資料
	 */
	protected function get_cancelled_invoice_data( \WC_Order $order ): array {
		$meta_keys = new InvoiceMetaKeys( $order );
		return $meta_keys->get_cancelled_data();
	}

	// ========== 斷言 Helper ==========

	/**
	 * 斷言操作成功（$this->lastError 應為 null）
	 */
	protected function assert_operation_succeeded(): void {
		$this->assertNull(
			$this->lastError,
			sprintf( '預期操作成功，但發生錯誤：%s', $this->lastError?->getMessage() )
		);
	}

	/**
	 * 斷言操作失敗（$this->lastError 不應為 null）
	 */
	protected function assert_operation_failed(): void {
		$this->assertNotNull( $this->lastError, '預期操作失敗，但沒有發生錯誤' );
	}

	/**
	 * 斷言操作失敗且錯誤訊息包含指定文字
	 *
	 * @param string $msg 期望錯誤訊息包含的文字
	 */
	protected function assert_operation_failed_with_message( string $msg ): void {
		$this->assertNotNull( $this->lastError, '預期操作失敗' );
		$this->assertStringContainsString(
			$msg,
			$this->lastError->getMessage(),
			"錯誤訊息不包含 \"{$msg}\"，實際訊息：{$this->lastError->getMessage()}"
		);
	}

	/**
	 * 斷言 action hook 被觸發
	 *
	 * @param string $action_name action 名稱
	 */
	protected function assert_action_fired( string $action_name ): void {
		$this->assertGreaterThan(
			0,
			did_action( $action_name ),
			"Action '{$action_name}' 未被觸發"
		);
	}

	/**
	 * 斷言訂單狀態符合預期
	 *
	 * @param \WC_Order $order        訂單物件
	 * @param string    $expected_status 期望狀態（不含 wc- 前綴）
	 */
	protected function assert_order_status( \WC_Order $order, string $expected_status ): void {
		// 重新從資料庫讀取訂單，確保狀態是最新的
		$fresh_order = wc_get_order( $order->get_id() );
		$actual      = $fresh_order ? $fresh_order->get_status() : $order->get_status();
		$this->assertSame(
			$expected_status,
			$actual,
			"訂單狀態不符，期望 '{$expected_status}'，實際為 '{$actual}'"
		);
	}

	/**
	 * 斷言訂單的 Order Note 包含指定文字
	 *
	 * @param \WC_Order $order 訂單物件
	 * @param string    $text  期望包含的文字
	 */
	protected function assert_order_note_contains( \WC_Order $order, string $text ): void {
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
		$found = false;
		foreach ( $notes as $note ) {
			if ( str_contains( $note->content, $text ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, "訂單備忘錄中找不到包含 \"{$text}\" 的記錄" );
	}

	/**
	 * 斷言 Provider 已在容器中啟用
	 *
	 * @param string $provider_id Provider ID
	 */
	protected function assert_provider_enabled( string $provider_id ): void {
		$this->assertTrue(
			ProviderUtils::is_enabled( $provider_id ),
			"Provider '{$provider_id}' 應已啟用，但實際未啟用"
		);
	}

	/**
	 * 斷言 Provider 未啟用
	 *
	 * @param string $provider_id Provider ID
	 */
	protected function assert_provider_disabled( string $provider_id ): void {
		$this->assertFalse(
			ProviderUtils::is_enabled( $provider_id ),
			"Provider '{$provider_id}' 應未啟用，但實際已啟用"
		);
	}
}
