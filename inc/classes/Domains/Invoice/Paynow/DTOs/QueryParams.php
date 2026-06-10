<?php
/**
 * PayNow 電子發票查詢參數 DTO（GET /api/invoices query string，唯讀，體系 3）
 *
 * 取得發票資料以 GET query string 傳遞（非 JSON body）。query 參數鍵為 PascalCase：
 *   { InvoiceNumber, OrderNo, Limit, Page }
 *
 * 本 DTO 採「以發票號碼精準查詢」：由已開立發票 meta 的 invoice_number 反查（必要），
 * 可選帶 order_no 輔助。to_array() 輸出的鍵即為 GET query string key（InvoiceNumber / OrderNo …）。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §7 取得發票資料
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

/** PayNow 電子發票查詢參數 DTO */
final class QueryParams {

	/**
	 * Constructor（private，經 from_issued_data() 建立）
	 *
	 * @param string $invoice_number 發票號碼.
	 * @param string $order_no       訂單編號（可空，輔助查詢）.
	 */
	private function __construct(
		private readonly string $invoice_number,
		private readonly string $order_no,
	) {}

	/**
	 * 由已開立發票 meta 建立查詢參數
	 *
	 * @param array<string, mixed> $issued_data 已開立發票 meta（含 invoice_number / 可選 order_no）.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 找不到發票號碼.
	 */
	public static function from_issued_data( array $issued_data ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		if ( '' === $invoice_number ) {
			throw new \InvalidArgumentException( '找不到發票號碼，無法查詢' );
		}

		$order_no = (string) ( $issued_data['order_no'] ?? '' );

		return new self( $invoice_number, $order_no );
	}

	/**
	 * 輸出 PayNow 查詢 GET query string 欄位陣列（鍵為 PascalCase）
	 *
	 * 僅輸出有值的欄位（避免空 OrderNo 干擾查詢）。
	 *
	 * @return array<string, string> 查詢 query string 欄位（InvoiceNumber / [OrderNo]）.
	 */
	public function to_array(): array {
		$params = [
			'InvoiceNumber' => $this->invoice_number,
		];

		if ( '' !== $this->order_no ) {
			$params['OrderNo'] = $this->order_no;
		}

		return $params;
	}
}
