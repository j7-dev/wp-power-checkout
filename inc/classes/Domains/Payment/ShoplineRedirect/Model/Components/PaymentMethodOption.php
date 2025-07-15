<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components;

use J7\WpUtils\Classes\DTO;
use J7\WpUtils\Classes\General;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Enums\PaymentMethod;

/**
 * PaymentMethodOption
 * PaymentMethodOptions 裡面的選項
 * 實例化時需要宣告 $type
 * */
final class PaymentMethodOption extends DTO {

	/** @var 'CreditCardOption' | 'ChaileaseBNPLOption' | 'JKOPayOption' | 'VirtualAccountOption' 付款方式類型 */
	private string $type;

	/** @var array<string> 設定支援的分期期數，0 指一般交易。若不帶入則默認只設定為一般交易 */
	public array $installmentCounts;

	/** @var int 設定付款方式的逾時時間，單位：min。為了顧客體驗，建議帶入4320（即3天）。若不帶入則默認為 4320（即3天）。若不滿足整數天，則會向上取整 */
	public int $paymentExpireTime;


	/** 驗證 installmentCounts 的值是否都是數字 */
	protected function validate(): void {
		if (!in_array( $this->type, PaymentMethod::get_option_names(), true )) {
			$this->dto_error->add(
				'validate_failed',
				'type 必須為 ' . implode( ',', PaymentMethod::get_option_names() ) . ' 其中一個'
			);
		}

		if ( isset( $this->installmentCounts ) ) {
			if (!General::array_every( $this->installmentCounts, 'is_numeric' )) {
				$this->dto_error->add(
				'validate_failed',
				'installmentCounts 必須為數字，' . implode( ',', $this->installmentCounts ) . ' 不是數字'
				);
			}
		}

		if ('CreditCardOption' === $this->type && isset($this->paymentExpireTime)) {
			$this->dto_error->add(
			'validate_failed',
			'CreditCardOption 不需要 paymentExpireTime 設定'
			);
		}

		if ( in_array( $this->type, [ 'JKOPayOption', 'VirtualAccountOption' ], true ) && isset( $this->installmentCounts ) ) {
			$this->dto_error->add(
			'validate_failed',
			'JKOPayOption 不需要 installmentCounts 設定'
			);
		}
	}
}
