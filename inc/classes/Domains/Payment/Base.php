<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\Payment;

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

	/** @var array 付款方式表單欄位 */
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

		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->expire_date = (int) $this->get_option( 'expire_date', 3 ); // 預設為3天
		$this->min_amount  = (int) $this->get_option( 'min_amount', 0 );
		$this->max_amount  = (int) $this->get_option( 'max_amount', 0 );

		// 在結帳頁顯示欄位
		\add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_after_billing_address' ] );

		// 在感謝頁 receipt 渲染例如 ATM 顯示虛擬帳號
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
	 *
	 * 會把 field schema 的值存入 option
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 *
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {
		return parent::process_admin_options();
	}

	/** 在結帳頁顯示欄位 */
	public function render_after_billing_address( \WC_Order $order ): void {
	}

	/** 在感謝頁 receipt 渲染例如 ATM 顯示虛擬帳號 */
	public function render_at_receipt( int $order_id ): void {
	}
}
