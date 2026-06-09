/**
 * PAYUNi UNi Embed V3 後台設定表單資料（對齊 PayuniUniEmbedSettingsDTO）
 *
 * 屬性命名統一採 snake_case，與後端 DTO 屬性一一對齊，避免 pick / merge 時遺漏欄位。
 *
 * 與 UPP 的 TFormData 差異：
 *  - 無 allowed_payments / installment_periods / expire_min（UNi Embed 僅信用卡，無取號流程）。
 *  - 新增 iframe_domain（V3 token_get 內層必填，須含 https://）。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	order_button_text: string
	min_amount: number
	max_amount: number

	// --- API --- //
	mode: string
	merchant_id: string
	hash_key: string
	hash_iv: string

	// --- V3 特有欄位 --- //
	iframe_domain: string
}
