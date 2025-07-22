<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * CreditCard
 *  */
final class CreditCard extends DTO {
	/** @var Enums\CreditCardType::value *卡類型 */
	public string $type;

	/** @var string *卡號前六位 */
	public string $bin;

	/** @var string *卡號後四位 */
	public string $last4;

	/** @var string 卡類別 */
	public string $category;

	/** @var string 發卡行 */
	public string $issuer;

	/** @var string 發卡國家 */
	public string $issuerCountry;

	/** @var string 卡組織 */
	public string $brand;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [ 'type', 'bin', 'last4' ];

	/** 自訂驗證 */
	public function validate(): void {
		parent::validate();
		Enums\CreditCardType::from( $this->type );
	}
}
