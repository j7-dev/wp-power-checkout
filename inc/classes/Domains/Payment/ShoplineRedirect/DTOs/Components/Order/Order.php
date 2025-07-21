<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\Order;

use J7\WpUtils\Classes\DTO;

/**
 * Order 訂單 交易訂單資訊
 * 請求會帶
 *  */
final class Order extends DTO {

	/** @var Product[] 商品 */
	public array $products;

	/** @var Shipping 運送 */
	public Shipping $shipping;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'products',
		'shipping',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 創建實例
	 */
	public static function create( \WC_Order $order ): self {
		$products = [];
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$products[] = Product::create( $item );
		}

		$args = [
			'products' => $products,
			'shipping' => Shipping::create( $order ),
		];

		return new self($args);
	}
}
