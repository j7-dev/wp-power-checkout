<?php
/**
 * 藍新 ezPay 電子發票 API endpoint
 *
 * 每支 API 皆為 HTTP POST + Form Post（UTF-8），POST body 固定兩欄：
 * MerchantID_（商店代號明文）、PostData_（業務參數 AES-256-CBC 加密後 hex）。
 * 業務參數寫在 PostData_ 加密前的 query string 內，並各自帶 Version（取自本 enum）。
 *
 * 端點 path（value）皆為 /Api 前綴的相對路徑，組 URL 時前接 EzpaySettingsDTO::get_api_url()。
 * Version 為每支 API 規定的「串接程式版本」字串，務必逐支對齊官方手冊：
 *  - 開立發票 invoice_issue      → 1.5
 *  - 作廢發票 invoice_invalid     → 1.0
 *  - 開立折讓 allowance_issue     → 1.3
 *  - 作廢折讓 allowanceInvalid    → 1.0
 *  - 查詢發票 invoice_search      → 1.3
 *
 * ⚠️ 本期不含兩段式觸發（invoice_touch_issue / allowance_touch_issue），故 enum 不列入。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums;

/** 藍新 ezPay 電子發票 API endpoint */
enum EApi: string {
	// 開立發票（即時 / 等待觸發 / 預約自動）.
	case INVOICE_ISSUE = '/Api/invoice_issue';

	// 作廢發票.
	case INVOICE_INVALID = '/Api/invoice_invalid';

	// 開立折讓（部分退款開折讓單）.
	case ALLOWANCE_ISSUE = '/Api/allowance_issue';

	// 作廢折讓（僅能作廢已確認的折讓）.
	case ALLOWANCE_INVALID = '/Api/allowanceInvalid';

	// 查詢發票（唯讀）.
	case INVOICE_SEARCH = '/Api/invoice_search';

	/**
	 * 取得該端點的「串接程式版本」字串（注入 PostData_ 的 Version 欄位）
	 *
	 * @return string Version（如 '1.5'）.
	 */
	public function version(): string {
		return match ( $this ) {
			self::INVOICE_ISSUE     => '1.5',
			self::INVOICE_INVALID   => '1.0',
			self::ALLOWANCE_ISSUE   => '1.3',
			self::ALLOWANCE_INVALID => '1.0',
			self::INVOICE_SEARCH    => '1.3',
		};
	}

	/**
	 * 取得端點中文標籤（log / order note 顯示用）
	 *
	 * @return string 標籤.
	 */
	public function label(): string {
		return match ( $this ) {
			self::INVOICE_ISSUE     => '開立發票',
			self::INVOICE_INVALID   => '作廢發票',
			self::ALLOWANCE_ISSUE   => '開立折讓',
			self::ALLOWANCE_INVALID => '作廢折讓',
			self::INVOICE_SEARCH    => '查詢發票',
		};
	}
}
