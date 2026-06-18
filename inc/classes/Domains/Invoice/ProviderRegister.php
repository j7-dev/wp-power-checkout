<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Services\EzpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Paynow\Services\PaynowInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\BlocksInvoiceIntegration;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\InvoiceApiService;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\DTOs\CheckoutFieldDTO;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\CheckoutFields;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\OrderUtils;

/** Loader 載入電子發票方式 */
final class ProviderRegister {

	// 發票 APP 渲染用的 ID
	private const RENDER_ID = 'power_checkout_invoice_metabox_app';

	/** @var array<string, string> $invoice_providers [id, class]  */
	private static array $invoice_providers = [
		AmegoProvider::ID         => AmegoProvider::class,
		EcpayInvoiceProvider::ID  => EcpayInvoiceProvider::class,
		EzpayInvoiceProvider::ID  => EzpayInvoiceProvider::class,
		PaynowInvoiceProvider::ID => PaynowInvoiceProvider::class,
	];


	/** 註冊 hooks */
	public static function register_hooks(): void {
		// 支援傳統訂單和 HPOS
		// 使用 'add_meta_boxes' hook 可以同時支援兩種儲存方式
		\add_action( 'add_meta_boxes', [ __CLASS__, 'add_invoice_meta_box' ] );
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'issue_invoice_script' ], 20 );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'issue_invoice_script' ], 20 );
		\add_action( 'plugins_loaded', [ CheckoutFields::class, 'register_hooks' ], 1000);

		$any_enabled = false;
		foreach ( self::$invoice_providers as $id => $class_name ) {
			// 如果電子發票啟用，才實例化放入容器
			if (!ProviderUtils::is_enabled( $id)) {
				continue;
			}
			self::register_provider_hooks( $id, $class_name);
			$any_enabled = true;
		}

		// 有啟用的服務才註冊 API
		if ($any_enabled) {
			InvoiceApiService::instance();

			// block 結帳發票表單整合（enqueue + cart extensions + update callback + 下單寫 meta）
			BlocksInvoiceIntegration::register_hooks();

			// 退款自動開折讓 hook（部分退款 + 已開發票 + provider 支援折讓 + 設定開關開 → 自動開折讓）
			\add_action( 'woocommerce_order_refunded', [ __CLASS__, 'maybe_issue_allowance_on_refund' ], 10, 2 );

			( new CheckoutFieldDTO(
				[
					'id'    => MetaKeys::get_issue_params_key(),
					'label' => '發票資料',
				]
				) )->register();
		}
	}

	/**
	 * 退款時自動開立折讓
	 *
	 * 觸發條件（全部成立才開折讓）：
	 *  1. 訂單存在且已開立發票（有 issued_data）
	 *  2. 訂單使用的 provider 支援折讓（implements ISupportsAllowance）
	 *  3. 該 provider 設定 auto_allowance_on_refund = 'yes'（預設關）
	 *  4. 本次為「部分退款」——退款後仍有剩餘金額（全額退款走作廢發票既有邏輯，不開折讓）
	 *
	 * 任何 \Throwable 都被攔截，不破壞 WooCommerce 退款主流程。
	 *
	 * @param int $order_id  訂單 ID
	 * @param int $refund_id 退款單 ID
	 *
	 * @return void
	 */
	public static function maybe_issue_allowance_on_refund( int $order_id, int $refund_id ): void {
		try {
			$order = \wc_get_order( $order_id );
			if (!$order instanceof \WC_Order) {
				return;
			}

			// 須已開立發票
			$meta_keys = new MetaKeys( $order );
			if (!$meta_keys->get_issued_data()) {
				return;
			}

			// provider 須支援折讓
			$provider = ProviderUtils::get_provider( $meta_keys->get_provider_id() );
			if (!$provider instanceof ISupportsAllowance || !$provider instanceof IInvoiceService) {
				return;
			}

			// 設定開關須開啟（預設關）
			$settings = $provider::get_settings();
			if ('yes' !== ( $settings['auto_allowance_on_refund'] ?? 'no' )) {
				return;
			}

			// 僅部分退款開折讓：全額退款（退款後無剩餘）走作廢發票邏輯
			$remaining = (float) $order->get_remaining_refund_amount();
			if ($remaining <= 0) {
				return;
			}

			// 本次退款金額
			$refund_amount = self::get_refund_amount( $refund_id );
			if ($refund_amount <= 0) {
				return;
			}

			$result = $provider->issue_allowance( $order, $refund_amount );

			// 折讓回正規化 \WP_Error 時記 order note，但不中斷退款主流程（never-throw）。
			if (\is_wp_error( $result )) {
				self::note_provider_error( $order, $result, '退款自動開折讓', $meta_keys->get_provider_id() );
			}
		} catch (\Throwable $e) {
			Plugin::logger(
				"退款自動開折讓失敗 #{$order_id}： {$e->getMessage()}",
				'error',
				[],
				5
			);
		}
	}

	/**
	 * 取得退款單金額
	 *
	 * @param int $refund_id 退款單 ID
	 *
	 * @return float
	 */
	private static function get_refund_amount( int $refund_id ): float {
		if ($refund_id <= 0) {
			return 0.0;
		}
		$refund = \wc_get_order( $refund_id );
		if (!$refund instanceof \WC_Order_Refund) {
			return 0.0;
		}
		// 退款金額在 WC 內部為負值表示，取絕對值
		return \abs( (float) $refund->get_amount() );
	}

	/**
	 * 註冊服務的鉤子
	 *
	 * @param string $id 服務 id
	 * @param string $class_name 服務類別
	 * @return void
	 */
	private static function register_provider_hooks( string $id, string $class_name ): void {
		if (!\class_exists($class_name)) {
			return;
		}
		/** @var callable $callable */
		$callable = [ $class_name, 'instance' ];
		/** @var \J7\PowerCheckout\Shared\Abstracts\BaseService $instance */
		$instance                        = \call_user_func( $callable );
		ProviderUtils::$container[ $id ] = $instance;

		/** @var IInvoiceService $provider */
		$provider          = ProviderUtils::$container[ $id ];
		$provider_settings = $provider->get_settings();

		// 註冊自動開立發票、自動取消發票的 hooks。
		//
		// FM-07：`woocommerce_order_status_{status}` action hook 不消費 callback 回傳值，
		// 直掛 `[$provider, 'issue'|'cancel']` 會讓 provider 回的 \WP_Error 無人讀 → 失敗無痕。
		// 故改掛 wrapper static method：wrapper 內呼叫 provider → is_wp_error() 為真時記 order note，
		// 絕不向 hook 拋（never-throw 保留）。wrapper 為 provider-agnostic（依 current_action 反查狀態 +
		// 遍歷啟用 provider），故每個狀態只需掛一次（以 has_action 去重，避免多 provider 共用狀態時重複觸發）。
		if (isset($provider_settings['auto_issue_order_statuses']) && \is_array($provider_settings['auto_issue_order_statuses'])) {
			$auto_issue_order_statuses = $provider_settings['auto_issue_order_statuses'];
			foreach ($auto_issue_order_statuses as $status_with_prefix) {
				$status = OrderUtils::strip_prefix( (string) $status_with_prefix);
				if (false === \has_action( "woocommerce_order_status_{$status}", [ __CLASS__, 'auto_issue_wrapper' ] )) {
					\add_action( "woocommerce_order_status_{$status}", [ __CLASS__, 'auto_issue_wrapper' ] );
				}
			}
		}

		if (isset($provider_settings['auto_cancel_order_statuses']) && \is_array($provider_settings['auto_cancel_order_statuses'])) {
			$auto_cancel_order_statuses = $provider_settings['auto_cancel_order_statuses'];
			foreach ($auto_cancel_order_statuses as $status_with_prefix) {
				$status = OrderUtils::strip_prefix( (string) $status_with_prefix);
				if (false === \has_action( "woocommerce_order_status_{$status}", [ __CLASS__, 'auto_cancel_wrapper' ] )) {
					\add_action( "woocommerce_order_status_{$status}", [ __CLASS__, 'auto_cancel_wrapper' ] );
				}
			}
		}
	}

	/**
	 * 自動開立發票 wrapper（woocommerce_order_status_{status} 回呼）
	 *
	 * 採 never-throw：provider issue() 回 \WP_Error 時記 order note（含 error_code + message），
	 * 絕不向 WC hook 拋例外（怕中斷訂單狀態變更流程）。成功路徑行為與直掛 issue() 完全一致。
	 *
	 * @param int $order_id 訂單 ID（WC 觸發 woocommerce_order_status_{status} 時帶入）
	 *
	 * @return void
	 */
	public static function auto_issue_wrapper( int $order_id ): void {
		self::run_auto_action( 'issue', 'auto_issue_order_statuses', $order_id, '自動開立發票' );
	}

	/**
	 * 自動作廢發票 wrapper（woocommerce_order_status_{status} 回呼）
	 *
	 * 採 never-throw：provider cancel() 回 \WP_Error 時記 order note，絕不向 WC hook 拋例外。
	 *
	 * @param int $order_id 訂單 ID（WC 觸發 woocommerce_order_status_{status} 時帶入）
	 *
	 * @return void
	 */
	public static function auto_cancel_wrapper( int $order_id ): void {
		self::run_auto_action( 'cancel', 'auto_cancel_order_statuses', $order_id, '自動作廢發票' );
	}

	/**
	 * 自動開立 / 作廢 wrapper 共用執行體（provider-agnostic）
	 *
	 * 依 current_action() 反查觸發狀態，遍歷已啟用且該狀態列於對應設定鍵
	 * （auto_issue_order_statuses / auto_cancel_order_statuses）的 Invoice provider，
	 * 逐一呼叫其 issue()/cancel()；回 \WP_Error 時記 order note。
	 * 任何 \Throwable 一律攔截不外拋（never-throw 鐵律）。
	 *
	 * @param 'issue'|'cancel' $action       provider 方法名
	 * @param string           $settings_key 對應的設定鍵
	 * @param int              $order_id     訂單 ID
	 * @param string           $action_label 動作中文標籤（order note / log 用）
	 *
	 * @return void
	 */
	private static function run_auto_action( string $action, string $settings_key, int $order_id, string $action_label ): void {
		try {
			$order = \wc_get_order( $order_id );
			if (!$order instanceof \WC_Order) {
				return;
			}

			// 反查觸發狀態（去 wc- 前綴）。
			$current_action = (string) \current_action();
			$status         = \str_replace( 'woocommerce_order_status_', '', $current_action );

			foreach ( self::$invoice_providers as $id => $class_name ) {
				$provider = ProviderUtils::get_provider( $id );
				if (!$provider instanceof IInvoiceService) {
					continue;
				}

				// 僅對「該狀態列於對應設定鍵」的 provider 執行（對齊既有 per-status 掛載語義）。
				$settings            = $provider::get_settings();
				$statuses            = isset($settings[ $settings_key ]) && \is_array($settings[ $settings_key ]) ? $settings[ $settings_key ] : [];
				$normalized_statuses = \array_map( static fn( $s ): string => OrderUtils::strip_prefix( (string) $s ), $statuses );
				if (!\in_array( $status, $normalized_statuses, true )) {
					continue;
				}

				/** @var array<string, mixed>|\WP_Error $result */
				$result = 'cancel' === $action ? $provider->cancel( $order ) : $provider->issue( $order );

				if (\is_wp_error( $result )) {
					self::note_provider_error( $order, $result, $action_label, $id );
				}
			}
		} catch (\Throwable $e) {
			Plugin::logger(
				"{$action_label}失敗 #{$order_id}： {$e->getMessage()}",
				'error',
				[],
				5
			);
		}
	}

	/**
	 * 將 provider 回傳的正規化 \WP_Error 記入 order note（含 error_code + raw_code + message）
	 *
	 * @param \WC_Order $order        訂單
	 * @param \WP_Error $error        provider 回傳的錯誤
	 * @param string    $action_label 動作中文標籤
	 * @param string    $provider_id  provider id
	 *
	 * @return void
	 */
	private static function note_provider_error( \WC_Order $order, \WP_Error $error, string $action_label, string $provider_id ): void {
		$code     = NormalizedError::get_code( $error );
		$raw_code = NormalizedError::get_raw_code( $error );

		$error_code  = null === $code ? (string) $error->get_error_code() : $code->value;
		$raw_segment = null === $raw_code ? '' : "（{$raw_code}）";

		$note = \sprintf(
			/* translators: 1: 動作, 2: provider id, 3: 正規化錯誤碼, 4: raw_code, 5: 錯誤訊息 */
			\__( '%1$s失敗（%2$s）：%3$s%4$s %5$s', 'power_checkout' ),
			$action_label,
			$provider_id,
			$error_code,
			$raw_segment,
			$error->get_error_message()
		);

		$order->add_order_note( $note );
	}


	/**
	 * 新增發票 MetaBox
	 *
	 * @param string $post_type 文章類型
	 */
    public static function add_invoice_meta_box( string $post_type ): void { // phpcs:ignore
		// 支援 HPOS 和傳統訂單
		// HPOS: screen_id 為 'woocommerce_page_wc-orders'
		// 傳統: post_type 為 'shop_order'
		$order_screen_ids = [ 'shop_order' ];

		// 檢查是否啟用 HPOS
		if ( \class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order_screen_ids[] = \wc_get_page_screen_id( 'shop-order' );
			}
		}

		\add_meta_box(
			'power_checkout_invoice_meta_box',
			\__( '電子發票資訊', 'power_checkout' ),
			[ __CLASS__, 'render_invoice_meta_box' ],
			$order_screen_ids,
			'side',
			'high'
		);
	}

	/**
	 * 渲染發票 MetaBox 內容
	 *
	 * @param \WP_Post|\WC_Order $post_or_order 訂單物件 (HPOS) 或文章物件 (傳統)
	 */
	public static function render_invoice_meta_box( \WP_Post|\WC_Order $post_or_order ): void {

		if (!ProviderUtils::has_providers( \array_keys( self::$invoice_providers))) {
			echo '找不到已啟用的電子發票服務';
			return;
		}

		// 取得訂單物件
		$order = $post_or_order instanceof \WC_Order ? $post_or_order : \wc_get_order( $post_or_order->ID );

		if ( !$order ) {
			echo '無法取得訂單資訊';
			return;
		}

		\printf(
			'<div id="%1$s" data-order-id="%2$s" style="margin-top:1rem;"></div>',
		self::RENDER_ID,
			$order->get_id()
		);
	}


	/**
	 * Enqueue 發票 APP Script
	 *
	 * @param string $hook 後台頁面 hook
	 *
	 * @return void
	 */
	public static function issue_invoice_script( $hook ): void {
		// if ( !OrderUtils::is_order_detail( $hook ) ) {
		// return;
		// }
		SettingTabService::enqueue_vue_app();

		$invoice_providers          = ProviderUtils::get_providers( \array_keys( self::$invoice_providers));
		$invoice_providers_settings = \array_map(
			static function ( $p ): array {
				if ($p instanceof IInvoiceService) {
					return $p::get_settings();
				}
				return [];
			},
			$invoice_providers
			);
		$is_admin                   = \is_admin();
		// 暴露給前端的資料
		$data = [
			'render_ids'        => self::get_render_ids(),
			'is_admin'          => $is_admin,
			'invoice_providers' => $invoice_providers_settings,
			'is_issued'         => false,
			'invoice_number'    => '',
			'order'             => [],
		];

		// 如果在後台，那就取得訂單
		if ($is_admin) {
			$order_id = OrderUtils::get_order_id( $hook );
			$order    = \wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$data['order']     = [
					'id' => (string) $order->get_id(),
				];
				$meta_keys         = new MetaKeys( $order);
				$issued_data       = $meta_keys->get_issued_data();
				$data['is_issued'] = (bool) $issued_data;

				// 如果已經發行過發票就取得發票號碼
				if ($issued_data) {
					$provider = ProviderUtils::get_provider( $meta_keys->get_provider_id() );
					if ($provider instanceof IInvoiceService) {
						$data['invoice_number'] = $provider->get_invoice_number($order);
					}
				}
			}
		}

		// 要額外給前端的資料
		$obj_name = self::RENDER_ID . '_data'; // power_checkout_invoice_metabox_app_data
		\wp_localize_script(
			SettingTabService::$handle,
			$obj_name,
			$data
		);
	}


	/** @return BaseSettingsDTO[] 取得 provider 設定 dtos */
	public static function get_registered_provider_dtos(): array {
		return \array_map( static fn( $class_name ) => BaseSettingsDTO::create( $class_name), self::$invoice_providers );
	}

	/** @return array<string> 取得渲染的 ids */
	private static function get_render_ids(): array {
		$field_id = MetaKeys::get_issue_params_key();
		$kebab    = Plugin::$kebab;

		return [
			self::RENDER_ID, // 後台 metabox
			$field_id, // 傳統結帳
		// "order-{$kebab}-{$field_id}", //TODO 區塊結帳
		];
	}
}
