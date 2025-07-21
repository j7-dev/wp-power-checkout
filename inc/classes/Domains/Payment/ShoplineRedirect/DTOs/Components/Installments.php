<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;

/**
 * Installments 支援分期的付款方式的分期資訊
 *  */
final class Installments extends DTO {

	/** @var string 分期期數(選填) */
	public string $count;

	/** @var string 首期金額，台幣金額*100，譬如1元為100(選填) (12) */
	public string $installDownPay;

	/** @var string 每期金額，台幣金額*100，譬如1元為100(選填) (12) */
	public string $installPay;
}
