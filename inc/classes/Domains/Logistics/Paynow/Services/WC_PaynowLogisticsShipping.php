<?php
/**
 * PayNow（立吉富體系 1）物流 WC_Shipping_Method（classic 結帳運送方式）
 *
 * 鏡像 Logistics\Ecpay\Services\WC_EcpayLogisticsShipping，per-service 多運送方式：
 *  - 使 PayNow 7-11 / 全家 / 萊爾富超商取貨 + 黑貓宅配出現在 classic 結帳「運送方式」清單。
 *  - 每個 enabled service（SEVEN/FAMI/HILIFE/TCAT）各產一個 rate，「選哪個 rate」即決定 service。
 *  - 固定運費（後台可設 cost，預設 0；不做依重量 / 地區的級距運費）。
 *  - 結帳頁寫 _pc_paynow_logistics_service_id 到 order meta（白名單把關）。
 *
 * 設計差異（與 ECPay 鏡像）：
 *  - enabled_methods 儲存 PaynowLogisticService case 名稱（如 'SEVEN' / 'Fami'，大小寫不敏感），
 *    透過 {@see PaynowLogisticService::try_from_name()} 映射為 enum，rate meta 寫 enum value（01-06）。
 *  - is_chosen() / save_checkout_meta() 為 public static（callback / register 測試直呼）。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 3 步驟 10
 * @see inc/classes/Domains/Logistics/Ecpay/Services/WC_EcpayLogisticsShipping.php（鏡像）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Services;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;

/** PayNow 立吉富物流運送方式（classic checkout） */
final class WC_PaynowLogisticsShipping extends \WC_Shipping_Method {

	/** @var string 運送方式 id（WooCommerce shipping method 識別碼） */
	public const METHOD_ID = 'paynow_logistics';

	/** @var string 固定運費（後台可設，預設 0） */
	public string $cost = '0';

	/**
	 * Constructor
	 *
	 * @param int $instance_id Shipping zone instance id（0 = 全域）
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = self::METHOD_ID;
		$this->instance_id        = \absint( $instance_id );
		$this->method_title       = \__( 'PayNow 立吉富物流', 'power_checkout' );
		$this->method_description = \__( 'PayNow 立吉富物流，支援 7-11 / 全家 / 萊爾富超商取貨與黑貓宅配，可代收貨款（COD）。', 'power_checkout' );

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

		$this->title   = (string) $this->get_option( 'title', \__( 'PayNow 超商取貨 / 宅配', 'power_checkout' ) );
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
				'label'   => \__( '啟用 PayNow 立吉富物流運送方式', 'power_checkout' ),
				'default' => 'yes',
			],
			'title'   => [
				'title'       => \__( '名稱', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '結帳頁顯示的運送方式名稱。', 'power_checkout' ),
				'default'     => \__( 'PayNow 超商取貨 / 宅配', 'power_checkout' ),
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
	 * 計算運費（每個 enabled service 各產一個 rate）
	 *
	 * 第一性原理：WC 一個 shipping rate = 顧客可選的一個運送選項。物流啟用多個 service
	 * （SEVEN/FAMI/HILIFE/TCAT）時，須為每個 enabled service 各 add 一個 rate，使顧客
	 * 「選哪個 rate」即決定 service。每個 rate：
	 *   - rate_id 帶 service value 後綴（{@see get_rate_id()} → 形如 paynow_logistics:01），
	 *   - meta_data 帶 service_id（enum value，classic 下單時搬進 order item meta），
	 *   - label 用對應 service 中文標籤（7-11 店到店 / 全家 / 萊爾富 / 黑貓宅配）。
	 * 固定運費沿用後台設定 cost（不做依重量 / 地區的級距運費）。
	 *
	 * @param array<string, mixed> $package 購物車運送 package
	 *
	 * @return void
	 */
	public function calculate_shipping( $package = [] ): void {
		$cost = (float) $this->cost;
		$base = $this->title ?: $this->method_title;

		// 無 enabled service → 不產生任何 rate（顧客無可選的 PayNow 物流運送方式）
		foreach ( $this->get_supported_services() as $service ) {
			$this->add_rate(
				[
					// rate_id 帶 service value 後綴：顧客選定的 rate 即決定 service
					'id'        => $this->get_rate_id( $service->value ),
					'label'     => "{$base}（{$service->label()}）",
					'cost'      => $cost,
					'package'   => $package,
					// 帶 service_id meta（enum value）：classic 下單時搬進 order item meta
					'meta_data' => [ 'service_id' => $service->value ],
				]
			);
		}
	}

