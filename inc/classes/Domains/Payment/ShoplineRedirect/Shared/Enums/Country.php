<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 Country
 * 目前僅支援 TW, CN, HK
 */
enum Country: string {
	/** @var string 台灣 */
	case TW = 'TW';

	/** @var string 中國 */
	case CN = 'CN';

	/** @var string 香港 */
	case HK = 'HK';

	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::TW => '台灣',
			self::CN => '中國',
			self::HK => '香港',
		};
	}
}
