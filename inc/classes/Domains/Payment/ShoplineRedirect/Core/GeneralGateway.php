<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Init;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\PaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Service\Service;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;

/**
 * GeneralGateway 跳轉支付
 * TODO Shopline payment 似乎是跳轉到 Shopline 的頁面才選擇支付方式，與綠界不同  確認後，再改成正確的備註
 * */
final class GeneralGateway extends PaymentGateway {

	/** @var string 付款方式 ID */
	public $id = Init::PREFIX . 'redirect';

	/** Constructor */
	public function __construct() {
		$this->payment_label = __( 'Shopline Payment (Redirect)', 'power_checkout' );
		parent::__construct();
	}

	/**
	 * [前台] 在 /checkout/order-pay/ 頁渲染 /checkout/order-pay/{$order_id}/?key=wc_order_{$order_key}
	 * RY 在這邊做表單提交
	 * 已經確認過 order 存在，且是當前的付款方式，所以不用再驗證
	 * */
	public function render_at_receipt( int $order_id ): void {
		// 清空購物車
		\WC()->cart->empty_cart();
		/** @var \WC_Order $order */
		$order = \wc_get_order( $order_id );

		// 狀態轉為保留，因為 SLP 的付款成功狀態是非同步，所以待確認
		$order->update_status( 'wc-on-hold' );
		$order->add_order_note( \__( 'Shopline Payment 付款狀態確認中', 'power_checkout' ) );
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
		try {
			parent::process_payment( $order_id );
			$order       = \wc_get_order( $order_id );
			$this->order = $order;
			$service     = new Service( $this, $order );
			// 取得要跳轉的 url
			$redirect = $service->create_session();
			return ProcessResult::SUCCESS->to_array( $redirect );
		} catch (\Throwable $th) {
			$this->logger( $th->getMessage(), 'error', [], 5 );
			\wc_add_notice( $th->getMessage(), 'error' );
			return ProcessResult::FAILED->to_array();
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
