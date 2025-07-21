<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * 子付款狀態，採用手動請款時需要關注此參數
 */
enum ResponseSubStatus: string {

	/** @var string 已授權 */
	case AUTHORIZED = 'AUTHORIZED';
	/** @var string 人工審核中 */
	case PENDING_REVIEW = 'PENDING_REVIEW';
	/** @var string 風控審核中 */
	case RISK_PENDING = 'RISK_PENDING';
	/** @var string 風控拒絕 */
	case RISK_REJECTED = 'RISK_REJECTED';
	/** @var string 授權失敗 */
	case AUTHORIZATION_FAILED = 'AUTHORIZATION_FAILED';
	/** @var string 請款失敗 */
	case CAPTURE_FAILED = 'CAPTURE_FAILED';

	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::AUTHORIZED => '已授權',
			self::PENDING_REVIEW => '人工審核中',
			self::RISK_PENDING => '風控審核中',
			self::RISK_REJECTED => '風控拒絕',
			self::AUTHORIZATION_FAILED => '授權失敗',
			self::CAPTURE_FAILED => '請款失敗',
		};
	}
}
