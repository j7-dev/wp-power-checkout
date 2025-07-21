<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\PaymentMethodOption as Option;


/**
 * PaymentMethodOptions
 * 請求會帶
 * 設定不同付款方式的資訊。Applepay 和 LINE Pay 暫不支援設定
 * */
final class PaymentMethodOptions extends DTO {

	/** @var Option 信用卡付款方式設定，包含一般交易和分期交易*/
	public Option $CreditCardOption;

	/** @var Option 中租 zingla 銀角零卡付款方式設定*/
	public Option $ChaileaseBNPLOption;

	/** @var Option 街口支付付款方式設定*/
	public Option $JKOPayOption;

	/** @var Option ATM 銀行轉帳付款方式設定*/
	public Option $VirtualAccountOption;
}
