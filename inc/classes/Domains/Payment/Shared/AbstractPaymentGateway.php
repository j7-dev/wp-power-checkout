<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared;

use J7\PowerCheckout\Domains\WC_Settings_API\Model\FormField;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;
use J7\WpUtils\Classes\WP;

/** 付款閘道抽象類別 */
abstract class AbstractPaymentGateway extends \WC_Payment_Gateway {

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) */
	public string $payment_type = '';

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
	public $description = '';

	/** @var string 前台顯示付款方式按鈕文字 */
	public $order_button_text;

	/** @var int 付款截止日(天)，通常 ATM / CVS / BARCODE 才有 */
	public int $expire_date = 3;

	/** @var int 付款方式最小金額 */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額 */
	public $max_amount;

	/** @var \WC_Order|null 訂單 */
	public \WC_Order|null $order = null;

	/** @var \WP_Error 錯誤訊息 */
	public \WP_Error $error;

	/** @var array<string> 必須設定的屬性 */
	private array $require_properties = [
		'id',
		'icon',
		'payment_label',
	];

	/** Constructor */
	public function __construct() {
		$this->error             = new \WP_Error();
		$this->title             = $this->payment_label;
		$this->method_title      = $this->payment_label;
		$this->order_button_text = sprintf( __( 'Pay via %s', 'woocommerce' ), $this->payment_label );

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

		$this->validate_properties();

		\add_action('shutdown', [ $this, 'print_error' ]);
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
	 * @return array{result: ProcessResult::SUCCESS | ProcessResult::FAILED, redirect?: string}
	 * @throws \Exception 如果訂單不存在
	 */
	public function process_payment( $order_id ): array {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( __( 'Order not found.', 'power_checkout' ) );
		}
		$this->before_process_payment( $order );
		$order->add_order_note( \sprintf( __( 'Pay via %s', 'power_checkout' ), $this->method_title ) );
		\wc_maybe_reduce_stock_levels( $order_id );
		\wc_release_stock_for_order( $order );
		$redirect = $order->get_checkout_payment_url( true );
		return ProcessResult::SUCCESS->to_array( $redirect );
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
			$this->logger( $th->getMessage(), 'error', [], 5 );
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
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message 訊息
	 * @param string               $level 等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args 附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 */
	public function logger( string $message, string $level = 'debug', array $args = [], $trace_limit = 0 ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, "power_checkout_{$this->id}", $trace_limit );
		if ( $this->order && in_array( $level, [ 'info', 'error', 'warning' ], true ) ) {
			$order_note = WP::array_to_html( $args, [ 'title' => $message ] );
			$this->order->add_order_note( $order_note );
		}
	}

	/** 每次請求結束時如果有錯誤就印出錯誤訊息 */
	public function print_error(): void {
		if ( !$this->error->has_errors() ) {
			return;
		}

		$error_messages = $this->error->get_error_messages();
		if ( ! $error_messages ) {
			return;
		}
		$this->logger( $error_messages[0], 'critical', [ 'messages' => $error_messages ], 5 );
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

	/**
	 * 驗證必須設定的屬性
	 *
	 * @throws \Exception 如果屬性未設定
	 *  */
	private function validate_properties(): void {
		foreach ( $this->require_properties as $property ) {
			if ( ! isset( $this->$property ) ) {
				throw new \Exception(  static::class . "必須設定 {$property} 屬性" );
			}
		}
	}
}
