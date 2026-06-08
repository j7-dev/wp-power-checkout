<?php
/**
 * 藍新 ezPay 發票折讓 開立 / 作廢回應 DTO
 *
 * 對應「外層 Status=SUCCESS 後、Result（json_decode 後）的內層欄位」。
 * InvoiceApiClient::decode_result()（折讓 CheckCode 欄位集）已負責外層 Status 判定與折讓 CheckCode 驗證。
 *
 * ⚠️ 折讓回應欄位名（與綠界 Ecpay 的 AllowanceResponse 刻意不同，對齊 ezPay 測試契約與 allowance_data meta 鍵名）：
 *   - 折讓號     ezPay Result 鍵 AllowanceNo → 屬性 allowance_no（非綠界 IA_Allow_No / allowance_number）
 *   - 折讓金額   ezPay Result 鍵 AllowanceAmt → 屬性 allowance_amt
 *   - 剩餘金額   ezPay Result 鍵 RemainAmt    → 屬性 remain_amt
 *   - 原發票號碼 ezPay Result 鍵 InvoiceNumber → 屬性 invoice_number
 *
 * 開立折讓回應含 AllowanceNo / AllowanceAmt / RemainAmt；作廢折讓回應僅含 AllowanceNo + CreateTime。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §4.2 / §6.2
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票折讓回應 DTO */
final class AllowanceResponse {

	/** @var string 商店代號 MerchantID */
	public readonly string $merchant_id;

	/** @var string 折讓號 AllowanceNo */
	public readonly string $allowance_no;

	/** @var string 原發票號碼 InvoiceNumber */
	public readonly string $invoice_number;

	/** @var string 自訂編號 MerchantOrderNo */
	public readonly string $merchant_order_no;

	/** @var int 折讓金額 AllowanceAmt */
	public readonly int $allowance_amt;

	/** @var int 折讓後剩餘發票金額 RemainAmt */
	public readonly int $remain_amt;

	/** @var string 作廢折讓時間 CreateTime（作廢折讓回應才有） */
	public readonly string $create_time;

	/**
	 * Constructor
	 *
	 * 由解密後的 Result 陣列建構（鍵名為 ezPay 原始大駝峰）。缺欄位以空字串 / 0 補。
	 *
	 * @param array<string, mixed> $result 解密後的 Result 陣列.
	 */
	public function __construct( array $result ) {
		$this->merchant_id       = (string) ( $result['MerchantID'] ?? '' );
		$this->allowance_no      = (string) ( $result['AllowanceNo'] ?? '' );
		$this->invoice_number    = (string) ( $result['InvoiceNumber'] ?? '' );
		$this->merchant_order_no = (string) ( $result['MerchantOrderNo'] ?? '' );
		$this->allowance_amt     = (int) ( $result['AllowanceAmt'] ?? 0 );
		$this->remain_amt        = (int) ( $result['RemainAmt'] ?? 0 );
		$this->create_time       = (string) ( $result['CreateTime'] ?? '' );
	}

	/**
	 * 業務是否成功
	 *
	 * 由 client 的 decode_result() 驗證外層 Status===SUCCESS 與折讓 CheckCode 後建構；能建構出本 DTO 即代表平台處理成功。
	 * 此處進一步要求至少含折讓號，避免空 Result 被誤判為成功。
	 *
	 * @return bool 成功回 true.
	 */
	public function is_success(): bool {
		return '' !== $this->allowance_no;
	}
}
