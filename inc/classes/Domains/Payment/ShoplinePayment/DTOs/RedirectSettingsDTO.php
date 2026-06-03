<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs;

use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGatewaySettings;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline 跳轉支付設定，單例
 * 從 woocommerce_{$gateway_id}_settings 取得資料
 */
final class RedirectSettingsDTO extends DTO implements IGatewaySettings {
	use EnableTrait;

	// region 基礎通用欄位

	/** @var string 付款方式 icon */
	public string $icon = 'https://img.shoplineapp.com/media/image_clips/62297669a344ad002979d725/original.png';

	/** @var string 前台顯示付款方式標題 */
	public string $title = 'Shopline Payment 線上付款';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '提供信用卡一次付清、分期付款、虛擬帳號轉帳，以及 LINE Pay、街口支付、APPLE PAY 等付款方式';

	/** @var string 前台顯示付款方式按鈕文字 */
	public string $order_button_text = '前往付款';

	/** @var int 付款期限(分鐘)，通常 ATM / CVS / BARCODE 才有 */
	public int $expire_min = 360;

	/** @var int 付款方式最小金額 */
	public int $min_amount = 0;

	/** @var int 付款方式最大金額 */
	public int $max_amount = 0;

	/** @var string $mode Enums\Mode::value 模式  */
	public string $mode = 'prod';

	// endregion

	/** @var string SLP 平台 ID，平台特店必填，平台特店底下會有子特店 */
	public string $platformId = '';
	/** @var string *直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID */
	public string $merchantId = '';
	/** @var string *API 介面金鑰 */
	public string $apiKey = '';
	/** @var string 客戶端金鑰 */
	public string $clientKey = '';
	/** @var string 端點 */
	public string $apiUrl = 'https://api.shoplinepayments.com';
	/** @var string 簽名密鑰，需要設定完 webhook 後，由 shopline 窗口提供 @see https://docs.shoplinepayments.com/api/event/model/#簽章演算法 */
	public string $signKey = '';

	/** @var array<string> $allowPaymentMethodList array<Enums\PaymentMethod::value> 允許的付款方式 */
	public array $allowPaymentMethodList = [
		'CreditCard',
		'VirtualAccount',
		// 'JKOPay',
					'ApplePay',
		'LinePay',
		'ChaileaseBNPL',
	];

	/** @var array<string, array<string, list<string>>> $paymentMethodOptions */
	public array $paymentMethodOptions = [
		'CreditCard' => [
			'installmentCounts' => [ '0', '3', '6', '9', '12', '18', '24' ],
		],
		'ChaileaseBNPL' => [
			'installmentCounts' => [ '0', '3', '6', '12', '18', '24', '30', '36' ],
		],
	];

	/** 取得實例 */
	public static function instance(): self {
		$settings_array = ProviderUtils::get_option( RedirectGateway::ID );
		$settings_array = \is_array( $settings_array ) ? $settings_array : [];
		return new self($settings_array);
	}

	/**  @return void 型別轉換 */
	protected function before_init(): void {
		// Issue #16：先 trim 所有 string 與陣列內 string 元素，避免 wp_options 殘留的前後不可見字元污染屬性
		if (\is_array( $this->dto_data )) {
			$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );
		}

		$int_keys = [
			'expire_min',
			'min_amount',
			'max_amount',
		];
		foreach ($int_keys as $key) {
			if (!isset($this->dto_data[ $key ])) {
				continue;
			}
			$this->dto_data[ $key ] = (int) $this->dto_data[ $key ];
		}

		if (!isset($this->dto_data['order_button_text'])) {
			$this->dto_data['order_button_text'] = \sprintf(\__( '使用 %s 付款', 'power_checkout' ), $this->title);
		}
	}

	/**
	 * 實例化後，如果是 測試模式就修改屬性
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if (Mode::TEST->value === $this->mode) {
			$this->merchantId = '3252264968486264832';
			$this->apiKey     = 'sk_sandbox_fc8d1884a9064b6ba4b2cc16d124663c';
			$this->clientKey  = 'pk_sandbox_f03ae82192c946888fbf0901b8d2053a';
			$this->apiUrl     = 'https://api-sandbox.shoplinepayments.com';
			// TODO 這 signKey 是 partnerdemo 的 signKey，需要改成實際的 signKey // fea6681d4e8f4889ac06f944450e43b7
		} else {
			$this->apiUrl = 'https://api.shoplinepayments.com';
		}

		if ( !empty( $this->signKey ) ) {
			$converted     = \mb_convert_encoding($this->signKey, 'UTF-8', 'auto');
			$this->signKey = \is_string($converted) ? $converted : $this->signKey;
			// Issue #16：mb_convert_encoding 可能引入頭尾不可見字元，再 trim 一次作為兜底
			$this->signKey = StrHelper::trim_invisible( $this->signKey );
		}
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *
	 * @noinspection PhpExpressionResultUnusedInspection
	 */
	protected function validate(): void {
		parent::validate();
		if (isset( $this->mode)) {
			Mode::from( $this->mode );
		}
		foreach ( $this->allowPaymentMethodList as $payment_method ) {
			Enums\PaymentMethod::from( $payment_method );
		}
	}
}
