// router/index.js
import { createRouter, createMemoryHistory } from 'vue-router'

export const ROUTER_MAPPER = {
	shopline_payment_redirect: '/payments/shopline_payment_redirect',
	ecpay_aio: '/payments/ecpay_aio',
	ecpay_ecpg: '/payments/ecpay_ecpg',
	newebpay_mpg: '/payments/newebpay_mpg',
	payuni_upp: '/payments/payuni_upp',
	payuni_uni_embed: '/payments/payuni_uni_embed',
	paynow: '/payments/paynow',
	amego: '/invoices/amego',
	ecpay: '/invoices/ecpay',
	ezpay: '/invoices/ezpay',
	paynow_invoice: '/invoices/paynow_invoice',
	ecpay_receipt: '/receipts/ecpay_receipt',
	ecpay_logistics: '/logistics/ecpay_logistics',
	payuni_logistics: '/logistics/payuni_logistics',
	paynow_logistics: '/logistics/paynow_logistics',
}

const routes = [
	{ path: '/', redirect: '/payments' }, // 預設導向 /payments
	{ path: '/payments', component: () => import('@/pages/Payments/index.vue') },
	{
		path: '/payments/shopline_payment_redirect',
		component: () => import('@/pages/Payments/SLP/index.vue'),
	}, // 請根據實際路徑調整
	{
		path: '/payments/ecpay_aio',
		component: () => import('@/pages/Payments/EcpayAio/index.vue'),
	},
	{
		path: '/payments/ecpay_ecpg',
		component: () => import('@/pages/Payments/EcpayEcpg/index.vue'),
	},
	{
		path: '/payments/newebpay_mpg',
		component: () => import('@/pages/Payments/NewebpayMpg/index.vue'),
	},
	{
		path: '/payments/payuni_upp',
		component: () => import('@/pages/Payments/PayuniUpp/index.vue'),
	},
	{
		path: '/payments/payuni_uni_embed',
		component: () => import('@/pages/Payments/PayuniUniEmbed/index.vue'),
	},
	{
		path: '/payments/paynow',
		component: () => import('@/pages/Payments/Paynow/index.vue'),
	},
	{
		path: '/logistics',
		component: () => import('@/pages/Logistics/index.vue'),
	},
	{
		path: '/logistics/ecpay_logistics',
		component: () => import('@/pages/Logistics/Ecpay/index.vue'),
	},
	{
		path: '/logistics/payuni_logistics',
		component: () => import('@/pages/Logistics/Payuni/index.vue'),
	},
	{
		path: '/logistics/paynow_logistics',
		component: () => import('@/pages/Logistics/Paynow/index.vue'),
	},
	{ path: '/invoices', component: () => import('@/pages/Invoices/index.vue') },
	{
		path: '/invoices/amego',
		component: () => import('@/pages/Invoices/Amego/index.vue'),
	},
	{
		path: '/invoices/ecpay',
		component: () => import('@/pages/Invoices/Ecpay/index.vue'),
	},
	{
		path: '/invoices/ezpay',
		component: () => import('@/pages/Invoices/Ezpay/index.vue'),
	},
	{
		path: '/invoices/paynow_invoice',
		component: () => import('@/pages/Invoices/Paynow/index.vue'),
	},
	{ path: '/receipts', component: () => import('@/pages/Receipts/index.vue') },
	{
		path: '/receipts/ecpay_receipt',
		component: () => import('@/pages/Receipts/Ecpay/index.vue'),
	},
]

const router = createRouter({
	history: createMemoryHistory(),
	routes,
})

export default router
