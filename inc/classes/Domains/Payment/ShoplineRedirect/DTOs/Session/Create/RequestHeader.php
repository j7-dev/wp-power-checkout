<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Session\Create;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Settings;

/**
 * Shopline Payment 跳轉式支付 Request Header
 * 工廠模式， requestId 必須每次請求唯一
 *
 * @example 放進 wp_remote_post 的 header 中
 * $response = wp_remote_post( $url, array(
 *   'body'    => $data,
 *   'headers' => RequestHeader::create($order)->to_array(),
 * ) );
 */
final class RequestHeader extends DTO {

	/** @var string *固定值：application/json */
	public string $ContentType = 'application/json';

	/** @var string SLP 平台 ID，平台特店必填，平台特店底下會有子特店 */
	public string $platformId;

	/** @var string *直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID */
	public string $merchantId;

	/** @var string *API 介面金鑰 */
	public string $apiKey;

	/** @var string (32) *請求流水號，每個 HTTP 請求唯一，可以用 $order_id + 請求唯一數 + 13位timestamp， order_id 16位之類都沒問題 */
	public string $requestId;

	/** @var string (32) 冪等 KEY AI: 冪等性意思是同一個操作執行多次，結果都一樣。在金流中，就是避免用戶重複點擊付款按鈕導致重複扣款的災難。 */
	public string $idempotentKey;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'merchantId',
		'apiKey',
		'requestId',
	];

	/**
	 * @param \WC_Order $order 訂單
	 * @return self 取得實例
	 */
	public static function create( \WC_Order $order ): self {
		$settings     = Settings::instance();
		$milliseconds = intval(( new \DateTimeImmutable() )->format('Uv')); // 13位
		$request_id   = $order->get_id() . '-' . \wp_unique_id() . '-' . $milliseconds;
		$args         = [
			'merchantId' => $settings->merchantId,
			'apiKey'     => $settings->apiKey,
			'requestId'  => ( new Helper($request_id, 'requestId', 32) )->substr()->value,
		];

		return new self($args);
	}

	/** @return array<string, string> 轉換為陣列 */
	public function to_array(): array {
		$to_array                 = parent::to_array();
		$to_array['Content-Type'] = $this->ContentType;
		unset($to_array['ContentType']);
		return $to_array;
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();

		if (strlen($this->requestId) > 32) {
			throw new \Exception('requestId 長度不能超過 32 個字');
		}

		if (isset($this->idempotentKey)) {
			if (strlen($this->idempotentKey) > 32) {
				throw new \Exception('idempotentKey 長度不能超過 32 個字');
			}
		}
	}
}
