<?php
/**
 * PayNow 付款方式（REST API 體系 1 — PaymentIntent allowedPaymentMethods）
 *
 * String-backed enum，值域對齊 PayNow REST API `allowedPaymentMethods`
 * （payment-rest-api.md §4.1）。
 *
 * ⚠️ Q1 裁決（paynow-execution-plan §澄清裁決 Q1）：刻意「排除」`ApplePayDeferred`。
 *    官方規範 ApplePayDeferred 不可與其他付款方式併用，與本外掛 checkout 流程不相容，
 *    故本 enum 不含此 case；CreatePaymentIntentParams 收到該值即拒絕（防誤用）。
 *
 * is_offline() 分類：ATM / ConvenienceStore 為「待繳型」（取號後另行繳款），
 *   其餘（信用卡 / 分期 / LINE Pay 線上實體 / Apple Pay）皆為「即時授權型」。
 *
 * @see .claude/skills/paynow/references/concepts.md §4 付款方式總覽（體系 1）
 * @see .claude/skills/paynow/references/payment-rest-api.md §4.1 allowedPaymentMethods
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums;

/** PayNow 付款方式（string-backed，排除 ApplePayDeferred） */
enum PaynowPaymentMethod: string {
	/** 信用卡一次付清 */
	case CreditCard = 'CreditCard';
	/** 信用卡分期 */
	case CreditCardInstallment = 'CreditCardInstallment';
	/** ATM 虛擬帳號（待繳型） */
	case ATM = 'ATM';
	/** 超商代碼（待繳型） */
	case ConvenienceStore = 'ConvenienceStore';
	/** LINE Pay 線上 */
	case LINEPayOnline = 'LINEPayOnline';
	/** LINE Pay 實體 */
	case LINEPayOffline = 'LINEPayOffline';
	/** Apple Pay（即時，非延遲付款） */
	case ApplePay = 'ApplePay';

	/**
	 * 是否為離線（待繳型）付款方式
	 *
	 * ATM / ConvenienceStore 取號後另行繳款 → true；其餘即時授權 → false。
	 *
	 * @return bool
	 */
	public function is_offline(): bool {
		return match ( $this ) {
			self::ATM, self::ConvenienceStore => true,
			default                           => false,
		};
	}

	/**
	 * 取得付款方式中文標籤
	 *
	 * @return string
	 */
	public function label(): string {
		return match ( $this ) {
			self::CreditCard            => '信用卡',
			self::CreditCardInstallment => '信用卡分期',
			self::ATM                   => 'ATM 虛擬帳號',
			self::ConvenienceStore      => '超商代碼',
			self::LINEPayOnline         => 'LINE Pay 線上',
			self::LINEPayOffline        => 'LINE Pay 實體',
			self::ApplePay              => 'Apple Pay',
		};
	}
}
