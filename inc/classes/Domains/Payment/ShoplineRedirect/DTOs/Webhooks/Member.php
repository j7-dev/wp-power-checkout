<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks;

use J7\WpUtils\Classes\DTO;

/**
 * 付款會員
 *
 * @see https://docs.shoplinepayments.com/api/event/model/member/
 */
final class Member extends DTO {

	/** @var string *會員 ID */
	public string $customerId;

	/** @var string *特店會員 ID */
	public string $referenceCustomerId;

	/** @var int *建立時間（時間戳） */
	public int $createTime;

	/** @var array 必填屬性 */
	protected array $require_properties = [ 'customerId', 'referenceCustomerId', 'createTime' ];
}
