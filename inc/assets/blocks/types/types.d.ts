declare module '@woocommerce/blocks-registry' {
	export function registerPaymentMethod(options: any): void;
}

declare module '@woocommerce/settings' {
	export function getSetting(key: string, defaultValue?: any): any;
}
