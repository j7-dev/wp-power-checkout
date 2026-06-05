/**
 * 綠界 ECPay 站內付 2.0（ECPG）order-received 頁 SDK 渲染模組
 *
 * 站內付 2.0 為「前端 SDK 收卡」流程，token 在下單後（before_process_payment）才產生，
 * 故 SDK 渲染落在 order-received 頁。本模組（接入 Vue 主 bundle，於 index.ts 條件 mount）：
 *
 *   1. 偵測 order-received 頁的 power_checkout_ecpg_data（透過 @/utils/env 取得，不直接讀 window）
 *   2. 依序載入綠界 SDK 三依賴：jQuery（WP bundle 已帶）→ node-forge → /Scripts/sdk-1.0.0.js
 *   3. ECPay.initialize('Stage'|'Prod', 1, cb) → ECPay.createPayment(token, 'zh-TW', cb, 'V2')
 *      在固定容器 #ECPayPayment（container_id）渲染收單 UI
 *   4. 顧客輸入卡片 → 點「確認付款」→ ECPay.getPayToken(cb) 取 PayToken（cb 第一參數為物件，取 .PayToken）
 *   5. POST create_payment_url { order_id, order_key, pay_token } 觸發後端 CreatePayment
 *   6. 依回應 data.three_d_url（後端已把巢狀 ThreeDInfo.ThreeDURL 攤平）：
 *        非空 → window.location = three_d_url 完成 3DS；空 → 顯示「已建立付款，等待結果」
 *
 * SDK 方法簽章與依賴順序唯一來源：ECPay-API-Skill guides/02-payment-ecpg.md（禁猜）。
 * create-payment 端點以 order_key 授權（非 nonce），故用 fetch 直接 POST，不帶 X-WP-Nonce。
 *
 * @see js/src/external/InvoiceApp/index.ts（外部 mini-app 標竿）
 * @see inc/classes/Domains/Payment/Ecpg/Services/EcpgGateway.php
 * @see inc/classes/Domains/Payment/Ecpg/Http/EcpgFrontendApi.php
 */

import { ElMessage } from 'element-plus'

// 註：Element Plus CSS 由主 bundle（js/src/index.ts）統一載入，本模組與其同 bundle，無需重複 import
import { loadScript } from '@/external/EcpgPayment/loadScript'
import {
	IEcpayPaymentInfo,
	IEcpgData,
	ICreatePaymentResponse,
} from '@/external/EcpgPayment/types'
import { ECPG_DATA } from '@/utils/env'

/**
 * node-forge CDN（綠界 SDK 前端加密依賴，須在 SDK 之前載入）
 * 版本對齊官方範例（guides/02 §JS SDK 三依賴）。
 */
const NODE_FORGE_URL =
	'https://cdn.jsdelivr.net/npm/node-forge@0.7.0/dist/forge.min.js'

/** jQuery fallback（WP bundle handle 已宣告 jquery 依賴，理論上已載入；此為防禦） */
const JQUERY_URL = 'https://code.jquery.com/jquery-3.7.1.min.js'

/** 付款介面語系（zh-TW 繁中 / en-US 英文，本專案統一繁中） */
const LANGUAGE = 'zh-TW'

/** 觸發付款的按鈕 id（本模組建立，附在容器下方） */
const SUBMIT_BUTTON_ID = 'pc-ecpg-submit'

/**
 * 確保 SDK 渲染容器 #ECPayPayment 存在（SDK 硬編碼此 id，不可自訂）
 *
 * 正常情況由後端 order-received 頁輸出；若缺失則建立並附到頁面主內容，避免 SDK 渲染無處可去。
 *
 * @param containerId 容器 id（來自後端 build_sdk_config，固定 ECPayPayment）
 * @return 容器元素
 */
const ensureContainer = (containerId: string): HTMLElement => {
	const existing = document.getElementById(containerId)
	if (existing) {
		return existing
	}
	const container = document.createElement('div')
	container.id = containerId

	// 優先掛在 WooCommerce order-received 區塊，否則退回 body
	const host =
		document.querySelector('.woocommerce-order') ||
		document.querySelector('.entry-content') ||
		document.body
	host.appendChild(container)
	return container
}

/**
 * 在容器下方建立「確認付款」按鈕，點擊觸發 getPayToken → create-payment
 *
 * @param container SDK 渲染容器（#ECPayPayment）
 * @param onClick   點擊處理（取 PayToken 並送後端）
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
 * 依序載入綠界 SDK 三依賴並回傳 window.ECPay
 *
 * jQuery → node-forge → sdk-1.0.0.js，缺一不可且須照順序（否則 SDK throw）。
 *
 * @return window.ECPay（載入後注入）
 * @throws SDK 或依賴載入失敗 / 載入後 window.ECPay 仍不存在
 */
