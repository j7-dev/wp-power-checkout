/**
 * 動態載入 PayNow Component SDK v2（內嵌式信用卡 / 多元付款元件）
 *
 * ⚠️ PayNow 安全規範：SDK 一律由 js.paynow.com.tw 載入（CSP script-src + frame-src 須白名單），
 *    禁止下載至商店主機託管；測試 / 正式由 createPayment 的 env: 'sandbox'|'production' 切換，
 *    非換 SDK URL（來源：payment-rest-api.md §3）。
 * 載入後 window.PayNow 即可用；以 Promise 串接確保 onload 後才繼續流程。
 */

/** 已載入 / 載入中的 script，避免重複注入（key = src） */
const loadingMap = new Map<string, Promise<void>>()

/**
 * 注入 PayNow SDK script 並等待其載入完成
 *
 * 同一 src 重複呼叫回傳同一個 Promise（冪等，避免 SPA 重掛或多次 mount 重複載入）。
 *
 * @param src SDK URL（後端 build_sdk_config 提供，固定 js.paynow.com.tw/sdk/v2/index.js）
 * @return 載入完成（含已快取）resolve；載入失敗 reject
 */
export const loadScript = (src: string): Promise<void> => {
	const cached = loadingMap.get(src)
	if (cached) {
		return cached
	}

	const promise = new Promise<void>((resolve, reject) => {
		// 頁面上若已存在相同 src 的 script，直接視為完成
		const exist = document.querySelector<HTMLScriptElement>(
			`script[src="${src}"]`
		)
		if (exist) {
			resolve()
			return
		}

		const script = document.createElement('script')
		script.src = src
		script.async = false
		script.onload = () => resolve()
		script.onerror = () =>
			reject(new Error(`PayNow 內嵌付款 SDK 載入失敗：${src}`))
		document.head.appendChild(script)
	})

	loadingMap.set(src, promise)
	return promise
}
