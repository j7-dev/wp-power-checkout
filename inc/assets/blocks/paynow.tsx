import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import { decodeEntities } from '@wordpress/html-entities'

const id = 'paynow'
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

		// PayNow（體系 1 Component SDK v2，站內付不跳轉）：SDK 收單與 3DS 流程於 order-received 頁
		// 由 MountPaynowPayment 處理（比照 ECPG / PAYUNi UNi Embed），block 結帳階段不渲染卡片表單、不存綁定卡片。
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊 PayNow（立吉富）體系 1 Component SDK v2（內嵌式，站內付）付款方式
 */
registerPaymentMethod(options)
