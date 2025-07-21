<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks;

use J7\WpUtils\Classes\DTO;

/**
 * 付款工具
 *
 * @see https://docs.shoplinepayments.com/api/event/model/instrument/
 */
final class Instrument extends DTO {

	/** @var string *會員 ID*/
	public string $customerId;

	/** @var string *特店會員 ID*/
	public string $referenceCustomerId;

	/** @var Components\PaymentInstrument *付款工具*/
	public Components\PaymentInstrument $paymentInstrument;

	/** @var string 附加資訊 選填*/
	public string $additionalData;

	/** @var string 透傳資訊 選填*/
	public string $passthrough;

	/** @var array 必填屬性 */
	protected array $require_properties = [
		'customerId',
		'referenceCustomerId',
		'paymentInstrument',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array<string, mixed> $args 原始資料
	 */
	public static function create( array $args ): self {
		$args['paymentInstrument'] = Components\PaymentInstrument::create( $args['paymentInstrument'] );
		return new self( $args );
	}
}
