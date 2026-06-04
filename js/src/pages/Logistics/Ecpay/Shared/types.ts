import type {
	EEcpayLogisticsAccountType,
	EEcpayLogisticsMode,
	EEcpayLogisticsSubType,
} from '@/pages/Logistics/Ecpay/Shared/enums'

/**
 * 綠界全方位物流 v2 後台設定表單資料（對齊 EcpayLogisticsSettingsDTO）
 *
 * 欄位採 snake_case，與後端 DTO 屬性一致。
 * account_type 切換 B2C / C2C 兩組憑證；兩組憑證各異，前台依 account_type 顯示對應組。
 */
export type TFormData = {
	// --- 一般設定 --- //
	title: string
	description: string
	// --- API / 模式 --- //
	mode: `${EEcpayLogisticsMode}`
	// --- 帳號類型與兩組憑證 --- //
	account_type: `${EEcpayLogisticsAccountType}`
	b2c_merchant_id: string
	b2c_hash_key: string
	b2c_hash_iv: string
	c2c_merchant_id: string
	c2c_hash_key: string
	c2c_hash_iv: string
	// --- 物流方式與寄件人 --- //
	enabled_methods: `${EEcpayLogisticsSubType}`[]
	sender_name: string
	sender_phone: string
	sender_zip_code: string
	sender_address: string
}
