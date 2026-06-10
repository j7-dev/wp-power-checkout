<?php
/**
 * PayNow 電子發票折讓開立參數 DTO（POST /api/invoices/allowance 業務欄位，體系 3）
 *
 * 開立折讓 request body 欄位（PayNow 發票 API 為純 JSON，無對稱加密信封）：
 *   { invoice_number, remark, items[] }
 * 其中 items[] 每筆：{ quantity, unit_price, amount, tax, tax_type, invoice_body_sequence_number }。
 *
 * 本 DTO 以「單一彙總含稅折讓項」表示折讓（鏡像 ezPay AllowanceParams 做法）：
 *   折讓單以單一品項帶含稅折讓金額，tax=0（含稅折讓申報無法扣抵），對應原發票首筆明細（序號 1）。
 *
 * @see .claude/skills/paynow/references/invoice-api.md §5 發票折讓
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\DTOs;

use J7\PowerCheckout\Domains\Invoice\Paynow\Shared\Enums\ETaxType;

/** PayNow 電子發票折讓開立參數 DTO */
final class AllowanceParams {

	/**
	 * Constructor（private，經 from_issued_data() 建立）
	 *
	 * @param string $invoice_number   原發票號碼.
	 * @param int    $allowance_amount 折讓含稅金額（> 0）.
	 */
	private function __construct(
		private readonly string $invoice_number,
		private readonly int $allowance_amount,
	) {}

	/**
	 * 由已開立發票 meta + 折讓金額建立折讓開立參數
	 *
	 * @param array<string, mixed> $issued_data      已開立發票 meta（含 invoice_number）.
	 * @param int                  $allowance_amount 折讓含稅金額（> 0）.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 找不到原發票號碼 / 折讓金額非正.
	 */
	public static function from_issued_data( array $issued_data, int $allowance_amount ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		if ( '' === $invoice_number ) {
			throw new \InvalidArgumentException( '找不到原發票號碼，無法開立折讓' );
		}
		if ( $allowance_amount <= 0 ) {
			throw new \InvalidArgumentException( '折讓金額必須大於 0' );
		}

		return new self( $invoice_number, $allowance_amount );
	}

	/**
	 * 輸出 PayNow allowance request body 業務欄位陣列
	 *
	 * 以單一彙總含稅折讓項表示折讓；tax=0（含稅折讓申報無法扣抵），對應原發票首筆明細序號 1。
	 *
	 * @return array<string, mixed> 折讓開立 request body 欄位.
	 */
	public function to_array(): array {
		return [
			'invoice_number' => $this->invoice_number,
			'remark'         => '訂單折讓',
			'items'          => [
				[
					'quantity'                     => 1,
					'unit_price'                   => $this->allowance_amount,
					'amount'                       => $this->allowance_amount,
					'tax'                          => 0,
					'tax_type'                     => ETaxType::SaleTax->value,
					'invoice_body_sequence_number' => 1,
				],
			],
		];
	}
}
