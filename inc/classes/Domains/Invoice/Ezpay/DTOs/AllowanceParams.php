<?php
/**
 * 藍新 ezPay 發票折讓開立參數 DTO（allowance_issue 的 PostData_ 業務欄位）
 *
 * 開立折讓 PostData_ 欄位（不含 RespondType / Version / TimeStamp，由 client 注入）：
 *   { InvoiceNo, MerchantOrderNo, ItemName, ItemCount, ItemUnit, ItemPrice, ItemAmt, ItemTaxAmt, TotalAmt, [BuyerEmail], Status }
 *
 * ⚠️ 欄位名陷阱：折讓開立的發票號碼欄位為 `InvoiceNo`（**非** 開立 / 作廢用的 `InvoiceNumber`）。
 *
 * 金額（concepts §含稅/未稅 + api-reference §金額計算）：
 *   折讓單以「單一彙總含稅項」表示。ItemPrice / ItemAmt 帶「含稅」折讓金額，ItemTaxAmt=0（含稅折讓申報無法扣抵）。
 *   平台檢核：TotalAmt = Σ ItemAmt + Σ ItemTaxAmt = 折讓含稅金額 + 0 = 折讓含稅金額。
 *
 * Status=1（即時確認）：折讓開立後立即確認，不做兩段式 allowance_touch_issue。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §4. 開立折讓
 * @see .claude/skills/ezpay-invoice/references/concepts.md §含稅/未稅
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票折讓開立參數 DTO */
final class AllowanceParams {

	/**
	 * Constructor（private，經 from_issued_data() 建立）
	 *
	 * @param string $invoice_no        原發票號碼（折讓開立欄位名為 InvoiceNo）.
	 * @param string $merchant_order_no 原開立時的自訂編號.
	 * @param int    $allowance_amount  折讓含稅金額.
	 * @param string $notify_mail       折讓通知 Email（空字串不通知）.
	 */
	private function __construct(
		private readonly string $invoice_no,
		private readonly string $merchant_order_no,
		private readonly int $allowance_amount,
		private readonly string $notify_mail,
	) {}

	/**
	 * 由已開立發票 meta + 折讓金額建立折讓開立參數
	 *
	 * @param array<string, mixed> $issued_data       已開立發票 meta（含 invoice_number）.
	 * @param string               $merchant_order_no 原開立時的自訂編號（由 provider 以同規則推得）.
	 * @param int                  $allowance_amount  折讓含稅金額（> 0）.
	 * @param string               $notify_mail       折讓通知 Email（空字串不通知）.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 找不到原發票號碼 / 折讓金額非正.
	 */
	public static function from_issued_data(
		array $issued_data,
		string $merchant_order_no,
		int $allowance_amount,
		string $notify_mail = ''
	): self {
		$invoice_no = (string) ( $issued_data['invoice_number'] ?? '' );
		if ( '' === $invoice_no ) {
			throw new \InvalidArgumentException( '找不到原發票號碼，無法開立折讓' );
		}
		if ( $allowance_amount <= 0 ) {
			throw new \InvalidArgumentException( '折讓金額必須大於 0' );
		}

		return new self( $invoice_no, $merchant_order_no, $allowance_amount, $notify_mail );
	}

	/**
	 * 輸出 ezPay PostData_ 業務欄位陣列
	 *
	 * 以單一彙總含稅項表示折讓；TotalAmt = ItemAmt + ItemTaxAmt（= 折讓含稅金額 + 0）。
	 *
	 * @return array<string, string> 折讓開立 PostData_ 欄位.
	 */
	public function to_array(): array {
		$data = [
			'InvoiceNo'       => $this->invoice_no,
			'MerchantOrderNo' => $this->merchant_order_no,
			'ItemName'        => '訂單折讓',
			'ItemCount'       => '1',
			'ItemUnit'        => '式',
			'ItemPrice'       => (string) $this->allowance_amount,
			'ItemAmt'         => (string) $this->allowance_amount,
			'ItemTaxAmt'      => '0',
			'TotalAmt'        => (string) $this->allowance_amount,
			'Status'          => '1', // 即時確認折讓.
		];

		if ( '' !== $this->notify_mail ) {
			$data['BuyerEmail'] = $this->notify_mail;
		}

		return $data;
	}
}
