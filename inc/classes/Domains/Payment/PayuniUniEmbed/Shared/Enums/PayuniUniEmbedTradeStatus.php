<?php
/**
 * PAYUNi UNi Embed V3（內嵌式信用卡）交易狀態 TradeStatus
 *
 * Int-backed enum，值域與 UPP 對齊（1/2/3/8），但「不含 0」——
 * UNi Embed 僅支援信用卡，無 ATM / CVS 取號流程，故無 TradeStatus=0（取號成功）。
 *
 * 依 payuni-uni-embed-v3 §merchant_trade 回傳 / §TradeStatus：
 *  1=已付款（授權成功）, 2=付款失敗, 3=付款取消, 8=訂單待確認（UNKNOWN，60 秒未收銀行回應）。
 *
 * Gateway 識別值：UNi Embed 回傳的 Gateway 欄位固定為 9（IFrame），與 UPP 的 2 區隔，
 * 供 refund / 補單時辨識交易來源走哪條 API 路徑（兩者在同一 PAYUNi 平台並列）。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §merchant_trade 回傳 §TradeStatus §Gateway
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Enums;

/** PAYUNi UNi Embed V3 交易狀態（int-backed） */
enum PayuniUniEmbedTradeStatus: int {
	/** 已付款（授權成功） */
	case Paid = 1;
	/** 付款失敗 */
	case Failed = 2;
	/** 付款取消 */
	case Cancelled = 3;
	/** 訂單待確認（UNKNOWN，60 秒未收銀行回應） */
	case Pending = 8;

	/**
	 * @var int Gateway 識別值（IFrame 內嵌式固定為 9，與 UPP 的 2 區隔）
	 *
	 * 依 payuni-uni-embed-v3 §Gateway 欄位：UNi Embed 回傳 Gateway=9（IFrame）。
	 */
	public const GATEWAY_VALUE = 9;

	/**
	 * 是否已完成付款（TradeStatus=1）
	 *
	 * 失敗（2）/ 取消（3）/ 待確認（8）皆不算已付款。
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		return self::Paid === $this;
	}

	/**
	 * 取得交易狀態中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::Paid      => '已付款',
			self::Failed    => '付款失敗',
			self::Cancelled => '付款取消',
			self::Pending   => '訂單待確認',
		};
	}
}
