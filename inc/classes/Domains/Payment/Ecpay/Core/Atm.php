<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Core;

use J7\PowerPayment\Domains\Payment\Base;

/** Bootstrap */
final class Atm extends Base {

	/** Constructor */
	public function __construct() {
		$this->id                 = 'pp_ecpay_atm';
		$this->has_fields         = false;
		$this->order_button_text  = __( 'Pay via ATM', 'power_payment' );
		$this->method_title       = __( 'ECPay ATM', 'power_payment' );
		$this->method_description = '';
		$this->icon               = 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQMTjo4Y8SMNcXz0ZSm5Bg92fqHYYTICRTwPw&s';
		$this->form_fields        = [];

		parent::__construct();
	}

	/**
	 * 處理付款
	 *
	 * @see WC_Payment_Gateway::process_payment
	 * @param int $order_id 訂單 ID
	 * @return array{result?: string, redirect?: string}
	 *
	 * @example
	 * return [
	 *     'result'   => 'success',
	 *     'redirect' => $order->get_checkout_payment_url( true ),
	 * ];
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		$order->add_order_note( __( 'Pay via ECPay ATM', 'ry-woocommerce-tools' ) );
		wc_maybe_reduce_stock_levels( $order_id );
		wc_release_stock_for_order( $order );

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}
}
