<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 Response Status
 */
enum ResponseStatus: string {
	/** @var string 建立 */
	case CREATED = 'CREATED';
	/** @var string 處理中 */
	case PENDING = 'PENDING';
	/** @var string 成功 */
	case SUCCEEDED = 'SUCCEEDED';
	/** @var string 已逾期 */
	case EXPIRED = 'EXPIRED';

	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREATED => '建立',
			self::PENDING => '處理中',
			self::SUCCEEDED => '成功',
			self::EXPIRED => '已逾期',
		};
	}
}
