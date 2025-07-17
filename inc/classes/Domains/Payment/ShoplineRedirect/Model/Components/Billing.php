<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;

/**
 * Billing 帳單資訊
 * 請求會帶
 *  */
final class Billing extends DTO {

	/** @var string (32) 帳單描述，還沒想要要記錄那些資訊 */
	public string $description;

	/** @var PersonalInfo *收件人資訊 */
	public PersonalInfo $personalInfo;

	/** @var Address *收件地址 */
	public Address $address;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'personalInfo',
		'address',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$args = [
			'personalInfo' => PersonalInfo::create( $order ),
			'address'      => Address::create( $order ),
		];
		return new self($args);
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		parent::validate();

		if ( Helper::strlen( $this->description ) > 32 ) {
			$this->dto_error->add(
				'validate_failed',
				'description 長度不能超過 32 位'
			);
		}
	}
}
