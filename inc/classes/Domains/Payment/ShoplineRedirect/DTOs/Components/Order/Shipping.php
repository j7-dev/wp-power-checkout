<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\Order;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\PersonalInfo;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\Address;

/**
 * Shipping 訂單裡面的運送資訊 物流訂單資訊,SLP 智慧風控必需
 * 請求會帶
 *  */
final class Shipping extends DTO {

	/** @var string (64) *物流方式，如超商取貨/宅配等 */
	public string $shippingMethod;

	/** @var string (64) *物流通道，如黑貓宅配等 */
	public string $carrier;

	/** @var PersonalInfo *收件人資訊 */
	public PersonalInfo $personalInfo;

	/** @var Address *收件地址 */
	public Address $address;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'shippingMethod',
		'carrier',
		'personalInfo',
		'address',
	];
	/**
	 * TODO shippingMethod carrier 還不確定怎麼填寫
	 *
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$args = [
			'shippingMethod' => ( new Helper($order->get_shipping_method() ?: 'N/A', 'shippingMethod', 64) )->substr()->value,
			'carrier'        => ( new Helper($order->get_shipping_method() ?: 'N/A', 'carrier', 64) )->substr()->value,
			'personalInfo'   => PersonalInfo::create( $order ),
			'address'        => Address::create( $order ),
		];

		return new self( $args );
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		( new Helper($this->shippingMethod, 'shippingMethod', 64) )->get_strlen(true);
		( new Helper($this->carrier, 'carrier', 64) )->get_strlen(true);
	}
}
