<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Settings\Model\Settings as PowerCheckoutSettings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components\AllowPaymentMethodList;

/**
 * Shopline 跳轉支付設定，單例
 */
final class Settings extends DTO {

	const KEY = 'ShoplineRedirect';

	/** @var 'prod' | 'test' 模式 */
	public string $mode = 'test';

	/** @var string SLP 平台 ID，平台特店必填，平台特店底下會有子特店 */
	public string $platformId;

	/** @var string *直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID */
	public string $merchantId;

	/** @var string *API 介面金鑰 */
	public string $apiKey;

	/** @var string 客戶端金鑰 */
	public string $clinetKey;

	/** @var string 端點 */
	public string $apiUrl;

	/** @var AllowPaymentMethodList 允許的付款方式 */
	public AllowPaymentMethodList $allowPaymentMethodList;

	/**
	 * 創建實例，單例
	 *
	 * @param array $args 設定
	 * @return self
	 */
	public static function create( array $args = [] ): self {
		if (self::$dto_instance) {
			return self::$dto_instance;
		}
		self::$dto_instance = new self($args);
		return self::$dto_instance;
	}

	/**  @return self 取得實例，單例 */
	public static function instance(): self {
		return PowerCheckoutSettings::instance()->payments->ShoplineRedirect;
	}
}
