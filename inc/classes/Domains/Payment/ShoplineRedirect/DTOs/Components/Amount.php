<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\Currency;


/**
 * Amount 金額
 * 請求會帶
 *  */
final class Amount extends DTO {

	/** @var int (14) *金額，台幣傳金額*100，譬如1元傳入100 */
	public int $value;

	/** @var Currency::value 幣種，目前僅支援 TWD */
	public string $currency = 'TWD';

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'value',
		'currency',
	];

	/**
	 * @param float $amount 台幣金額
	 * @return self 創建實例
	 */
	public static function create( float $amount ): self {
		$args = [
			'value' => $amount * 100,
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

		if (strlen( (string) $this->value) > 14) {
			throw new \Exception('value 長度不能超過 14 位，台幣金額不能超過 12 位');
		}
	}
}