const loadEcpaySdk = async (
	sdkUrl: string
): Promise<NonNullable<Window['ECPay']>> => {
	// jQuery：WP bundle handle 已宣告依賴，通常已存在；缺失才補載
	if (typeof window.jQuery === 'undefined') {
		await loadScript(JQUERY_URL)
	}
	await loadScript(NODE_FORGE_URL)
	await loadScript(sdkUrl)

	if (!window.ECPay) {
		throw new Error('綠界站內付 SDK 載入後 window.ECPay 不存在')
	}
	return window.ECPay
}

/**
 * 取得 PayToken 後 POST create-payment，依回應導向 3DS 或顯示結果
 *
 * @param data     ECPG order-received 資料（含 create_payment_url / order_id / order_key）
 * @param payToken SDK getPayToken 取得的 PayToken 字串
 * @param button   確認付款按鈕（控制 loading）
 */
const submitPayment = async (
	data: IEcpgData,
	payToken: string,
	button: HTMLButtonElement
): Promise<void> => {
	try {
		const resp = await fetch(data.create_payment_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				order_id: data.order_id,
				order_key: data.order_key,
				pay_token: payToken,
			}),
		})

		const result = (await resp.json()) as ICreatePaymentResponse

		if (!resp.ok || result.code !== 'success') {
			ElMessage.error(result.message || '建立付款失敗，請稍後再試或聯繫商家')
			setButtonLoading(button, false)
			return
		}

		const threeDUrl = result.data?.three_d_url ?? ''
		const need3ds = result.data?.need_3ds ?? false

		// 後端已把巢狀 ThreeDInfo.ThreeDURL 攤平為 three_d_url；非空即導向 3D 驗證頁
		if (need3ds && threeDUrl) {
			ElMessage.info('導向 3D 驗證頁面…')
			window.location.assign(threeDUrl)
			return
		}

		// 不需 3DS：付款已建立，最終結果由 ReturnURL 幕後通知，提示顧客即可
		ElMessage.success(result.message || '付款建立成功，正在確認結果…')
		setButtonLoading(button, false)
	} catch (err) {
		// 不外洩內部細節（後端已寫 order note / log）
		ElMessage.error('建立付款時發生錯誤，請稍後再試')
		setButtonLoading(button, false)
		console.error('ECPG create-payment 失敗', err)
	}
}

/**
 * 啟動站內付 2.0 收單流程（SDK 初始化 → 渲染 → 綁定取 PayToken）
 *
 * @param data ECPG order-received 資料
 */
const startEcpgPayment = async (data: IEcpgData): Promise<void> => {
	const container = ensureContainer(data.container_id)

	let sdk: NonNullable<Window['ECPay']>
	try {
		sdk = await loadEcpaySdk(data.sdk_url)
	} catch (err) {
		ElMessage.error('綠界付款元件載入失敗，請重新整理頁面')
		console.error('ECPG SDK 載入失敗', err)
		return
	}

	// 環境由 initialize 切換（字串 'Stage'|'Prod'，非整數）；type=1 為 Web
	const envType: 'Stage' | 'Prod' = data.is_test ? 'Stage' : 'Prod'

	sdk.initialize(envType, 1, (initErr) => {
		// errMsg 須用 != null 判斷（空字串非錯誤）
		if (initErr != null) {
			ElMessage.error('綠界付款元件初始化失敗')
			console.error('ECPay.initialize 失敗', initErr)
			return
		}

		// createPayment 必須在 initialize callback 內呼叫（避免 SDK 尚未就緒的競態）
		sdk.createPayment(
			data.token,
			LANGUAGE,
			(createErr) => {
				if (createErr != null) {
					ElMessage.error('綠界付款元件渲染失敗')
					console.error('ECPay.createPayment 失敗', createErr)
					return
				}

				// 渲染成功後才提供「確認付款」按鈕
				const button = renderSubmitButton(container, (btn) => {
					setButtonLoading(btn, true)
					sdk.getPayToken((paymentInfo: IEcpayPaymentInfo | null, payErr) => {
						if (payErr != null) {
							ElMessage.error('取得付款資訊失敗，請確認卡片資訊')
							setButtonLoading(btn, false)
							console.error('ECPay.getPayToken 失敗', payErr)
							return
						}

						// paymentInfo.PayToken 才是字串，不可送整個物件
						const payToken = paymentInfo?.PayToken
						if (!payToken || typeof payToken !== 'string') {
							ElMessage.error('PayToken 無效，請重新輸入卡片資訊')
							setButtonLoading(btn, false)
							return
						}
						void submitPayment(data, payToken, btn)
					})
				})

				// 確保按鈕初始為可點擊
				setButtonLoading(button, false)
			},
			'V2'
		)
	})
}

/**
 * 掛載入口：偵測 order-received 頁的 power_checkout_ecpg_data 後啟動 SDK 渲染
 *
 * 由 js/src/index.ts 呼叫。無資料（非 ECPG order-received 頁 / 未成功取號）時靜默 return，
 * 不污染其他頁面。
 *
 * @return void
 */
const MountEcpgPayment = (): void => {
	const data = ECPG_DATA

	// 無資料或無 token：非站內付 order-received 頁，不啟動
	if (!data || !data.token) {
		return
	}
	void startEcpgPayment(data)
}

export default MountEcpgPayment
