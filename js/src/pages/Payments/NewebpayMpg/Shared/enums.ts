/**
 * 藍新 NewebPay MPG 付款方式 enum
 *
 * 值對齊後端 MpgPaymentMethod::value（TradeInfo 旗標名）。
 * MpgSettingsDTO::$allowedPayments 預設僅 CREDIT，其餘可於設定頁勾選。
 */
export enum ENewebpayMpgPaymentMethod {
	CREDIT = 'CREDIT',
	VACC = 'VACC',
	WEBATM = 'WEBATM',
	CVS = 'CVS',
	BARCODE = 'BARCODE',
	LINEPAY = 'LINEPAY',
	APPLEPAY = 'APPLEPAY',
	ESUNWALLET = 'ESUNWALLET',
	TAIWANPAY = 'TAIWANPAY',
	TWQR = 'TWQR',
}
