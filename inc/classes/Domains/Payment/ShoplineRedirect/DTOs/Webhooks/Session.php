<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\ResponseStatus;

/**
 * 結帳交易
 *
 * @see https://docs.shoplinepayments.com/api/event/model/session/
 */
final class Session extends DTO {

	/** @var string *SLP 結帳交易訂單編號 (32)*/
	public string $sessionId;

	/** @var string *特店訂單號 (32)*/
	public string $referenceId;

	/** @var ResponseStatus::value *結帳交易狀態 (16)*/
	public string $status;

	/** @var string *結帳交易提供給顧客付款的 URL (256)*/
	public string $sessionUrl;

	/** @var int *訂單建立時間*/
	public int $createTime;

	/** @var Components\Amount *訂單金額*/
	public Components\Amount $amount;

	/** @var Components\PaymentDetail[] 付款方式詳細資訊 */
	public array $paymentDetails;

	/** @var array 必填屬性 */
	protected array $require_properties = [ 'sessionId', 'referenceId', 'status', 'sessionUrl', 'createTime', 'amount' ];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array{
	 *    sessionId: string,
	 *    referenceId: string,
	 *    status: ResponseStatus::value,
	 *    sessionUrl: string,
	 *    createTime: int,
	 *    amount: array{
	 *      currency: string,
	 *      value: int,
	 *    },
	 *    paymentDetails: array<array<string, mixed>>,
	 * } $args
	 */
	public static function create( array $args ): self {
		if ( isset( $args['paymentDetails'] ) ) {
			$args['paymentDetails'] = array_map( fn( $payment_detail ) => Components\PaymentDetail::parse( $payment_detail ), $args['paymentDetails'] );
		}
		$args['amount'] = Components\Amount::parse( $args['amount'] );
		return new self( $args );
	}

	/** 自訂驗證邏輯 */
	public function validate(): void {
		parent::validate();
		ResponseStatus::from( $this->status );
	}
}
