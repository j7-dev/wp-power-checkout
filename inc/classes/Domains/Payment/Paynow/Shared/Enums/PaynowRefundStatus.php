<?php
/**
 * PayNow 退款狀態（REST API 體系 1 — Refund）
 *
 * String-backed enum，值域對齊 PayNow Refund 退款狀態類型
 * （payment-rest-api.md §5.1）：
 *
 *  - success          退款成功
 *  - failed           退款失敗
 *  - rejected         拒絕（原因在回傳 RejectReason）
 *  - processing       退款處理中
 *  - validation_error request 驗證有誤
 *
 * @see .claude/skills/paynow/references/payment-rest-api.md §5 Refund 退款狀態
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums;

/** PayNow 退款狀態（string-backed） */
enum PaynowRefundStatus: string {
	/** 退款成功 */
	case Success = 'success';
	/** 退款失敗 */
	case Failed = 'failed';
	/** 拒絕（原因在 RejectReason） */
	case Rejected = 'rejected';
	/** 退款處理中 */
	case Processing = 'processing';
	/** Request 驗證有誤 */
	case ValidationError = 'validation_error';

	/**
	 * 是否退款成功
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return self::Success === $this;
	}

	/**
	 * 是否遭拒絕
	 *
	 * @return bool
	 */
	public function is_rejected(): bool {
		return self::Rejected === $this;
	}

	/**
	 * 是否處理中
	 *
	 * @return bool
	 */
	public function is_processing(): bool {
		return self::Processing === $this;
	}

	/**
	 * 取得狀態中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Success         => '退款成功',
			self::Failed          => '退款失敗',
			self::Rejected        => '拒絕',
			self::Processing      => '退款處理中',
			self::ValidationError => '驗證有誤',
		};
	}
}
