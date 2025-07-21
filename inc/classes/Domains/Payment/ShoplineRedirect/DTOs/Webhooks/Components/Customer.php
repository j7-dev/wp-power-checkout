<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks\Components;

use J7\WpUtils\Classes\DTO;

/**
 * 付款交易裡面的 order.customer
 *
 * @see https://docs.shoplinepayments.com/api/event/model/payment/
 *  */
final class Customer extends DTO {

	/** @var string *顧客唯一標識，需為唯一值 (64)*/
	public string $referenceCustomerId;

	/** @var string *SLP 會員 ID，在 purchaseScene 為純綁卡/綁卡及付款/定期扣款時，會建立 SLP 會員 (64)*/
	public string $customerId;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'referenceCustomerId',
		'customerId',
	];
}
