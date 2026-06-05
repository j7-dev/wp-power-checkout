<?php
/**
 * 綠界全方位物流 WC_Shipping_Method（風險 R7 — 專案全新領域，最小切片）
 *
 * 使超商取貨 / 宅配出現在 classic 結帳「運送方式」清單，並提供：
 *  - 固定運費（計畫 T4：後台可設 cost，預設 0；不做依重量 / 地區的級距運費）。
 *  - 結帳頁寫 _pc_logistics_sub_type / _pc_logistics_payment_scenario 到 order meta
 *    （classic checkout 透過 woocommerce_checkout_create_order hook）。
 *
 * ⚠️ 第二期才做：block checkout 選店 UI（計畫 T3，classic-first）；
 *    級距運費；多 instance 拆分為各超商獨立運送方式。
 *
 * enabled_methods（EcpayLogisticsSettingsDTO）決定結帳頁可選的物流子類型，
 * 透過 {@see self::get_supported_sub_types()} 取得（settings.enabled_methods 子集）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\Services;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsSubType;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;

/** 綠界全方位物流運送方式（classic checkout） */
final class WC_EcpayLogisticsShipping extends \WC_Shipping_Method {

	/** @var string 運送方式 id（WooCommerce shipping method 識別碼） */
	public const METHOD_ID = 'ecpay_logistics';

	/** @var string 固定運費（後台可設，預設 0；計畫 T4） */
	public string $cost = '0';

	/**
	 * Constructor
	 *
	 * @param int $instance_id Shipping zone instance id（0 = 全域）
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = \absint( $instance_id );
		$this->method_title       = \__( '綠界 ECPay 全方位物流', 'power_checkout' );
		$this->method_description = \__( '綠界 ECPay 全方位物流，支援 7-11 / 全家 / 萊爾富超商取貨與黑貓宅配，可代收貨款（COD）。', 'power_checkout' );

		// 同時支援 shipping zones（instance）與全域設定
		$this->supports = [
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		];

		$this->init();
	}

	/**
	 * 初始化設定欄位與值
	 *
	 * @return void
	 */
	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		// 從設定讀取（含啟用狀態與標題、固定運費）
		$this->title   = (string) $this->get_option( 'title', \__( '綠界超商取貨 / 宅配', 'power_checkout' ) );
		$this->enabled = (string) $this->get_option( 'enabled', 'yes' );
		$this->cost    = (string) $this->get_option( 'cost', $this->cost );

