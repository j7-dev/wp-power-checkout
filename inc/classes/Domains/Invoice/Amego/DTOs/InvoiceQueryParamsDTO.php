<?php
/**
 * 光貿發票查詢請求參數 DTO（invoice_query，唯讀）
 *
 * `/json/invoice_query` 的 `data` 為 Object，依 type 擇一帶 order_id / invoice_number：
 *   - type=order   → order_id（訂單編號，≤ 40 字，限 180 天內）
 *   - type=invoice → invoice_number（發票號碼，≤ 10 字，限 180 天內）
 *
 * @see .claude/skills/amego-invoice/references/api-reference.md §發票查詢 /json/invoice_query
 */

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\WpUtils\Classes\DTO;

/** 光貿發票查詢請求參數 DTO（invoice_query） */
final class InvoiceQueryParamsDTO extends DTO {

	/** @var string 查詢類型：order 訂單編號 / invoice 發票號碼 */
	public string $type = 'invoice';

	/** @var string 訂單編號（type=order 時必填） */
	public string $order_id = '';

	/** @var string 發票號碼（type=invoice 時必填） */
	public string $invoice_number = '';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'type',
	];

	/**
	 * 以發票號碼建立查詢參數
	 *
	 * @param string $invoice_number 發票號碼
	 *
	 * @return self
	 * @throws \Exception 發票號碼為空
	 */
	public static function by_invoice_number( string $invoice_number ): self {
		if ('' === $invoice_number) {
			throw new \Exception( '發票號碼為空，無法查詢' );
		}
		return new self(
			[
				'type'           => 'invoice',
				'invoice_number' => $invoice_number,
			]
		);
	}

	/**
	 * 取得公開的屬性 array（依 type 僅帶必要欄位）
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		if ('order' === $this->type) {
			return [
				'type'     => 'order',
				'order_id' => $this->order_id,
			];
		}
		return [
			'type'           => 'invoice',
			'invoice_number' => $this->invoice_number,
		];
	}
}
