/**
 * 綠界電子收據後台設定表單資料（對齊 EcpayReceiptSettingsDTO）
 *
 * 憑證欄位採 snake_case（merchant_id / hash_key / hash_iv），與後端 DTO 屬性一致。
 * 收據類型 default_receipt_type：1=一般 / 2=公益 / 4=政治。
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

	// --- 收據設定 --- //
	default_receipt_type: number
	retrieval_method: string
	donor_type: string
	identifier: string
	payment_method: string

	// --- 自動化 --- //
	auto_issue_order_statuses: string[]
	auto_cancel_order_statuses: string[]
}
