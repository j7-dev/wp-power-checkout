<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 CustomerType
 * '0' | '1' (1) *顧客類型，0 為遊客，1 為登入會員
 */
enum CustomerType: string {
	/** @var string 遊客 */
	case GUEST = '0';
	/** @var string 登入會員 */
	case MEMBER = '1';

	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::GUEST => '遊客',
			self::MEMBER => '登入會員',
		};
	}
}
