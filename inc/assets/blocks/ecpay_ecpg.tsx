import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { __ } from '@wordpress/i18n'
import { decodeEntities } from '@wordpress/html-entities'
import { getSetting } from '@woocommerce/settings'

const id = 'ecpay_ecpg'

/**
 * 後端 EcpgBlocksIntegration::get_payment_method_data() 提供的付款方式資料
 *
 * 共用欄位（name/title/description/supports/order_button_text/icons）來自 BlocksIntegration，
 * 另含 ecpg 站內付 SDK「靜態設定」（sdk_url/merchant_id/create_payment_url/is_test/container_id）。
 *
 * 為何此處不放 token：站內付 2.0 的交易 token 在下單時（before_process_payment）才產生，
 * block checkout 在「下單前」尚無訂單與 token，故 SDK 實際渲染落在 order-received 頁
 * （由六-C2 的 TS 模組讀 window.power_checkout_ecpg_data 渲染），此 block 僅負責付款方式註冊。
 */
const settings: TEcpgSettings = getSetting(`${id}_data`, {})

const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

/** 站內付下單前說明：token 於下單後才產生，故卡片資訊在確認頁（order-received）站內輸入 */
const description =
	decodeEntities(settings.description || '') ||
	__(
		'點擊下單後，將於確認頁輸入信用卡資訊（站內安全收單，不離開網站）。',
		'power_checkout',
	)

/**
 * 付款方式內容元件
 * 顯示站內付付款說明，不在結帳頁渲染 SDK（SDK 於 order-received 頁渲染）。
 */
const Content = () => {
	return description
}

/**
 * 付款方式標籤元件
 *
 * @param {*} props Props from payment API.
 */
const Label = (props: any) => {
	const { PaymentMethodLabel } = props.components
	return <PaymentMethodLabel text={label} />
}

const options = {
	name,
	label: <Label />,
	ariaLabel: label,
	placeOrderButtonLabel: order_button_text,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	paymentMethodId: id,
	supports: {
		features,
		// 站內付 SDK 自行收集卡片資訊（order-received 頁），不使用 WC 內建儲存卡功能
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊綠界 ECPay 站內付 2.0（ECPG）付款方式
 */
registerPaymentMethod(options)
