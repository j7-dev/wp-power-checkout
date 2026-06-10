/**
 * PayNow（立吉富體系 1）物流相關 enum（對齊後端 Logistics\Paynow\Shared\Enums）
 */

/**
 * PayNow 物流模式 enum（對齊後端 Mode::value）
 */
export enum EPaynowLogisticsMode {
	TEST = 'test',
	PROD = 'prod',
}

/**
 * PayNow 物流服務 enum（對齊後端 PaynowLogisticService case 名稱）
 *
 * ⚠️ enabled_methods 儲存的是 case 名稱（非 backed value 01-06），
 * 後端以 PaynowLogisticService::try_from_name() 大小寫不敏感映射回 enum。
 * 首期常溫超商（SEVEN / FAMI / HILIFE）+ 黑貓宅配（TCAT）。
 */
export enum EPaynowLogisticService {
	/** 7-11 店到店 */
	Seven = 'Seven',

	/** 全家 店到店 */
	Fami = 'Fami',

	/** HiLife 店到店 */
	Hilife = 'Hilife',

	/** 黑貓 宅配 */
	Tcat = 'Tcat',
}

/** PayNow 物流服務選項（checkbox 用，label 對齊後端 PaynowLogisticService::label()） */
export const PAYNOW_LOGISTIC_SERVICE_OPTIONS = [
	{ value: EPaynowLogisticService.Seven, label: '7-11 超商取貨' },
	{ value: EPaynowLogisticService.Fami, label: '全家超商取貨' },
	{ value: EPaynowLogisticService.Hilife, label: '萊爾富超商取貨' },
	{ value: EPaynowLogisticService.Tcat, label: '黑貓宅配' },
] as const
