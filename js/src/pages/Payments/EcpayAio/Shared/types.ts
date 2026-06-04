import { EEcpayAioPaymentMethod } from '@/pages/Payments/EcpayAio/Shared/enums'

/**
 * 允許的付款方式選項（對齊 AioSettingsDTO::$allowedPayments）
 */
export const PAYMENT_METHODS = [
	{
		value: EEcpayAioPaymentMethod.CREDIT,
		label: '信用卡',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EEcpayAioPaymentMethod.ATM,
		label: 'ATM 虛擬帳號',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EEcpayAioPaymentMethod.WEB_ATM,
		label: '網路 ATM',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EEcpayAioPaymentMethod.CVS,
		label: '超商代碼',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EEcpayAioPaymentMethod.BARCODE,
		label: '超商條碼',
		disabled: false,
		tooltip: undefined,
	},
	{
		value: EEcpayAioPaymentMethod.APPLE_PAY,
		label: 'Apple Pay',
		disabled: false,
		tooltip: undefined,
	},
] as const

/**
 * 信用卡分期期數選項（對齊 AioSettingsDTO::$installmentPeriods 預設 3/6/12/18/24/30）
 *
 * 後端 before_init 會將期數正規化為 int，故以 number 表示。
 */
export const INSTALLMENT_PERIODS: number[] = [3, 6, 12, 18, 24, 30]

/**
 * 綠界 AIO 後台設定表單資料（對齊 AioSettingsDTO）
 *
 * 命名混用 snake_case（WC 設定欄位慣例）與 camelCase（綠界 API 憑證），
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
	// --- 付款方式與分期 --- //
	allowedPayments: string[]
	installmentPeriods: number[]
	periodConfig: Record<string, unknown>
}
