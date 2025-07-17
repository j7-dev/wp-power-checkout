<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Settings\Model\Settings as PowerCheckoutSettings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline 跳轉支付設定，單例
 */
final class Settings extends DTO {

	const KEY = 'ShoplineRedirect';

	/** @var Enums\Mode 模式 */
	public Enums\Mode $mode;

	/** @var string SLP 平台 ID，平台特店必填，平台特店底下會有子特店 */
	public string $platformId;

	/** @var string *直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID */
	public string $merchantId = '';

	/** @var string *API 介面金鑰 */
	public string $apiKey = '';

	/** @var string 客戶端金鑰 */
	public string $clinetKey = '';

	/** @var string 端點 */
	public string $apiUrl = 'https://api.shoplinepayments.com';

	/** @var array<Enums\PaymentMethod::value> 允許的付款方式 */
	public array $allowPaymentMethodList = [
		Enums\PaymentMethod::CREDITCARD,
		Enums\PaymentMethod::VIRTUALACCOUNT,
		Enums\PaymentMethod::JKOPAY,
		Enums\PaymentMethod::APPLEPAY,
		Enums\PaymentMethod::LINEPAY,
		Enums\PaymentMethod::CHAILEASEBNPL,
	];

	/** @var self|null 單例 */
	protected static $dto_instance = null;

	/** 創建實例，單例 @param array $args 設定 @return self */
	public static function create( array $args = [] ): self {
		if (self::$dto_instance) {
			return self::$dto_instance;
		}

		$mode = $args['mode'] ?? Enums\Mode::TEST->value;
		if (Enums\Mode::TEST->value === $mode) {
			$args['merchantId'] = '3252264968486264832';
			$args['apiKey']     = 'sk_sandbox_fc8d1884a9064b6ba4b2cc16d124663c';
			$args['clinetKey']  = 'pk_sandbox_f03ae82192c946888fbf0901b8d2053a';
			$args['apiUrl']     = 'https://api-sandbox.shoplinepayments.com';
		}

		self::$dto_instance = new self( $args);
		return self::$dto_instance;
	}

	/**  @return self 取得實例，單例 */
	public static function instance(): self {
		return PowerCheckoutSettings::instance()->payments->ShoplineRedirect;
	}
}
