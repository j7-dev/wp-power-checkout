/**
 * PAYUNi UNi Embed V3（內嵌式信用卡，站內付不跳轉）order-received 頁 SDK 渲染模組
 *
 * UNi Embed 為「前端 SDK 收卡 → 後端幕後授權」流程，SDK_TOKEN 在下單後（before_process_payment）
 * 才產生，故 SDK 渲染落在 order-received 頁（比照綠界 ECPG）。本模組（接入 Vue 主 bundle，於
 * index.ts 條件 mount）：
 *
 *   1. 偵測 order-received 頁的 power_checkout_payuni_uni_data（透過 @/utils/env 取得，不直接讀 window）
 *   2. 載入 PAYUNi SDK：https://vendor.payuni.com.tw/sdk/uni-payment.js（env S/P 切換，禁本機託管）
 *   3. UniPayment.createSession(SDK_TOKEN, { env, elements: 固定容器 ids }) → start()（顯示 iframe 輸入框）
 *   4. onUpdate（三欄位驗證狀態）→ 顧客填卡 → 點「確認付款」→ getTradeResult()（V3：僅取得卡號綁定 TOKEN 結果，不授權）
 *   5. POST create_payment_url { order_id, order_key, trade_result } 觸發後端 merchant_trade 幕後授權
 *   6. 依回應 data.need_3ds：
 *        true  → window.location = data.three_d_url 完成 3D 驗證
 *        false → 顯示「已建立付款，等待結果」（最終結果以 NotifyURL 為準）
 *
 * SDK 方法簽章與行為唯一來源：payuni-uni-embed-v3 SKILL.md §SDK 整合 / §SDK API 完整索引（禁猜）。
 * create-payment 端點以 order_key 授權（非 nonce，支援訪客結帳），故用 fetch 直接 POST，不帶 X-WP-Nonce。
 *
 * @see js/src/external/EcpgPayment/index.ts（內嵌式 SDK 標竿）
 * @see inc/classes/Domains/Payment/PayuniUniEmbed/Services/PayuniUniEmbedGateway.php
 * @see inc/classes/Domains/Payment/PayuniUniEmbed/Http/PayuniUniEmbedFrontendApi.php
 */

import { ElMessage } from 'element-plus'

// 註：Element Plus CSS 由主 bundle（js/src/index.ts）統一載入，本模組與其同 bundle，無需重複 import
import { loadScript } from '@/external/PayuniUniEmbed/loadScript'
import {
	ICreatePaymentResponse,
	IPayuniUniData,
	IUniPaymentSdk,
} from '@/external/PayuniUniEmbed/types'
import { PAYUNI_UNI_DATA } from '@/utils/env'

/** 觸發付款的按鈕 id（本模組建立，附在容器下方） */
const SUBMIT_BUTTON_ID = 'pc-payuni-uni-submit'

/** 內嵌欄位容器外層包裝 id（本模組建立，承載三個 iframe 容器） */
const WRAPPER_ID = 'pc-payuni-uni-wrapper'

/**
 * 確保 SDK 渲染所需的三個固定容器存在（put_card_no / put_card_exp / put_card_cvc）
 *
 * PAYUNi SDK 硬編碼這些 id（不可自訂）。正常情況可由後端 order-received 頁輸出；
 * 若缺失則建立並附到頁面主內容，避免 SDK 渲染無處可去。
 *
 * @param data PAYUNi UNi Embed order-received 資料（含 container_ids）
 * @return 承載三容器的外層 wrapper（供按鈕掛載定位）
 */
const ensureContainers = (data: IPayuniUniData): HTMLElement => {
	const existingWrapper = document.getElementById(WRAPPER_ID)
	if (existingWrapper) {
		return existingWrapper
	}

	const wrapper = document.createElement('div')
	wrapper.id = WRAPPER_ID

	const fields: { id: string; label: string }[] = [
		{ id: data.container_ids.card_no, label: '信用卡號碼' },
		{ id: data.container_ids.card_exp, label: '有效期限 (MMYY)' },
		{ id: data.container_ids.card_cvc, label: '安全碼' },
	]

	fields.forEach(({ id, label }) => {
		// 若頁面已有此固定 id 容器（後端輸出），不重複建立，僅納入 wrapper 不動原 DOM
		if (document.getElementById(id)) {
			return
		}
		const field = document.createElement('div')
		field.style.marginBottom = '12px'

		const labelEl = document.createElement('label')
		labelEl.textContent = label
		labelEl.style.display = 'block'
		labelEl.style.marginBottom = '4px'

		const box = document.createElement('div')
		box.id = id
		box.style.height = '40px'
		box.style.border = '1px solid #dcdfe6'
		box.style.borderRadius = '4px'
		box.style.padding = '0 12px'

		field.appendChild(labelEl)
		field.appendChild(box)
		wrapper.appendChild(field)
	})

	const host =
		document.querySelector('.woocommerce-order') ||
		document.querySelector('.entry-content') ||
		document.body
	host.appendChild(wrapper)
	return wrapper
}

/**
 * 在容器下方建立「確認付款」按鈕，點擊觸發 getTradeResult → create-payment
 *
 * @param wrapper 容器外層 wrapper
 * @param onClick 點擊處理（取綁定結果並送後端）
 * @return 按鈕元素（供 disable / loading 控制）
 */
