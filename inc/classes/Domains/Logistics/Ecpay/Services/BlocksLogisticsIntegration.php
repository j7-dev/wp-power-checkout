<?php
/**
 * 綠界全方位物流 — WC Block Checkout 選店整合（後端串接）
 *
 * 物流屬「運送方式 / shipping」，WC Blocks 沒有 registerShippingMethod 對應 API，
 * 故前端（inc/assets/blocks/ecpay_logistics.tsx）改用官方 slot fill
 * ExperimentalOrderShippingPackages + registerPlugin，將「選擇門市」UI 插入運送方式步驟。
 *
 * 本類別負責後端四件事（對應前一 agent 列的 block 缺口）：
 *   1. enqueue block JS（inc/assets/dist/blocks/ecpay_logistics.js）+ wp_localize_script
 *      給前端 `ecpay_logistics_data`（store_selection_url / enabled_methods）。
 *   2. ExtendSchema 註冊 cart extensions：把 session 暫存門市注入 cart.extensions['ecpay_logistics']，
 *      block 前端 useSelect(wc/store/cart) 讀得到已選門市。
 *   3. register_update_callback：cart/extensions 端點可觸發本 namespace（保留擴充，目前 no-op 同步）。
 *   4. 下單寫 meta：woocommerce_store_api_checkout_order_processed（block 下單）把 session 門市
 *      + sub_type + payment_scenario 搬進 order meta，並清除 session 暫存。
 *
 * ⚠️ classic checkout 的下單寫 meta 由 WC_EcpayLogisticsShipping::save_checkout_meta
 *    （woocommerce_checkout_create_order）處理，兩條路徑互不干擾。
 *
 * @see inc/assets/blocks/ecpay_logistics.tsx
 * @see .claude/rules/react-blocks.rule.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\Services;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Plugin;

/** 綠界全方位物流 block 結帳整合（後端串接） */
final class BlocksLogisticsIntegration {

	/** @var string 本外掛在 WC Block / Store API 的 namespace（對齊前端 tsx NAMESPACE） */
	public const NAMESPACE = 'ecpay_logistics';

	/** @var string block JS script handle */
	private const SCRIPT_HANDLE = 'pc-ecpay-logistics-blocks';

