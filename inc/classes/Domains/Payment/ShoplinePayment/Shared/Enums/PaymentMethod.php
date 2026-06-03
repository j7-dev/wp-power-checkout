<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 PaymentMethod
 */
enum PaymentMethod: string {
	/** 信用卡、信用卡分期 */
	case CREDITCARD = 'CreditCard';
	/** ATM 銀行轉帳 */
	case VIRTUALACCOUNT = 'VirtualAccount';
	/** 街口支付 */
	case JKOPAY = 'JKOPay';
	/** ApplePay */
	case APPLEPAY = 'ApplePay';
	/** LINE Pay */
	case LINEPAY = 'LinePay';
	/** 中租zingla零卡分期 */
	case CHAILEASEBNPL = 'ChaileaseBNPL';


	/**
	 * 取得狀態的標籤
	 *
	 * @return string 狀態的標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::CREDITCARD => '信用卡',
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

	/**
	 * @param \WC_Order $order 訂單
	 * @param float     $amount 退款金額
	 *
	 * @return bool|\WP_Error
	 */
	public function can_refund( \WC_Order $order, float $amount ): bool|\WP_Error {
		if (self::VIRTUALACCOUNT === $this) {
			return new \WP_Error('refund_not_support', "❌ {$this->label()} 不支援退款");
		}

		// 中租零角只支援全額退款
		$full_refund = ( (float) $order->get_total() ) === $amount;
		if (self::CHAILEASEBNPL === $this && !$full_refund) {
			return new \WP_Error('refund_not_support', "❌ {$this->label()} 僅支援全額退款，不支援部分退款");
		}

		return true;
	}
}
