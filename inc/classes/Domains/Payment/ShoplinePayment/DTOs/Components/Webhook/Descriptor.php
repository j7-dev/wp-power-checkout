<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook;

use J7\WpUtils\Classes\DTO;

/**
 * 銀行帳單描述資訊
 *  */
final class Descriptor extends DTO {

	/** @var string 銀行帳單顯示的城市資訊，收費來源（商戶）城市*/
	public string $city;

	/** @var string 銀行帳單的描述資訊*/
	public string $name;
}
