<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Enums;

/**
 * 付款結果
 * process_payment 有一定的 array 結構
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
 *  */
enum ProcessResult: string {
	/** @var string 成功 */
	case SUCCESS = 'success';

	/** @var string 失敗 */
	case FAILED = 'failure';

	/**
	 * 取得結果陣列
	 *
	 * @param string $redirect 跳轉 URL
	 * @return array{result: string, redirect?: string} 結果陣列
	 */
	public function to_array( string $redirect = '' ): array {
		return match ( $this ) {
			self::SUCCESS => [
				'result'   => self::SUCCESS->value,
				'redirect' => $redirect,
			],
			self::FAILED  => [
				'result' => self::FAILED->value,
			],
		};
	}
}
