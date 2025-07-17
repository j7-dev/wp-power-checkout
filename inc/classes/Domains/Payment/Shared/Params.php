<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared;

use J7\WpUtils\Classes\WP;

/**
 * 請求、回應參數
 * 每次付款請求，不論是哪種付款方式，都將請求參數、回應參數 raw data 儲存在 order meta 中
 */
class Params {

	/** @var string 請求參數 meta_key */
	const REQUEST_KEY = 'pc_payment_req_params';

	/** @var string 回應參數 meta_key */
	const RESPONSE_KEY = 'pc_payment_res_params';

	/** @var \WC_Order 訂單 */
	public function __construct(
		private \WC_Order $order,
	) {}

	/**
	 * 儲存請求參數
	 *
	 * @param array<string, mixed> $params 儲存請求參數
	 * @param string               $url 請求 URL
	 * @return self
	 */
	public function save_request( array $params, string $url ): self {
		$this->order->update_meta_data(
			self::REQUEST_KEY,
			[
				'params' => $params,
				'url'    => $url,
			]
			);
		$this->order->save_meta_data();
		return $this;
	}

	/**
	 * 取得請求參數
	 *
	 * @param string $key 取得請求參數的 key
	 * @return array<string, mixed> 請求參數
	 */
	public function get_request( string $key = 'params' ): array {
		$params = $this->order->get_meta( self::REQUEST_KEY );
		$params = is_array( $params ) ? $params : [];
		return $key ? ( $params[ $key ] ?? [] ) : $params;
	}

	/** @param array<string, mixed> $params 儲存回應參數 @return self */
	public function save_response( array $params ): self {
		$this->order->update_meta_data(
			self::RESPONSE_KEY,
			[
				'params' => $params,
			]
			);
		$this->order->save_meta_data();
		return $this;
	}

	/**
	 * 取得回應參數
	 *
	 * @param string $key 取得回應參數的 key
	 * @return array<string, mixed> 回應參數
	 */
	public function get_response( string $key = 'params' ): array {
		$params = $this->order->get_meta( self::RESPONSE_KEY );
		$params = is_array( $params ) ? $params : [];
		return $key ? ( $params[ $key ] ?? [] ) : $params;
	}
}