	/**
	 * 取得結帳頁可選的物流服務（settings.enabled_methods 子集，僅合法 service）
	 *
	 * 設定 enabled_methods 儲存 case 名稱（如 'SEVEN' / 'Fami'，大小寫不敏感），
	 * 經 {@see PaynowLogisticService::try_from_name()} 映射為 enum 物件，無對應者忽略。
	 *
	 * @return array<int, PaynowLogisticService>
	 */
	public function get_supported_services(): array {
		$settings = PaynowLogisticsSettingsDTO::instance();
		$methods  = $settings->enabled_methods;

		$supported = [];
		foreach ( $methods as $method ) {
			$service = PaynowLogisticService::try_from_name( (string) $method );
			if (null !== $service) {
				$supported[] = $service;
			}
		}

		return $supported;
	}

	/**
	 * Classic checkout：將結帳選擇的物流服務寫入 order meta（白名單把關）
	 *
	 * 由 woocommerce_checkout_create_order hook 觸發（見 ProviderRegister）。
	 * service_id 取得來源（第一性原理：「選定的 rate」即決定 service）：
	 *   - 選定的運送方式（order shipping item）的 service_id meta —— WC set_shipping_rate()
	 *     會把 rate 的 meta_data（含本外掛帶的 service_id）搬進 order item meta。
	 * 安全：僅寫入「合法 enum value 且在 enabled_methods（get_supported_services）」的 service_id，
	 * 杜絕注入任意值，並阻擋未啟用的合法 service。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data  結帳送出資料
	 *
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		// 僅在選用本物流運送方式時寫入
		if (!self::is_chosen( $order )) {
			return;
		}

		$service_id = self::resolve_chosen_service_id( $order );
		if ('' === $service_id) {
			return;
		}

		// 安全：僅寫入「合法 enum value 且已啟用（enabled_methods）」的 service_id，杜絕注入 / 未啟用 service
		$method         = new self();
		$enabled_values = \array_map(
			static fn( PaynowLogisticService $s ): string => $s->value,
			$method->get_supported_services()
		);
		if (!\in_array( $service_id, $enabled_values, true )) {
			return;
		}

		$meta = new PaynowLogisticsMetaKeys( $order );
		$meta->update_service_id( $service_id );
		$meta->update_provider_id( self::METHOD_ID );
	}

	/**
	 * 訂單是否選用本物流運送方式
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return bool
	 */
	public static function is_chosen( \WC_Order $order ): bool {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (self::METHOD_ID === $shipping_item->get_method_id()) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 從訂單取得「選定的 rate」對應的物流服務代碼（service_id meta）
	 *
	 * 來源：本物流運送方式 order shipping item 的 service_id meta（多 rate：選哪個 rate 決定 service）。
	 * 本方法不做白名單過濾（交由呼叫端統一把關）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string service_id（enum value，01-06），無則空字串
	 */
	private static function resolve_chosen_service_id( \WC_Order $order ): string {
		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			if (self::METHOD_ID !== $shipping_item->get_method_id()) {
				continue;
			}
			$service_id = (string) $shipping_item->get_meta( 'service_id' );
			if ('' !== $service_id) {
				return $service_id;
			}
		}
		return '';
	}
}
