<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * 付款交易的 payment 屬性
 *
 * @see https://docs.shoplinepayments.com/api/event/model/payment/
 */
final class Payment extends DTO {

	/** @var Enums\PaymentMethod::value *付款方式 (16) */
	public string $paymentMethod;

	/** @var string|null 子付款方式 (16) 選填 */
	public string|null $subPaymentMethod;

	/** @var bool 自動確認，默認為 false 選填 */
	public bool $autoConfirm;

	/** @var bool 自動請款，默認為 true 選填 */
	public bool $autoCapture;

	/** @var Enums\PaymentBehavior::value *付款場景 (32) 必填參考 */
	public string $paymentBehavior;

	/** @var string|null 付款成功时间 (32) 選填 13位 timestamp */
	public string|null $paymentSuccessTime;

	/** @var Components\Amount *必填 */
	public Components\Amount $paidAmount;

	/** @var string 第三方平台流水號，街口支付和 LINE Pay 特店對帳使用 選填 */
	public string|null $channelDealId;

	/** @var string SHOPLINE Payments 付款會員 ID，快捷付款、定期扣款場景必填 (32) 選填 */
	public string|null $paymentCustomerId;

	/** @var array 必填屬性 */
	protected array $require_properties = [ 'paymentMethod', 'paymentBehavior', 'paidAmount' ];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array<string, mixed> $args 原始資料
	 * @return self
	 */
	public static function create( array $args ): self {
		$args['paidAmount'] = Components\Amount::parse( $args['paidAmount'] );
		if ( isset( $args['CreditCard'] ) ) {
			$args['CreditCard'] = Components\CreditCard::parse( $args['CreditCard'] );
		}
		if ( isset( $args['VirtualAccount'] ) ) {
			$args['VirtualAccount'] = Components\VirtualAccount::parse( $args['VirtualAccount'] );
		}
		if ( isset( $args['PaymentInstrument'] ) ) {
			$args['PaymentInstrument'] = Components\PaymentInstrument::parse( $args['PaymentInstrument'] );
		}
		if ( isset( $args['PaymentMethodOptions'] ) ) {
			$args['PaymentMethodOptions'] = PaymentMethodOptions::create( $args['PaymentMethodOptions'] );
		}
		return new self( $args );
	}

	/** 自訂驗證 */
	public function validate(): void {
		parent::validate();
		Enums\PaymentMethod::from( $this->paymentMethod );
		Enums\PaymentBehavior::from( $this->paymentBehavior );
	}
}
