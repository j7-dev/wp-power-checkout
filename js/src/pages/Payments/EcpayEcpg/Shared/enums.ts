/**
 * 綠界站內付 2.0（ECPG）付款方式 enum
 *
 * 值對齊後端 EcpayPaymentMethod::value。EcpgSettingsDTO 本階段聚焦信用卡
 * （站內付元件收單），預設僅 Credit；ATM / CVS / BARCODE 取號流程屬後續階段。
 */
export enum EEcpgPaymentMethod {
	CREDIT = 'Credit',
}
