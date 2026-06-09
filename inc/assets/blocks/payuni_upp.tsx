import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { getSetting } from '@woocommerce/settings'
import { decodeEntities } from '@wordpress/html-entities'

const id = 'payuni_upp'
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

		// 導轉式金流（UPP 整合支付頁，無 SDK / 不綁卡），與綠界 AIO / 藍新 MPG 同構
		showSavedCards: false,
		showSaveOption: false,
	},
}

/**
 * 註冊 PAYUNi 統一金流（UPP）付款方式
 */
registerPaymentMethod(options)
