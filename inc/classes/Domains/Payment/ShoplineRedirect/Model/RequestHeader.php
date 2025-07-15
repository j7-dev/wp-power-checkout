<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Service;

/**
 * Shopline Payment 跳轉式支付 Request Header
 * 工廠模式， requestId 必須每次請求唯一
 *
 * @example 放進 wp_remote_post 的 header 中
 * $response = wp_remote_post( $url, array(
 *   'body'    => $data,
 *   'headers' => RequestHeader::create()->to_array(),
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

	/** @var string (32) 冪等 KEY (還不知道用途) */
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
		$service      = Service::instance();
		$milliseconds = intval(( new \DateTimeImmutable() )->format('Uv')); // 13位
		$request_id   = $order->get_id() . '-' . \wp_unique_id() . '-' . $milliseconds;
		$args         = [
			'merchantId' => $service->settings->merchantId,
			'apiKey'     => $service->settings->apiKey,
			'requestId'  => ( new Helper($request_id) )->max( 32 )->value,
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

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		parent::validate();

		if (strlen($this->requestId) > 32) {
			$this->dto_error->add(
			'validate_failed',
			'requestId 長度不能超過 32 個字'
			);
		}

		if (isset($this->idempotentKey)) {
			if (strlen($this->idempotentKey) > 32) {
				$this->dto_error->add(
				'validate_failed',
				'idempotentKey 長度不能超過 32 個字'
				);
			}
		}
	}
}
