<?php
/**
 * 綠界物流帳號類型 LogisticsAccountType
 *
 * 同一 provider 內以 account_type 切換兩組憑證（B2C / C2C），
 * 兩組 MerchantID / HashKey / HashIV 各異，用錯帳號 AES 解密會直接失敗（計畫 R5）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §C2C 操作
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 綠界物流帳號類型 */
enum LogisticsAccountType: string {
	/** B2C 大宗寄倉（測試帳號 MerchantID 2000132） */
	case B2C = 'b2c';
	/** C2C 店到店（測試帳號 MerchantID 2000933，HashKey/HashIV 各異） */
	case C2C = 'c2c';
}
