/**
 * 綠界物流相關 enum（對齊後端 Logistics\Shared\Enums）
 */

/**
 * 綠界物流模式 enum（對齊後端 Mode::value）
 */
export enum EEcpayLogisticsMode {
	TEST = 'test',
	PROD = 'prod',
}

/**
 * 綠界物流帳號類型 enum（對齊後端 LogisticsAccountType::value）
 *
 * 同一 provider 內以 account_type 切換兩組憑證（B2C / C2C），
 * 兩組 MerchantID / HashKey / HashIV 各異。
 */
export enum EEcpayLogisticsAccountType {
	B2C = 'b2c',
	C2C = 'c2c',
}

/**
 * 綠界物流子類型 enum（對齊後端 LogisticsSubType::value）
 *
 * 值對齊綠界 API（FAMI / UNIMART / HILIFE / HOME）。
 */
export enum EEcpayLogisticsSubType {
	/** 全家超商取貨 */
	FAMI = 'FAMI',
	/** 統一超商（7-11）取貨 */
	UNIMART = 'UNIMART',
	/** 萊爾富超商取貨 */
	HILIFE = 'HILIFE',
	/** 宅配（黑貓） */
	HOME = 'HOME',
}

/** 物流子類型選項（checkbox 用，label 對齊後端 LogisticsSubType::label()） */
export const LOGISTICS_SUB_TYPE_OPTIONS = [
	{ value: EEcpayLogisticsSubType.UNIMART, label: '統一超商（7-11）取貨' },
	{ value: EEcpayLogisticsSubType.FAMI, label: '全家超商取貨' },
	{ value: EEcpayLogisticsSubType.HILIFE, label: '萊爾富超商取貨' },
	{ value: EEcpayLogisticsSubType.HOME, label: '宅配（黑貓）' },
] as const

/** 帳號類型選項（radio 用） */
export const LOGISTICS_ACCOUNT_TYPE_OPTIONS = [
	{ value: EEcpayLogisticsAccountType.B2C, label: 'B2C 大宗寄倉' },
	{ value: EEcpayLogisticsAccountType.C2C, label: 'C2C 店到店' },
] as const
