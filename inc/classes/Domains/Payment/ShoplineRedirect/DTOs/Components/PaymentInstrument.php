<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;

/**
 * PaymentInstrument
 *  */
final class PaymentInstrument extends DTO {
	/** @var string 付款工具 ID */
	public string $paymentInstrumentId;

	/** @var string 是否要儲存付款工具 ID */
	public string $savePaymentInstrument;
}
