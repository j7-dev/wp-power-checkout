/**
 * PayNow 立吉富電子發票後台設定表單資料（對齊 PaynowInvoiceSettingsDTO）
 *
 * PayNow 發票（體系 3）以 Bearer JWT-Token 認證，故僅一把憑證 jwt_token（無對稱加密 hash_key / hash_iv）。
 * auto_allowance_on_refund 控制「部分退款時是否自動開立折讓」（預設關）。
 * 自動開立 / 作廢訂單狀態由 BaseSettingsDTO 提供。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string

	// --- API --- //
	mode: string
	jwt_token: string

	// --- 自動化 --- //
	auto_issue_order_statuses: string[]
	auto_cancel_order_statuses: string[]
	auto_allowance_on_refund: string
}
