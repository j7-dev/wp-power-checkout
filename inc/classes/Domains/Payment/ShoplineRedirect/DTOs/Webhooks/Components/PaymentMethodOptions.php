<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\Installments;


/**
 * PaymentMethodOptions 付款方式可選資訊
 * */
final class PaymentMethodOptions extends DTO {

	/** @var Installments 支援分期的付款方式的分期資訊*/
	public Installments $installments;

	/**
	 * @param array<string, mixed> $args 原始資料
	 * @return self
	 */
	public static function create( array $args ): self {
		if ( isset( $args['installments'] ) ) {
			$args['installments'] = Installments::parse( $args['installments'] );
		}
		return new self( $args );
	}
}
