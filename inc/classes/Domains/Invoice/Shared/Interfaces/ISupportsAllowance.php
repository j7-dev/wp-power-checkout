<?php
/**
 * 發票折讓能力介面
 *
 * 折讓（Allowance）為「部分退款開折讓單」能力，非所有發票服務都需實作。
 * 與 IInvoiceService 分離，讓 provider 可選擇性支援（目前 Ecpay 支援，Amego 後續）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Interfaces;

interface ISupportsAllowance {

	/**
	 * 開立折讓（部分退款）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 * @param float         $amount      折讓金額（含稅）
	 * @param string        $notify_mail 折讓通知 Email（空字串不通知）
	 *
	 * @return array<string, mixed>|\WP_Error 成功回 array；失敗回經 NormalizedError::from() 建立的 \WP_Error
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array|\WP_Error;

	/**
	 * 作廢折讓
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回 array；失敗回經 NormalizedError::from() 建立的 \WP_Error
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array|\WP_Error;
}
