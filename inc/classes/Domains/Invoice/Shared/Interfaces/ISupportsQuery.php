<?php
/**
 * 發票查詢能力介面（唯讀）
 *
 * 查詢「已開立發票」的明細 / 狀態，不變更任何訂單或發票狀態。
 * 與 IInvoiceService / ISupportsAllowance 分離，讓 provider 可選擇性支援。
 *
 * 目前 Ecpay（GetIssue）與 Amego（invoice_query）支援。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Interfaces;

interface ISupportsQuery {

	/**
	 * 查詢發票明細（唯讀）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> 發票明細；查無或失敗回空陣列
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array;
}
