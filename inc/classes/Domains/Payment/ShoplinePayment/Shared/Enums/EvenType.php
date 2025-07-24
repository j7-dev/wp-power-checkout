<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;
use J7\WpUtils\Classes\DTO;

/**
 * Shopline Payment Event Type
 * 通知電文 webhook 類型
 */
enum EventType: string {
	// 結帳交易
	case SESSION_CREATED   = 'session.created';      // 結帳交易已創建
	case SESSION_EXPIRED   = 'session.expired';      // 結帳交易已過期
	case SESSION_PENDING   = 'session.pending';      // 結帳交易處理中
	case SESSION_SUCCEEDED = 'session.succeeded';  // 結帳交易已成功

	// 付款交易
	case TRADE_SUCCEEDED        = 'trade.succeeded';              // 付款成功
	case TRADE_FAILED           = 'trade.failed';                    // 付款失敗
	case TRADE_EXPIRED          = 'trade.expired';                  // 付款逾時
	case TRADE_PROCESSING       = 'trade.processing';            // 付款處理中
	case TRADE_CANCELLED        = 'trade.cancelled';              // 取消付款
	case TRADE_CUSTOMER_ACTION  = 'trade.customer_action';  // 等待顧客付款確認
	case TRADE_REFUND_SUCCEEDED = 'trade.refund.succeeded';  // 退款成功
	case TRADE_REFUND_FAILED    = 'trade.refund.failed';        // 退款失敗

	// 會員
	case CUSTOMER_CREATED = 'customer.created';  // 會員建立
	case CUSTOMER_UPDATED = 'customer.updated';  // 會員更新
	case CUSTOMER_DELETED = 'customer.deleted';  // 會員註銷

	// 付款工具
	case CUSTOMER_INSTRUMENT_BINDED   = 'customer.instrument.binded';      // 會員綁定付款工具
	case CUSTOMER_INSTRUMENT_UPDATED  = 'customer.instrument.updated';    // 會員更新付款工具
	case CUSTOMER_INSTRUMENT_UNBINDED = 'customer.instrument.unbinded';  // 會員解綁付款工具

	/**
	 * 取得事件類型的標籤
	 *
	 * @return string 事件類型的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::SESSION_CREATED => '結帳交易已創建',
			self::SESSION_EXPIRED => '結帳交易已過期',
			self::SESSION_PENDING => '結帳交易處理中',
			self::SESSION_SUCCEEDED => '結帳交易已成功',
			self::TRADE_SUCCEEDED => '付款成功',
			self::TRADE_FAILED => '付款失敗',
			self::TRADE_EXPIRED => '付款逾時',
			self::TRADE_PROCESSING => '付款處理中',
			self::TRADE_CANCELLED => '取消付款',
			self::TRADE_CUSTOMER_ACTION => '等待顧客付款確認',
			self::TRADE_REFUND_SUCCEEDED => '退款成功',
			self::TRADE_REFUND_FAILED => '退款失敗',
			self::CUSTOMER_CREATED => '會員建立',
			self::CUSTOMER_UPDATED => '會員更新',
			self::CUSTOMER_DELETED => '會員註銷',
			self::CUSTOMER_INSTRUMENT_BINDED => '會員綁定付款工具',
			self::CUSTOMER_INSTRUMENT_UPDATED => '會員更新付款工具',
			self::CUSTOMER_INSTRUMENT_UNBINDED => '會員解綁付款工具',
		};
	}

	/**
	 * 依照依照事件類型的 DTO
	 *
	 * @param array<string, mixed> $data 原始資料
	 * @return DTO 事件類型的 DTO
	 */
	public function dto( array $data ): DTO {
		return match ( $this ) {
			self::SESSION_CREATED,
			self::SESSION_EXPIRED,
			self::SESSION_PENDING,
			self::SESSION_SUCCEEDED => Webhooks\Session::create($data),
			self::TRADE_SUCCEEDED,
			self::TRADE_FAILED,
			self::TRADE_EXPIRED,
			self::TRADE_PROCESSING,
			self::TRADE_CANCELLED,
			self::TRADE_CUSTOMER_ACTION => Webhooks\Payment::create($data),
			self::TRADE_REFUND_SUCCEEDED,
			self::TRADE_REFUND_FAILED => Webhooks\Refund::create($data),
			self::CUSTOMER_CREATED,
			self::CUSTOMER_UPDATED,
			self::CUSTOMER_DELETED => Webhooks\Member::parse($data),
			self::CUSTOMER_INSTRUMENT_BINDED,
			self::CUSTOMER_INSTRUMENT_UPDATED,
			self::CUSTOMER_INSTRUMENT_UNBINDED => Webhooks\Instrument::create($data),
		};
	}
}
