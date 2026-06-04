<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Managers;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\RtnCode;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/**
 * 綠界 AIO StatusManager
 *
 * 依付款結果通知（ReturnURL）的 RtnCode（字串）轉換訂單狀態：
 *  - RtnCode='1'（付款成功）→ 處理中
 *  - 其他 RtnCode（付款失敗）→ 維持等待付款，記錄 RtnCode / RtnMsg
 *
 * 取號成功（RtnCode='2' / '10100073'）不走此 Manager，由 AioCallback 的
 * payment-info 端點處理（不改訂單狀態）。
 *
 * 比照 ShoplinePayment\Managers\StatusManager 的設計。
 */
final class StatusManager {

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $_payload ReturnURL 通知參數（已驗章）
	 * @param \WC_Order            $order    訂單
	 */
	public function __construct(
		private readonly array $_payload,
		private readonly \WC_Order $order,
	) {}

	/**
	 * 依 RtnCode 更新訂單狀態
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		$rtn_code = (string) ( $this->_payload['RtnCode'] ?? '' );
		$rtn_msg  = (string) ( $this->_payload['RtnMsg'] ?? '' );

		// 寫入付款明細
		( new EcpayMetaKeys( $this->order ) )->update_payment_detail( $this->_payload );

		// order note 記錄付款明細
		$note = WP::array_to_html(
			$this->_payload,
			[ 'title' => '綠界 ECPay 付款結果通知' ]
		);
		$this->order->add_order_note( $note );

		$order_status = match ( true ) {
			RtnCode::is_paid_success( $rtn_code ) => OrderStatus::PROCESSING,
			default => OrderStatus::PENDING,
		};

		if ( OrderStatus::PENDING === $order_status ) {
			$this->order->add_order_note(
				\sprintf( '綠界付款失敗，RtnCode：%s，RtnMsg：%s', $rtn_code, $rtn_msg )
			);
		}

		$this->order->update_status( $order_status->value );
	}
}
