<?php
/**
 * 光貿作廢折讓請求參數 DTO（g0501）
 *
 * 作廢已開立折讓單。`/json/g0501` 的 `data` 為「陣列」，可一次作廢多張，
 * 每筆僅需 CancelAllowanceNumber（折讓單編號）。
 *
 * @see .claude/skills/amego-invoice/references/api-reference.md §作廢折讓 /json/g0501
 */

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\WpUtils\Classes\DTO;

/** 光貿作廢折讓請求參數 DTO（g0501） */
final class CancelAllowanceParamsDTO extends DTO {

	/** @var string 折讓單編號 */
	public string $CancelAllowanceNumber = '';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'CancelAllowanceNumber',
	];

	/**
	 * 從已開立折讓資料建立作廢參數
	 *
	 * @param array<string, mixed> $allowance_data 已開立折讓 meta（含 allowance_number）
	 *
	 * @return self
	 * @throws \Exception 找不到折讓單號
	 */
	public static function from_allowance_data( array $allowance_data ): self {
		$allowance_number = (string) ( $allowance_data['allowance_number'] ?? '' );
		if (!$allowance_number) {
			throw new \Exception( '找不到折讓單號，無法作廢折讓' );
		}

		return new self(
			[
				'CancelAllowanceNumber' => $allowance_number,
			]
		);
	}

	/**
	 * 取得公開的屬性 array
	 *
	 * 作廢折讓 g0501 的 data 為「陣列」，故 to_array 回傳 list。
	 * 與 CancelInvoiceParamsDTO 相同：回傳 list 但以 array<string,mixed> 標註相容基類。
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		// g0501 的 data 為 list（一筆作廢折讓 Object）
		$entry = [
			'CancelAllowanceNumber' => $this->CancelAllowanceNumber,
		];
		// 以 array_values 包成 list；經 array_merge 擦除 sealed shape，再以 @var 相容基類
		/** @var array<string, mixed> $result */
		$result = \array_merge( [], [ $entry ] );
		return $result;
	}
}
