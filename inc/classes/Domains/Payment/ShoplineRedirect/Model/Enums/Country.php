<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Enums;

/**
 * Shopline Payment 跳轉式支付 Country
 * 目前僅支援 TWD
 */
enum Country: string {
	/** @var string 台幣 */
	case TWD = 'TWD';

	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::TWD => '台幣',
		};
	}
}
