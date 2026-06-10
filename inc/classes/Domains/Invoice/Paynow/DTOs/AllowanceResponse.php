<?php
/**
 * PayNow 電子發票折讓 開立 / 作廢回應 DTO（體系 3）
 *
 * 對應「PayNow 外層回應 { status, type, message, result, request_id } 經 decode_result()
 * 取出 type===success 後的內層 result 陣列」。InvoiceApiClient::decode_result() 已負責外層 type 判定。
 *
 * ⚠️ 折讓回應欄位名（PayNow snake_case，對齊 allowance_data meta 鍵名）：
 *   - 折讓號     PayNow result 鍵 allowance_number → 屬性 allowance_number。
 *   - 原發票號碼 PayNow result 鍵 invoice_number    → 屬性 invoice_number。
 *   - 折讓金額   PayNow result 鍵 allowance_amount  → 屬性 allowance_amount。
 *   - 剩餘金額   PayNow result 鍵 remain_amount     → 屬性 remain_amount。
 *
 * 開立折讓回應含 allowance_number / allowance_amount / remain_amount；作廢折讓回應 result 可能不含
 * allowance_number，故 invalid_allowance() 會由 client 補上原折讓號供呼叫端判定成功。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §5 發票折讓 / §6 折讓作廢
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

/** PayNow 電子發票折讓回應 DTO */
final class AllowanceResponse {

	/** @var string 折讓號 allowance_number */
	public readonly string $allowance_number;

	/** @var string 原發票號碼 invoice_number */
	public readonly string $invoice_number;

	/** @var string 折讓日期 allowance_date */
	public readonly string $allowance_date;

	/** @var int 折讓金額 allowance_amount */
	public readonly int $allowance_amount;

	/** @var int 折讓後剩餘發票金額 remain_amount */
	public readonly int $remain_amount;

	/**
	 * Constructor
	 *
	 * 由 decode_result() 取出的內層 result 陣列建構（鍵名為 PayNow 原始 snake_case）。缺欄位以空字串 / 0 補。
	 *
	 * @param array<string, mixed> $result PayNow 內層 result 陣列.
	 */
	public function __construct( array $result ) {
		$this->allowance_number = (string) ( $result['allowance_number'] ?? '' );
		$this->invoice_number   = (string) ( $result['invoice_number'] ?? '' );
		$this->allowance_date   = (string) ( $result['allowance_date'] ?? '' );
		$this->allowance_amount = (int) ( $result['allowance_amount'] ?? 0 );
		$this->remain_amount    = (int) ( $result['remain_amount'] ?? 0 );
	}

	/**
	 * 業務是否成功
	 *
	 * 由 client 的 decode_result() 驗證外層 type===success 後建構；此處進一步要求至少含折讓號，
	 * 避免空 result 被誤判為成功。
	 *
	 * @return bool 成功回 true.
	 */
	public function is_success(): bool {
		return '' !== $this->allowance_number;
	}
}
