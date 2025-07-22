<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 PaymentMethod
 */
enum PaymentMethod: string {
	/** @var string 信用卡、信用卡分期 */
	case CREDITCARD = 'CreditCard';
	/** @var string ATM 銀行轉帳 */
	case VIRTUALACCOUNT = 'VirtualAccount';
	/** @var string 街口支付 */
	case JKOPAY = 'JKOPay';
	/** @var string ApplePay */
	case APPLEPAY = 'ApplePay';
	/** @var string LINE Pay */
	case LINEPAY = 'LinePay';
	/** @var string 中租zingla零卡分期 */
	case CHAILEASEBNPL = 'ChaileaseBNPL';


	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREDITCARD => '信用卡、信用卡分期',
			self::VIRTUALACCOUNT => 'ATM 銀行轉帳',
			self::JKOPAY => '街口支付',
			self::APPLEPAY => 'ApplePay',
			self::LINEPAY => 'LINE Pay',
			self::CHAILEASEBNPL => '中租zingla零卡分期',
		};
	}


	/**
	 * 取得選項的類別名稱
	 * PaymentMethodOption 用
	 *
	 * @return array<string> 選項的類別名稱
	 */
	public static function get_option_names(): array {
		return array_map(
			fn ( PaymentMethod $payment_method ) => "{$payment_method->value}Option",
			[
				self::CREDITCARD,
				self::VIRTUALACCOUNT,
				self::JKOPAY,
				self::CHAILEASEBNPL,
			]
			);
	}
}
