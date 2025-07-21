<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\Country;

/**
 * Address 物流送貨地址
 * 請求會帶
 *  */
final class Address extends DTO {
	/** @var Country::value (2) *國家地區編碼，如 TW */
	public string $countryCode;

	/** @var string (12) 州或省代碼 */
	public string $stateCode = '';

	/** @var string (128) 州或省名稱 */
	public string $state = '';

	/** @var string (128) 城市名稱 */
	public string $city;

	/** @var string (128) 區域 */
	public string $district;

	/** @var string (128) *詳細街道地址 */
	public string $street;

	/** @var string (32) 郵政編碼 */
	public string $postcode;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'countryCode',
		'street',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$street = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();

		$args = [
			'countryCode' => ( new Helper($order->get_billing_country(), 'billing_country', 2) )->substr()->value,
			'city'        => ( new Helper($order->get_billing_state(), 'billing_state', 128) )->substr()->value,
			'district'    => ( new Helper($order->get_billing_city(), 'billing_city', 128) )->substr()->value,
			'street'      => ( new Helper($street, 'street', 128) )->substr()->value,
			'postcode'    => ( new Helper($order->get_billing_postcode(), 'billing_postcode', 32) )->substr()->value,
		];
		return new self($args);
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		Country::from( $this->countryCode );
		( new Helper($this->stateCode, 'stateCode', 12) )->get_strlen(true);
		( new Helper($this->state, 'state', 128) )->get_strlen(true);
		( new Helper($this->city, 'city', 128) )->get_strlen(true);
		( new Helper($this->district, 'district', 128) )->get_strlen(true);
		( new Helper($this->street, 'street', 128) )->get_strlen(true);
		( new Helper($this->postcode, 'postcode', 32) )->get_strlen(true);
	}
}
