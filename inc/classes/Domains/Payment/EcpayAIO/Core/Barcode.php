<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Core;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Abstracts\PaymentGateway;

/** Barcode */
final class Barcode extends PaymentGateway {

	/** @var string 付款方式 ID */
	public $id = 'pc_ecpayaio_barcode';

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) ChoosePayment 參數 */
	public string $payment_type = 'BARCODE';

	/**
	 * 過濾表單欄位
	 *
	 * @param array<string, mixed> $fields 表單欄位
	 * @return array<string, mixed> 過濾後的表單欄位
	 * */
	public function filter_fields( array $fields ): array {
		$fields['expire_date'] = [
			'title'             => __( 'Payment deadline', 'power_checkout' ),
			'type'              => 'decimal',
			'default'           => 7,
			'placeholder'       => 7,
			'description'       => __( 'Barcode allowable payment deadline from 1 day to 60 days.', 'power_checkout' ),
			'custom_attributes' => [
				'min'  => 1,
				'max'  => 30,
				'step' => 1,
			],
		];
		return $fields;
	}

	/** 取得付款方式標題 @return string */
	public function set_label(): string {
		return __( 'ECPay Barcode', 'power_checkout' );
	}

	/**
	 * 不同的 gateway 會有不同的自訂 request params
	 *
	 * @return array<string, mixed>
	 */
	public function extra_request_params(): array {
		return [
			'StoreExpireDate' => $this->expire_date,
		];
	}

	/**TODO
	 * 應該是要作取號後，馬上跳轉感謝頁，不過應該不用這麼繞才對
	 * Get the return url (thank you page).
	 *
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function get_return_url( $order = null ) {
		$return_url = WC()->api_request_url('ry_ecpay_gateway_return');
		if ($order) {
			$return_url = add_query_arg('id', $order->get_id(), $return_url);
			$return_url = add_query_arg('key', $order->get_order_key(), $return_url);
		}

		return $return_url;
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
		$order->add_order_note( __( 'Pay via ECPay ATM', 'power_checkout' ) );
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
	 * 個人/商務賣家：訂單金額需介於 31元(含)~6,000元(含)，方可建立訂單。
	 * 特約賣家：訂單金額需介於31元(含)~20,000元(含)，方可建立訂單。
	 *
	 * @see https://support.ecpay.com.tw/4804/
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {

		// 取得 $_POST 的指定欄位 name
		$expire_date_name = $this->get_field_key( 'expire_date' );
		$min_amount_name  = $this->get_field_key( 'min_amount' );
		$max_amount_name  = $this->get_field_key( 'max_amount' );

		// 解構，不存在就會是 null
		@[
			$expire_date_name => $expire_date,
			$min_amount_name  => $min_amount,
			$max_amount_name  => $max_amount,
		] = $this->get_post_data();

		$expire_date = (int) $expire_date;
		$min_amount  = (float) $min_amount;
		$max_amount  = (float) $max_amount;

		if ( $expire_date < 1 || $expire_date > 30 ) {
			$this->errors[] = __( 'Save failed. Barcode payment deadline out of range.', 'power_checkout' );
		}

		if ($min_amount < 31 ) {
			$this->errors[] = sprintf( __( 'Save failed. %s minimum amount out of range.', 'power_checkout' ), $this->method_title );
		}

		if ( $max_amount > 20000 ) {
			$this->errors[] = sprintf( __( 'Save failed. %s maximum amount out of range.', 'power_checkout' ), $this->method_title );
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}

	/** TODO
	 * [Admin] 在後台 order detail 頁地址下方顯示資訊
	 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		?>
<h3 style="clear:both"><?php echo __( 'Payment details', 'ry-woocommerce-tools' ); ?>
</h3>
<table>
	<tr>
		<td><?php echo __( 'Barcode 1', 'ry-woocommerce-tools' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_barcode_Barcode1' ); ?>
		</td>
	</tr>
	<tr>
		<td><?php echo __( 'Barcode 2', 'ry-woocommerce-tools' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_barcode_Barcode2' ); ?>
		</td>
	</tr>
	<tr>
		<td><?php echo __( 'Barcode 3', 'ry-woocommerce-tools' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_barcode_Barcode3' ); ?>
		</td>
	</tr>
	<tr>
		<td><?php echo __( 'Payment deadline', 'ry-woocommerce-tools' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_barcode_ExpireDate' ); ?>
		</td>
	</tr>
</table>
		<?php
	}
}
