<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\Payment;

use J7\PowerPayment\Domains\WC_Settings_API\Model\FormField;

/** Base */
abstract class Base extends \WC_Payment_Gateway {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 付款方式 ID */
	public $id;

	/** @var string 付款方式 icon */
	public $icon;

	/** @var bool 是否再結帳頁顯示自訂欄位 */
	public $has_fields;

	/** @var string 後台顯示付款方式標題 */
	public $method_title;

	/** @var string 後台顯示付款方式描述 */
	public $method_description;

	/** @var array<string, array<string, mixed>> 付款方式表單欄位 */
	public $form_fields;

	/** @var string 前台顯示付款方式標題 */
	public $title;

	/** @var string 前台顯示付款方式描述 */
	public $description;

	/** @var int 付款截止日(天)，通常 ATM 才有 */
	public int $expire_date = 3;

	/** @var int 付款方式最小金額 */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額 */
	public $max_amount;

	/** Constructor */
	public function __construct() {

		$this->form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				/* translators: %s: Gateway method title */
				'label'   => sprintf( __( 'Enable %s', 'power_payment' ), $this->method_title ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'default'     => $this->method_title,
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'woocommerce' ),
				'type'        => 'text',
				'default'     => $this->order_button_text,
				'desc_tip'    => true,
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
			],
			'min_amount'  => [
				'title'             => __( 'Minimum order amount', 'power_payment' ),
				'type'              => 'number',
				'default'           => 0,
				'placeholder'       => 0,
				'description'       => __( '0 to disable minimum amount limit.', 'power_payment' ),
				'custom_attributes' => [
					'min'  => 5,
					'step' => 1,
				],
			],
			'max_amount'  => [
				'title'             => __( 'Maximum order amount', 'power_payment' ),
				'type'              => 'number',
				'default'           => 0,
				'placeholder'       => 0,
				'description'       => __( '0 to disable maximum amount limit.', 'power_payment' ),
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			'expire_date' => [
				'title'             => __( 'Payment deadline', 'ry-woocommerce-tools' ),
				'type'              => 'number',
				'default'           => 3,
				'placeholder'       => 3,
				'description'       => __( 'ATM allowable payment deadline from 1 day to 60 days.', 'ry-woocommerce-tools' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				],
			],
		];

		$strict = wp_get_environment_type() === 'local';
		FormField::parse_array( $this->form_fields, $strict );

		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->expire_date = (int) $this->get_option( 'expire_date', 3 ); // 預設為3天
		$this->min_amount  = (int) $this->get_option( 'min_amount', 0 );
		$this->max_amount  = (int) $this->get_option( 'max_amount', 0 );

		// 儲存欄位
		\add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		// 在結帳頁顯示欄位
		\add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_after_billing_address' ] );

		// 在 /checkout/order-pay/ 頁渲染 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
		\add_action( "woocommerce_receipt_{$this->id}", [ $this, 'render_at_receipt' ] );
	}

	/**
	 * 是否可用
	 * 基於原本 WC_Payment_Gateway 的 is_available 方法，增加最小金額限制
	 *
	 * @return bool
	 */
	public function is_available() {
		$is_available = ( 'yes' === $this->enabled );
		if ( ! $is_available ) {
			return false;
		}

		$total = $this->get_order_total();
		// phpstan-ignore-next-line
		if ( ! \WC()->cart || $total <= 0 ) {
			return false;
		}

		if ( $this->min_amount > 0 && $total < $this->min_amount ) {
			return false;
		}

		if ( $this->max_amount > 0 && $total > $this->max_amount ) {
			return false;
		}

		return $is_available;
	}

	/**
	 * [後台] 欄位儲存 field schema 的值存入 option，可以自訂驗證邏輯
	 * 可以用
	 * \WC_Admin_Settings::add_error
	 * 或
	 * $this->errors[] = 'error_message';
	 * $this->display_errors();
	 * 來顯示錯誤訊息
	 *
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {
		return parent::process_admin_options();
	}

	/** [後台]在結帳頁顯示欄位 */
	public function render_after_billing_address( \WC_Order $order ): void {
	}

	/**
	 * [前台] 在 /checkout/order-pay/ 頁渲染 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
	 * RY 在這邊做表單提交
	 * */
	public function render_at_receipt( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		// TODO
		var_dump( 'adg0s54g0s4g' );
		// RY 在這邊做跳轉前端表單提交
		// RY_ECPay_Gateway_Api::checkout_form( $order, $this );
		\WC()->cart->empty_cart();
	}

	/** [後台]顯示錯誤訊息，改用 WC_Admin_Settings */
	public function display_errors(): void {
		if ( $this->errors ) {
			foreach ( $this->errors as $error ) {
				\WC_Admin_Settings::add_error( $error );
			}
		}
	}
}
