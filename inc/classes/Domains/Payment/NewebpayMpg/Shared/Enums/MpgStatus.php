<?php
/**
 * 藍新 NewebPay MPG 交易狀態判定（Status + RespondCode）
 *
 * 藍新 callback 解密後：
 *  - 頂層 Status：'SUCCESS' 代表交易流程成功（含取號成功），其餘為錯誤碼（如 MPG03012）。
 *  - Result.RespondCode：銀行回應碼，'00' 代表付款成功。
 *
 * 「付款成功」的判定 = Status === 'SUCCESS' 且 RespondCode === '00'。
 * offline 取號（VACC/CVS/BARCODE）也會回 Status='SUCCESS'，但此時尚未付款，
 * 須由 PaymentType（is_offline）+ 是否有 RespondCode='00' 區分（取號通知通常無 RespondCode='00'）。
 *
 * QueryTradeInfo 的 TradeStatus 為另一套碼（0 未付 / 1 已付 / 2 失敗 / 3 取消 / 6 退款），於 Phase 3 使用。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §Callback Response Parameters
 * @see .claude/skills/newebpay-mpg/references/backend-apis.md §TradeStatus Values
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums;

/** 藍新 MPG 交易狀態語意 */
enum MpgStatus: string {
	/** 交易流程成功（頂層 Status） */
	case SUCCESS = 'SUCCESS';

	/** 銀行回應：付款成功（Result.RespondCode） */
	public const RESPOND_CODE_SUCCESS = '00';

	/** QueryTradeInfo TradeStatus：未付款 */
	public const TRADE_STATUS_UNPAID = '0';
	/** QueryTradeInfo TradeStatus：已付款 */
	public const TRADE_STATUS_PAID = '1';
	/** QueryTradeInfo TradeStatus：失敗 */
	public const TRADE_STATUS_FAILED = '2';
	/** QueryTradeInfo TradeStatus：已取消 */
	public const TRADE_STATUS_CANCELLED = '3';
	/** QueryTradeInfo TradeStatus：已退款 */
	public const TRADE_STATUS_REFUNDED = '6';

	/**
	 * 是否為「付款成功」（Status='SUCCESS' 且 RespondCode='00'）
	 *
	 * @param string $status       頂層 Status
	 * @param string $respond_code Result.RespondCode
	 *
	 * @return bool
	 */
	public static function is_paid_success( string $status, string $respond_code ): bool {
		return self::SUCCESS->value === $status && self::RESPOND_CODE_SUCCESS === $respond_code;
	}

	/**
	 * 頂層 Status 是否為 SUCCESS（交易流程成功，含取號成功）
	 *
	 * @param string $status 頂層 Status
	 *
	 * @return bool
	 */
	public static function is_status_success( string $status ): bool {
		return self::SUCCESS->value === $status;
	}

	/**
	 * QueryTradeInfo：TradeStatus 是否為已付款
	 *
	 * @param string $trade_status TradeStatus
	 *
	 * @return bool
	 */
	public static function is_trade_paid( string $trade_status ): bool {
		return self::TRADE_STATUS_PAID === $trade_status;
	}
}
