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

		// 安全：轉 processing 前比對綠界回傳金額（TradeAmt）與訂單總額。
		// 不符（疑似竄改 / 錯誤回傳）→ 維持 pending + 告警，絕不自動完成付款。
		// @see .claude/skills/ECPay-API-Skill/guides/21-webhook-events-reference.md（ReturnURL TradeAmt）
		if ( OrderStatus::PROCESSING === $order_status && ! $this->is_amount_matched() ) {
			$this->order->add_order_note(
				\sprintf(
					'綠界付款通知金額不符，TradeAmt：%s，訂單應收：%d，維持等待付款（疑似竄改）',
					(string) ( $this->_payload['TradeAmt'] ?? '' ),
					$this->get_order_amount()
				)
			);
			$this->order->update_status( OrderStatus::PENDING->value );
			return;
		}

		if ( OrderStatus::PENDING === $order_status ) {
			$this->order->add_order_note(
				\sprintf( '綠界付款失敗，RtnCode：%s，RtnMsg：%s', $rtn_code, $rtn_msg )
			);
		}

		$this->order->update_status( $order_status->value );
	}

	/**
	 * 比對綠界回傳金額（TradeAmt）是否等於訂單應收總額
	 *
	 * 綠界 AIO 金額為整數新台幣，訂單總額以 ceil 取整後比對。
	 *
	 * @return bool
	 */
	private function is_amount_matched(): bool {
		$trade_amt = (int) ( $this->_payload['TradeAmt'] ?? 0 );
		return $trade_amt === $this->get_order_amount();
	}

	/**
	 * 取得訂單應收金額（整數新台幣）
	 *
	 * @return int
	 */
	private function get_order_amount(): int {
		return (int) \ceil( (float) $this->order->get_total() );
	}
}
