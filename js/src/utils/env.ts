import { IEcpgData } from '@/external/EcpgPayment/types'
import { IPayuniUniData } from '@/external/PayuniUniEmbed/types'

const env = window?.power_checkout_data?.env

export const SITE_URL = env?.SITE_URL
export const API_URL = env?.API_URL
export const CURRENT_USER_ID = env?.CURRENT_USER_ID
export const CURRENT_POST_ID = env?.CURRENT_POST_ID
export const PERMALINK = env?.PERMALINK
export const APP_NAME = env?.APP_NAME
export const KEBAB = env?.KEBAB
export const SNAKE = env?.SNAKE
export const NONCE = env?.NONCE
export const APP1_SELECTOR = env?.APP1_SELECTOR

/**
 * 綠界 ECPay 站內付 2.0（ECPG）order-received 頁專屬資料
 *
 * 比照 InvoiceApp 讀其專屬 localize 物件的方式，集中於 env.ts 取得，模組內不直接讀 window。
 * 僅在「訂單以 ecpay_ecpg 結帳且已成功取得交易 token」的 order-received 頁存在，否則為 undefined。
 */
export const ECPG_DATA: IEcpgData | undefined = window?.power_checkout_ecpg_data

/**
 * PAYUNi UNi Embed V3（內嵌式信用卡）order-received 頁專屬資料
 *
 * 由 PayuniUniEmbedGateway::before_order_received 透過 wp_localize_script 掛在 Vue bundle handle 上，
 * 僅在「訂單以 payuni_uni_embed 結帳且已成功 token_get 取得 SDK_TOKEN」的 order-received 頁存在，
 * 否則為 undefined。模組內不直接讀 window，集中於此取得（比照 ECPG_DATA）。
 */
export const PAYUNI_UNI_DATA: IPayuniUniData | undefined =
	window?.power_checkout_payuni_uni_data
