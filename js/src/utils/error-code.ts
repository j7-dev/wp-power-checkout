/**
 * 正規化錯誤碼（error_code）→ 友善繁中訊息對照
 *
 * einvoice 導入第九階段（前端錯誤顯示）：後端 P5/P6 已讓 REST 錯誤回應帶
 * `error_code`（正規化 code）+ `raw_code` + `message`。本模組把這組固定的正規化
 * code 對映為「可行動」的繁體中文訊息，供 axios interceptor 與元件在錯誤發生時
 * 顯示比泛用 `message` 更精確的提示。
 *
 * 正規化 code 值域與後端 `inc/classes/Shared/Errors/ErrorCode.php`（10 值 backed enum）
 * 為單一事實來源；若後端調整值域，請同步本檔。
 *
 * @see inc/classes/Shared/Errors/ErrorCode.php
 */

/**
 * 後端正規化錯誤碼（與 ErrorCode.php 的 10 個 case 一一對應）
 *
 * 採 `as const` 物件 + union type，符合專案「有限狀態不用 magic string」慣例。
 */
export const ERROR_CODE = {
	// 認證失敗（金鑰 / JWT / 商店憑證 / 簽章金鑰錯誤）
	AUTH: 'AUTH',

	// 驗證失敗（UBN checksum / 載具捐贈互斥 / 金額守恆 / 退款金額不合法 / 必填缺）
	VALIDATION: 'VALIDATION',

	// 查無資源（查無發票 / 查無交易）
	NOT_FOUND: 'NOT_FOUND',

	// 狀態衝突（重複開立 / 已作廢 / 已開折讓 / 重複處理）
	CONFLICT: 'CONFLICT',

	// 字軌號碼用罄（發票專屬）
	NUMBER_EXHAUSTED: 'NUMBER_EXHAUSTED',

	// 驗章失敗（CheckCode / CheckMacValue / HMAC / HashInfo 不符）
	SIGNATURE: 'SIGNATURE',

	// 不支援的操作（不支援折讓 / 查詢；退款不支援 / capture / void no-op）
	UNSUPPORTED: 'UNSUPPORTED',

	// 連線失敗（API 連線失敗 / 逾時 / 無回應）
	NETWORK: 'NETWORK',

	// provider 回未分類錯誤碼（映射表未涵蓋，保留 raw_code 供 debug）
	PROVIDER: 'PROVIDER',

	// 未預期 \Throwable（never-throw 鐵律下，driver 例外一律歸此）
	UNKNOWN: 'UNKNOWN',
} as const

/** 正規化錯誤碼字面量聯集型別 */
export type TErrorCode = (typeof ERROR_CODE)[keyof typeof ERROR_CODE]

/**
 * 正規化 code → 友善繁中訊息對照表
 *
 * 僅收錄「能給出比後端 message 更精確 / 可行動」的 code。
 * PROVIDER / UNKNOWN / SIGNATURE 等情境後端 message 已足夠具體，刻意不在此表，
 * 由 `resolveErrorMessage()` fallback 回後端 `message`。
 */
const ERROR_CODE_MESSAGE: Partial<Record<TErrorCode, string>> = {
	[ERROR_CODE.UNSUPPORTED]: '此付款方式不支援線上退款，請至金流後台手動處理',
	[ERROR_CODE.VALIDATION]: '資料驗證失敗，請檢查發票 / 退款欄位',
	[ERROR_CODE.AUTH]: '金流 / 發票憑證錯誤，請至設定頁確認金鑰',
	[ERROR_CODE.NETWORK]: '連線逾時，請稍後再試',
	[ERROR_CODE.CONFLICT]: '狀態衝突（可能已開立 / 已作廢）',
	[ERROR_CODE.NOT_FOUND]: '查無對應發票 / 交易',
	[ERROR_CODE.NUMBER_EXHAUSTED]: '發票字軌號碼已用罄',
}

/**
 * 後端錯誤回應 envelope（P5/P6 契約）
 *
 * `error.response.data` 的形狀；所有欄位皆為選填以容忍非本契約的舊回應 / 第三方錯誤。
 *
 * @see inc/classes/Shared/Errors/NormalizedError.php
 */
export interface INormalizedErrorResponse {
	// 既有回應碼（如 'error' / 'success'；非正規化錯誤碼）
	code?: string

	// 正規化錯誤碼（10 值之一）
	error_code?: TErrorCode | string

	// provider 原始錯誤碼（debug 用，映射表未涵蓋時保留）
	raw_code?: string

	// 後端產生的人類可讀訊息（fallback 來源）
	message?: string

	// 附帶資料
	data?: unknown
}

/**
 * 將正規化 error_code 解析為友善繁中訊息
 *
 * 解析優先序：對照表命中 → 後端 `message` → 預設泛用訊息。
 * 「ElNotification 由 interceptor 處理」鐵律下，本函式只負責「算出要顯示的字串」，
 * 不觸發任何通知。
 *
 * @param data 後端錯誤回應 envelope（`error.response.data`），可為 undefined
 * @return 適合顯示給使用者的繁中錯誤訊息
 */
export function resolveErrorMessage(
	data?: INormalizedErrorResponse | null
): string {
	const friendly = data?.error_code
		? ERROR_CODE_MESSAGE[data.error_code as TErrorCode]
		: undefined

	return friendly || data?.message || '發生未預期的錯誤，請稍後再試'
}

/**
 * 從 axios 錯誤物件取出後端正規化錯誤 envelope
 *
 * 容忍非 axios / 無 response 的錯誤（回 undefined）。元件層想讀 `error_code`
 * 做分支（如 RefundDialog 對 UNSUPPORTED 的特別提示）時使用。
 *
 * @param error 未知型別的錯誤物件（多半是 axios error）
 * @return 正規化錯誤 envelope，取不到時回 undefined
 */
export function getNormalizedError(
	error: unknown
): INormalizedErrorResponse | undefined {
	if (error && typeof error === 'object' && 'response' in error) {
		const response = (error as { response?: { data?: unknown } }).response
		const responseData = response?.data
		if (responseData && typeof responseData === 'object') {
			return responseData as INormalizedErrorResponse
		}
	}
	return undefined
}

/**
 * 判斷某錯誤是否屬於指定正規化錯誤碼
 *
 * @param error 未知型別的錯誤物件（多半是 axios error）
 * @param code  欲比對的正規化錯誤碼
 * @return 命中回 true
 */
export function isErrorCode(error: unknown, code: TErrorCode): boolean {
	return getNormalizedError(error)?.error_code === code
}
