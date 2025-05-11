<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Core;

use J7\PowerPayment\Domains\Payment\Ecpay\Abstracts\PaymentGateway;

/** Atm */
final class Atm extends PaymentGateway {

	/** @var string 付款方式 ID */
	public $id = 'pp_ecpay_atm';

	/** @var string 付款方式標題  (自訂，用來顯示) */
	public string $payment_label = 'ECPay ATM';

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) ChoosePayment 參數 */
	public string $payment_type = 'ATM';

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

	/** TODO
	 * [Admin] 在後台 order detail 頁地址下方顯示資訊
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
