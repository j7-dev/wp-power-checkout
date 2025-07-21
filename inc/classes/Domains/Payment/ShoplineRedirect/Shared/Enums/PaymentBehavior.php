<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 PaymentBehavior
 */
enum PaymentBehavior: string {
	/** @var string 一般付款 */
	case REGULAR = 'Regular';
	/** @var string 純綁卡 */
	case CARD_BIND = 'CardBind';
	/** @var string 付款並綁卡 */
	case CARD_BIND_PAYMENT = 'CardBindPayment';
	/** @var string 快捷付款 */
	case QUICK_PAYMENT = 'QuickPayment';
	/** @var string 定期扣款 */
	case RECURRING = 'Recurring';

	/**
	 * 取得標籤
	 *
	 * @return string 標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::REGULAR => '一般付款',
			self::CARD_BIND => '純綁卡',
			self::CARD_BIND_PAYMENT => '付款並綁卡',
			self::QUICK_PAYMENT => '快捷付款',
			self::RECURRING => '定期扣款',
		};
	}
}
