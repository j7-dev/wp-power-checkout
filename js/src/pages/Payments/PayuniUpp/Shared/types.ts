import { EPayuniUppPaymentMethod } from '@/pages/Payments/PayuniUpp/Shared/enums'

/**
 * 允許的付款方式選項（對齊 PayuniSettingsDTO::$allowed_payments / PayuniPaymentMethod）
 *
 * ⚠️ 部分付款方式（icash Pay / LINE Pay / 街口 / Apple Pay / Google Pay）需先向 PAYUNi 申請開通。
 */
export const PAYMENT_METHODS = [
	{
		value: EPayuniUppPaymentMethod.CREDIT,
		label: '信用卡',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPayuniUppPaymentMethod.CREDIT_INST,
		label: '信用卡分期',
		disabled: false,
		tooltip: '勾選後可於下方設定分期期數',
	},
	{
		value: EPayuniUppPaymentMethod.ATM,
		label: 'ATM 虛擬帳號',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPayuniUppPaymentMethod.CVS,
		label: '超商代碼',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EPayuniUppPaymentMethod.ICASH,
		label: 'icash Pay',
		disabled: false,
		tooltip: '需向 PAYUNi 申請開通',
	},
	{
		value: EPayuniUppPaymentMethod.LINE_PAY,
		label: 'LINE Pay',
		disabled: false,
		tooltip: '需向 PAYUNi 申請開通',
	},
	{
		value: EPayuniUppPaymentMethod.JKOPAY,
		label: '街口支付',
		disabled: false,
		tooltip: '需向 PAYUNi 申請開通',
	},
	{
		value: EPayuniUppPaymentMethod.APPLE_PAY,
		label: 'Apple Pay',
		disabled: false,
		tooltip: '需向 PAYUNi 申請開通',
	},
	{
		value: EPayuniUppPaymentMethod.GOOGLE_PAY,
		label: 'Google Pay',
		disabled: false,
		tooltip: '需向 PAYUNi 申請開通',
	},
] as const

/**
 * 信用卡分期期數選項（對齊 PAYUNi InstFlag 3/6/9/12/18/24/30）
 *
 * 後端 PayuniSettingsDTO::$installment_periods 以逗號分隔字串保存（如 '3,6,12'）。
 * 前端以 number[] 操作 checkbox，送出前再轉為逗號字串。
 */
export const INSTALLMENT_PERIODS: number[] = [
	3,
	6,
	9,
	12,
	18,
	24,
	30,
]

/**
 * PAYUNi 統一金流 UPP 後台設定表單資料（對齊 PayuniSettingsDTO）
 *
 * 屬性命名統一採 snake_case，與後端 DTO 屬性一一對齊，避免 pick / merge 時遺漏欄位。
 * installment_periods 維持後端格式（逗號分隔字串），checkbox 的 number[] 轉換於 index.vue 處理。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	order_button_text: string
	min_amount: number
	max_amount: number
	expire_min: number

	// --- API --- //
	mode: string
	merchant_id: string
	hash_key: string
	hash_iv: string

	// --- 付款方式與分期 --- //
	allowed_payments: string[]
	installment_periods: string
}
