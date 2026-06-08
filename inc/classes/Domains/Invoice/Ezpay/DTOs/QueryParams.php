<?php
/**
 * 藍新 ezPay 發票查詢參數 DTO（invoice_search 的 PostData_ 業務欄位，唯讀）
 *
 * 查詢發票 PostData_ 欄位（不含 RespondType / Version / TimeStamp，由 client 注入）：
 *   SearchType=0（以發票號碼 + 隨機碼查詢）→ { SearchType, InvoiceNumber, RandomNum }
 *
 * 本 DTO 採 SearchType=0：以已開立發票 meta 的 invoice_number + random_num 反查，
 * 為「已開立發票」的精準查詢（不需 MerchantOrderNo + TotalAmt 模式）。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §7. 查詢發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票查詢參數 DTO（SearchType=0） */
final class QueryParams {

	/**
	 * Constructor（private，經 from_issued_data() 建立）
	 *
	 * @param string $invoice_number 發票號碼.
	 * @param string $random_num     發票防偽隨機碼（4 碼）.
	 */
	private function __construct(
		private readonly string $invoice_number,
		private readonly string $random_num,
	) {}

	/**
	 * 由已開立發票 meta 建立查詢參數（SearchType=0）
	 *
	 * @param array<string, mixed> $issued_data 已開立發票 meta（含 invoice_number / random_num）.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 找不到發票號碼.
	 */
	public static function from_issued_data( array $issued_data ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		if ( '' === $invoice_number ) {
			throw new \InvalidArgumentException( '找不到發票號碼，無法查詢' );
		}

		$random_num = (string) ( $issued_data['random_num'] ?? '' );

		return new self( $invoice_number, $random_num );
	}

	/**
	 * 輸出 ezPay PostData_ 業務欄位陣列
	 *
	 * @return array<string, string> 查詢 PostData_ 欄位.
	 */
	public function to_array(): array {
		return [
			'SearchType'    => '0',
			'InvoiceNumber' => $this->invoice_number,
			'RandomNum'     => $this->random_num,
		];
	}
}
