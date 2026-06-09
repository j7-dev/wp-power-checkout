/**
 * PAYUNi UNi Embed V3 付款方式 enum
 *
 * 值對齊後端 PayuniUniEmbedPaymentMethod::value（string-backed）。
 * UNi Embed（內嵌式信用卡，站內付不跳轉）僅支援信用卡（一次付清 / 分期），
 * 與 UPP（導轉式，多元付款）不同——故無 ATM / CVS / 行動支付等選項。
 */
export enum EPayuniUniEmbedPaymentMethod {
	CREDIT = 'Credit',
	CREDIT_INST = 'CreditInst',
}
