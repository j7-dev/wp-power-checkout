<?php
/**
 * 綠界發票折讓 開立 / 作廢回應 DTO
 *
 * 對應「解密後的內層 Data」。AES-JSON 雙層錯誤檢查：
 *   1. 外層 TransCode === 1（由 InvoiceApiClient 檢查，非 1 不解密）
 *   2. 內層 RtnCode === 1（整數 1，非字串 '1'）
 *
 * 開立折讓成功回傳折讓單號：
 *   - B2C：IA_Allow_No（折讓單號 16 碼）
 *   - B2B：AllowanceNo
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §折讓回應處理
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md §折讓
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界發票折讓回應 DTO（內層 Data） */
final class AllowanceResponse extends DTO {

	/** @var int 1 = 業務成功，其他為錯誤代碼 */
	public int $RtnCode = 0;

	/** @var string 回應訊息 */
	public string $RtnMsg = '';

	/** @var string B2C 折讓單號 */
	public string $IA_Allow_No = '';

	/** @var string B2B 折讓單號 */
	public string $AllowanceNo = '';

	/** @var string 原發票號碼 */
	public string $IA_Invoice_No = '';

	/** @var int 該發票折讓後剩餘可折讓金額 */
	public int $IIS_Remain_Allowance_Amt = 0;

	/** @var string[] 必填 */
	protected array $require_properties = [
		'RtnCode',
	];

	/** @return bool 業務是否成功（RtnCode 為整數 1） */
	public function is_success(): bool {
		return 1 === $this->RtnCode;
	}

	/** @return string 取得折讓單號（B2C IA_Allow_No 優先，否則 B2B AllowanceNo） */
	public function get_allowance_number(): string {
		return $this->IA_Allow_No ?: $this->AllowanceNo;
	}
}
