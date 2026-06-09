<?php
/**
 * PAYUNi UPP V2 交易狀態 TradeStatus（NotifyURL / ReturnURL 回傳）
 *
 * int-backed enum（0/1/2/3/8 無重複，與付款方式開關不同概念）。
 *
 * 依 payuni-upp-v2 §EncryptInfo 內 通用回傳參數 TradeStatus：
 *  0=取號成功（ATM/CVS 等待繳費）, 1=已付款, 2=付款失敗, 3=付款取消, 8=訂單待確認。
 *
 * ⚠️ TradeStatus=8（待確認）：UNKNOWN 情境（60 秒未收銀行回應），不算正式付款，
 *    最終結果由後續 NotifyURL 確認。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §TradeStatus
 * @see .claude/skills/payuni-upp-v2/references/codes-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums;

/** PAYUNi UPP V2 交易狀態（int-backed） */
enum PayuniTradeStatus: int {
	/** 取號成功（ATM / CVS 等待繳費） */
	case GetCode = 0;
	/** 已付款 */
	case Paid = 1;
	/** 付款失敗 */
	case Failed = 2;
	/** 付款取消 */
	case Cancelled = 3;
	/** 訂單待確認（UNKNOWN，60 秒未收銀行回應） */
	case Pending = 8;

	/**
	 * 是否已完成付款（TradeStatus=1）
	 *
	 * 取號成功（0）/ 失敗（2）/ 取消（3）/ 待確認（8）皆不算已付款。
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return self::Paid === $this;
	}

	/**
	 * 是否為取號成功（TradeStatus=0，ATM/CVS 已產生繳費資訊）
	 *
	 * @return bool
	 */
	public function is_get_code(): bool {
		return self::GetCode === $this;
	}

	/**
	 * 取得交易狀態中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::GetCode   => '取號成功',
			self::Paid      => '已付款',
			self::Failed    => '付款失敗',
			self::Cancelled => '付款取消',
			self::Pending   => '訂單待確認',
		};
	}
}
