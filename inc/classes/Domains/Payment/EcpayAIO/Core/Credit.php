<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Core;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Abstracts\PaymentGateway;

/** Credit */
class Credit extends PaymentGateway {
	/** @var string 付款方式 ID */
	const ID = Init::PREFIX . 'credit';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) ChoosePayment 參數 */
	public string $payment_type = 'Credit';

	/** Constructor */
	public function __construct() {
		$this->payment_label = __( 'ECPayAIO Credit', 'power_checkout' );
		parent::__construct();
	}

	/**
	 * [後台] 自訂欄位驗證邏輯
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 * 信用卡手續費最低收取金額(含)* ~ 199,999元(含)
	 *
	 * @see https://support.ecpay.com.tw/4804/
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {

		// 取得 $_POST 的指定欄位 name
		$min_amount_name = $this->get_field_key( 'min_amount' );
		$max_amount_name = $this->get_field_key( 'max_amount' );

		// 解構，不存在就會是 null
		@[
			$min_amount_name  => $min_amount,
			$max_amount_name  => $max_amount,
		] = $this->get_post_data();

		$min_amount = (float) $min_amount;
		$max_amount = (float) $max_amount;

		if ( $min_amount < 5 ) {
			$this->errors[] = sprintf( __( 'Save failed. %s minimum amount out of range.', 'power_checkout' ), $this->method_title );
		}

		if ( $max_amount > 199999 ) {
			$this->errors[] = sprintf( __( 'Save failed. %s maximum amount out of range.', 'power_checkout' ), $this->method_title );
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}


	/**
	 * [Admin] 在後台 order detail 頁地址下方顯示資訊
	 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}
		?>
<h3 style="clear:both"><?php echo __( 'Payment details', 'power_checkout' ); ?>
</h3>
TODO
		<?php
	}
}
