<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\CustomerType;

/**
 * Customer 顧客資訊，SLP智慧風控必需
 * 請求會帶
 *  */
final class Customer extends DTO {
	/** @var string (64) *顧客唯一標識，需為唯一值 */
	public string $referenceCustomerId;

	/** @var CustomerType::value (1) *顧客類型，0 為遊客，1 為登入會員 */
	public string $type;

	/** @var PersonalInfo *收件人資訊 */
	public PersonalInfo $personalInfo;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'referenceCustomerId',
		'personalInfo',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$customer_ref = $order->get_customer_id() ?: $order->get_billing_email();
		if ( ! $customer_ref ) {
			$customer_ref = $order->get_billing_phone();
		}

		$args = [
			'referenceCustomerId' => ( new Helper( (string) $customer_ref, 'customer_ref', 64) )->substr()->value,
			'type'                => $order->get_customer_id() ? CustomerType::MEMBER->value : CustomerType::GUEST->value,
			'personalInfo'        => PersonalInfo::create( $order ),
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

		if ( ! $this->referenceCustomerId ) {
			throw new \Exception('referenceCustomerId 不能為空');
		}
	}
}
