<?php
/**
 * 電子發票 — WC Block Checkout 發票表單整合（後端串接）
 *
 * 對應 classic 結帳的發票欄位（CheckoutFields → `_pc_issue_invoice_params` order meta）。
 * block 結帳沒有 classic 的 woocommerce_checkout_fields，故改走 Store API：
 *
 *   1. enqueue block JS（inc/assets/dist/blocks/pc_invoice.js）+ AssetDataRegistry 注入
 *      `pc_invoice_data`（providers 清單 + nonce），供前端 getSetting 讀取與表單渲染。
 *   2. ExtendSchema 註冊 cart extensions：把 session 暫存的發票參數注入
 *      cart.extensions['pc_invoice']，前端 useSelect(wc/store/cart) 讀得到已填值（刷新 / 持久）。
 *   3. register_update_callback（namespace pc_invoice）：前端 extensionCartUpdate 送入發票欄位，
 *      後端 InvoiceParamsValidator sanitize + validate 後暫存於 WC session。
 *   4. 下單寫 meta：woocommerce_store_api_checkout_order_processed 把 session 發票參數搬進
 *      order meta（與 classic 同一個 `_pc_issue_invoice_params` key），並清除 session 暫存。
 *
 * ⚠️ classic 結帳的下單寫 meta 由 CheckoutFields::save_checkout_field_to_order
 *    （woocommerce_checkout_update_order_meta）處理，兩條路徑互不干擾、寫入相同 meta key，
 *    故下游開立發票邏輯（IssueInvoiceParamsDTO / provider::issue）零改動沿用。
 *
 * @see inc/assets/blocks/pc_invoice.tsx（前端表單）
 * @see inc/classes/Domains/Logistics/Ecpay/Services/BlocksLogisticsIntegration.php（同 pattern）
 * @see .claude/rules/react-blocks.rule.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Services;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\CartInvoiceSession;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\InvoiceParamsValidator;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 電子發票 block 結帳發票表單整合（後端串接） */
final class BlocksInvoiceIntegration {

	/** @var string 本外掛發票在 WC Block / Store API 的 namespace（對齊前端 tsx NAMESPACE） */
	public const NAMESPACE = 'pc_invoice';

	/** @var string block JS script handle */
	private const SCRIPT_HANDLE = 'pc-invoice-blocks';

	/**
	 * 已知發票 provider id（用於組裝前端 providers 清單）
	 *
	 * @var array<int, string>
	 */
	private static array $provider_ids = [ 'amego', 'ecpay' ];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		// 1. block 結帳頁 enqueue
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_block_assets' ], 20 );

		// 2 + 3 + 1b. ExtendSchema（cart extensions）+ update callback + 注入前端靜態設定
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api' ] );

