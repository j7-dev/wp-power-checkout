<?php
/**
 * PAYUNi UNi Embed V3（內嵌式）付款方式
 *
 * String-backed enum。UNi Embed 僅做信用卡內嵌收單（一次付清 / 分期），
 * 不涉及 ATM / CVS / LINE Pay / 街口等取號或轉導型付款（那些走 UPP）。
 *
 * 與 UPP 的 PayuniPaymentMethod 同採 string-backing，便於與後台 settings
 * allowed_payments 欄位對齊；但值域刻意收斂為信用卡系列。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §UNi Embed 僅支援信用卡
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Enums;

/** PAYUNi UNi Embed V3 付款方式（string-backed） */
enum PayuniUniEmbedPaymentMethod: string {
	/** 信用卡一次付清 */
	case Credit = 'Credit';
	/** 信用卡分期 */
	case CreditInst = 'CreditInst';

	/**
	 * 映射 PAYUNi PaymentType（信用卡系列固定為 1）
	 *
	 * @return int PAYUNi PaymentType
	 */
	public function payment_type(): int {
		return 1;
	}

	/**
	 * 取得付款方式中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Credit     => '信用卡',
			self::CreditInst => '信用卡分期',
		};
	}
}
