/**
 * PayNow（立吉富）體系 1 Component SDK v2（內嵌式，站內 iframe）order-received 頁渲染模組
 *
 * PayNow 為「前端 SDK 收卡 → SDK 直接授權 + 3DS」流程，PaymentIntent secret 在下單後
 * （before_process_payment 呼叫 create_payment_intent）才產生，故 SDK 渲染落在 order-received 頁
 * （比照綠界 ECPG / PAYUNi UNi Embed）。本模組（接入 Vue 主 bundle，於 index.ts 條件 mount）：
 *
 *   1. 偵測 order-received 頁的 power_checkout_paynow_data（透過 @/utils/env 取得，不直接讀 window）
 *   2. 載入 PayNow SDK：https://js.paynow.com.tw/sdk/v2/index.js（env sandbox/production 切換，禁本機託管）
 *   3. PayNow.createPayment({ publicKey, secret, env }) → mount('#paynow-container')（顯示收單 iframe）
 *   4. 顧客填卡 → 點「確認付款」→ PayNow.checkout()
 *        - response.error → 顯示錯誤，不導頁（顧客可重填卡片）
 *        - 成功 → 導向 order-received URL（**結果一律以後端 Webhook NotifyURL 為準**）
 *
 * ⚠️ 與 PAYUNi UNi Embed 的「減一支」差異：
 *    PayNow SDK checkout() 直接與 PayNow 完成授權 + 3DS，**無「前端 POST create-payment 觸發後端
 *    merchant_trade」中間步驟**。前端 checkout 成功僅代表流程完成，付款結果以 Webhook 為準。
 *    故本模組無 fetch create-payment、無 order_key POST 授權。
 *
 * SDK 方法簽章與行為唯一來源：.claude/skills/paynow/references/payment-rest-api.md §3
 * 與 php-examples.md §3（禁猜、禁上網）。
 *
 * @see js/src/external/PayuniUniEmbed/index.ts（內嵌式 SDK 標竿，本模組減 create-payment POST）
 * @see inc/classes/Domains/Payment/Paynow/Services/PaynowGateway.php build_sdk_config()
 */

import { ElMessage } from 'element-plus'

// 註：Element Plus CSS 由主 bundle（js/src/index.ts）統一載入，本模組與其同 bundle，無需重複 import
import { loadScript } from '@/external/PaynowPayment/loadScript'
import {
	IPaynowCheckoutResponse,
	IPaynowData,
} from '@/external/PaynowPayment/types'
import { PAYNOW_DATA, SITE_URL } from '@/utils/env'

/** 觸發付款的按鈕 id（本模組建立，附在容器下方） */
const SUBMIT_BUTTON_ID = 'pc-paynow-submit'

/**
 * 確保 SDK mount 目標容器存在（後端固定 container_id = paynow-container）
 *
 * PayNow SDK mount('#paynow-container') 需此容器；正常可由後端 order-received 頁輸出，
 * 若缺失則建立並附到頁面主內容，避免 SDK 渲染無處可去。
 *
 * @param data PayNow order-received 資料（含 container_id）
 * @return SDK mount 目標容器
 */
const ensureContainer = (data: IPaynowData): HTMLElement => {
	const existing = document.getElementById(data.container_id)
	if (existing) {
		return existing
	}

	const container = document.createElement('div')
	container.id = data.container_id
	container.style.marginBottom = '12px'

	const host =
		document.querySelector('.woocommerce-order') ||
		document.querySelector('.entry-content') ||
		document.body
	host.appendChild(container)
	return container
}

/**
 * 在容器下方建立「確認付款」按鈕，點擊觸發 PayNow.checkout()
 *
 * @param container SDK mount 容器
 * @param onClick   點擊處理（提交付款）
 * @return 按鈕元素（供 disable / loading 控制）
 */
const renderSubmitButton = (
	container: HTMLElement,
	onClick: (button: HTMLButtonElement) => void
): HTMLButtonElement => {
	const existing = document.getElementById(SUBMIT_BUTTON_ID)
	if (existing instanceof HTMLButtonElement) {
		return existing
	}
	const button = document.createElement('button')
	button.id = SUBMIT_BUTTON_ID
	button.type = 'button'
	button.className = 'el-button el-button--primary'
	button.style.marginTop = '16px'
	button.textContent = '確認付款'
	button.addEventListener('click', () => onClick(button))
	container.insertAdjacentElement('afterend', button)
	return button
}

