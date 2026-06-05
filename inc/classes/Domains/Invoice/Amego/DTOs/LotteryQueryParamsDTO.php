<?php
/**
 * 光貿中獎發票查詢請求參數 DTO（lottery_status，唯讀）
 *
 * `/json/lottery_status` 的 `data` 為 Object：依西元年 + 期別查中獎發票。
 * 建議雙月 1 號後查（例：9-10 月發票 11/25 開獎，12/1 後查）。
 *
 * @see .claude/skills/amego-invoice/references/api-reference.md §中獎發票 /json/lottery_status
 */

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\WpUtils\Classes\DTO;

/** 光貿中獎發票查詢請求參數 DTO（lottery_status） */
final class LotteryQueryParamsDTO extends DTO {

	/** @var int 西元年 */
	public int $Year = 0;

	/** @var int 期別 0~5：0(1-2月)/1(3-4月)/2(5-6月)/3(7-8月)/4(9-10月)/5(11-12月) */
	public int $Period = 0;

	/** @var string[] 必填 */
	protected array $require_properties = [
		'Year',
		'Period',
	];

	/**
	 * 以年 + 期別建立查詢參數
	 *
	 * @param int $year   西元年
	 * @param int $period 期別 0~5
	 *
	 * @return self
	 * @throws \Exception 期別不在 0~5
	 */
	public static function create_params( int $year, int $period ): self {
		if ($period < 0 || $period > 5) {
			throw new \Exception( '期別須為 0~5' );
		}
		return new self(
			[
				'Year'   => $year,
				'Period' => $period,
			]
		);
	}

	/**
	 * 輸出查詢 Data
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'Year'   => $this->Year,
			'Period' => $this->Period,
		];
	}
}
