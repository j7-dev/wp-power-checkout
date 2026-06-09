/**
 * PAYUNi UNi Embed V3（內嵌式信用卡，站內付不跳轉）order-received 頁 SDK 渲染模組型別
 *
 * 資料來源為後端 PayuniUniEmbedGateway::before_order_received 的 wp_localize_script
 * （power_checkout_payuni_uni_data）與 build_sdk_config()。SDK 介面（IUniPaymentSdk）對齊
 * PAYUNi 官方 uni-payment.js（JS SDK Ver 2.0）的實際方法，
 * 來源：payuni-uni-embed-v3 SKILL.md §SDK 整合 / §SDK API 完整索引（唯一 API 來源，禁猜）。
 */

/** 後端 build_sdk_config 提供的固定容器 id（SDK 約定，前端必須使用此 id） */
export interface IPayuniUniContainerIds {
	/** 卡號 iframe 容器 id（固定 put_card_no） */
	card_no: string

	/** 有效期限 iframe 容器 id（固定 put_card_exp） */
	card_exp: string

	/** CVC iframe 容器 id（固定 put_card_cvc） */
	card_cvc: string

	/** 約定 / 記憶卡號 checkbox 容器 id（固定 put_token_type；本 Cycle 一次付清不啟用） */
	token_type: string
}

/**
 * order-received 頁專屬逐訂單 + 靜態 SDK 設定（power_checkout_payuni_uni_data）
 *
 * 與後端 PayuniUniEmbedGateway::build_sdk_config() + before_order_received 的鍵一對一對齊。
 */
export interface IPayuniUniData {
	/** PAYUNi 內嵌 JS SDK URL（固定 vendor domain，禁下載託管；環境由 createSession env 切換） */
	sdk_url: string

	/** 商店代號 MerID（公開，非機密；HashKey/HashIV 絕不曝露前端） */
	merchant_id: string

	/** SDK 環境：'S'=測試（sandbox-vendor）/ 'P'=正式 */
	env: 'S' | 'P'

	/** token_get 階段傳入的 IFrameDomain（供來源驗證對照，含 https://） */
	iframe_domain: string

	/** 取得綁定結果後 POST 回此端點觸發 merchant_trade（order_key 授權，非 nonce） */
	create_payment_url: string

	/** SDK 約定的固定容器 id（put_card_no / put_card_exp / put_card_cvc / put_token_type） */
	container_ids: IPayuniUniContainerIds

	/** 後端 token_get 取得的 SDK_TOKEN（10 分鐘有效，供 SDK createSession + merchant_trade 共用） */
	sdk_token: string

	/** 訂單 id（POST create-payment 用） */
	order_id: string

	/** 訂單 order_key（WC 原生擁有權憑證，POST create-payment 授權用） */
	order_key: string
}

/** create-payment 端點回應的 data 內容（{ code, message, data } 的 data） */
export interface ICreatePaymentData {
	/** 是否需 3D 驗證（true → 導向 three_d_url 進行銀行 3D 驗證） */
	need_3ds: boolean

	/** merchant_trade 回傳的 3D 導頁 URL（need_3ds=true 時才有；非 3D 不帶此鍵） */
	three_d_url?: string
}

/** create-payment 端點回應（後端統一 { code, message, data } 格式） */
export interface ICreatePaymentResponse {
	code: string
	message: string
	data: ICreatePaymentData | null
}

/**
 * uni-payment.js onUpdate 回呼的單一欄位驗證狀態
 *
 * 來源：SKILL §onUpdate 事件 — true=驗證通過 / null=尚未填寫 / false=欄位錯誤 / 'typing'=輸入中。
 */
export type UniPaymentFieldStatus = true | false | null | 'typing'

/** uni-payment.js onUpdate 回呼參數（status 為三欄位驗證狀態；event 為特殊事件） */
export interface IUniPaymentUpdate {
	status?: {
		CardNo: UniPaymentFieldStatus
		CardExp: UniPaymentFieldStatus
		CardCvc: UniPaymentFieldStatus
	}
	event?: 'useTokenType'
	data?: {
		tokenType: '1' | '2' | '3'
		tokenTypeText: string
		cardNo: string | null
	}
}

/**
 * uni-payment.js createSession 初始化選項
 *
 * elements 與 HTML 容器 id 對應；env 必填（'S'|'P'）；useInst 啟用分期才設 true。
 * 來源：SKILL §createSession options（唯一 API 來源）。
 */
export interface IUniPaymentInitOptions {
	env: 'S' | 'P'
	useInst: boolean
	elements: {
		CardNo: string
		CardExp: string
		CardCvc: string
		CardTokenType?: string
	}
	style?: {
		color?: string
		errorColor?: string
		fontSize?: string
		fontWeight?: string
		lineHeight?: string
	}
}

/**
 * uni-payment.js getTradeResult 回傳（V3：僅卡號綁定 TOKEN 結果，不執行授權）
 *
 * ⚠️ V3 關鍵：getTradeResult 只做「SDK Token 綁定」，授權由後端 merchant_trade 完成。
 * 來源：SKILL §getTradeResult(config) 參數 §V3 vs V2 行為差異。
 */
export interface IUniPaymentTradeResult {
	EncryptInfo: string
	HashInfo: string
	MerID: string
	Status: string
	Version: string
}

/**
 * uni-payment.js SDK 實例（UniPayment.createSession 回傳）
 *
 * 方法簽章對齊官方 uni-payment.js（JS SDK Ver 2.0）：
 *  - start()：驗證 origin / token，顯示 iframe 輸入框（須先 resolve 才可呼叫其他方法）
 *  - onUpdate(cb)：註冊欄位驗證狀態 / 事件回呼
 *  - getCardAcceptInfo()：取得支援分期期數（須 createSession useInst:true）
 *  - getTradeResult(config)：V3 僅取得卡號綁定 TOKEN 結果，不授權
 * 來源：SKILL §SDK API 完整索引（唯一 API 來源，禁猜）。
 */
export interface IUniPaymentSdk {
	start: () => Promise<unknown>
	onUpdate: (callback: (update: IUniPaymentUpdate) => void) => void
	getCardAcceptInfo: () => Promise<{ CreditInst: Record<string, string> }>
	getTokenTypeText: (callback: (text: string) => void) => string
	getTradeResult: (config?: {
		cardInst?: number
		useDefault?: boolean
	}) => Promise<IUniPaymentTradeResult>
}

/** PAYUNi uni-payment.js 全域物件（window.UniPayment，由 SDK 注入） */
export interface IUniPaymentGlobal {
	createSession: (
		token: string,
		options: IUniPaymentInitOptions
	) => IUniPaymentSdk
}