/** 切換按鈕 loading / disabled 狀態 */
const setButtonLoading = (
	button: HTMLButtonElement,
	loading: boolean
): void => {
	button.disabled = loading
	button.textContent = loading ? '處理中…' : '確認付款'
}

/**
 * 組裝 order-received URL（checkout 成功後導向；最終結果以 Webhook 為準）
 *
 * 以 SITE_URL + order_id + order_key 組標準 WC order-received 路徑；
 * SITE_URL 缺失時退而原地 reload（避免硬編網域）。
 *
 * @param data PayNow order-received 資料
 * @return order-received URL
 */
const buildOrderReceivedUrl = (data: IPaynowData): string => {
	const base = (SITE_URL || '').replace(/\/$/, '')
	if (!base) {
		return window.location.href
	}
	return `${base}/checkout/order-received/${data.order_id}/?key=${data.order_key}`
}

/**
 * 解析 checkout 回應的錯誤訊息（error 可能為 string 或 { message }）
 *
 * @param error checkout response.error
 * @return 錯誤訊息字串（無錯誤回空字串）
 */
const resolveCheckoutError = (
	error: IPaynowCheckoutResponse['error']
): string => {
	if (!error) {
		return ''
	}
	if (typeof error === 'string') {
		return error
	}
	return error.message || '付款失敗，請確認卡片資訊後再試'
}

/**
 * 啟動 PayNow 收單流程（SDK 載入 → createPayment → mount → checkout）
 *
 * @param data PayNow order-received 資料
 */
const startPaynow = async (data: IPaynowData): Promise<void> => {
	const container = ensureContainer(data)

	try {
		await loadScript(data.sdk_url)
	} catch (err) {
		ElMessage.error('PayNow 付款元件載入失敗，請重新整理頁面')
		console.error('PayNow SDK 載入失敗', err)
		return
	}

	if (!window.PayNow) {
		ElMessage.error('PayNow 付款元件載入後 window.PayNow 不存在')
		return
	}

	// createPayment：publicKey / secret / env 由後端 build_sdk_config + before_order_received 提供
	try {
		window.PayNow.createPayment({
			publicKey: data.public_key,
			secret: data.secret,
			env: data.env,
		})
		window.PayNow.mount(`#${data.container_id}`, { locale: 'zh_tw' })
	} catch (err) {
		ElMessage.error('PayNow 付款元件初始化失敗')
		console.error('PayNow.createPayment / mount 失敗', err)
		return
	}

	// 渲染成功後才提供「確認付款」按鈕
	const button = renderSubmitButton(container, (btn) => {
		setButtonLoading(btn, true)

		// ⚠️ 減一支：checkout() 直接與 PayNow 完成授權 + 3DS，無 POST create-payment 中間步驟
		window
			.PayNow!.checkout()
			.then((response: IPaynowCheckoutResponse) => {
				const errMessage = resolveCheckoutError(response?.error)
				if (errMessage) {
					ElMessage.error(errMessage)
					setButtonLoading(btn, false)
					return
				}

				// 成功：導向 order-received（最終付款結果一律以後端 Webhook NotifyURL 為準）
				ElMessage.success('付款已送出，正在確認結果…')
				window.location.assign(buildOrderReceivedUrl(data))
			})
			.catch((err: unknown) => {
				ElMessage.error('付款時發生錯誤，請稍後再試')
				setButtonLoading(btn, false)
				console.error('PayNow.checkout 失敗', err)
			})
	})

	setButtonLoading(button, false)
}

/**
 * 掛載入口：偵測 order-received 頁的 power_checkout_paynow_data 後啟動 SDK 渲染
 *
 * 由 js/src/index.ts 呼叫。無資料（非 PayNow order-received 頁 / 未成功建立 PaymentIntent）時
 * 靜默 return，不污染其他頁面。
 *
 * @return void
 */
const MountPaynowPayment = (): void => {
	const data = PAYNOW_DATA

	// 無資料或無 secret：非 PayNow order-received 頁 / 未成功建立 PaymentIntent，不啟動
	if (!data || !data.secret) {
		return
	}
	void startPaynow(data)
}

export default MountPaynowPayment
