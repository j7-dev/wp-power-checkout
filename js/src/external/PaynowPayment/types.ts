/**
 * PayNow（立吉富）體系 1 Component SDK v2（內嵌式，站內 iframe）order-received 頁渲染模組型別
 *
 * 資料來源為後端 PaynowGateway::before_order_received 的 wp_localize_script
 * （power_checkout_paynow_data）與 build_sdk_config()。SDK 介面（IPaynowSdk）對齊
 * PayNow Component SDK v2，唯一 API 來源：.claude/skills/paynow/references/payment-rest-api.md §3
 * 與 php-examples.md §3（禁猜、禁上網）。
 *
 * ⚠️ 與 UNi Embed 的「減一支」差異：PayNow SDK checkout() 直接與 PayNow 完成授權 + 3DS，
 *    **無「前端送回後端 merchant_trade」中間步驟**，故型別不含 create_payment_url，
 *    亦無 create-payment 回應型別。付款結果一律以後端 Webhook（NotifyURL）為準。
 */

/**
 * order-received 頁專屬逐訂單 + 靜態 SDK 設定（power_checkout_paynow_data）
 *
 * 與後端 PaynowGateway::build_sdk_config() + before_order_received 的鍵一對一對齊。
 */
export interface IPaynowData {
	/** PayNow Component SDK v2 URL（固定 CDN js.paynow.com.tw，禁下載託管；環境由 createPayment env 切換） */
	sdk_url: string

	/** PublicKey（前端 Component SDK 初始化用；非機密。PrivateKey 絕不曝露前端） */
	public_key: string

	/** SDK 環境：'sandbox'=測試 / 'production'=正式 */
	env: 'sandbox' | 'production'

	/** SDK mount 目標容器 id（固定 paynow-container，前端必須使用此 id） */
	container_id: string

	/** PaymentIntent secret（pp_xxx_st_xxx，供 SDK createPayment 渲染收單；逐訂單機密） */
	secret: string

	/** 訂單 id（成功後導向 order-received 用） */
	order_id: string

	/** 訂單 order_key（WC 原生擁有權憑證，組 order-received URL 用） */
	order_key: string
}

/**
 * PayNow.checkout() 回傳（Component SDK v2）
 *
 * 來源：payment-rest-api.md §3：checkout() 回 Promise，response.error 非空代表前端流程錯誤。
 * 付款成功與否仍以後端 Webhook / GET payment-intents 為準（SDK checkout 成功只代表前端流程完成）。
 */
export interface IPaynowCheckoutResponse {
	/** 錯誤訊息（非空代表前端 checkout 失敗，不導頁） */
	error?: string | { message?: string } | null
}

/**
 * PayNow Component SDK v2 全域物件（window.PayNow，由 sdk/v2/index.js 注入）
 *
 * 方法簽章對齊 payment-rest-api.md §3：
 *  - createPayment({ publicKey, secret, env })：建立付款實例（secret 來自後端 PaymentIntent）
 *  - mount(selector, options?)：掛載收單 UI（options 可帶 appearance / locale）
 *  - checkout()：提交付款（回 Promise<IPaynowCheckoutResponse>，直接完成授權 + 3DS）
 *  - updateLocale(locale)：變更語系
 *  - on(event, cb)：註冊事件（mounted / update / paymentMethodSelected 等）
 */
export interface IPaynowGlobal {
	createPayment: (config: {
		publicKey: string
		secret: string
		env: 'sandbox' | 'production'
	}) => void

	mount: (selector: string, options?: { locale?: string }) => void

	checkout: () => Promise<IPaynowCheckoutResponse>

	updateLocale?: (locale: string) => void

	on?: (event: string, callback: (data: unknown) => void) => void
}
