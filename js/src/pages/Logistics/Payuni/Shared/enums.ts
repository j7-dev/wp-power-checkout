/**
 * PAYUNi 物流相關 enum（對齊後端 Logistics\Payuni\Shared\Enums）
 */

/**
 * PAYUNi 物流模式 enum（對齊後端 Mode::value）
 */
export enum EPayuniLogisticsMode {
	TEST = 'test',
	PROD = 'prod',
}

/**
 * PAYUNi 超商物流型態 enum（對齊後端 PayuniLgsType::value 的超商子集）
 *
 * ⚠️ 與綠界不同：PAYUNi 物流與金流共用單一組 MerID / HashKey / HashIV，
 * B2C / C2C 差異只在 trade / ship_map 的 LgsType 參數，不換金鑰。
 */
export enum EPayuniLgsType {
	B2C = 'B2C',
	C2C = 'C2C',
}

/**
 * PAYUNi 物流子類型 enum（對齊後端 PayuniSubType::value）
 *
 * PAYUNi 物流僅支援 7-ELEVEN 與黑貓宅配（無全家 / 萊爾富 / OK）。
 */
export enum EPayuniSubType {
	/** 7-ELEVEN 超商取貨（需 ship_map 選店） */
	SEVEN = 'SEVEN',

	/** 黑貓宅配（不選店） */
	HOME = 'HOME',
}

/** 物流子類型選項（checkbox 用，label 對齊後端 PayuniSubType::label()） */
export const PAYUNI_SUB_TYPE_OPTIONS = [
	{ value: EPayuniSubType.SEVEN, label: '7-ELEVEN 超商取貨' },
	{ value: EPayuniSubType.HOME, label: '黑貓宅配' },
] as const

/** 超商物流型態選項（radio 用） */
export const PAYUNI_LGS_TYPE_OPTIONS = [
	{ value: EPayuniLgsType.B2C, label: 'B2C 大宗寄倉' },
	{ value: EPayuniLgsType.C2C, label: 'C2C 店到店' },
] as const
