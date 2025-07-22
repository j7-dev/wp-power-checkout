<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Address as BaseAddress;

/**
 * Webhooks 付款工具裡面的 Address
 * 繼承 BaseAddress
 * 每個都是選填，所以不用驗證也不用設定必填屬性
 *
 * @see https://docs.shoplinepayments.com/api/event/model/instrument/
 *  */
final class Address extends BaseAddress {
	/** @var string 街道2 */
	public string $street2;

	/** @var string 街道3 */
	public string $street3;



	/** @var array<string> 必填屬性 */
	protected $required_properties = [];

	/** 自訂驗證邏輯 */
	protected function validate(): void {
	}
}
