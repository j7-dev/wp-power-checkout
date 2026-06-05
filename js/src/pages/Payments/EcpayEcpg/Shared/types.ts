import { EEcpgPaymentMethod } from '@/pages/Payments/EcpayEcpg/Shared/enums'

/**
 * 允許的付款方式選項（對齊 EcpgSettingsDTO::$allowedPayments）
 *
 * 站內付 2.0 本階段僅信用卡，故只列 Credit。
 */
export const PAYMENT_METHODS = [
	{
		value: EEcpgPaymentMethod.CREDIT,
		label: '信用卡',
		disabled: false,
		tooltip: undefined,
	},
] as const

/**
 * 綠界站內付 2.0（ECPG）後台設定表單資料（對齊 EcpgSettingsDTO）
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	orderButtonText: string
	minAmount: number
	maxAmount: number

	// --- API --- //
	mode: string
	merchantId: string
	hashKey: string
	hashIv: string

	// --- 付款方式 --- //
	allowedPayments: string[]
}
