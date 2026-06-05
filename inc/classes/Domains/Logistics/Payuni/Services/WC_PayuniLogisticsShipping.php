<?php
/**
 * PAYUNi 統一金流物流 WC_Shipping_Method（classic 結帳運送方式）
 *
 * 鏡像 Logistics\Ecpay\Services\WC_EcpayLogisticsShipping：
 *  - 使 PAYUNi 7-ELEVEN 超商取貨 / 黑貓宅配出現在 classic 結帳「運送方式」清單。
 *  - 固定運費（後台可設 cost，預設 0）。
 *  - 結帳頁寫 _pc_logistics_sub_type / _pc_logistics_payment_scenario 到 order meta。
 *
 * enabled_methods（PayuniLogisticsSettingsDTO）決定結帳頁可選的物流子類型（SEVEN / HOME），
 * 透過 {@see self::get_supported_sub_types()} 取得（settings.enabled_methods 子集）。
 *
 * @see .claude/skills/payuni-logistics-v3/references/cvs-apis.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Services;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniSubType;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;

/** PAYUNi 統一金流物流運送方式（classic checkout） */
final class WC_PayuniLogisticsShipping extends \WC_Shipping_Method {

	/** @var string 運送方式 id（WooCommerce shipping method 識別碼） */
	public const METHOD_ID = 'payuni_logistics';

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
		$this->method_title       = \__( 'PAYUNi 統一金流物流', 'power_checkout' );
		$this->method_description = \__( 'PAYUNi 統一金流物流，支援 7-ELEVEN 超商取貨與黑貓宅配，可代收貨款（COD）。', 'power_checkout' );

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

		$this->title   = (string) $this->get_option( 'title', \__( 'PAYUNi 7-11 超商取貨 / 黑貓宅配', 'power_checkout' ) );
		$this->enabled = (string) $this->get_option( 'enabled', 'yes' );
		$this->cost    = (string) $this->get_option( 'cost', $this->cost );

		\add_action(
			'woocommerce_update_options_shipping_' . $this->id,
			[ $this, 'process_admin_options' ]
		);
	}

	/**
	 * 後台運送方式設定欄位（啟用 / 標題 / 固定運費）
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'enabled' => [
				'title'   => \__( '啟用', 'power_checkout' ),
				'type'    => 'checkbox',
				'label'   => \__( '啟用 PAYUNi 統一金流物流運送方式', 'power_checkout' ),
				'default' => 'yes',
			],
			'title'   => [
				'title'       => \__( '名稱', 'power_checkout' ),
				'type'        => 'text',
				'description' => \__( '結帳頁顯示的運送方式名稱。', 'power_checkout' ),
				'default'     => \__( 'PAYUNi 7-11 超商取貨 / 黑貓宅配', 'power_checkout' ),
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
	 * 計算運費（回固定運費）
	 *
	 * @param array<string, mixed> $package 購物車運送 package
	 *
	 * @return void
	 */
	public function calculate_shipping( $package = [] ): void {
		$cost = (float) $this->cost;

		$this->add_rate(
			[
				'id'      => $this->get_rate_id(),
				'label'   => $this->title ?: $this->method_title,
				'cost'    => $cost,
				'package' => $package,
			]
		);
	}

	/**
	 * 取得結帳頁可選的物流子類型（settings.enabled_methods 子集，僅合法子類型）
	 *
	 * @return array<int, string>
	 */
	public function get_supported_sub_types(): array {
		$settings = PayuniLogisticsSettingsDTO::instance();
		$methods  = $settings->enabled_methods;

		$valid = \array_map(
			static fn( PayuniSubType $sub_type ): string => $sub_type->value,
			PayuniSubType::cases()
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
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data 結帳送出資料
	 *
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		if (!self::is_chosen( $order )) {
			return;
		}

		$meta = new LogisticsMetaKeys( $order );

		$sub_type = isset($_POST['_pc_logistics_sub_type']) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		? \sanitize_text_field( \wp_unslash( (string) $_POST['_pc_logistics_sub_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
		: '';
		// 安全：僅寫入已啟用的合法子類型（白名單）
		$method = new self();
		if ('' !== $sub_type && \in_array( $sub_type, $method->get_supported_sub_types(), true )) {
			$meta->update_sub_type( $sub_type );
		}

		// 付款情境：COD → cod，其餘 → online
		$is_cod   = 'cod' === $order->get_payment_method();
		$scenario = $is_cod ? LogisticsPaymentScenario::COD->value : LogisticsPaymentScenario::ONLINE->value;
		$meta->update_payment_scenario( $scenario );

		$meta->update_provider_id( PayuniLogisticsProvider::ID );
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
}
