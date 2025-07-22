<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

/**
 * 退款交易
 *
 * @see https://docs.shoplinepayments.com/api/event/model/refund/
 */
final class Refund extends DTO {

	/** @var string *SLP 退款訂單號 (32)*/
	public string $refundOrderId;

	/** @var string *特店訂單號 (32)*/
	public string $referenceOrderId;

	/** @var string *SLP 付款交易訂單編號 (32)*/
	public string $tradeOrderId;

	/** @var Components\Amount *訂單金額*/
	public Components\Amount $amount;

	/** @var ResponseStatus::value *退款狀態 (32) 參考 */
	public string $status;

	/** @var Components\PaymentError|null 退款失敗原因 選填 */
	public Components\PaymentError|null $refundMsg;

	/** @var string|null 第三方平台流水號，街口支付和 LINE Pay 特店對帳使用 選填 */
	public string|null $channelDealId;

	/** @var array 必填屬性 */
	protected array $require_properties = [
		'refundOrderId',
		'referenceOrderId',
		'tradeOrderId',
		'amount',
		'status',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array<string, mixed> $args 原始資料
	 */
	public static function create( array $args ): self {
		$args['amount'] = Components\Amount::parse( $args['amount'] );
		if ( isset( $args['refundMsg'] ) ) {
			$args['refundMsg'] = Components\PaymentError::parse( $args['refundMsg'] );
		}
		return new self( $args );
	}
}
