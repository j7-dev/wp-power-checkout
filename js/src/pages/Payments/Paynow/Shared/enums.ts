/**
 * PayNow（立吉富）體系 1 付款方式 enum
 *
 * 值對齊後端 PaynowPaymentMethod::value（string-backed，payment-rest-api.md §4.1
 * allowedPaymentMethods）。
 *
 * ⚠️ 刻意「排除」ApplePayDeferred（Q1 裁決）：官方規範 ApplePayDeferred 不可與其他付款方式併用，
 *    與本外掛 checkout 流程不相容，故前端勾選清單不含此值（與後端 enum 一致）。
 */
export enum EPaynowPaymentMethod {
	CREDIT_CARD = 'CreditCard',
	CREDIT_CARD_INSTALLMENT = 'CreditCardInstallment',
	ATM = 'ATM',
	CONVENIENCE_STORE = 'ConvenienceStore',
	LINE_PAY_ONLINE = 'LINEPayOnline',
	LINE_PAY_OFFLINE = 'LINEPayOffline',
	APPLE_PAY = 'ApplePay',
}

/** 付款方式中文標籤（後台勾選清單顯示用） */
export const PAYNOW_PAYMENT_METHOD_LABELS: Record<
	EPaynowPaymentMethod,
	string
> = {
	[EPaynowPaymentMethod.CREDIT_CARD]: '信用卡',
	[EPaynowPaymentMethod.CREDIT_CARD_INSTALLMENT]: '信用卡分期',
	[EPaynowPaymentMethod.ATM]: 'ATM 虛擬帳號',
	[EPaynowPaymentMethod.CONVENIENCE_STORE]: '超商代碼',
	[EPaynowPaymentMethod.LINE_PAY_ONLINE]: 'LINE Pay 線上',
	[EPaynowPaymentMethod.LINE_PAY_OFFLINE]: 'LINE Pay 實體',
	[EPaynowPaymentMethod.APPLE_PAY]: 'Apple Pay',
}
