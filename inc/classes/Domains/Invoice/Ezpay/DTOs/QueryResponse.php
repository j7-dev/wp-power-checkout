<?php
/**
 * 藍新 ezPay 發票查詢回應 DTO（invoice_search，唯讀）
 *
 * 將解密後的 Result（json_decode 後）正規化為跨 provider 一致的標準化鍵，供 provider::query_invoice() 直接回傳。
 *
 * 標準化鍵（對齊 EzpayQueryTest 契約）：
 *   - invoice_number ← ezPay InvoiceNumber（發票號碼）
 *   - invoice_status ← ezPay InvoiceStatus（1=已開立、2=已作廢）
 *   - upload_status  ← ezPay UploadStatus（0 未上傳 / 1 已上傳成功 / 2 上傳中 / 3 上傳失敗 / 4 上傳逾時）
 *   - total_amt      ← ezPay TotalAmt（含稅總額）
 *
 * 另保留 invoice_trans_no / random_num / create_time / tax_type 等原始欄位，以利後台顯示與除錯。
 * 本 DTO 為「唯讀查詢結果」，不寫任何 meta、不改訂單狀態（由 provider 保證）。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §7.2
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票查詢回應 DTO（唯讀） */
final class QueryResponse {

	/** @var string 發票號碼 InvoiceNumber */
	public readonly string $invoice_number;

	/** @var string ezPay 電子發票開立序號 InvoiceTransNo */
	public readonly string $invoice_trans_no;

	/** @var string 發票防偽隨機碼 RandomNum */
	public readonly string $random_num;

	/** @var int 發票金額 TotalAmt（含稅） */
	public readonly int $total_amt;

	/** @var string 課稅別 TaxType（1 應稅 / 2 零稅率 / 3 免稅 / 9 混合） */
	public readonly string $tax_type;

	/** @var string 發票狀態 InvoiceStatus（1 已開立 / 2 已作廢） */
	public readonly string $invoice_status;

	/** @var string 財政部上傳狀態 UploadStatus（0 未上傳 / 1 已上傳成功 / 2 上傳中 / 3 上傳失敗 / 4 上傳逾時） */
	public readonly string $upload_status;

	/** @var string 開立發票時間 CreateTime */
	public readonly string $create_time;

	/**
	 * Constructor
	 *
	 * 由解密後的查詢 Result 陣列建構（鍵名為 ezPay 原始大駝峰）。缺欄位以空字串 / 0 補。
	 *
	 * @param array<string, mixed> $result 解密後的查詢 Result 陣列.
	 */
	public function __construct( array $result ) {
		$this->invoice_number   = (string) ( $result['InvoiceNumber'] ?? '' );
		$this->invoice_trans_no = (string) ( $result['InvoiceTransNo'] ?? '' );
		$this->random_num       = (string) ( $result['RandomNum'] ?? '' );
		$this->total_amt        = (int) ( $result['TotalAmt'] ?? 0 );
		$this->tax_type         = (string) ( $result['TaxType'] ?? '' );
		$this->invoice_status   = (string) ( $result['InvoiceStatus'] ?? '' );
		$this->upload_status    = (string) ( $result['UploadStatus'] ?? '' );
		$this->create_time      = (string) ( $result['CreateTime'] ?? '' );
	}

	/**
	 * 輸出標準化查詢結果陣列（跨 provider 一致鍵）
	 *
	 * @return array<string, mixed> 含 invoice_number / invoice_status / upload_status / total_amt 等標準化鍵.
	 */
	public function to_array(): array {
		return [
			'invoice_number'   => $this->invoice_number,
			'invoice_trans_no' => $this->invoice_trans_no,
			'random_num'       => $this->random_num,
			'total_amt'        => $this->total_amt,
			'tax_type'         => $this->tax_type,
			'invoice_status'   => $this->invoice_status,
			'upload_status'    => $this->upload_status,
			'create_time'      => $this->create_time,
		];
	}
}