		// 4. block 下單：把 session 暫存發票參數搬進 order meta
		\add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'save_block_checkout_meta' ], 10, 1 );
	}

	/**
	 * 把 pc_invoice_data 注入 wcSettings server data（前端 getSetting('pc_invoice_data')）
	 *
	 * 採非 deprecated 的 AssetDataRegistry::add()，避免 woocommerce_shared_settings filter 棄用警告。
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
			if (!$registry->exists( 'pc_invoice_data' )) {
				$registry->add( 'pc_invoice_data', self::get_block_data() );
			}
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'電子發票 block 設定注入失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 於 block 結帳頁 enqueue block JS
	 *
	 * @return void
	 */
	public static function enqueue_block_assets(): void {
		if (!self::is_block_checkout()) {
			return;
		}

		$asset_url = Plugin::$url . '/inc/assets/dist/blocks/pc_invoice.js';

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
	 * 取得 block 前端靜態設定（providers 清單 + nonce）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_block_data(): array {
		return [
			'providers' => self::get_enabled_providers(),
			'nonce'     => \wp_create_nonce( 'wp_rest' ),
		];
	}

	/**
	 * 註冊 Store API 擴充：cart extensions（注入已填發票參數）+ update callback
	 *
	 * @return void
	 */
	public static function register_store_api(): void {
		// 注入前端靜態設定
		self::register_asset_data();

		// cart extensions：注入 session 暫存發票參數，block 前端 useSelect(wc/store/cart) 讀得到
		if (\function_exists( 'woocommerce_store_api_register_endpoint_data' )) {
			\woocommerce_store_api_register_endpoint_data(
				[
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => [ __CLASS__, 'cart_extension_data' ],
					'schema_callback' => [ __CLASS__, 'cart_extension_schema' ],
				]
			);
		}

		// update callback：前端 extensionCartUpdate 送入發票欄位 → sanitize + validate → 暫存 session
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
	 * 購物車 extensions data callback：回傳已填發票參數（給前端 cart.extensions['pc_invoice']）
	 *
	 * @return array<string, mixed>
	 */
	public static function cart_extension_data(): array {
		$params = CartInvoiceSession::get();
		if (null === $params) {
			return [
				'invoiceType' => '',
				'provider'    => '',
				'filled'      => false,
			];
		}
		return \array_merge( $params, [ 'filled' => true ] );
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
			'provider'    => $string_field( \__( '發票服務 id', 'power_checkout' ) ),
			'invoiceType' => $string_field( \__( '發票類型', 'power_checkout' ) ),
			'individual'  => $string_field( \__( '個人發票類型', 'power_checkout' ) ),
			'carrier'     => $string_field( \__( '載具', 'power_checkout' ) ),
			'moica'       => $string_field( \__( '自然人憑證', 'power_checkout' ) ),
			'companyName' => $string_field( \__( '公司名稱', 'power_checkout' ) ),
			'companyId'   => $string_field( \__( '統一編號', 'power_checkout' ) ),
			'donateCode'  => $string_field( \__( '捐贈碼', 'power_checkout' ) ),
			'filled'      => [
				'description' => \__( '是否已填寫發票資訊', 'power_checkout' ),
				'type'        => 'boolean',
				'readonly'    => true,
			],
		];
	}

	/**
	 * 購物車 extensions update callback（前端 extensionCartUpdate 觸發）
	 *
	 * 後端 sanitize + validate 後暫存於 WC session。驗證失敗一律 catch，記 log 後不寫入
	 * （前端表單已先驗證；後端為第二道防線，失敗時不阻塞 cart 重算）。
	 *
	 * @param array<string, mixed> $data 前端送入的發票欄位
	 * @return void
	 */
	public static function handle_update_callback( array $data ): void {
		try {
			// 顯式清除（前端送 clear 旗標時清除暫存，例如取消開立發票）
			if (!empty( $data['clear'] )) {
				CartInvoiceSession::clear();
				return;
			}

			$params = InvoiceParamsValidator::validate( $data );
			CartInvoiceSession::store( $params );
		} catch ( \Throwable $e ) {
			// 驗證失敗 / session 不可用 → 記 log，不阻塞 cart 重算
			Plugin::logger(
				'電子發票 block 表單暫存失敗',
				'info',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 於 block 下單：把 session 暫存發票參數搬進 order meta（同 classic 的 `_pc_issue_invoice_params`）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function save_block_checkout_meta( \WC_Order $order ): void {
		try {
			$params = CartInvoiceSession::get();
			if (null === $params) {
				return;
			}

			( new MetaKeys( $order ) )->update_issue_params( $params );

			// 搬移完成後清除 session 暫存，避免殘留影響下一筆訂單
			CartInvoiceSession::clear();
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'電子發票 block 下單寫 meta 失敗',
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// region helpers

	/**
	 * 取得已啟用的發票 providers（id + title），供前端表單渲染
	 *
	 * @return array<int, array{id: string, title: string}>
	 */
	private static function get_enabled_providers(): array {
		$result = [];
		foreach ( self::$provider_ids as $id ) {
			if (!ProviderUtils::is_enabled( $id )) {
				continue;
			}
			$provider = ProviderUtils::get_provider( $id );
			if (!$provider instanceof IInvoiceService) {
				continue;
			}
			$settings = $provider::get_settings();
			$result[] = [
				'id'    => $id,
				'title' => (string) ( $settings['title'] ?? $id ),
			];
		}
		return $result;
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
