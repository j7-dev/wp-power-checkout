<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Settings\DTOs\Settings as PowerCheckoutSettings;

/**
 * 綠界全方位金流 AIO 設定，單例
 */
final class Settings extends DTO {

	const KEY = 'EcpayAIO';

	/** @var 'prod' | 'test' 模式 */
	public string $mode = 'test';

	/** @var string 綠界特店編號 */
	public string $merchant_id;

	/** @var string HashKey */
	public string $hash_key;

	/** @var string HashIV */
	public string $hash_iv;

	/** @var string CheckMacValue */
	public string $check_mac_value;

	/** @var string 綠界 AioCheckOut 端點 */
	public string $aio_checkout_endpoint;

	/** @var string 綠界 QueryTradeInfo 端點 */
	public string $query_trade_info_endpoint;

	/** @var string 綠界 SPCreateTrade 端點 */
	public string $sptoken_endpoint;

	/** @var self|null 單例 */
	protected static $settings_instance = null;

	/**
	 * 創建實例，單例
	 *
	 * @param array $args 設定
	 * @return self
	 */
	public static function create( array $args = [] ): self {
		if (self::$settings_instance) {
			return self::$settings_instance;
		}
		self::$settings_instance = new self($args);
		return self::$settings_instance;
	}

	/**  @return self 取得實例，單例  */
	public static function instance(): self {
		return PowerCheckoutSettings::instance()->payments->EcpayAIO;
	}
}
