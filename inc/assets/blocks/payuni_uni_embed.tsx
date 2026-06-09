import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import { decodeEntities } from '@wordpress/html-entities'

const id = 'payuni_uni_embed'
const settings = getSetting(`${id}_data`, {})
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

const Content = () => {
	return decodeEntities(settings.description || '')
}

/**
 * Label component
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

		// UNi Embed（內嵌式信用卡，站內付不跳轉）：SDK 收單與 3D 流程於 order-received 頁
		// 由 MountPayuniUniEmbed 處理（比照 ECPG），block 結帳階段不渲染卡片表單、不存綁定卡片。
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊 PAYUNi 統一金流 UNi Embed V3（內嵌式信用卡，站內付）付款方式
 */
registerPaymentMethod(options)
