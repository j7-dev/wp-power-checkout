<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 Response Status
 */
enum ResponseStatus: string {
	/** @var string 建立 */
	case CREATED = 'CREATED';
	/** @var string 顧客處理中 */
	case CUSTOMER_ACTION = 'CUSTOMER_ACTION';
	/** @var string 內部處理中 */
	case PROCESSING = 'PROCESSING';
	/** @var string 處理中 */
	case PENDING = 'PENDING';
	/** @var string 成功 */
	case SUCCEEDED = 'SUCCEEDED';
	/** @var string 已逾期 */
	case EXPIRED = 'EXPIRED';
	/** @var string 失敗 */
	case FAILED = 'FAILED';
	/** @var string 已取消 */
	case CANCELLED = 'CANCELLED';

	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREATED => '建立',
			self::CUSTOMER_ACTION => '顧客處理中',
			self::PROCESSING => '內部處理中',
			self::PENDING => '處理中',
			self::SUCCEEDED => '成功',
			self::FAILED => '失敗',
			self::CANCELLED => '已取消',
			self::EXPIRED => '已過期',
		};
	}
}
