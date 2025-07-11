<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components;

use J7\WpUtils\Classes\DTO;


/**
 * AllowPaymentMethodList
 * 請求會帶
 *
 * 設定 SessionURL 上可以使用的付款方式，陣列的順序為實際在 Session URL 顯示的付款方式順序。傳入範例：["CreditCard", "VirtualAccount", "JKOPay", "ApplePay", "LinePay", "ChaileaseBNPL"]
 * */
final class AllowPaymentMethodList extends DTO {

	/** @var string 信用卡、信用卡分期 */
	const CREDITCARD = 'CreditCard';
	/** @var string ATM 銀行轉帳 */
	const VIRTUALACCOUNT = 'VirtualAccount';
	/** @var string 街口支付 */
	const JKOPAY = 'JKOPay';
	/** @var string ApplePay */
	const APPLEPAY = 'ApplePay';
	/** @var string LINE Pay */
	const LINEPAY = 'LinePay';
	/** @var string 中租zingla零卡分期 */
	const CHAILEASEBNPL = 'ChaileaseBNPL';

	/** @var array<string> 設定 SessionURL 上可以使用的付款方式，陣列的順序為實際在 Session URL 顯示的付款方式順序。傳入範例：["CreditCard", "VirtualAccount", "JKOPay", "ApplePay", "LinePay", "ChaileaseBNPL"] */
	public array $allowPaymentMethodList = [];

	/**
	 * 取得實例
	 *
	 * @param array<string> $allow_payment_method_list 允許的付款方式
	 * @return self 取得實例
	 */
	public static function instance( array $allow_payment_method_list = [] ): self {
		$args = [
			'allowPaymentMethodList' => $allow_payment_method_list,
		];
		return new self($args);
	}

	/** @return array<string> 轉換為陣列 */
	public function to_array(): array {
		return $this->allowPaymentMethodList;
	}

	/**
	 * 取得付款方式的標籤
	 *
	 * @param string $payment_method 付款方式
	 * @return string 付款方式的標籤
	 * @throws \Exception 不支援的付款方式
	 */
	public function get_label( string $payment_method ): string {
		return match ($payment_method) {
			self::CREDITCARD => __('CreditCard', 'power-checkout'),
			self::VIRTUALACCOUNT => __('VirtualAccount', 'power-checkout'),
			self::JKOPAY => __('JKOPay', 'power-checkout'),
			self::APPLEPAY => __('ApplePay', 'power-checkout'),
			self::LINEPAY => __('LINE Pay', 'power-checkout'),
			self::CHAILEASEBNPL => __('ChaileaseBNPL', 'power-checkout'),
			default => throw new \Exception("不支援的付款方式：{$payment_method}"),
		};
	}


	/** 驗證 allowPaymentMethodList 的值是否都是常數之一 */
	protected function validate(): void {
		$constants = [
			self::CREDITCARD,
			self::VIRTUALACCOUNT,
			self::JKOPAY,
			self::APPLEPAY,
			self::LINEPAY,
			self::CHAILEASEBNPL,
		];
		foreach ($this->allowPaymentMethodList as $payment_method) {
			if (!in_array($payment_method, $constants)) {
				$this->dto_error->add(
					'validate_failed',
					'allowPaymentMethodList 必須為 ' . implode(', ', $constants) . ' 其中一個'
				);
			}
		}
	}
}
