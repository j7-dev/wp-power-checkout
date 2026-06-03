<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\DTOs;

use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * 整合的設定項基類
 *
 * @phpstan-consistent-constructor
 */
class BaseSettingsDTO extends DTO {
	use EnableTrait;

	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = '';

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '';

	/** @var string  標題 */
	public string $method_title = '';

	/** @var string  描述 */
	public string $method_description = '';

	/** @var string[] 自動開立發票的訂單狀態  */
	public array $auto_issue_order_statuses = [];

	/** @var string[] 自動作廢發票的訂單狀態  */
	public array $auto_cancel_order_statuses = [ 'wc-refunded' ];

	// endregion 基礎通用欄位

	/** @var Mode Mode 模式  */
	public Mode $mode = Mode::PROD;

	/**
	 * @param string $class_name 物流、電子發票類別
	 *
	 * @return static
	 */
	public static function create( string $class_name ): static {
		/** @var callable $callable */
		$callable = [ $class_name, 'get_settings' ];
		/** @var array<string, mixed> $settings_array */
		$settings_array = \call_user_func( $callable );
		return new static($settings_array);
	}

	/**
	 * 實例化前的處理
	 *
	 * 1. 對 dto_data 中所有 string 與 array 內 string 元素進行 trim_invisible_deep
	 *    （Issue #16 — 既有 wp_options 殘留前後不可見字元時，DTO 讀取要無感修復）
	 * 2. 將 mode 字串轉為 Mode enum
	 *
	 * @return void
	 */
	protected function before_init(): void {
		if (\is_array( $this->dto_data )) {
			$this->dto_data = StrHelper::trim_invisible_deep( $this->dto_data );
		}

		if (isset($this->dto_data['mode']) && \is_string($this->dto_data['mode'])) {
			$this->dto_data['mode'] = Mode::from($this->dto_data['mode']);
		}
	}
}
