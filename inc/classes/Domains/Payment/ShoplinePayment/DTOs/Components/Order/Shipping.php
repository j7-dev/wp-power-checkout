<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Order;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\PersonalInfo;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Address;

/**
 * Shipping 訂單裡面的運送資訊 物流訂單資訊,SLP 智慧風控必需
 * 請求會帶
 *  */
final class Shipping extends DTO {

	/** @var string (64) *物流方式，如超商取貨/宅配等 */
	public string $shippingMethod;

	/** @var string (64) *物流通道，如黑貓宅配等 */
	public string $carrier;

	/** @var PersonalInfo *收件人資訊 */
	public PersonalInfo $personalInfo;

	/** @var Address *收件地址 */
	public Address $address;

	/** @var array<string> 必填屬性 */
	protected array $required_properties = [
		'shippingMethod',
		'carrier',
		'personalInfo',
		'address',
	];
	/**
	 * 由 WC_Order 組裝 SLP 運送資訊（智慧風控必需，兩欄皆 String(64)、自由字串）
	 *
	 * 依 SLP create session 文件（api-reference.md）：
	 *   - shippingMethod：物流「方式」，e.g. "delivery" / "pickup"。
	 *     目前以 WC 的 get_shipping_method()（運送方式 label）填入，語意相符。
	 *   - carrier：物流「通道 / 承運商」名稱，e.g. carrier name（如「黑貓宅配」）。
	 *     WC 標準資料無獨立 carrier 來源（承運商綁在運送方式設定內，無通用 API），
	 *     文件對 carrier 僅給 "e.g. carrier name"，未規定固定值。
	 *
	 * TODO carrier 正確值待確認：理想為實際承運商名稱，但無可靠 WC 來源；
	 *      暫以 get_shipping_method() 佔位（風控仍收到非空字串）。
	 *      確認 SLP 對 carrier 是否有格式 / 白名單要求後再調整，勿貿然改動以免影響既有風控通過率。
	 *
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$args = [
			'shippingMethod' => ( new StrHelper( $order->get_shipping_method() ?: 'N/A', 'shippingMethod', 64) )->substr()->value,
			'carrier'        => ( new StrHelper( $order->get_shipping_method() ?: 'N/A', 'carrier', 64) )->substr()->value,
			'personalInfo'   => PersonalInfo::create( $order ),
			'address'        => Address::create( $order ),
		];

		return new self( $args );
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		( new StrHelper( $this->shippingMethod, 'shippingMethod', 64) )->get_strlen( true);
		( new StrHelper( $this->carrier, 'carrier', 64) )->get_strlen( true);
	}
}
