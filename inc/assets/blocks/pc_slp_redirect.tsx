import { registerPaymentMethod } from '@woocommerce/blocks-registry'
import { __ } from '@wordpress/i18n'
import { decodeEntities } from '@wordpress/html-entities'
import { getSetting } from '@woocommerce/settings'

const settings = getSetting('pc_slp_redirect_data', {})
const { name, order_button_text, supports: features } = settings
console.log('settings', settings)

const label = decodeEntities(settings.title)
console.log('label', label)

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

	supports: {
		features,
		showSavedCards: true,
		showSaveOption: true,
	},
}

/**
 * 註冊付款方式
 * 也可以用 import { registerPaymentMethod } from '@woocommerce/blocks-registry';
 */
registerPaymentMethod(options)
