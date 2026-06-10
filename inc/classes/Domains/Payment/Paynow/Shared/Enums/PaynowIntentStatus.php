<?php
/**
 * PayNow PaymentIntent 狀態（REST API 體系 1）
 *
 * String-backed enum，值域對齊 PayNow PaymentIntent `status`
 * （payment-rest-api.md §4.1 Response：建立後為 `draft`；後續執行付款 / 收單後
 * 轉 `processing` / `pending_review` / `success` / `canceled`）。
 *
 *  - draft          已建立尚未付款（PaymentIntent 初始狀態）
 *  - processing     付款處理中
 *  - pending_review 已發起付款、待驗證（3DS / 超商待繳 / ATM 待繳）
 *  - success        付款成功
 *  - canceled       已取消
 *
 * @see .claude/skills/paynow/references/concepts.md §5 PaymentIntent 狀態
 * @see .claude/skills/paynow/references/payment-rest-api.md §4 PaymentIntent
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums;

/** PayNow PaymentIntent 狀態（string-backed） */
enum PaynowIntentStatus: string {
	/** 已建立尚未付款 */
	case Draft = 'draft';
	/** 付款處理中 */
	case Processing = 'processing';
	/** 已發起付款、待驗證（3DS / 待繳） */
	case PendingReview = 'pending_review';
	/** 付款成功 */
	case Success = 'success';
	/** 已取消 */
	case Canceled = 'canceled';

	/**
	 * 是否付款成功
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return self::Success === $this;
	}

	/**
	 * 是否為草稿（已建立尚未付款）
	 *
	 * @return bool
	 */
	public function is_draft(): bool {
		return self::Draft === $this;
	}

	/**
	 * 取得狀態中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Draft         => '已建立尚未付款',
			self::Processing    => '付款處理中',
			self::PendingReview => '待驗證',
			self::Success       => '付款成功',
			self::Canceled      => '已取消',
		};
	}
}
