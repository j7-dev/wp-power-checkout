/**
 * 動態載入外部 <script>（綠界站內付 SDK 三依賴依序載入用）
 *
 * 綠界 JS SDK 依賴順序：jQuery → node-forge → /Scripts/sdk-1.0.0.js（大寫 S），
 * 缺任一或順序錯誤 SDK 會 throw Error（來源：ECPay-API-Skill guides/02 §JS SDK 三依賴）。
 * 本助手以 Promise 串接確保「前一個 onload 後才載入下一個」。
 */

/** 已載入 / 載入中的 script，避免重複注入（key = src） */
const loadingMap = new Map<string, Promise<void>>()

/**
 * 注入一支外部 script 並等待其載入完成
 *
 * 同一 src 重複呼叫會回傳同一個 Promise（冪等，避免 SPA 重掛或多次 mount 重複載入）。
 *
 * @param src script URL
 * @return 載入完成（含已快取）resolve；載入失敗 reject
 */
export const loadScript = (src: string): Promise<void> => {
	const cached = loadingMap.get(src)
	if (cached) {
		return cached
	}

	const promise = new Promise<void>((resolve, reject) => {
		// 頁面上若已存在相同 src 的 script（如其他流程已載入），直接視為完成
		const exist = document.querySelector<HTMLScriptElement>(
			`script[src="${src}"]`
		)
		if (exist) {
			resolve()
			return
		}

		const script = document.createElement('script')
		script.src = src
		script.async = false // 保留執行順序（與 appendChild 串接搭配）
		script.onload = () => resolve()
		script.onerror = () =>
			reject(new Error(`綠界站內付 SDK 依賴載入失敗：${src}`))
		document.head.appendChild(script)
	})

	loadingMap.set(src, promise)
	return promise
}
