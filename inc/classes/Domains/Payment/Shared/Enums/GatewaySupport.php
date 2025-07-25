<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Enums;

/**
 * 付款方式支援的特性
 *
 * @see WC_Payment_Gateway
 *  */
enum GatewaySupport: string {

	/** @var string 支援預設的信用卡表單 */
	case DEFAULT_CREDIT_CARD_FORM = 'default_credit_card_form';

	/** @var string 支援產品 */
	case PRODUCTS = 'products';

	/** @var string 支援退款 */
	case REFUNDS = 'refunds';

	/** @var string 支援 cardhash，信用卡 token 化 */
	case TOKENIZATION = 'tokenization';

	/** @var string 支援區塊結帳 */
	case CHECKOUT_BLOCKS = 'checkout-blocks';
}
