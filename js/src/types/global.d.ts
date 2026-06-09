import { CheckboxProps } from '@/components/Checkbox/types'
import { IEcpgData, IEcpaySdk } from '@/external/EcpgPayment/types'
import {
	IPayuniUniData,
	IUniPaymentGlobal,
} from '@/external/PayuniUniEmbed/types'
import { IOrderData } from '@/external/RefundDialog/types'

export {}

export interface IEnv {
	SITE_URL: string
	API_URL: string
	CURRENT_USER_ID: number
	CURRENT_POST_ID: number
	PERMALINK: string
	APP_NAME: string
	KEBAB: string
	SNAKE: string
	NONCE: string
	APP1_SELECTOR: string
	IS_LOCAL: boolean
	ORDER_STATUSES: CheckboxProps[]
}

declare global {
	interface Window {
		power_checkout_data: {
			env: IEnv
		} // 或更精確的型別
		power_checkout_order_data: IOrderData
		power_checkout_invoice_metabox_app_data: {
			render_ids: string[]
			order: {
				id: ''
			}
			is_admin: boolean
			is_issued: boolean
			invoice_number: string
			invoice_providers: {
				id: string
				icon: string
				title: string
				description: string
				method_title: string
				method_description: string
				mode: 'test' | 'prod'
			}[]
		}

		/**
		 * 綠界 ECPay 站內付 2.0（ECPG）order-received 頁專屬資料
		 *
		 * 由 EcpgGateway::before_order_received 透過 wp_localize_script 掛在 Vue bundle handle 上，
		 * 僅在「訂單以 ecpay_ecpg 結帳且已成功取得交易 token」的 order-received 頁存在。
		 * @see inc/classes/Domains/Payment/Ecpg/Services/EcpgGateway.php build_sdk_config()
		 */
		power_checkout_ecpg_data?: IEcpgData

		/** 綠界站內付 2.0 前端 JS SDK 全域物件（由 sdk-1.0.0.js 注入 window） */
		ECPay?: IEcpaySdk

		/** jQuery（綠界 SDK 依賴；WP 環境通常已載入，型別僅作存在性檢查） */
		jQuery?: unknown

		/**
		 * PAYUNi UNi Embed V3（內嵌式信用卡）order-received 頁專屬資料
		 *
		 * 由 PayuniUniEmbedGateway::before_order_received 透過 wp_localize_script 掛在 Vue bundle handle，
		 * 僅在「訂單以 payuni_uni_embed 結帳且已 token_get 取得 SDK_TOKEN」的 order-received 頁存在。
		 * @see inc/classes/Domains/Payment/PayuniUniEmbed/Services/PayuniUniEmbedGateway.php build_sdk_config()
		 */
		power_checkout_payuni_uni_data?: IPayuniUniData

		/** PAYUNi uni-payment.js 前端 JS SDK 全域物件（由 vendor.payuni.com.tw 注入 window） */
		UniPayment?: IUniPaymentGlobal
	}
}
