// 訂單詳情頁掛載

import { createApp } from 'vue'
import Dialog from '@/external/RefundDialog/Dialog.vue'
import ElementPlus from 'element-plus'
import 'element-plus/dist/index.css'
import '@/index.css'
import { QueryClient, VueQueryPlugin } from '@tanstack/vue-query'

/**
 * 退款時跳出的 Dialog
 * @constructor
 */
const Module = () => {
	const CONTAINER_ID = 'power_checkout_refund_dialog'
	// 只在訂單詳情頁掛載：form#order = HPOS、form#post = 傳統訂單頁
	// 第二個條件作為重複掛載的防護（節點已存在就不再建立）
	const orderForm = document.querySelector('form#order, form#post')
	if (!orderForm || document.getElementById(CONTAINER_ID)) {
		return
	}
	const body = document.querySelector('body')
	// 在 body 結束前 render div
	body?.insertAdjacentHTML('beforeend', `<div id="${CONTAINER_ID}"></div>`)

	// Mount Vue app
	const app = createApp(Dialog)

	const queryClient = new QueryClient({
		defaultOptions: {
			queries: {
				staleTime: 15 * 60 * 1000, // 15 分鐘內視為新鮮
				gcTime: 15 * 60 * 1000, // 快取保留 15 分鐘
				retry: 0, // 最多重試 0 次
				refetchOnWindowFocus: false, // 禁用視窗聚焦時重新請求
			},
			mutations: {
				retry: 0, // mutation 失敗不重試次
			},
		},
	})
	app.use(VueQueryPlugin, { queryClient })
	app.use(ElementPlus)
	app.mount(`#${CONTAINER_ID}`)
}

export default Module
