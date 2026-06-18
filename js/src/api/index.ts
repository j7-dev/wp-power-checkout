import axios from 'axios'
import { ElNotification } from 'element-plus'

import { API_URL, NONCE } from '@/utils/env'
import { resolveErrorMessage, getNormalizedError } from '@/utils/error-code'

// 403（nonce / cookie 過期）時的確認對話框文案
const SESSION_EXPIRED_CONFIRM =
	'\n網站 Cookie 已經過期，請重新整理頁面後才能繼續使用\n\n按 【確認】 ，重新整理頁面\n\n或者按 【取消】 ，您可以手動複製尚未儲存的資料避免頁面刷新後遺失'

const apiClient = axios.create({
	baseURL: `${API_URL}/power-checkout/v1/`,
	timeout: 30000,
	headers: {
		'Content-Type': 'application/json',
		'X-WP-Nonce': NONCE,
	},
})

// Response 攔截器
apiClient.interceptors.response.use(
	(response) => {
		// 成功處理：非 GET 且帶 message 時由此統一彈出成功通知
		const method = (response.config.method || 'get').toUpperCase()
		if (method === 'GET') {
			return response
		}

		const message = response?.data?.message

		if (!message) {
			return response
		}

		ElNotification({
			title: '成功',
			message,
			position: 'bottom-right',
			type: 'success',
		})
		return response
	},

	// 錯誤處理
	(error) => {
		if (error.response) {
			// 伺服器有響應但狀態碼表示錯誤
			switch (error.response.status) {
				case 403:
					// eslint-disable-next-line no-alert
					if (window.confirm(SESSION_EXPIRED_CONFIRM)) {
						window.location.reload()
					}
					break
				default:
					break
			}
		}

		// 解析後端正規化錯誤回應：優先用 error_code 對照友善訊息，
		// 找不到對照（或非本契約回應）才 fallback 後端 message。
		// 維持「ElNotification 由 interceptor 統一處理，元件不手動觸發」鐵律。
		const normalized = getNormalizedError(error)

		// 沒有任何可顯示訊息（無 response / 無 message / 無 error_code）時，
		// 不彈通知，直接把錯誤往下拋給呼叫端的 onError 處理。
		if (!normalized?.error_code && !normalized?.message) {
			return Promise.reject(error)
		}

		ElNotification({
			title: '發生 API 錯誤',
			message: resolveErrorMessage(normalized),
			position: 'bottom-right',
			type: 'error',
		})

		// 返回錯誤（呼叫端 onError 仍可讀 error_code 做進一步分支）
		return Promise.reject(error)
	}
)

export default apiClient
