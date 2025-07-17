<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\Country;

/**
 * Address 物流送貨地址
 * 請求會帶
 *  */
final class Address extends DTO {
	/** @var Country (2) *國家地區編碼，如 TW */
	public Country $countryCode;

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
			'countryCode' => ( new Helper($order->get_billing_country()) )->max( 2 )->value,
			'city'        => ( new Helper($order->get_billing_state()) )->max( 128 )->value,
			'district'    => ( new Helper($order->get_billing_city()) )->max( 128 )->value,
			'street'      => ( new Helper($street) )->max( 128 )->value,
			'postcode'    => ( new Helper($order->get_billing_postcode()) )->max( 32 )->value,
		];
		return new self($args);
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		parent::validate();

		if ( Helper::strlen( $this->stateCode ) > 12 ) {
			$this->dto_error->add(
				'validate_failed',
				'stateCode 長度不能超過 12 位'
			);
		}

		if ( Helper::strlen( $this->state ) > 128 ) {
			$this->dto_error->add(
				'validate_failed',
				'state 長度不能超過 128 位'
			);
		}

		if ( Helper::strlen( $this->city ) > 128 ) {
			$this->dto_error->add(
				'validate_failed',
				'city 長度不能超過 128 位'
			);
		}

		if ( Helper::strlen( $this->district ) > 128 ) {
			$this->dto_error->add(
				'validate_failed',
				'district 長度不能超過 128 位'
			);
		}

		if ( Helper::strlen( $this->street ) > 128 ) {
			$this->dto_error->add(
				'validate_failed',
				'street 長度不能超過 128 位'
			);
		}

		if ( Helper::strlen( $this->postcode ) > 32 ) {
			$this->dto_error->add(
				'validate_failed',
				'postcode 長度不能超過 32 位'
			);
		}
	}
}
