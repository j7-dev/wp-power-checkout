/**
 * 綠界電子發票後台設定表單資料（對齊 EcpayInvoiceSettingsDTO）
 *
 * 憑證欄位採 snake_case（merchant_id / hash_key / hash_iv），與後端 DTO 屬性一致。
 * 自動開立 / 作廢訂單狀態由 BaseSettingsDTO 提供。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	// --- API --- //
	mode: string
	merchant_id: string
	hash_key: string
	hash_iv: string
	// --- 自動化 --- //
	auto_issue_order_statuses: string[]
	auto_cancel_order_statuses: string[]
}
