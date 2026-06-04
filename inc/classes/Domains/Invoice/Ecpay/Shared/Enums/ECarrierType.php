<?php
/**
 * 綠界 B2C 發票載具類別 CarrierType
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §載具類型
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums;

/** 綠界 B2C 載具類別 */
enum ECarrierType: string {
	// 無載具（紙本或捐贈）
	case NONE = '';

	// 綠界科技電子發票載具（雲端，測試最簡單）
	case ECPAY = '1';

	// 自然人憑證條碼
	case MOICA = '2';

	// 手機條碼
	case MOBILE = '3';

	// 悠遊卡
	case EASYCARD = '4';

	// 一卡通
	case IPASS = '5';

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::NONE => '無載具',
			self::ECPAY => '綠界電子發票載具',
			self::MOICA => '自然人憑證條碼',
			self::MOBILE => '手機條碼',
			self::EASYCARD => '悠遊卡',
			self::IPASS => '一卡通',
		};
	}
}
