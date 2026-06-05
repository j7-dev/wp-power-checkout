<?php
/**
 * 綠界電子收據開立 / 作廢回應 DTO（解密後的內層 Data）
 *
 * AES-JSON 雙層錯誤檢查：
 *   1. 外層 TransCode === 1（由 ReceiptApiClient 檢查，非 1 不解密）
 *   2. 內層 RtnCode === 1（整數 1，非字串 '1'）
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §步驟 1
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界電子收據回應 DTO（內層 Data） */
final class ReceiptIssueResponse extends DTO {

	/** @var int 1 = 業務成功，其他為錯誤代碼 */
	public int $RtnCode = 0;

	/** @var string 回應訊息 */
	public string $RtnMsg = '';

	/** @var string 綠界收據編號（如 Sale2026040800000448） */
	public string $ReceiptNo = '';

	/** @var string 特店自訂編號 */
	public string $RelateNumber = '';

	/** @var string 開立日期 */
	public string $ReceiptDate = '';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'RtnCode',
	];

	/** @return bool 業務是否成功（RtnCode 為整數 1） */
	public function is_success(): bool {
		return 1 === $this->RtnCode;
	}

	/** @return string 取得收據編號 */
	public function get_receipt_number(): string {
		return $this->ReceiptNo;
	}
}
