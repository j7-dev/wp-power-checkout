<?php
/**
 * 綠界發票開立 / 作廢回應 DTO
 *
 * 對應「解密後的內層 Data」。AES-JSON 雙層錯誤檢查：
 *   1. 外層 TransCode === 1（由 InvoiceApiClient 檢查，非 1 不解密）
 *   2. 內層 RtnCode === 1（整數 1，非字串 '1'）
 *
 * ⚠️ 發票號碼欄位 B2C 為 InvoiceNo、B2B 為 InvoiceNumber，需取兩者。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §步驟 2
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界發票回應 DTO（內層 Data） */
final class IssueResponse extends DTO {

	/** @var int 1 = 業務成功，其他為錯誤代碼 */
	public int $RtnCode = 0;

	/** @var string 回應訊息 */
	public string $RtnMsg = '';

	/** @var string B2C 發票號碼（兩個英文字母 + 8 位數字，如 QQ00000001） */
	public string $InvoiceNo = '';

	/** @var string B2B 發票號碼 */
	public string $InvoiceNumber = '';

	/** @var string 發票開立日期（作廢時必填，需與開立時一致） */
	public string $InvoiceDate = '';

	/** @var string 隨機碼 */
	public string $RandomNumber = '';

	/** @var string[] 必填  */
	protected array $require_properties = [
		'RtnCode',
	];

	/** @return bool 業務是否成功（RtnCode 為整數 1） */
	public function is_success(): bool {
		return 1 === $this->RtnCode;
	}

	/** @return string 取得發票號碼（B2C InvoiceNo 優先，否則 B2B InvoiceNumber） */
	public function get_invoice_number(): string {
		return $this->InvoiceNo ?: $this->InvoiceNumber;
	}
}
