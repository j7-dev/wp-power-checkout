<?php
/**
 * PayNow 電子發票查詢回應 DTO（GET /api/invoices，唯讀，體系 3）
 *
 * 將 decode_result() 取出的內層 result 正規化為跨 provider 一致的標準化鍵，供 provider::query_invoice()
 * 直接回傳。本 DTO 為「唯讀查詢結果」，不寫任何 meta、不改訂單狀態（由 provider 保證）。
 *
 * 標準化鍵（PayNow snake_case，對齊查詢契約）：
 *   - invoice_number ← PayNow invoice_number（發票號碼）。
 *   - invoice_status ← PayNow invoice_status（發票狀態，如 issued / canceled）。
 *   - total_amount   ← PayNow total_amount（含稅總額）。
 *
 * 另保留 invoice_date / order_no 等原始欄位，以利後台顯示與除錯。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §7 取得發票資料
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

/** PayNow 電子發票查詢回應 DTO（唯讀） */
final class QueryResponse {

	/** @var string 發票號碼 invoice_number */
	public readonly string $invoice_number;

	/** @var string 發票狀態 invoice_status（issued / canceled 等） */
	public readonly string $invoice_status;

	/** @var int 發票總金額 total_amount（含稅） */
	public readonly int $total_amount;

	/** @var string 開立發票日期 invoice_date */
	public readonly string $invoice_date;

	/** @var string 訂單編號 order_no */
	public readonly string $order_no;

	/**
	 * Constructor
	 *
	 * 由 decode_result() 取出的查詢 result 陣列建構（鍵名為 PayNow 原始 snake_case）。缺欄位以空字串 / 0 補。
	 * 查詢端點 result 可能為「發票物件」或「發票清單（取首筆）」，由 client 統一傳入單一發票物件。
	 *
	 * @param array<string, mixed> $result PayNow 查詢 result 陣列（單一發票物件）.
	 */
	public function __construct( array $result ) {
		$this->invoice_number = (string) ( $result['invoice_number'] ?? '' );
		$this->invoice_status = (string) ( $result['invoice_status'] ?? '' );
		$this->total_amount   = (int) ( $result['total_amount'] ?? 0 );
		$this->invoice_date   = (string) ( $result['invoice_date'] ?? '' );
		$this->order_no       = (string) ( $result['order_no'] ?? '' );
	}

	/**
	 * 輸出標準化查詢結果陣列（跨 provider 一致鍵）
	 *
	 * @return array<string, mixed> 含 invoice_number / invoice_status / total_amount 等標準化鍵.
	 */
	public function to_array(): array {
		return [
			'invoice_number' => $this->invoice_number,
			'invoice_status' => $this->invoice_status,
			'total_amount'   => $this->total_amount,
			'invoice_date'   => $this->invoice_date,
			'order_no'       => $this->order_no,
		];
	}
}
