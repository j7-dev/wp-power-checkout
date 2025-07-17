<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Core;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Abstracts\PaymentGateway;

/** CVS 超商取貨 */
final class CVS extends PaymentGateway {

	/** @var string 付款方式 ID */
	public $id = 'pc_ecpayaio_cvs';

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) ChoosePayment 參數 */
	public string $payment_type = 'CVS';

	/** Constructor */
	public function __construct() {
		$this->payment_label = __( 'ECPayAIO CVS', 'power_checkout' );
		parent::__construct();
	}

	/**
	 * 過濾表單欄位
	 *
	 * @see https://developers.ecpay.com.tw/?p=2874
	 * @param array<string, mixed> $fields 表單欄位
	 * @return array<string, mixed> 過濾後的表單欄位
	 * */
	public function filter_fields( array $fields ): array {
		$fields['expire_date'] = [
			'title'             => __( 'Payment deadline (minutes)', 'power_checkout' ),
			'type'              => 'decimal',
			'default'           => 10080,
			'placeholder'       => 10080,
			'description'       => __( 'CVS allowable payment deadline from 1 minute to 60 days.', 'power_checkout' ),
			'custom_attributes' => [
				'min'  => 1,
				'max'  => 43200,
				'step' => 1,
			],
		];
		return $fields;
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

	/**
	 * [後台] 自訂欄位驗證邏輯
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 * ATM手續費最低收取金額*+1元」(含)~49,999元(含)
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

		if ( $expire_date < 1 || $expire_date > 60 ) {
			$this->errors[] = __( 'Save failed. ATM payment deadline out of range.', 'power_checkout' );
		}

		if ( $min_amount < 5 ) {
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
<h3 style="clear:both"><?php echo __( 'Payment details', 'power_checkout' ); ?>
</h3>
<table>
	<tr>
		<td><?php echo __( 'Bank', 'power_checkout' ); ?>
		</td>
		<td><?php echo _x( $order->get_meta( '_ecpay_atm_BankCode' ), 'Bank code', 'power_checkout' ); ?> (<?php echo $order->get_meta( '_ecpay_atm_BankCode' ); ?>)</td>
	</tr>
	<tr>
		<td><?php echo __( 'ATM Bank account', 'power_checkout' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_atm_vAccount' ); ?>
		</td>
	</tr>
	<tr>
		<td><?php echo __( 'Payment deadline', 'power_checkout' ); ?>
		</td>
		<td><?php echo $order->get_meta( '_ecpay_atm_ExpireDate' ); ?>
		</td>
	</tr>
</table>
		<?php
	}
}
