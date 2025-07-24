<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\EventType;

/**
 * Shopline 跳轉支付設定，單例
 */
final class Body extends DTO {

	/** @var string *SLP 事件 ID (35)*/
	public string $id;

	/** @var EventType::value *SLP 事件類型，如 session.succeeded (32)*/
	public string $type;

	/** @var int *通知事件建立時間 (14)*/
	public int $created;

	/** @var DTO 事件資料 DTO ，會依照 $type 不同，套用不同的 data DTO*/
	public DTO $data;


	/** @var array 必填屬性 */
	protected array $require_properties = [
		'id',
		'type',
		'created',
		'data',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 *  @param array{
	 *    id: string,
	 *    type: EventType::value,
	 *    created: int,
	 *    data: array{string: mixed},
	 * } $args
	 */
	public static function create( array $args ): self {
		$type         = EventType::from($args['type']);
		$data         = $type->dto($args['data']);
		$args['data'] = $data;
		return new self($args);
	}

	/** 自訂驗證邏輯 */
	public function validate(): void {
		parent::validate();
		EventType::from($this->type);
	}
}
