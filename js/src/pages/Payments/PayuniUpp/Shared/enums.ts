/**
 * PAYUNi UPP V2 付款方式 enum
 *
 * 值對齊後端 PayuniPaymentMethod::value（string-backed）。
 * PayuniSettingsDTO::$allowed_payments 預設僅 Credit，其餘可於設定頁勾選。
 */
export enum EPayuniUppPaymentMethod {
	CREDIT = 'Credit',
	CREDIT_INST = 'CreditInst',
	ATM = 'ATM',
	CVS = 'CVS',
	ICASH = 'ICash',
	LINE_PAY = 'LinePay',
	JKOPAY = 'JKoPay',
	APPLE_PAY = 'ApplePay',
	GOOGLE_PAY = 'GooglePay',
}
