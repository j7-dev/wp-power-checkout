<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\DTOs;

use J7\WpUtils\Classes\DTO;

/**
 * Power Checkout Settings
 *
 * 取得各個金流的設定
 * 所有的設定都存放在 wp_options 中 option_name 為 power_checkout_settings
 * power_checkout_settings 是一個超大的 array，裡面有各種設定
 *
 * 金流 [power_checkout_settings][payments][$gateway_id]
 * 物流 [power_checkout_settings][shippings][$shipping_id]
 * 電子發票 [power_checkout_settings][invoices][$invoice_id]
 *  */
final class Settings extends DTO {

	const OPTION_NAME = 'power_checkout_settings';

	/** @var Components\Payments 金流 */
	public Components\Payments $payments;

	/** @var Components\Shippings 物流 */
	// public Components\Shippings $shippings;

	/** @var Components\Invoices 電子發票 */
	// public Components\Invoices $invoices;

	/** 取得實例，單例 */
	public static function instance(): self {
		if (self::$dto_instance) {
			return self::$dto_instance;
		}
		$settings = \get_option(self::OPTION_NAME, []);
		$settings = is_array($settings) ? $settings : [];
		$args     = [
			'payments' => Components\Payments::create($settings['payments'] ?? []),
			// 'shippings' => Components\Shippings::create($settings['shippings'] ?? []),
			// 'invoices'  => Components\Invoices::create($settings['invoices'] ?? []),
		];
		self::$dto_instance = new self($args);
		return self::$dto_instance;
	}
}