		\add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			[ $this, 'process_admin_options' ]
		);
	}

	/**
	 * 後台運送方式設定欄位（最小切片：啟用 / 標題 / 固定運費）
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => \__( '啟用', 'power_checkout' ),
				'type'    => 'checkbox',
				'label'   => \__( '啟用綠界全方位物流運送方式', 'power_checkout' ),
				'default' => 'yes',
			],
			'title'   => [
				'title'       => \__( '名稱', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '結帳頁顯示的運送方式名稱。', 'power_checkout' ),
				'default'     => \__( '綠界超商取貨 / 宅配', 'power_checkout' ),
				'desc_tip'    => true,
			],
			'cost'    => [
				'title'       => \__( '運費', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '固定運費（新台幣），預設 0；本期不支援依重量 / 地區的級距運費。', 'power_checkout' ),
				'default'     => '0',
				'desc_tip'    => true,
			],
		];

		// 全域（非 instance）設定使用同一組欄位
		$this->form_fields = $this->instance_form_fields;
	}

	/**
	 * 計算運費（每個 enabled sub_type 各產一個 rate）
	 *
	 * 第一性原理：WC 一個 shipping rate = 顧客可選的一個運送選項。物流啟用多個 sub_type
	 * （FAMI/UNIMART/HILIFE/HOME）時，須為每個 enabled sub_type 各 add 一個 rate，使顧客
	 * 「選哪個 rate」即決定 sub_type。每個 rate：
	 *   - rate_id 帶 sub_type 後綴（{@see get_rate_id()} → 形如 ecpay_logistics:FAMI），
	 *   - meta_data 帶 sub_type（Store API CartShippingRateSchema 預設出到 block 前端 cart.shippingRates），
	 *   - label 用對應子類型中文標籤（全家 / 統一 / 萊爾富 / 宅配）。
	 * 固定運費沿用後台設定 cost（計畫 T4，不做依重量 / 地區的級距運費）。
	 *
	 * @param array<string, mixed> $package 購物車運送 package
	 *
	 * @return void
	 */
	public function calculate_shipping( $package = [] ): void {
		$cost      = (float) $this->cost;
		$base      = $this->title ?: $this->method_title;
		$sub_types = $this->get_supported_sub_types();

		// 無 enabled sub_type → 不產生任何 rate（顧客無可選的綠界物流運送方式）
		foreach ( $sub_types as $sub_type ) {
			$label = LogisticsSubType::label_of( $sub_type );

			$this->add_rate(
				[
					// rate_id 帶 sub_type 後綴：顧客選定的 rate 即決定 sub_type
					'id'        => $this->get_rate_id( $sub_type ),
					'label'     => "{$base}（{$label}）",
					'cost'      => $cost,
					'package'   => $package,
					// 帶 sub_type meta：classic 下單時搬進 order item meta；block 經 Store API 出到前端
					'meta_data' => [ 'sub_type' => $sub_type ],
				]
			);
		}
	}

	/**
	 * 取得結帳頁可選的物流子類型（settings.enabled_methods 子集，僅合法子類型）
	 *
	 * @return array<int, string>
	 */
	public function get_supported_sub_types(): array {
		$settings = EcpayLogisticsSettingsDTO::instance();
		$methods  = $settings->enabled_methods;

		$valid = \array_map(
			static fn( LogisticsSubType $sub_type ): string => $sub_type->value,
			LogisticsSubType::cases()
		);

		$supported = [];
		foreach ( $methods as $method ) {
			$method = (string) $method;
			if (\in_array( $method, $valid, true )) {
				$supported[] = $method;
			}
		}

		return $supported;
	}

	/**
	 * Classic checkout：將結帳選擇的物流子類型 / 付款情境寫入 order meta
	 *
	 * 由 woocommerce_checkout_create_order hook 觸發（見 ProviderRegister）。
	 * sub_type 取得順序（第一性原理：「選定的 rate」即決定 sub_type）：
	 *   1. 選定的運送方式（order shipping item）的 sub_type meta —— WC set_shipping_rate()
	 *      會把 rate 的 meta_data（含本外掛帶的 sub_type）搬進 order item meta。
	 *   2. 退化相容：舊版結帳送出欄位 _pc_logistics_sub_type（單值）。
	 * 兩者皆經白名單（已啟用合法子類型）把關，杜絕注入。
	 * payment_scenario 依付款方式（COD → cod，其餘 → online）。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data 結帳送出資料
	 *
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		// 僅在選用本物流運送方式時寫入
		if (!self::is_chosen( $order )) {
			return;
		}

		$meta = new LogisticsMetaKeys( $order );

		// 物流子類型：優先取「選定的 rate」（order shipping item meta），退化取舊版 $_POST 欄位
		$sub_type = self::resolve_chosen_sub_type( $order );

		// 安全：僅寫入已啟用的合法子類型（白名單），杜絕注入任意值
		$method = new self();
		if ('' !== $sub_type && \in_array( $sub_type, $method->get_supported_sub_types(), true )) {
			$meta->update_sub_type( $sub_type );
		}

		// 付款情境：COD → cod，其餘 → online
		$is_cod   = 'cod' === $order->get_payment_method();
		$scenario = $is_cod ? LogisticsPaymentScenario::COD->value : LogisticsPaymentScenario::ONLINE->value;
		$meta->update_payment_scenario( $scenario );
	}

	/**
	 * 從訂單取得「選定的 rate」對應的物流子類型
	 *
	 * 來源優先序：
	 *   1. 本物流運送方式 order shipping item 的 sub_type meta（多 rate：選哪個 rate 決定 sub_type）。
	 *   2. 退化相容：舊版結帳送出欄位 _pc_logistics_sub_type（單值，phase 1 classic）。
	 * 本方法不做白名單過濾（交由呼叫端統一把關）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 子類型字串（FAMI/UNIMART/HILIFE/HOME），無則空字串
	 */
	private static function resolve_chosen_sub_type( \WC_Order $order ): string {
		// 1. 選定的運送方式 item 的 sub_type meta（WC 由 rate meta_data 搬入）
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (self::METHOD_ID !== $shipping_item->get_method_id()) {
				continue;
			}
			$sub_type = (string) $shipping_item->get_meta( 'sub_type' );
			if ('' !== $sub_type) {
				return $sub_type;
			}
		}

		// 2. 退化相容：舊版結帳送出欄位（單值）
		return isset($_POST['_pc_logistics_sub_type']) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		? \sanitize_text_field( \wp_unslash( (string) $_POST['_pc_logistics_sub_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		: '';
	}

	/**
	 * 訂單是否選用本物流運送方式
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return bool
	 */
	private static function is_chosen( \WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (self::METHOD_ID === $shipping_item->get_method_id()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 傳統結帳（classic checkout）：enqueue 選店按鈕腳本 + localize（cart 級選店）
	 *
	 * 僅在「傳統結帳頁」（非 block）載入；block 結帳由 BlocksLogisticsIntegration 處理。
	 * 由 wp_enqueue_scripts hook 觸發（見 ProviderRegister）。
	 *
	 * @return void
	 */
	public static function enqueue_classic_assets(): void {
		if (!self::is_classic_checkout()) {
			return;
		}

		$handle = 'pc-classic-logistics';
		\wp_enqueue_script(
			$handle,
			\J7\PowerCheckout\Plugin::$url . '/inc/assets/js/classic-logistics.js',
			[ 'jquery' ],
			\J7\PowerCheckout\Plugin::$version,
			true
		);

		$selected_store = \J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession::get_selected_store();

		\wp_localize_script(
			$handle,
			'pcClassicLogistics',
			[
				'store_selection_url' => \site_url( 'wp-json/power-checkout/v1/logistics/store-selection', 'https' ),
				'nonce'               => \wp_create_nonce( 'wp_rest' ),
				'selected_store'      => $selected_store,
				'cvs_sub_types'       => [ LogisticsSubType::FAMI->value, LogisticsSubType::UNIMART->value, LogisticsSubType::HILIFE->value ],
				'sub_type_field'      => '_pc_logistics_sub_type',
				'i18n_select'         => \__( '選擇門市', 'power_checkout' ),
				'i18n_reselect'       => \__( '重新選擇門市', 'power_checkout' ),
				'i18n_loading'        => \__( '處理中…', 'power_checkout' ),
				'i18n_error'          => \__( '無法開啟門市選擇頁，請稍後再試或聯繫商家。', 'power_checkout' ),
			]
		);
	}

	/**
	 * 是否為傳統結帳頁（非 block）
	 *
	 * @return bool
	 */
	private static function is_classic_checkout(): bool {
		if (!\function_exists( 'is_checkout' ) || !\is_checkout()) {
			return false;
		}
		// block 結帳頁含 woocommerce/checkout 區塊 → 交由 BlocksLogisticsIntegration 處理
		if (\function_exists( 'has_block' )) {
			$post = \get_post();
			if ($post instanceof \WP_Post && \has_block( 'woocommerce/checkout', $post )) {
				return false;
			}
		}
		return true;
	}
}
