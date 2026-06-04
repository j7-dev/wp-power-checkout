declare module '@woocommerce/blocks-registry' {
	export function registerPaymentMethod(options: any): void;
}

declare module '@woocommerce/settings' {
	export function getSetting(key: string, defaultValue?: any): any;
}

declare module '@wordpress/i18n' {
	export function __(text: string, domain?: string): string;
}

declare module '@wordpress/html-entities' {
	export function decodeEntities(text: string): string;
}

/**
 * 綠界站內付 2.0（ECPG）SDK 靜態設定
 *
 * 來源：EcpgGateway::build_sdk_config()（PHP）。
 * 不含逐訂單機密（token / order_key 等），可安全曝露前端；
 * HashKey / HashIV 絕不出現在此。
 *
 * @see inc/classes/Domains/Payment/Ecpg/Services/EcpgGateway.php build_sdk_config()
 */
type TEcpgSdkConfig = {
	/** 綠界站內付 SDK script 來源 URL */
	sdk_url: string;
	/** 特店編號（公開，非機密） */
	merchant_id: string;
	/** 後端建立付款的 REST endpoint（前端 SDK 取得卡片綁定結果後呼叫） */
	create_payment_url: string;
	/** 是否為測試環境（前端據此決定 ECPay.initialize('Stage'|'Prod')） */
	is_test: boolean;
	/** 綠界 SDK 硬編碼容器 id，前端渲染必須使用此 id */
	container_id: string;
};

/**
 * block checkout 付款方式資料（EcpgBlocksIntegration::get_payment_method_data()）
 *
 * 共用欄位由 BlocksIntegration 提供，ecpg 為站內付 SDK 靜態設定。
 * 注意：此結構不含 token（下單後才於 order-received 頁透過 power_checkout_ecpg_data 提供）。
 */
type TEcpgSettings = {
	/** 付款方式 id，等同 gateway id（ecpay_ecpg） */
	name: string;
	/** 付款方式標題（可能含 HTML entity，需 decodeEntities） */
	title: string;
	/** 付款方式說明（可能含 HTML entity，需 decodeEntities） */
	description: string;
	/** WC gateway supports 能力陣列 */
	supports: string[];
	/** 下單按鈕文字 */
	order_button_text: string;
	/** 站內付 SDK 靜態設定 */
	ecpg?: TEcpgSdkConfig;
};
