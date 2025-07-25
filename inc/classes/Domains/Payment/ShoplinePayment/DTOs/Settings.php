<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Settings\DTOs\Settings as PowerCheckoutSettings;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline 跳轉支付設定，單例
 */
final class Settings extends DTO {

	const KEY = 'ShoplinePayment';

	/** @var Enums\Mode::value 模式 */
	public string $mode;

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

	/** @var string 簽名密鑰，需要設定完 webhook 後，由 shopline 窗口提供 @see https://docs.shoplinepayments.com/api/event/model/#簽章演算法 */
	public string $signKey = '';

	/** @var array<Enums\PaymentMethod::value> 允許的付款方式 */
	public array $allowPaymentMethodList = [
		'CreditCard',
		'VirtualAccount',
		'JKOPay',
		'ApplePay',
		'LinePay',
		'ChaileaseBNPL',
	];

	/** @var self|null 單例 */
	protected static $settings_instance = null;

	/** 創建實例，單例 @param array $args 設定 @return self */
	public static function create( array $args = [] ): self {
		if (self::$settings_instance) {
			return self::$settings_instance;
		}

		$mode = $args['mode'] ?? Enums\Mode::TEST->value;
		if (Enums\Mode::TEST->value === $mode) {
			$args['mode']       = Enums\Mode::TEST->value;
			$args['merchantId'] = '3252264968486264832';
			$args['apiKey']     = 'sk_sandbox_fc8d1884a9064b6ba4b2cc16d124663c';
			$args['clinetKey']  = 'pk_sandbox_f03ae82192c946888fbf0901b8d2053a';
			$args['apiUrl']     = 'https://api-sandbox.shoplinepayments.com';
			// TODO 這 signKey 是 partnerdemo 的 signKey，需要改成實際的 signKey
			$args['signKey'] = 'fea6681d4e8f4889ac06f944450e43b7';
		}

		if ( isset( $args['signKey'] ) ) {
			$args['signKey'] = mb_convert_encoding($args['signKey'], 'UTF-8', 'auto');
		}

		self::$settings_instance = new self( $args);
		return self::$settings_instance;
	}

	/**  @return self 取得實例，單例 */
	public static function instance(): self {
		return PowerCheckoutSettings::instance()->payments->ShoplinePayment;
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	public function validate(): void {
		parent::validate();
		Enums\Mode::from( $this->mode );
		foreach ( $this->allowPaymentMethodList as $payment_method ) {
			Enums\PaymentMethod::from( $payment_method );
		}
	}
}
