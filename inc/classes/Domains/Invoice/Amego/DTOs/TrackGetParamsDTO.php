<?php
/**
 * 光貿字軌取號請求參數 DTO（track_get）
 *
 * `/json/track_get` 的 `data` 為 Object：取「API 配號」類型字軌（1 本 = 50 張）。
 * 一般電商使用自動配號（f0401）即可，此端點供需自管號碼池的進階場景。
 *
 * 注意：此為「取號」操作（會消耗字軌本數），非純唯讀；本專案目前僅提供能力，
 * 後台 UI / REST 整合標記為後續（後續），預設不在自動流程中觸發。
 *
 * @see .claude/skills/amego-invoice/references/api-reference.md §字軌取號 /json/track_get
 */

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\WpUtils\Classes\DTO;

/** 光貿字軌取號請求參數 DTO（track_get） */
final class TrackGetParamsDTO extends DTO {

	/** @var int 西元年 */
	public int $Year = 0;

	/** @var int 期別 0~5 */
	public int $Period = 0;

	/** @var int 本數，1 本 = 50 張發票 */
	public int $Book = 1;

	/** @var string[] 必填 */
	protected array $require_properties = [
		'Year',
		'Period',
		'Book',
	];

	/**
	 * 建立取號參數
	 *
	 * @param int $year   西元年
	 * @param int $period 期別 0~5
	 * @param int $book   本數（≥ 1）
	 *
	 * @return self
	 * @throws \Exception 參數不合法
	 */
	public static function create_params( int $year, int $period, int $book = 1 ): self {
		if ($period < 0 || $period > 5) {
			throw new \Exception( '期別須為 0~5' );
		}
		if ($book < 1) {
			throw new \Exception( '本數須 ≥ 1' );
		}
		return new self(
			[
				'Year'   => $year,
				'Period' => $period,
				'Book'   => $book,
			]
		);
	}

	/**
	 * 輸出 Data
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'Year'   => $this->Year,
			'Period' => $this->Period,
			'Book'   => $this->Book,
		];
	}
}
