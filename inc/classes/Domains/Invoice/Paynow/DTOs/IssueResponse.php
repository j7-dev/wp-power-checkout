<?php
/**
 * PayNow 電子發票開立 / 作廢回應 DTO（體系 3）
 *
 * 對應「PayNow 外層回應 { status, type, message, result, request_id } 經 decode_result()
 * 取出 type===success 後的內層 result 陣列」。InvoiceApiClient::decode_result() 已負責外層
 * type 判定（非 success 即拋 \RuntimeException），本 DTO 僅將 result 正規化為跨層取用的屬性。
 *
 * ⚠️ 與 ezPay / ECPay 關鍵差異（對齊 PayNow 發票 API 契約與 issued_data meta 鍵名）：
 *   - 發票號碼 PayNow result 鍵 invoice_number（snake_case）→ 屬性 invoice_number。
 *   - 無 CheckCode / InvoiceTransNo / RandomNum 等對稱簽章欄位（PayNow 發票純 Bearer 認證）。
 *
 * 開立成功才回 invoice_number；作廢回應 result 可能不含 invoice_number，故 cancel() 會由 client
 * 補上原發票號碼供呼叫端判定成功。is_success() 以「至少含發票號碼」判定（外層已是 type=success 才走到此）。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §3 單張發票開立 / §4 發票作廢
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

/** PayNow 電子發票開立 / 作廢回應 DTO */
final class IssueResponse {

	/** @var string 發票號碼 invoice_number */
	public readonly string $invoice_number;

	/** @var string 開立 / 作廢日期 invoice_date */
	public readonly string $invoice_date;

	/** @var string 訂單編號 order_no */
	public readonly string $order_no;

	/** @var int 發票總金額 total_amount（含稅） */
	public readonly int $total_amount;

	/**
	 * Constructor
	 *
	 * 由 decode_result() 取出的內層 result 陣列建構（鍵名為 PayNow 原始 snake_case）。缺欄位以空字串 / 0 補。
	 *
	 * @param array<string, mixed> $result PayNow 內層 result 陣列.
	 */
	public function __construct( array $result ) {
		$this->invoice_number = (string) ( $result['invoice_number'] ?? '' );
		$this->invoice_date   = (string) ( $result['invoice_date'] ?? '' );
		$this->order_no       = (string) ( $result['order_no'] ?? '' );
		$this->total_amount   = (int) ( $result['total_amount'] ?? 0 );
	}

	/**
	 * 業務是否成功
	 *
	 * 由 client 的 decode_result() 驗證外層 type===success 後建構；此處進一步要求至少含發票號碼，
	 * 避免空 result 被誤判為成功。
	 *
	 * @return bool 成功回 true.
	 */
	public function is_success(): bool {
		return '' !== $this->invoice_number;
	}
}
