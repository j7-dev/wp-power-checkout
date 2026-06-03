<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\PaymentMethodOption as Option;


/**
 * PaymentMethodOptions
 * 請求會帶
 * 設定不同付款方式的資訊。ApplePay 和 LINE Pay 不需要設定
 * */
final class PaymentMethodOptions extends DTO {

	/** @var Option 信用卡付款方式設定，包含一般交易和分期交易*/
	public Option $CreditCard;

	/** @var Option 中租 zingla 銀角零卡付款方式設定*/
	public Option $ChaileaseBNPL;

	/** @var Option 街口支付付款方式設定*/
	public Option $JKOPay;

	/** @var Option ATM 銀行轉帳付款方式設定*/
	public Option $VirtualAccount;

	/**
	 * @param array<string, mixed> $args 參數
	 *
	 * @return self
	 * @throws \Exception DTO 失敗
	 */
	public static function create( array $args ): self {
		$fields = [ 'CreditCard', 'ChaileaseBNPL', 'JKOPay', 'VirtualAccount' ];
		foreach ($fields as $field) {
			if ( ! isset( $args[ $field ] ) || ! \is_array( $args[ $field ] ) ) {
				continue;
			}
			/** @var array<string, mixed> $field_data */
			$field_data     = $args[ $field ];
			$args[ $field ] = Option::create( $field_data, "{$field}Option" );
		}

		return new self( $args );
	}
}
