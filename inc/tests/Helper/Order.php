<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;
use J7\PowerCheckoutTests\Helper\Product;
use J7\PowerCheckoutTests\Helper\User;
use J7\PowerCheckoutTests\Utils\STDOUT;

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
	public \WC_Order $order;

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
	public function create(array $args = []):self
	{
		$user = User::instance()->create()->user;
		$product = \reset(Product::instance()->create()->products);

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
		STDOUT::ok('訂單創建成功: #' . $this->order->get_id());

		$this->order->add_product($product, 2);
		$this->order->calculate_totals();
		return $this;
	}

	/**
	 * 設定訂單的 billing 資料
	 * @param array{
	 * address_1?: string,
	 * address_2?: string,
	 * city?: string,
	 * company?: string,
	 * country?: string,
	 * email?: string,
	 * first_name?: string,
	 * last_name?: string,
	 * phone?: string,
	 * postcode?: string,
	 * state?: string,
	 * } $args
	 * @param string $type 資料類型 billing | shipping
	 * @return self
	 */
	public function set_data(array $args = [], string $type = 'billing'):self
	{

		$default_args = [
			"address_1" => "台北市信義區信義路五段7號",
			"address_2" => "101大樓35樓",
			"city" => "台北市",
			"company" => "台積電股份有限公司",
			"country" => "TW",
			"email" => "chen.ming.hui@example.com.tw",
			"first_name" => "明輝",
			"last_name" => "陳",
			"phone" => "0912345678",
			"postcode" => "110",
			"state" => "台北市"
	 ];

		$args = \wp_parse_args($args, $default_args);

		foreach ($args as $key => $value) {
			$this->order->{"set_{$type}_{$key}"}($value);
		}
		return $this;
	}

	/**
	 * 設定折扣，測試小數點用
	 * @param float $discount 折扣百分比
	 * @return self
	 */
	public function set_discount(float $discount = 0.87):self
	{
		$order_total = $this->order->get_total();
		$this->order->set_discount_total($order_total * $discount);

		return $this;
	}

	/** 儲存訂單 */
	public function save():self
	{
		$this->order->save();
		return $this;
	}

		/**
	 * 測試結束後 刪除訂單
	 */
	public function tear_down()
	{
		$this->order->delete(true);
	}
}
