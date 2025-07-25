<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Settings\DTOs\Settings as PowerCheckoutSettings;
use J7\PowerCheckout\Domains\Payment;

/**
 * Power Checkout Settings 的 Payments
 *
 * 取得各個金流的設定
 * 所有的設定都存放在 wp_options 中 option_name 為 power_checkout_settings
 * power_checkout_settings 是一個超大的 array，裡面有各種設定
 *
 * 金流 [power_checkout_settings][payments][$gateway_id]
 *  */
final class Payments extends DTO {

	/** @var Payment\EcpayAIO\DTOs\Settings EcpayAIO 綠界 All in One 跳轉支付 */
	public Payment\EcpayAIO\DTOs\Settings $EcpayAIO;

	/** @var Payment\ShoplinePayment\DTOs\Settings Shopline 跳轉支付 */
	public Payment\ShoplinePayment\DTOs\Settings $ShoplinePayment;

	/** @var self|null 實例 */
	protected static self|null $settings_instance = null;

	/** 創建實例，單例 */
	public static function create( array $payments = [] ): self {
		if (self::$settings_instance) {
			return self::$settings_instance;
		}

		$payment_keys = [
			Payment\EcpayAIO\DTOs\Settings::KEY        => Payment\EcpayAIO\DTOs\Settings::class,
			Payment\ShoplinePayment\DTOs\Settings::KEY => Payment\ShoplinePayment\DTOs\Settings::class,
		];

		$args = [];
		foreach ( $payment_keys as $key => $class ) {
			// 將 $payments[ $key ] array 傳入到 Settings class 的 create 方法創建實例
			$args[ $key ] = call_user_func( [ $class, 'create' ], $payments[ $key ] ?? [] );
		}

		self::$settings_instance = new self($args);
		return self::$settings_instance;
	}

	/** 取得實例，單例 */
	public static function instance(): self {
		return PowerCheckoutSettings::instance()->payments;
	}
}
