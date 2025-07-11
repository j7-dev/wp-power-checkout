<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components\Order;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Components\Amount;

/**
 * Product 訂單裡面的商品資訊 商品列表資訊，SLP 智慧風控必需
 * 請求會帶
 *  */
final class Product extends DTO {

	/** @var string (64) *商品編號 */
	public string $id;

	/** @var string (128) *商品名稱 */
	public string $name;

	/** @var int *商品數量 */
	public int $quantity;

	/** @var Amount *商品金額 */
	public Amount $amount;

	/** @var string (512) 商品描述 */
	public string $desc;

	/** @var string (256) 商品連結地址 */
	public string $url;

	/** @var string (64) 商品 sku 編號 */
	public string $sku;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'id',
		'name',
		'quantity',
		'amount',
	];

	/**
	 * @param \WC_Order_Item_Product $item 訂單商品
	 * @return self 創建實例
	 */
	public static function create( \WC_Order_Item_Product $item ): self {
		$id   = (string) ( $item->get_variation_id() ?: $item->get_product_id() );
		$args = [
			'id'       => ( new Helper($id) )->filter()->max( 64 )->value,
			'name'     => ( new Helper($item->get_name()) )->filter()->max( 128 )->value,
			'quantity' => $item->get_quantity(),
			'amount'   => Amount::create( (float) $item->get_total() ),
		];

		$product = $item->get_product();
		if ( $product ) { // 預防有人訂單產生後，刪除產品，就會拿不到資料
			/** @var \WC_Product $product */
			$args['desc'] = ( new Helper($product->get_short_description()) )->filter()->max( 512 )->value;
			$url          = $product->get_permalink();
			if ( strlen( $url ) <= 256 ) {
				$args['url'] = $url;
			}

			$args['sku'] = ( new Helper($product->get_sku()) )->filter()->max( 64 )->value;
		}

		return new self( $args );
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		parent::validate();
		if ( Helper::strlen( $this->id ) > 64 ) {
			$this->dto_error->add(
			'validate_failed',
			'id 長度不能超過 64 位'
			);
		}

		if ( Helper::strlen( $this->name ) > 128 ) {
			$this->dto_error->add(
			'validate_failed',
			'name 長度不能超過 128 位'
			);
		}

		if ( Helper::strlen( $this->desc ) > 512 ) {
			$this->dto_error->add(
			'validate_failed',
			'desc 長度不能超過 512 位'
			);
		}

		if ( Helper::strlen( $this->url ) > 256 ) {
			$this->dto_error->add(
			'validate_failed',
			'url 長度不能超過 256 位'
			);
		}

		if ( Helper::strlen( $this->sku ) > 64 ) {
			$this->dto_error->add(
			'validate_failed',
			'sku 長度不能超過 64 位'
			);
		}
	}
}
