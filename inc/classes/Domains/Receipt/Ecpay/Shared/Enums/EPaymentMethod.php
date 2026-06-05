<?php
/**
 * 綠界電子收據付款方式 PaymentMethod（僅政治獻金 ReceiptType=4 必填）
 *
 * | 值 | 方式 | 限制 |
 * |----|------|------|
 * | 1  | 匯款 | — |
 * | 2  | 票據 | CheckInfo 必填 |
 * | 3  | 現金 | 金額 ≤ 100,000 |
 *
 * ReceiptType=1 或 2 時系統忽略此欄位。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §ReceiptType=4
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums;

/** 綠界電子收據付款方式 */
enum EPaymentMethod: int {
	/** 匯款 */
	case REMITTANCE = 1;

	/** 票據 */
	case CHECK = 2;

	/** 現金 */
	case CASH = 3;

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::REMITTANCE => '匯款',
			self::CHECK      => '票據',
			self::CASH       => '現金',
		};
	}
}
