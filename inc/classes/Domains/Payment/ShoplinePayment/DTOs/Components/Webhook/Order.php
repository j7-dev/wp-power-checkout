<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

/**
 * 付款交易裡面的 order
 *
 * @see https://docs.shoplinepayments.com/api/event/model/payment/
 *  */
final class Order extends DTO {

	/** @var string *特店 ID (32)*/
	public string $merchantId;

	/** @var Components\Amount *訂單金額*/
	public Components\Amount $amount;

	/** @var string *特店訂單號 (32)*/
	public string $referenceOrderId;

	/** @var int *訂單建立時間*/
	public int $createTime;

	/** @var Customer *顧客資訊*/
	public Customer $customer;

	/** @var int|null *訂單過期時間， 文件上沒有這個屬性 2025/07/22 SLP 回覆固定會帶 null*/
	public int|null $expireTime;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'merchantId',
		'amount',
		'referenceOrderId',
		'createTime',
		'customer',
	];

	/**
	 * @param array{
	 *    merchantId: string,
	 *    amount: array{
	 *      currency: string,
	 *      value: int,
	 *    },
	 *    referenceOrderId: string,
	 *    createTime: int,
	 *    customer: {
	 *      referenceCustomerId: string,
	 *      customerId: string,
	 *    },
	 * } $args
	 * @return self 創建實例
	 */
	public static function create( array $args ): self {
		$args['amount']   = Components\Amount::parse( $args['amount'] );
		$args['customer'] = Customer::parse( $args['customer'] );
		return new self($args);
	}
}
