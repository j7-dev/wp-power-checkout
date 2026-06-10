/**
 * PayNow（立吉富）體系 1 後台設定表單資料（對齊 PaynowSettingsDTO）
 *
 * 屬性命名統一採 snake_case，與後端 DTO 屬性一一對齊，避免 pick / merge 時遺漏欄位。
 *
 * 與 PAYUNi UNi Embed 的 TFormData 差異：
 *  - 憑證為 public_key / private_key（PayNow 體系 1 金鑰體系），非 merchant_id / hash_key / hash_iv。
 *  - 新增 allowed_payment_methods（多元付款勾選）/ allow_installments / expire_days
 *    （PayNow 支援信用卡 / ATM / 超商 / LINE Pay / Apple Pay 與離線付款繳款天數）。
 *  - 無 iframe_domain（PayNow SDK 由 env 切換 sandbox / production，無 IFrameDomain 概念）。
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
	public_key: string
	private_key: string

	// --- 付款方式與分期設定 --- //
	allowed_payment_methods: string[]
	allow_installments: boolean
	expire_days: number
}
