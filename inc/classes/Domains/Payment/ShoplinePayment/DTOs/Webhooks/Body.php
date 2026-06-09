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

	/** @var string *SLP 事件類型，如 session.succeeded (32)*/
	public string $type;

	/** @var int *通知事件建立時間 (14)*/
	public int $created;

	/** @var DTO 事件資料 DTO ，會依照 $type 不同，套用不同的 data DTO*/
	public DTO $data;


	/** @var array<string> 必填屬性 */
	protected array $require_properties = [
		'id',
		'type',
		'created',
		'data',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array<string, mixed> $args 參數
	 */
	public static function create( array $args ): self {
		$type = EventType::from( (string) $args['type']);
		/** @var array<string, mixed> $raw_data */
		$raw_data     = $args['data'] ?? [];
		$data         = $type->get_manager()->get_dto($raw_data);
		$args['data'] = $data;
		return new self($args);
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {
		parent::validate();
		if (isset( $this->type)) {
			EventType::from($this->type);
		}
	}

	/** @return \WC_Order|null 取得 Webhook 關聯的訂單，只有 Session|Payment 的 EventType 會拿到 Order */
	public function get_order(): \WC_Order|null {
		$data = $this->data;
		if ($data instanceof Session) {
			$order_id = $data->referenceId;
		} elseif ($data instanceof Payment) {
			$order_id = $data->referenceOrderId;
		} else {
			return null;
		}

		$order = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			return null;
		}
		return $order;
	}

	/** @return EventType Webhook 的 Event Type */
	public function get_event_type(): EventType {
		return EventType::from( $this->type );
	}
}
