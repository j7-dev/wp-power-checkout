<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Services\InvoiceApiService;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\DTOs\CheckoutFieldDTO;
use J7\PowerCheckout\Shared\Utils\CheckoutFields;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\OrderUtils;

/** Loader 載入電子發票方式 */
final class ProviderRegister {

	// 發票 APP 渲染用的 ID
	private const RENDER_ID = 'power_checkout_invoice_metabox_app';

	/** @var array<string, string> $invoice_providers [id, class]  */
	private static array $invoice_providers = [
		AmegoProvider::ID        => AmegoProvider::class,
		EcpayInvoiceProvider::ID => EcpayInvoiceProvider::class,
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

			( new CheckoutFieldDTO(
				[
					'id'    => MetaKeys::get_issue_params_key(),
					'label' => '發票資料',
				]
				) )->register();
		}
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
		$callable                        = [ $class_name, 'instance' ];
		/** @var \J7\PowerCheckout\Shared\Abstracts\BaseService $instance */
		$instance                        = \call_user_func( $callable );
		ProviderUtils::$container[ $id ] = $instance;

		/** @var IInvoiceService $provider */
		$provider          = ProviderUtils::$container[ $id ];
		$provider_settings = $provider->get_settings();

		// 註冊自動開立發票、自動取消方票的 hooks
		if (isset($provider_settings['auto_issue_order_statuses']) && \is_array($provider_settings['auto_issue_order_statuses'])) {
			$auto_issue_order_statuses = $provider_settings['auto_issue_order_statuses'];
			foreach ($auto_issue_order_statuses as $status_with_prefix) {
				$status = OrderUtils::strip_prefix( (string) $status_with_prefix);
				\add_action( "woocommerce_order_status_{$status}", [ $provider, 'issue' ] );
			}
		}

		if (isset($provider_settings['auto_cancel_order_statuses']) && \is_array($provider_settings['auto_cancel_order_statuses'])) {
			$auto_cancel_order_statuses = $provider_settings['auto_cancel_order_statuses'];
			foreach ($auto_cancel_order_statuses as $status_with_prefix) {
				$status = OrderUtils::strip_prefix( (string) $status_with_prefix);
				\add_action( "woocommerce_order_status_{$status}", [ $provider, 'cancel' ] );
			}
		}
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