	/** Register hooks @return void */
	public static function register_hooks(): void {
		// 1. block 結帳頁 enqueue（前端 slot fill 載入；store_selection_url 由 wcSettings server data 提供）
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_block_assets' ], 20 );

		// 2 + 3 + 1b. ExtendSchema（cart extensions）+ update callback + 注入前端靜態設定
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api' ] );

		// 4. block 下單：把 session 暫存門市搬進 order meta
		\add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'save_block_checkout_meta' ], 10, 1 );
	}

	/**
	 * 把 ecpay_logistics_data 注入 wcSettings server data（前端 getSetting('ecpay_logistics_data')）
	 *
	 * 採非 deprecated 的 AssetDataRegistry::add()（透過 Blocks Package 容器），
	 * 避免 woocommerce_shared_settings filter 的前端 console 棄用警告。
	 *
	 * @return void
	 */
	public static function register_asset_data(): void {
		$registry_class = '\Automattic\WooCommerce\Blocks\Package';
		$asset_class    = '\Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry';
		if (!\class_exists( $registry_class ) || !\class_exists( $asset_class )) {
			return;
		}

		try {
			/** @var \Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry $registry */
			$registry = \Automattic\WooCommerce\Blocks\Package::container()->get( $asset_class );
			if (!$registry->exists( 'ecpay_logistics_data' )) {
				$registry->add( 'ecpay_logistics_data', self::get_block_data() );
			}
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'綠界全方位物流 block 設定注入失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 於 block 結帳頁 enqueue block JS + localize ecpay_logistics_data
	 *
	 * 僅在「block 結帳頁」載入（避免污染其他頁面）。
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		if (!self::is_block_checkout()) {
			return;
		}

		$asset_url = Plugin::$url . '/inc/assets/dist/blocks/ecpay_logistics.js';

		\wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$asset_url,
			[
				'wc-blocks-checkout',
				'wp-plugins',
				'wp-data',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			Plugin::$version,
			true
		);
	}

	/**
	 * 取得 block 前端靜態設定（store_selection_url / enabled_methods）
	 *
	 * 透過 wc-settings 的 getSetting('ecpay_logistics_data') 提供；為相容 WC 取設定機制，
	 * 同步以 wp_localize_script 與 wcSettings server data 雙軌注入。
	 *
	 * @return array<string, mixed>
	 */
	public static function get_block_data(): array {
		$settings = EcpayLogisticsSettingsDTO::instance();

		return [
			'store_selection_url' => \site_url( 'wp-json/power-checkout/v1/logistics/store-selection', 'https' ),
			'enabled_methods'     => \array_values( $settings->enabled_methods ),
			'nonce'               => \wp_create_nonce( 'wp_rest' ),
		];
	}

	/**
	 * 註冊 Store API 擴充：cart extensions（注入已選門市）+ update callback
	 *
	 * @return void
	 */
	public static function register_store_api(): void {
		// 注入前端靜態設定，供前端讀取 ecpay_logistics_data
		self::register_asset_data();

		// cart extensions：注入 session 暫存門市，block 前端 useSelect(wc/store/cart) 讀得到
		if (\function_exists( 'woocommerce_store_api_register_endpoint_data' )) {
			// schema_type 預設即為 ARRAY_A（物件型），無須顯式指定
			\woocommerce_store_api_register_endpoint_data(
				[
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => [ __CLASS__, 'cart_extension_data' ],
					'schema_callback' => [ __CLASS__, 'cart_extension_schema' ],
				]
			);
		}

		// update callback：保留擴充入口（cart/extensions 端點觸發本 namespace；目前同步即可）
		if (\function_exists( 'woocommerce_store_api_register_update_callback' )) {
			\woocommerce_store_api_register_update_callback(
				[
					'namespace' => self::NAMESPACE,
					'callback'  => [ __CLASS__, 'handle_update_callback' ],
				]
			);
		}
	}

	/**
	 * 購物車 extensions data callback：回傳已選門市（給前端 cart.extensions['ecpay_logistics']）
	 *
	 * @return array<string, mixed>
	 */
	public static function cart_extension_data(): array {
		$store = CartLogisticsSession::get_selected_store();
		if (null === $store) {
			return [
				'store_id'   => '',
				'store_name' => '',
				'store_addr' => '',
				'sub_type'   => '',
			];
		}
		return [
			'store_id'   => $store['store_id'],
			'store_name' => $store['store_name'],
			'store_addr' => $store['store_addr'],
			'sub_type'   => $store['sub_type'],
		];
	}

	/**
	 * 購物車 extensions schema callback（型別宣告，供 Store API schema 驗證）
	 *
	 * @return array<string, mixed>
	 */
	public static function cart_extension_schema(): array {
		$string_field = static fn( string $desc ): array => [
			'description' => $desc,
			'type'        => 'string',
			'readonly'    => true,
		];

		return [
			'store_id'   => $string_field( \__( '已選門市代碼', 'power_checkout' ) ),
			'store_name' => $string_field( \__( '已選門市名稱', 'power_checkout' ) ),
			'store_addr' => $string_field( \__( '已選門市地址', 'power_checkout' ) ),
			'sub_type'   => $string_field( \__( '物流子類型', 'power_checkout' ) ),
		];
	}

	/**
	 * 購物車 extensions update callback（保留擴充入口）
	 *
	 * 門市寫入由綠界選店回呼（selection-callback）以權杖驗證後完成，故此處不接受前端直接寫門市
	 * （避免繞過綠界選店偽造門市）。保留入口供未來同步用。
	 *
	 * @param array<string, mixed> $data 前端送入的資料
	 * @return void
	 */
	public static function handle_update_callback( array $data ): void {
		// 安全：不接受前端直接寫門市（門市僅能由綠界選店回呼經權杖驗證寫入）。
		// 此 callback 目前僅作為 cart 重新計算的觸發點。
		unset( $data );
	}

	/**
	 * 於 block 下單：把 session 暫存門市 + sub_type + payment_scenario 搬進 order meta
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function save_block_checkout_meta( \WC_Order $order ): void {
		try {
			$store = CartLogisticsSession::get_selected_store();
			$meta  = new LogisticsMetaKeys( $order );

			$sub_type = self::get_chosen_sub_type( $order, $store );

			// 付款情境：COD → cod，其餘 → online
			$is_cod   = 'cod' === $order->get_payment_method();
			$scenario = $is_cod ? LogisticsPaymentScenario::COD->value : LogisticsPaymentScenario::ONLINE->value;

			// 僅在選用本物流運送方式時寫入
			if (!self::is_logistics_chosen( $order )) {
				return;
			}

			if ('' !== $sub_type) {
				$meta->update_sub_type( $sub_type );
			}
			$meta->update_payment_scenario( $scenario );

			// 有選店暫存（超商取貨）才搬門市 meta
			if (null !== $store) {
				$meta->update_temp_id( $store['temp_id'] );
				$meta->update_store_id( $store['store_id'] );
				$meta->update_store_name( $store['store_name'] );
				$meta->update_store_addr( $store['store_addr'] );
				$meta->update_provider_id( EcpayLogisticsProvider::ID );
			}

			// 搬移完成後清除 session 暫存，避免殘留影響下一筆訂單
			CartLogisticsSession::clear();
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'綠界全方位物流 block 下單寫 meta 失敗',
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// region helpers

	/**
	 * 取得下單選定的物流子類型（優先 session 暫存門市，其次運送方式 meta）
	 *
	 * @param \WC_Order                  $order 訂單
	 * @param array<string, string>|null $store session 暫存門市
	 * @return string
	 */
	private static function get_chosen_sub_type( \WC_Order $order, ?array $store ): string {
		// 超商取貨：選店暫存已帶 sub_type
		if (null !== $store && '' !== $store['sub_type']) {
			return $store['sub_type'];
		}
		unset( $order );
		return '';
	}

	/**
	 * 訂單是否選用綠界物流運送方式
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_logistics_chosen( \WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (WC_EcpayLogisticsShipping::METHOD_ID === $shipping_item->get_method_id()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 是否為 block 結帳頁
	 *
	 * @return bool
	 */
	private static function is_block_checkout(): bool {
		if (!\function_exists( 'is_checkout' ) || !\is_checkout()) {
			return false;
		}
		// block 結帳頁含 woocommerce/checkout 區塊
		if (\function_exists( 'has_block' )) {
			$post = \get_post();
			if ($post instanceof \WP_Post && \has_block( 'woocommerce/checkout', $post )) {
				return true;
			}
		}
		return false;
	}

	// endregion
}
