<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * CreditCard Type
 */
enum CreditCardType: string {
	/** @var string 信用卡 */
	case CREDIT = 'Credit';
	/** @var string 借記卡 */
	case DEBIT = 'Debit';

	/**
	 * 取得標籤
	 *
	 * @return string 標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREDIT => '信用卡',
			self::DEBIT => '借記卡',
		};
	}
}
