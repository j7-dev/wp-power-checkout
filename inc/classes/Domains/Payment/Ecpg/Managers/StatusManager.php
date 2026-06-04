<?php
/**
 * 綠界站內付 2.0（ECPG）StatusManager
 *
 * 依 ReturnURL 幕後通知（已解密的 Data）的 RtnCode（整數）轉換訂單狀態：
 *  - RtnCode === 1（付款成功）→ 處理中
 *  - 其他 RtnCode（付款失敗）→ 維持等待付款，記錄 RtnCode / RtnMsg
 *
 * ⚠️ 與 AIO StatusManager 的關鍵差異：AIO（CMV 類）RtnCode 為「字串」'1'；
 * 站內付 2.0（AES-JSON 類）解密後 RtnCode 為「整數」1，故本 Manager 以整數比對，
 * 不可共用 AIO StatusManager（型別比對不同會永遠失敗）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md §RtnCode 整數
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Managers;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/** 綠界站內付 2.0 StatusManager */
final class StatusManager {

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $_payload ReturnURL 解密後的 Data（業務資料）
	 * @param \WC_Order            $order    訂單
	 */
	public function __construct(
		private readonly array $_payload,
		private readonly \WC_Order $order,
	) {}

	/**
	 * 依 RtnCode（整數）更新訂單狀態
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		$rtn_code = (int) ( $this->_payload['RtnCode'] ?? 0 );
		$rtn_msg  = (string) ( $this->_payload['RtnMsg'] ?? '' );

		// 寫入付款明細（沿用 AIO 的 EcpayMetaKeys，key 為 _pc_ecpay_payment_detail）
		( new EcpayMetaKeys( $this->order ) )->update_payment_detail( $this->_payload );

		// order note 記錄付款明細
		$note = WP::array_to_html(
			$this->_payload,
			[ 'title' => '綠界站內付 2.0 付款結果通知' ]
		);
		$this->order->add_order_note( $note );

		$order_status = ( 1 === $rtn_code ) ? OrderStatus::PROCESSING : OrderStatus::PENDING;

		if ( OrderStatus::PENDING === $order_status ) {
			$this->order->add_order_note(
				\sprintf( '綠界站內付付款失敗，RtnCode：%d，RtnMsg：%s', $rtn_code, $rtn_msg )
			);
		}

		$this->order->update_status( $order_status->value );
	}
}
