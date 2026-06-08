import { ENewebpayMpgPaymentMethod } from '@/pages/Payments/NewebpayMpg/Shared/enums'

/**
 * 允許的付款方式選項（對齊 MpgSettingsDTO::$allowedPayments）
 *
 * ⚠️ TWQR 需 version=2.3（後端 validate 會擋）；部分付款方式需先向藍新申請開通。
 */
export const PAYMENT_METHODS = [
	{
		value: ENewebpayMpgPaymentMethod.CREDIT,
		label: '信用卡',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.VACC,
		label: 'ATM 虛擬帳號',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.WEBATM,
		label: 'WebATM',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.CVS,
		label: '超商代碼',
		disabled: false,
		tooltip: '金額須介於 30 - 20,000 元',
	},
	{
		value: ENewebpayMpgPaymentMethod.BARCODE,
		label: '超商條碼',
		disabled: false,
		tooltip: '金額須介於 30 - 20,000 元',
	},
	{
		value: ENewebpayMpgPaymentMethod.LINEPAY,
		label: 'LINE Pay',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.APPLEPAY,
		label: 'Apple Pay',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.ESUNWALLET,
		label: '玉山 Wallet',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.TAIWANPAY,
		label: '台灣 Pay',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: ENewebpayMpgPaymentMethod.TWQR,
		label: 'TWQR 行動支付',
		disabled: false,
		tooltip: '需 MPG 版本 2.3，且須向藍新申請開通',
	},
] as const

/**
 * 信用卡分期期數選項（對齊 MpgSettingsDTO::$installmentPeriods 預設 3/6/12/18/24/30）
 *
 * 後端 before_init 會將期數正規化為 int，故以 number 表示。
 */
export const INSTALLMENT_PERIODS: number[] = [
	3,
	6,
	12,
	18,
	24,
	30,
]

/**
 * MPG 版本選項
 */
export const VERSION_OPTIONS = [
	{ value: '2.3', label: '2.3（建議，支援 TWQR）' },
	{ value: '2.0', label: '2.0（舊版，不支援 TWQR）' },
] as const

/**
 * 藍新 NewebPay MPG 後台設定表單資料（對齊 MpgSettingsDTO）
 *
 * 命名混用 snake_case（WC 設定欄位慣例）與 camelCase（藍新 API 憑證），
 * 與後端 DTO 屬性一一對齊，避免 pick / merge 時遺漏欄位。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	orderButtonText: string
	minAmount: number
	maxAmount: number
	expireDate: number

	// --- API --- //
	mode: string
	merchantId: string
	hashKey: string
	hashIv: string

	// --- MPG 版本與加密 --- //
	version: string
	encryptType: number

	// --- 付款方式與分期 --- //
	allowedPayments: string[]
	installmentPeriods: number[]
	twqrLifeTime: number
}
