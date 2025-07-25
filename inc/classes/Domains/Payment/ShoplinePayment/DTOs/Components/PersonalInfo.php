<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;

/**
 * PersonalInfo 收貨人資訊
 * 請求會帶
 *  */
class PersonalInfo extends DTO {

	/** @var string (128) *顧客名字，firstName 和 lastName 加總長度不可超過 128 */
	public string $firstName;

	/** @var string (128) *顧客名字，firstName 和 lastName 加總長度不可超過 128 */
	public string $lastName;

	/** @var string (128) 顧客郵箱，郵箱和電話二者需至少傳入其一 */
	public string $email;

	/** @var string (64) 顧客電話，需帶國碼，舉例 +6287654321876，郵箱和電話二者需至少傳入其一 */
	public string $phone;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'lastName',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$args          = [
			'firstName' => ( new Helper($order->get_billing_first_name(), 'firstName', 128) )->filter()->substr()->value,
			'lastName'  => ( new Helper($order->get_billing_last_name(), 'lastName', 128) )->filter()->substr()->value,
			'email'     => ( new Helper($order->get_billing_email(), 'email', 128) )->substr()->value,
		];
		$billing_phone = $order->get_billing_phone();
		if ($billing_phone) {
			$phone_util    = \libphonenumber\PhoneNumberUtil::getInstance();
			$phone_proto   = $phone_util->parse($billing_phone, $order->get_billing_country());
			$phone_number  = $phone_util->format($phone_proto, \libphonenumber\PhoneNumberFormat::E164);
			$args['phone'] = ( new Helper($phone_number, 'phone', 64) )->substr()->value;
		}
		return new self($args);
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		( new Helper( "{$this->firstName}{$this->lastName}", 'full name', 128) )->get_strlen(true);

		if ( ! isset( $this->email ) && ! isset( $this->phone ) ) {
			throw new \Exception('郵箱和電話二者需至少傳入其一');
		}
	}
}
