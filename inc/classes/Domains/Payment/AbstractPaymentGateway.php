<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment;

use J7\PowerCheckout\Domains\WC_Settings_API\Model\FormField;

/** 付款閘道抽象類別 */
abstract class AbstractPaymentGateway extends \WC_Payment_Gateway {

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) */
	public string $payment_type;

	/** @var string 付款方式標題  (自訂，用來顯示) */
	public string $payment_label;

	/** @var string 付款方式 ID */
	public $id;

	/** @var string 付款方式 icon */
	public $icon;

	/** @var bool 是否再結帳頁顯示自訂欄位 */
	public $has_fields = false;

	/** @var string 後台顯示付款方式標題 */
	public $method_title;

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '';

	/** @var array<string, array<string, mixed>> 付款方式表單欄位 */
	public $form_fields = [];

	/** @var string 前台顯示付款方式標題 */
	public $title;

	/** @var string 前台顯示付款方式描述 */
	public $description;

	/** @var int 付款截止日(天)，通常 ATM / CVS / BARCODE 才有 */
	public int $expire_date = 3;

	/** @var int 付款方式最小金額 */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額 */
	public $max_amount;

	/** Constructor */
	public function __construct() {

		$this->payment_label     = $this->set_label();
		$this->method_title      = sprintf( __( '%s - Power Checkout', 'power_checkout' ), $this->payment_label );
		$this->order_button_text = sprintf( __( 'Pay via %s', 'power_checkout' ), $this->payment_label );

		$default_form_fields = [
			'enabled'     => [
				'title'   => __( 'Enable/Disable', 'woocommerce' ),
				/* translators: %s: Gateway method title */
				'label'   => sprintf( __( 'Enable %s', 'power_checkout' ), $this->method_title ),
				'type'    => 'checkbox',
				'default' => 'no',
			],
			'title'       => [
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'default'     => $this->title,
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
				'title'             => __( 'Minimum order amount', 'power_checkout' ),
				'type'              => 'decimal',
				'default'           => 5,
				'custom_attributes' => [
					'min'  => 5,
					'step' => 1,
				],
			],
			'max_amount'  => [
				'title'             => __( 'Maximum order amount', 'power_checkout' ),
				'type'              => 'decimal',
				'default'           => 0,
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			'expire_date' => [
				'title'             => __( 'Payment deadline', 'power_checkout' ),
				'type'              => 'decimal',
				'default'           => 3,
				'placeholder'       => 3,
				'description'       => __( 'ATM allowable payment deadline from 1 day to 60 days.', 'power_checkout' ),
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 60,
					'step' => 1,
				],
			],
		];

		// phpstan-ignore-next-line
		$this->form_fields = $this->filter_fields( $default_form_fields );
		$strict            = \wp_get_environment_type() === 'local';
		FormField::parse_array( $this->form_fields, $strict );
		$this->init_settings();
		$this->title       = $this->get_option( 'title' ) ?: $this->payment_label;
		$this->description = $this->get_option( 'description' );
		$this->expire_date = (int) $this->get_option( 'expire_date', 3 ); // 預設為3天
		$this->min_amount  = (int) $this->get_option( 'min_amount', 0 );
		$this->max_amount  = (int) $this->get_option( 'max_amount', 0 );

		// 儲存欄位
		\add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );

		// [Admin] 在後台 order detail 頁地址下方顯示資訊
		\add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_after_billing_address' ] );

		if ( $this->enabled ) {
			// 在 /checkout/order-pay/ 頁渲染 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
			\add_action( "woocommerce_receipt_{$this->id}", [ $this, 'render_at_receipt' ] );
		}
	}

	/** 取得付款方式標題 @return string */
	public function set_label(): string {
		return '';
	}

	/**
	 * 過濾表單欄位
	 *
	 * @param array<string, mixed> $fields 表單欄位
	 * @return array<string, mixed> 過濾後的表單欄位
	 * */
	public function filter_fields( array $fields ): array {
		return $fields;
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
		if ($total <= 0 ) {
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
	 * 處理付款
	 *
	 * @see WC_Payment_Gateway::process_payment
	 * @param int $order_id 訂單 ID
	 * @return array{result: 'success' | 'failure', redirect?: string}
	 *
	 * @example
	 * [success]
	 * return [
	 *     'result'   => 'success',
	 *     'redirect' => $order->get_checkout_payment_url( true ),
	 * ];
	 *
	 * $order->get_checkout_order_received_url() // 正常的感謝頁
	 *
	 * \wc_get_endpoint_url( 'order-received', '', wc_get_checkout_url() )
	 * /checkout/order-received/ 謝謝，我們已經收到您的訂單。
	 *
	 * $order->get_checkout_payment_url( true ) // 小小的結帳視窗
	 * /checkout/order-pay/2801/?key=wc_order_GrFD9faIj520O
	 *
	 * [failure]
	 * 搭配 wc_add_notice 來顯示錯誤訊息
	 * \wc_add_notice( 'error message', 'error' );
	 * return [
	 *     'result'   => 'failure',
	 * ];
	 */
	public function process_payment( $order_id ): array {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			\wc_add_notice( __( 'Order not found.', 'power_checkout' ), 'error' );
			return [
				'result' => 'failure',
			];
		}
		$this->before_process_payment( $order );
		$order->add_order_note( \sprintf( __( 'Pay via %s', 'power_checkout' ), $this->method_title ) );
		\wc_maybe_reduce_stock_levels( $order_id );
		\wc_release_stock_for_order( $order );

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ), // 前往 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
		];
	}

	/** @param \WC_Order $order 訂單 在 process_payment 之前執行 */
	protected function before_process_payment( \WC_Order $order ): void {
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

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
	}

	/**
	 * [前台] 在 /checkout/order-pay/ 頁渲染 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
	 * RY 在這邊做表單提交
	 * */
	public function render_at_receipt( int $order_id ): void {
		try {

			if (! $this->can_use( $order_id )) {
				return;
			}

			$order = \wc_get_order( $order_id );
			/** @var \WC_Order $order */
			$this->submit( $order );
		} catch (\Throwable $th) {
			$this->log( $th->getMessage(), '', 'error' );
			return;
		}
	}

	/**
	 * 不同的 gateway 會有不同的自訂 request params
	 *
	 * @return array<string, mixed>
	 */
	public function extra_request_params(): array {
		return [];
	}

	/** [後台]顯示錯誤訊息，改用 WC_Admin_Settings */
	public function display_errors(): void {
		if ( $this->errors ) {
			foreach ( $this->errors as $error ) {
				\WC_Admin_Settings::add_error( $error );
			}
		}
	}

	/**
	 * 記錄 log
	 *
	 * @param mixed  $message 訊息
	 * @param string $title 標題
	 * @param string $level 等級 info | error | alert | critical | debug | emergency | warning | notice
	 */
	public function log( mixed $message, string $title = '', string $level = 'info' ): void {

		$trace     = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5); // 只看5層
		$functions = [];
		foreach ( $trace as $t ) {
			$line        = $t['line'] ?? 'N/A';
			$functions[] = "{$t['function']} #L:{$line}";
		}

		\J7\WpUtils\Classes\WC::log(
			$message,
			$title,
			$level,
			[
				'source' => "{$this->id}__{$level}",
				'trace'  => $functions,
			]
			);
	}

	/**
	 * 提交表單
	 * 例如綠界需透過前端網頁導轉(Submit)到綠界付款API網址
	 *
	 * @see https://developers.ecpay.com.tw/?p=2872
	 * @param \WC_Order $order 訂單
	 */
	protected function submit( \WC_Order $order ): void {
	}

	/**
	 * 驗證訂單是不是使用此付款方式
	 * 通常用在 hook callback 中
	 *
	 * @param \WC_Order|int|string $order_or_id 訂單或訂單 ID
	 * @return bool
	 * @throws \Exception 如果訂單不是實例或不是實例的訂單
	 */
	protected function can_use( \WC_Order|int|string $order_or_id ): bool {
		if ( is_numeric($order_or_id)) {
			$order_or_id = \wc_get_order( $order_or_id );
		}

		if ( ! $order_or_id instanceof \WC_Order ) {
			throw new \Exception( "#{$order_or_id} is not instance of WC_Order" );
		}

		if ( $order_or_id->get_payment_method() !== $this->id ) {
			return false;
		}

		return true;
	}
}
