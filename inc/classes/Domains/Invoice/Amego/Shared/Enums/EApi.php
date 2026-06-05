<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\CancelInvoiceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceParamsDTO;
use J7\WpUtils\Classes\DTO;

/**
 * API endpoint
 */
enum EApi: string {
	case ISSUE             = '/json/f0401';
	case CANCEL            = '/json/f0501';
	case ALLOWANCE         = '/json/g0401'; // 開立折讓（部分退款開折讓單）
	case ALLOWANCE_INVALID = '/json/g0501'; // 作廢折讓
	case INVOICE_QUERY     = '/json/invoice_query'; // 發票查詢（單張）
	case ALLOWANCE_QUERY   = '/json/allowance_query'; // 折讓查詢（單張）
	case LOTTERY_STATUS    = '/json/lottery_status'; // 中獎發票查詢
	case TRACK_GET         = '/json/track_get'; // 字軌取號（API 配號）

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::ISSUE => '開立發票',
			self::CANCEL => '作廢發票',
			self::ALLOWANCE => '開立折讓',
			self::ALLOWANCE_INVALID => '作廢折讓',
			self::INVOICE_QUERY => '發票查詢',
			self::ALLOWANCE_QUERY => '折讓查詢',
			self::LOTTERY_STATUS => '中獎查詢',
			self::TRACK_GET => '字軌取號',
		};
	}

	/**
	 * 準備請求參數（開立 / 作廢發票用，折讓 / 查詢類另走專屬 DTO）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return DTO
	 * @throws \Exception 不支援以訂單直接準備的端點
	 */
	public function prepare_request_param( \WC_Order $order ): DTO {
		return match ($this) {
			self::ISSUE => IssueInvoiceParamsDTO::create( $order),
			self::CANCEL => new CancelInvoiceParamsDTO(
			[
				'orders' => [ $order ],
			]
				),
			default => throw new \Exception( "{$this->value} 不支援以訂單直接準備參數" ),
		};
	}
}
