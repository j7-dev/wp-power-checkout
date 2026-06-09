import { VueQueryPlugin, QueryClient } from '@tanstack/vue-query'
import ElementPlus from 'element-plus'
import { createApp } from 'vue'

import MountEcpgPayment from '@/external/EcpgPayment/index'
import MountInvoiceApp from '@/external/InvoiceApp/index'
import MountPayuniUniEmbed from '@/external/PayuniUniEmbed/index'
import MountRefundDialog from '@/external/RefundDialog/index'

import App from './App.vue'
import router from './router'
import 'element-plus/dist/index.css'
import './index.css'

const form = document.getElementById('mainform')
export const env = window.power_checkout_data.env

if (form) {
	// 刪除預設的元素
	form
		.querySelectorAll('h1, .notice, #message, .submit')
		.forEach((el) => el.remove())

	// 添加 div 容器
	const CONTAINER_ID = 'power-checkout-wc-setting-app'
	const div = document.createElement('div')
	div.id = CONTAINER_ID
	form.appendChild(div)

	// Mount Vue app
	const app = createApp(App)
	app.use(router)
	app.use(ElementPlus)

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

	app.mount(`#${CONTAINER_ID}`)
}

MountRefundDialog()
MountInvoiceApp()

// 綠界 ECPay 站內付 2.0：僅在 order-received 頁且有 power_checkout_ecpg_data 時啟動（內部自行守門）
MountEcpgPayment()

// PAYUNi UNi Embed V3 站內付：僅在 order-received 頁且有 power_checkout_payuni_uni_data 時啟動（內部自行守門）
MountPayuniUniEmbed()
