import type {
	EPaynowLogisticService,
	EPaynowLogisticsMode,
} from '@/pages/Logistics/Paynow/Shared/enums'

/**
 * PayNow（立吉富體系 1）物流後台設定表單資料（對齊 PaynowLogisticsSettingsDTO）
 *
 * 欄位採 snake_case，與後端 DTO 屬性一致。
 * 憑證為單一組（user_account / apicode），TripleDES DES-EDE3 加密；
 * enabled_methods 儲存 PaynowLogisticService case 名稱（Seven / Fami / Hilife / Tcat）。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API / 模式 --- //
	mode: `${EPaynowLogisticsMode}`

	// --- 憑證（單一組） --- //
	user_account: string
	apicode: string

	// --- 物流方式與寄件人 --- //
	enabled_methods: `${EPaynowLogisticService}`[]
	sender_name: string
	sender_phone: string
	sender_address: string
	sender_email: string
}
