/**
 * 綠界 AIO 付款方式 enum
 *
 * 值對齊後端 EcpayPaymentMethod::value 的 ChoosePayment 全集
 * （AioSettingsDTO::$allowedPayments 預設 Credit / ATM / WebATM / CVS / BARCODE / ApplePay）。
 */
export enum EEcpayAioPaymentMethod {
	CREDIT = 'Credit',
	ATM = 'ATM',
	WEB_ATM = 'WebATM',
	CVS = 'CVS',
	BARCODE = 'BARCODE',
	APPLE_PAY = 'ApplePay',
}
