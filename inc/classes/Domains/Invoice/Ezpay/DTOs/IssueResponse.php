<?php
/**
 * 藍新 ezPay 發票開立 / 作廢回應 DTO
 *
 * 對應「外層 Status=SUCCESS 後、Result（json_decode 後）的內層欄位」。
 * InvoiceApiClient::decode_result() 已負責外層 Status 判定與 CheckCode 驗證，
 * 本 DTO 僅將解密後的 Result 陣列正規化為「便於跨層取用」的屬性。
 *
 * ⚠️ 欄位名對照（與綠界 Ecpay 的 IssueResponse 刻意不同，對齊 ezPay 測試契約與 issued_data meta 鍵名）：
 *   - 發票號碼   ezPay Result 鍵 InvoiceNumber  → 屬性 invoice_number
 *   - 開立序號   ezPay Result 鍵 InvoiceTransNo → 屬性 invoice_trans_no
 *   - 防偽隨機碼 ezPay Result 鍵 RandomNum      → 屬性 random_num（非綠界 random_number）
 *   - 開立時間   ezPay Result 鍵 CreateTime     → 屬性 create_time
 *
 * 開立成功（Status=1 即時開立）才回傳 InvoiceNumber / RandomNum；作廢回應僅含 InvoiceNumber + CreateTime。
 * is_success() 以「外層已成功 + 內層 Result 至少含開立序號或發票號碼」判定，預設視為成功
 * （decode_result 失敗時 client 回 null，不會走到此 DTO）。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §1.2 / §3.2
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票開立 / 作廢回應 DTO */
final class IssueResponse {

	/** @var string 商店代號 MerchantID */
	public readonly string $merchant_id;

	/** @var string ezPay 電子發票開立序號 InvoiceTransNo（觸發 / 查詢時用） */
	public readonly string $invoice_trans_no;

	/** @var string 自訂編號 MerchantOrderNo */
	public readonly string $merchant_order_no;

	/** @var string 發票號碼 InvoiceNumber（Status=1 即時開立才回） */
	public readonly string $invoice_number;

	/** @var string 發票防偽隨機碼 RandomNum（4 碼） */
	public readonly string $random_num;

	/** @var int 發票金額 TotalAmt（含稅） */
	public readonly int $total_amt;

	/** @var string 開立 / 作廢時間 CreateTime */
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
		$this->invoice_trans_no  = (string) ( $result['InvoiceTransNo'] ?? '' );
		$this->merchant_order_no = (string) ( $result['MerchantOrderNo'] ?? '' );
		$this->invoice_number    = (string) ( $result['InvoiceNumber'] ?? '' );
		$this->random_num        = (string) ( $result['RandomNum'] ?? '' );
		$this->total_amt         = (int) ( $result['TotalAmt'] ?? 0 );
		$this->create_time       = (string) ( $result['CreateTime'] ?? '' );
	}

	/**
	 * 業務是否成功
	 *
	 * 由 client 的 decode_result() 驗證外層 Status===SUCCESS 與 CheckCode 後建構；能建構出本 DTO 即代表平台處理成功。
	 * 此處進一步要求至少含「開立序號」或「發票號碼」其一，避免空 Result 被誤判為成功。
	 *
	 * @return bool 成功回 true.
	 */
	public function is_success(): bool {
		return '' !== $this->invoice_trans_no || '' !== $this->invoice_number;
	}
}