const renderSubmitButton = (
	wrapper: HTMLElement,
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
	wrapper.insertAdjacentElement('afterend', button)
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
 * 取得綁定結果後 POST create-payment，依回應導向 3D 或顯示結果
 *
 * @param data   PAYUNi UNi Embed order-received 資料（含 create_payment_url / order_id / order_key）
 * @param button 確認付款按鈕（控制 loading）
 */
const submitPayment = async (
	data: IPayuniUniData,
	button: HTMLButtonElement
): Promise<void> => {
	try {
		const resp = await fetch(data.create_payment_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				order_id: data.order_id,
				order_key: data.order_key,

				// V3：前端僅回報「已完成 SDK 綁定」；金額與授權一律後端 merchant_trade 處理
				trade_result: 'bound',
			}),
		})

		const result = (await resp.json()) as ICreatePaymentResponse

		if (!resp.ok || result.code !== 'success') {
			ElMessage.error(result.message || '建立付款失敗，請稍後再試或聯繫商家')
			setButtonLoading(button, false)
			return
		}

		const need3ds = result.data?.need_3ds ?? false
		const threeDUrl = result.data?.three_d_url ?? ''

		// 需 3D：導向銀行 3D 驗證頁（銀行驗證後 PAYUNi Form POST 至 NotifyURL）
		if (need3ds && threeDUrl) {
			ElMessage.info('導向 3D 驗證頁面…')
			window.location.assign(threeDUrl)
			return
		}

		// 非 3D：授權已建立，最終結果由 NotifyURL 幕後通知，提示顧客即可
		ElMessage.success(result.message || '付款建立成功，正在確認結果…')
		setButtonLoading(button, false)
	} catch (err) {
		// 不外洩內部細節（後端已寫 order note / log）
		ElMessage.error('建立付款時發生錯誤，請稍後再試')
		setButtonLoading(button, false)
		console.error('PAYUNi UNi Embed create-payment 失敗', err)
	}
}

/**
 * 啟動 UNi Embed 收單流程（SDK 載入 → createSession → start → onUpdate → 綁定取結果）
 *
 * @param data PAYUNi UNi Embed order-received 資料
 */
const startPayuniUniEmbed = async (data: IPayuniUniData): Promise<void> => {
	const wrapper = ensureContainers(data)

	try {
		await loadScript(data.sdk_url)
	} catch (err) {
		ElMessage.error('PAYUNi 付款元件載入失敗，請重新整理頁面')
		console.error('PAYUNi UNi Embed SDK 載入失敗', err)
		return
	}

	if (!window.UniPayment) {
		ElMessage.error('PAYUNi 付款元件載入後 window.UniPayment 不存在')
		return
	}

	// createSession：env 'S'|'P' 由後端 build_sdk_config 提供；elements 對應固定容器 id
	let sdk: IUniPaymentSdk
	try {
		sdk = window.UniPayment.createSession(data.sdk_token, {
			env: data.env,
			useInst: false, // 本 Cycle：一次付清（分期 useInst 留待後續 Cycle）
			elements: {
				CardNo: data.container_ids.card_no,
				CardExp: data.container_ids.card_exp,
				CardCvc: data.container_ids.card_cvc,
			},
			style: {
				color: '#1f2937',
				errorColor: '#ef4444',
				fontSize: '14px',
				fontWeight: '400',
				lineHeight: '24px',
			},
		})
	} catch (err) {
		ElMessage.error('PAYUNi 付款元件初始化失敗')
		console.error('UniPayment.createSession 失敗', err)
		return
	}

	// 欄位驗證狀態（三欄皆 true 才允許送出）
	const fieldStatus = { CardNo: false, CardExp: false, CardCvc: false }
	sdk.onUpdate((update) => {
		if (update.status) {
			fieldStatus.CardNo = update.status.CardNo === true
			fieldStatus.CardExp = update.status.CardExp === true
			fieldStatus.CardCvc = update.status.CardCvc === true
		}
	})

	try {
		// start()：驗證 origin（對照 IFrameDomain）/ token，顯示 iframe 輸入框
		await sdk.start()
	} catch (err) {
		// Code 1007=來源驗證失敗、1008/1009=連線超時、IFTRADE04001=token 逾期
		ElMessage.error('PAYUNi 付款元件載入失敗，請重新整理頁面再試')
		console.error('UniPayment.start 失敗', err)
		return
	}

	// 渲染成功後才提供「確認付款」按鈕
	const button = renderSubmitButton(wrapper, (btn) => {
		// 三欄位皆通過才送出（SDK iframe 內驗證；此為前端 UX 防呆）
		if (!fieldStatus.CardNo || !fieldStatus.CardExp || !fieldStatus.CardCvc) {
			ElMessage.warning('請完整且正確填寫信用卡資訊')
			return
		}

		setButtonLoading(btn, true)

		// V3：getTradeResult 僅取得卡號綁定 TOKEN 結果（不授權）；授權由後端 merchant_trade 完成
		sdk
			.getTradeResult()
			.then(() => {
				void submitPayment(data, btn)
			})
			.catch((err: unknown) => {
				ElMessage.error('信用卡資訊綁定失敗，請確認卡片資訊後再試')
				setButtonLoading(btn, false)
				console.error('UniPayment.getTradeResult 失敗', err)
			})
	})

	setButtonLoading(button, false)
}

/**
 * 掛載入口：偵測 order-received 頁的 power_checkout_payuni_uni_data 後啟動 SDK 渲染
 *
 * 由 js/src/index.ts 呼叫。無資料（非 UNi Embed order-received 頁 / 未成功取號）時靜默 return，
 * 不污染其他頁面。
 *
 * @return void
 */
const MountPayuniUniEmbed = (): void => {
	const data = PAYUNI_UNI_DATA

	// 無資料或無 SDK_TOKEN：非 UNi Embed order-received 頁，不啟動
	if (!data || !data.sdk_token) {
		return
	}
	void startPayuniUniEmbed(data)
}

export default MountPayuniUniEmbed
