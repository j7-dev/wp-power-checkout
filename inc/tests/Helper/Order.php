<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;
use J7\PowerCheckoutTests\Helper\Product;
use J7\PowerCheckoutTests\Helper\User;

/**
 * Order class
 * 1. 實例化 Order 類別時，會自動創建訂單
 * 2. 有 create 跟 add 方法
 * @see https://rudrastyh.com/woocommerce/create-orders-programmatically.html
 */
class Order extends WC_UnitTestCase
{
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var \WC_Order */
	public $order;

	/**
	 * 創建訂單
	 * @param array{
	 * status?: string,
	 * customer_id?: int,
	 * customer_note?: string,
	 * parent?: int,
	 * order_id?: int,
	 * created_via: "admin", "checkout", "store-api",
	 * card_hash?: string,
	 * } $args
	 *
	 * @return self
	 */
	public function create($args = []):self
	{
		$user = User::instance()->user;
		$product = Product::instance()->create()->products[0];

		$default_args = array(
			'status' => 'pending', // 等待付款中
			'created_via' => 'admin', // default values are "admin", "checkout", "store-api"
			'order_id' => 0, // 新建立訂單
			'customer_id' => $user->ID, // 客戶ID
		);

		/**
		 * @var array{
		 * status?: string,
		 * customer_id?: int,
		 * customer_note?: string,
		 * parent?: int,
		 * order_id?: int,
		 * created_via: "admin", "checkout", "store-api",
		 * card_hash?: string,
		 * } $args
		 */
		$args = \wp_parse_args($args, $default_args);
		$this->order = \wc_create_order( $args );

		$this->order->add_product($product, 2);
		$this->order->calculate_totals();

		$this->order->save();
		return $this;
	}

		/**
	 * 測試結束後 刪除訂單
	 */
	public function tear_down()
	{
		parent::tear_down();
		$this->order->delete(true);
	}
}
