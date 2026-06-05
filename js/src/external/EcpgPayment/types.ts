/**
 * 綠界 ECPay 站內付 2.0（ECPG）order-received 頁 SDK 渲染模組型別
 *
 * 資料來源為後端 EcpgGateway::before_order_received 的 wp_localize_script（power_checkout_ecpg_data）
 * 與 build_sdk_config()。SDK 介面（IEcpaySdk）對齊綠界官方 sdk-1.0.0.js 的實際方法，
 * 來源：ECPay-API-Skill guides/02-payment-ecpg.md §前端 JavaScript SDK 整合（唯一 API 來源，禁猜）。
 */

/**
 * order-received 頁專屬逐訂單 + 靜態 SDK 設定（power_checkout_ecpg_data）
 *
 * 與後端 EcpgGateway::build_sdk_config() + before_order_received 的鍵一對一對齊。
 */
export interface IEcpgData {
	/** 綠界站內付 JS SDK URL（一律正式 domain，環境由 initialize 切換） */
	sdk_url: string

	/** 特店編號（公開，非機密；HashKey/HashIV 絕不曝露前端） */
	merchant_id: string

	/** 取得 PayToken 後 POST 回此端點觸發 CreatePayment（order_key 授權，非 nonce） */
	create_payment_url: string

	/** 測試環境（true → ECPay.initialize('Stage')；false → 'Prod'） */
	is_test: boolean

	/** SDK 硬編碼渲染容器 id（固定為 ECPayPayment，不可自訂） */
	container_id: string

	/** 後端 GetTokenbyTrade 取得的交易 Token，供 SDK createPayment 渲染收單 UI */
	token: string

	/** 冪等鍵 MerchantTradeNo（後端用，前端僅透傳 / 不直接送 create-payment） */
	merchant_trade_no: string

	/** 訂單 id（POST create-payment 用） */
	order_id: string

	/** 訂單 order_key（WC 原生擁有權憑證，POST create-payment 授權用） */
	order_key: string
}

/** create-payment 端點回應的 data 內容（{ code, message, data } 的 data） */
export interface ICreatePaymentData {
	/** CreatePayment 回應巢狀 ThreeDInfo.ThreeDURL 攤平；非空時前端必須導向完成 3DS */
	three_d_url: string

	/** 是否需 3D 驗證（true → 導向 three_d_url） */
	need_3ds: boolean
}

/** create-payment 端點回應（後端統一 { code, message, data } 格式） */
export interface ICreatePaymentResponse {
	code: string
	message: string
	data: ICreatePaymentData | null
}

/**
 * 綠界站內付 getPayToken 回呼的第一個參數（物件，非字串）
 *
 * ⚠️ PayToken 才是字串（JWT 格式 xxx.yyy.zzz），不可把整個物件送後端。
 */
export interface IEcpayPaymentInfo {
	PayToken: string
}

/**
 * 綠界站內付 2.0 JS SDK 全域物件（window.ECPay）
 *
 * 方法簽章對齊官方 sdk-1.0.0.js（positional 風格）：
 *  - initialize(env, type, callback)：env 為字串 'Stage'|'Prod'（非整數）、type=1 為 Web
 *  - createPayment(token, language, callback, version)：須在 initialize callback 內呼叫
 *  - getPayToken(callback)：消費者填完卡片後取得 PayToken
 * 回呼一律以 errMsg（string | null）回報錯誤，須用 `errMsg != null` 判斷（空字串非錯誤）。
 */
export interface IEcpaySdk {
	initialize: (
		env: 'Stage' | 'Prod',
		type: number,
		callback: (errMsg: string | null) => void
	) => void
	createPayment: (
		token: string,
		language: string,
		callback: (errMsg: string | null) => void,
		version: string
	) => void
	getPayToken: (
		callback: (
			paymentInfo: IEcpayPaymentInfo | null,
			errMsg: string | null
		) => void
	) => void
	getLanguage?: () => string
}
