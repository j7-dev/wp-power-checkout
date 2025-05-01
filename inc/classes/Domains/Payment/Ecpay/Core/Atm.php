<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Core;

use J7\PowerPayment\Domains\Payment\Abstract_Payment_Gateway;
use J7\PowerPayment\Utils\Base;
use J7\PowerPayment\Plugin;

/** Atm */
final class Atm extends Abstract_Payment_Gateway {

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) */
	public $payment_type = 'ATM';

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
			\wc_add_notice( __( 'Order not found.', 'power_payment' ), 'error' );
			return [
				'result' => 'failure',
			];
		}
		$order->add_order_note( __( 'Pay via ECPay ATM', 'power_payment' ) );
		\wc_maybe_reduce_stock_levels( $order_id );
		\wc_release_stock_for_order( $order );

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ), // 前往 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
		];
	}

	/**
	 * [後台] 自訂欄位驗證邏輯
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 *
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {

		// 取得 $_POST 的指定欄位 name
		$expire_date_name = $this->get_field_key( 'expire_date' );
		$min_amount_name  = $this->get_field_key( 'min_amount' );

		// 解構，不存在就會是 null
		@[
			$expire_date_name => $expire_date,
			$min_amount_name  => $min_amount,
		] = $this->get_post_data();

		$expire_date = (int) $expire_date;
		$min_amount  = (float) $min_amount;

		if ( $expire_date < 1 || $expire_date > 60 ) {
			$this->errors[] = __( 'Save failed. ATM payment deadline out of range.', 'power_payment' );
		}

		if ( $min_amount > 0 && $min_amount < 5 ) {
			$this->errors[] = sprintf( __( 'Save failed. %s minimum amount out of range.', 'power_payment' ), $this->method_title );
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}

	/**
	 * 提交表單
	 * 需透過前端網頁導轉(Submit)到綠界付款API網址
	 *
	 * @see https://developers.ecpay.com.tw/?p=2872
	 * @param \WC_Order $order 訂單
	 */
	protected function submit( \WC_Order $order ): void {
		$service = Service::instance();
		/** @var \WC_Order $order */
		$params = $service->get_params( $order, $this );

		Plugin::load_template(
				'auto-form',
				[
					'params' => $params,
					'url'    => $service->aio_checkout_endpoint,
				]
				);

		// DELETE ? 送出前就清除購物車了?
		\WC()->cart->empty_cart();
	}

	/**
	 * TODO
	 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		?>
<h3 style="clear:both"><?php echo __( 'Payment details', 'power_payment' ); ?>
</h3>
<table>
	<tr>
		<td><?php echo __( 'Bank', 'power_payment' ); ?>
		</td>
		<td><?php echo _x( $order->get_meta( '_ecpay_atm_BankCode' ), 'Bank code', 'power_payment' ); ?> (<?php echo $order->get_meta( '_ecpay_atm_BankCode' ); ?>)</td>
	</tr>
	<tr>
		<td><?php echo __( 'ATM Bank account', 'power_payment' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_atm_vAccount' ); ?>
		</td>
	</tr>
	<tr>
		<td><?php echo __( 'Payment deadline', 'power_payment' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_atm_ExpireDate' ); ?>
		</td>
	</tr>
</table>
		<?php
	}
}
