<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\WC_UnitTestCase;
use J7\PowerCheckoutTests\Utils\STDOUT;

/**
 * User class
 * 1. 實例化 Product 類別時，會自動創建 簡單、可變、訂閱、可變訂閱 產品
 * 2. 有 create 跟 delete 方法
 * TODO 支援組合、外部商品
 */
class Product extends WC_UnitTestCase
{
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var \WC_Product[] */
	public $products = [];

	/**
	 * 創建 簡單、可變 產品
	 */
	public function create(string $type = 'simple', int $qty = 1):self
	{
		match ($type) {
			'simple' => $this->create_simple($qty),
			'variable' => $this->create_variable(),
			'subscription' => $this->create_subscription(),
			'variable_subscription' => $this->create_variable_subscription(),
			default => $this->create_simple(),
		};

		foreach ($this->products as $product) {
			STDOUT::ok('商品創建成功: #' . $product->get_id());
		}

		return $this;
	}

	/**
	 * 創建 簡單 產品
	 */
	public function create_simple($qty = 1)
	{
		for ($i = 0; $i < $qty; $i++) {
			$product = new \WC_Product_Simple();
			$product->set_name("簡單商品 #{$i}");
			$product->set_regular_price('100');
			$product->set_description('這是一個測試用的簡單商品');
			$product->set_short_description('簡短描述');
			$product->set_status('publish');
			$product->save();
			$this->products[] = $product;
		}
	}

	/**
	 * 創建 可變 產品
	 */
	public function create_variable($qty = 1) {
		for ($i = 0; $i < $qty; $i++) {
			// 創建可變商品
			$product = new \WC_Product_Variable();
			$product->set_name("可變商品 #{$i}");
			$product->set_description('這是一個測試用的可變商品');
			$product->set_short_description('簡短描述');
			$product->set_status('publish');
			$product->save();

			// 創建商品屬性
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name('尺寸'); // 屬性名稱
			$attribute->set_options(['S', 'M', 'L']); // 屬性選項
			$attribute->set_position(0);
			$attribute->set_visible(true);
			$attribute->set_variation(true);

			$product->set_attributes(array($attribute));
			$product->save();

			// 創建商品變體
			$variation_data = array(
				array(
					'attributes' => array(
						'尺寸' => 'S'
					),
					'regular_price' => '100',
					'sku' => 'VAR-S'
				),
				array(
					'attributes' => array(
						'尺寸' => 'M'
					),
					'regular_price' => '120',
					'sku' => 'VAR-M'
				),
				array(
					'attributes' => array(
						'尺寸' => 'L'
					),
					'regular_price' => '140',
					'sku' => 'VAR-L'
				)
			);

			foreach ($variation_data as $variation) {
				$new_variation = new \WC_Product_Variation();
				$new_variation->set_parent_id($product->get_id());
				$new_variation->set_attributes($variation['attributes']);
				$new_variation->set_regular_price($variation['regular_price']);
				$new_variation->set_sku($variation['sku']);
				$new_variation->set_status('publish');
				$new_variation->save();
			}

			// 重新讀取產品資料，確保能獲取到最新的變體資訊
			$product = wc_get_product($product->get_id());

				// 將創建的商品存入 variable_product 屬性
			$this->products[] = $product;
		}
	}

	/**
	 * 創建 訂閱 產品
	 */
	public function create_subscription($qty = 1) {
		for ($i = 0; $i < $qty; $i++) {
			$product = new \WC_Product_Subscription();
			$product->set_name("訂閱商品 #{$i}");
			$product->set_description('這是一個測試用的訂閱商品');
			$product->set_status('publish');
			$product->save();
			$this->products[] = $product;
		}
	}

	/**
	 * 創建 可變訂閱 產品
	 */
	public function create_variable_subscription($qty = 1) {
		for ($i = 0; $i < $qty; $i++) {
			$product = new \WC_Product_Variable_Subscription();
			$product->set_name("可變訂閱商品 #{$i}");
			$product->set_description('這是一個測試用的可變訂閱商品');
			$product->set_status('publish');
			$product->save();
			$this->products[] = $product;
		}
	}

	/**
	 * 測試結束後 刪除所有商品
	 */
	public function tear_down()
	{
		parent::tear_down();

		// 刪除所有商品
		foreach ($this->products as $product) {
			$product->delete(true);
		}
	}
}
