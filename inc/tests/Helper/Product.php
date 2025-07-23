<?php

declare(strict_types=1);

namespace J7\PowerCheckoutTests\Helper;

use J7\PowerCheckoutTests\Utils\STDOUT;

/**
 * User class
 * 1. 實例化 Product 類別時，會自動創建 簡單、可變、訂閱、可變訂閱 產品
 * 2. 有 create 跟 delete 方法
 * TODO 可以考慮用 powerhouse 的方法
 */
class Product {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var \WC_Product[] */
	public $products = [];

	/**
	 * 創建 簡單、可變 產品
	 *
	 * @param array $args 商品資料
	 * @param int   $qty 創建數量
	 * @return self
	 */
	public function create( array $args = [], int $qty = 1 ): self {
		$type = $args['type'] ?? 'simple';
		unset($args['type']);

		match ($type) {
			'simple' => $this->create_simple($args, $qty),
			'variable' => $this->create_variable($args, $qty),
			'subscription' => $this->create_subscription($args, $qty),
			'variable_subscription' => $this->create_variable_subscription($args, $qty),
			default => $this->create_simple($args, $qty),
		};

		$ids = array_map(fn( $product ) => "#{$product->get_id()}", $this->products);
		STDOUT::ok("創建 {$qty} 個 {$type} 商品成功: " . implode(', ', $ids));

		return $this;
	}

	/**
	 * 創建簡單產品
	 *
	 * @param array $args 商品資料
	 * 詳情參考 https://github.com/j7-dev/wp-utils/blob/eb394b83a0d88fe697d1ea9a872acbb4f3008151/src/classes/WP.php#L267
	 * @param int   $qty 創建數量
	 * @return void
	 */
	public function create_simple( array $args = [], int $qty = 1 ) {
		for ($i = 0; $i < $qty; $i++) {
			$default_args = [
				'name'              => '簡單商品',
				'regular_price'     => '100',
				'description'       => '這是一個測試用的簡單商品',
				'short_description' => '簡單商品簡短描述',
				'status'            => 'publish',
			];
			$args         = \wp_parse_args($args, $default_args);
			$args['name'] = $args['name'] . " #{$i}";
			$product      = new \WC_Product_Simple();

			foreach ($args as $key => $value) {
				$product->update_meta_data($key, $value);
			}
			$product->save();
			$this->products[] = $product;
		}
	}

	/**
	 * 創建可變產品
	 *
	 * @param array $args 商品資料
	 * @param int   $qty 創建數量
	 * @return void
	 */
	public function create_variable( array $args = [], int $qty = 1 ) {
		for ($i = 0; $i < $qty; $i++) {
			$default_args = [
				'name'              => '可變商品',
				'regular_price'     => '200',
				'description'       => '這是一個測試用的可變商品',
				'short_description' => '可變商品簡短描述',
				'status'            => 'publish',
			];
			$args         = \wp_parse_args($args, $default_args);
			$args['name'] = $args['name'] . " #{$i}";
			// 創建可變商品
			$product = new \WC_Product_Variable();
			foreach ($args as $key => $value) {
				$product->update_meta_data($key, $value);
			}
			$product->save();

			// 創建商品屬性
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name('尺寸'); // 屬性名稱
			$attribute->set_options([ 'S', 'M', 'L' ]); // 屬性選項
			$attribute->set_position(0);
			$attribute->set_visible(true);
			$attribute->set_variation(true);

			$product->set_attributes([ $attribute ]);
			$product->save();

			// 創建商品變體
			$variation_data = [
				[
					'attributes'    => [
						'尺寸' => 'S',
					],
					'regular_price' => '100',
					'sku'           => 'VAR-S',
				],
				[
					'attributes'    => [
						'尺寸' => 'M',
					],
					'regular_price' => '120',
					'sku'           => 'VAR-M',
				],
				[
					'attributes'    => [
						'尺寸' => 'L',
					],
					'regular_price' => '140',
					'sku'           => 'VAR-L',
				],
			];

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
			$product = \wc_get_product($product->get_id());

			// 將創建的商品存入 variable_product 屬性
			$this->products[] = $product;
		}
	}

	/**
	 * 創建 訂閱 產品
	 *
	 * @param array $args 商品資料
	 * @param int   $qty 創建數量
	 * @return void
	 */
	public function create_subscription( array $args = [], int $qty = 1 ) {
		for ($i = 0; $i < $qty; $i++) {
			$default_args = [
				'name'              => '訂閱商品',
				'regular_price'     => '300',
				'description'       => '這是一個測試用的訂閱商品',
				'short_description' => '訂閱商品簡短描述',
				'status'            => 'publish',
			];
			$args         = \wp_parse_args($args, $default_args);
			$args['name'] = $args['name'] . " #{$i}";
			$product      = new \WC_Product_Subscription();
			foreach ($args as $key => $value) {
				$product->update_meta_data($key, $value);
			}

			// 還沒設定 meta data 扣款週期

			$product->save();
			$this->products[] = $product;
		}
	}

	/**
	 * 創建可變訂閱產品
	 */
	public function create_variable_subscription( $qty = 1 ) {
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
	 * 取得商品，預設隨機
	 *
	 * @param string|int $index_or_type 商品類型或索引
	 * @return \WC_Product
	 */
	public function get_product( string|int $index_or_type = 'random' ): \WC_Product {
		if (\is_numeric($index_or_type)) {
			return $this->products[ $index_or_type ];
		}
		return $this->products[ array_rand($this->products) ];
	}

	/**
	 * 測試結束後 刪除所有商品
	 */
	public function tear_down(): void {
		$count = count($this->products);
		$ids   = array_map(fn( $product ) => "#{$product->get_id()}", $this->products);
		// 刪除所有商品
		foreach ($this->products as $product) {
			$product->delete(true);
		}
		STDOUT::ok("刪除 {$count} 個商品成功: " . implode(', ', $ids));
		$this->products = [];
	}
}
