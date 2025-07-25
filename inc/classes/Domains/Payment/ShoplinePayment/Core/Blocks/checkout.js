const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement } = window.wp.element
const { decodeEntities } = window.wp.htmlEntities
const { getSetting } = window.wc.wcSettings
const { __ } = window.wp.i18n


const settings = getSetting('pc_slp_redirect_data', {})
const { name, order_button_text, supports: features } = settings
console.log('settings', settings);

const label = decodeEntities(settings.title)
console.log('label', label);

const Label = (props) => {
	const { PaymentMethodLabel } = props.components
	return <PaymentMethodLabel text={label} />
}

const Content = () => {
	return decodeEntities(settings.description || '');
};

const options = {
	name,
	label: <Label />,
	ariaLabel: label,
	placeOrderButtonLabel: order_button_text,
	content: createElement(Content, null),
	edit: createElement(Content, null),
	canMakePayment: () => true,

	supports: {
		features,
		showSavedCards: true,
		showSaveOption: true,
	},
};

/**
 * 註冊付款方式
 * 也可以用 import { registerPaymentMethod } from '@woocommerce/blocks-registry';
 */
registerPaymentMethod(options);