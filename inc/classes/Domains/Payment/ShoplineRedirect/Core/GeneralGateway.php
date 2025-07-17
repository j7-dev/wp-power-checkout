<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\PaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Service;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;

/**
 * GeneralGateway 跳轉支付
 * TODO Shopline payment 似乎是跳轉到 Shopline 的頁面才選擇支付方式，與綠界不同  確認後，再改成正確的備註
 * */
final class GeneralGateway extends PaymentGateway {

	/** @var string 付款方式 ID */
	public $id = 'pc_shoplinepayment_redirect';

	/** Constructor */
	public function __construct() {
		$this->payment_label = __( 'Shopline Payment (Redirect)', 'power_checkout' );
		parent::__construct();
	}

	/**
	 * Shopline 跳轉式支付核心支付邏輯
	 *
	 * @see \WC_Payment_Gateway::process_payment
	 * @param int $order_id 訂單 ID
	 * @return array{result: ProcessResult::SUCCESS | ProcessResult::FAILED, redirect?: string}
	 * @throws \Exception 如果訂單不存在
	 */
	public function process_payment( $order_id ): array {
		$order = \wc_get_order( $order_id );
		try {
			if ( ! $order instanceof \WC_Order ) {
				throw new \Exception( __( 'Order not found.', 'power_checkout' ) );
			}
			$this->order = $order;
			$service     = Service::instance( $this, $order );
			$service->create_trade();
			return ProcessResult::SUCCESS->to_array( $order );
		} catch (\Throwable $th) {
			\wc_add_notice( $th->getMessage(), 'error' );
			return ProcessResult::FAILED->to_array( $order );
		}
	}

	/**
	 * [後台] 自訂欄位驗證邏輯
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 * ATM手續費最低收取金額*+1元」(含)~49,999元(含)
	 * TODO 待處理
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
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

		if ( $max_amount > 50000 ) {
			$this->errors[] = sprintf( __( 'Save failed. %s maximum amount out of range.', 'power_checkout' ), $this->method_title );
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}

	/** TODO 待處理
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
