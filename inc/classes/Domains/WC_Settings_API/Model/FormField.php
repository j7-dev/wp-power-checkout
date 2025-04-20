<?php

declare (strict_types = 1);

namespace J7\PowerPayment\Domains\WC_Settings_API\Model;

use J7\WpUtils\Classes\DTO;

/**
 * FormField
 * 描述 WC_Settings_API 的 form_fields 的單一欄位
 *
 * @see https://developer.woocommerce.com/docs/settings-api/
 *  */
class FormField extends DTO {
	/** @var string 欄位名稱 */
	protected $field_name = '';

	/** @var string 設定頁面上顯示的標題 */
	public $title = '';

	/** @var string 設定頁面上顯示的描述 */
	public $description = '';

	/** @var string 欄位類型 (text|password|textarea|checkbox|select|multiselect) */
	public $type = '';

	/** @var mixed 設定的預設值 */
	public $default = '';

	/** @var string 輸入元素的CSS類別 */
	public $class = '';

	/** @var string 在輸入元素上內嵌的CSS規則 */
	public $css = '';

	/** @var string 標籤 (僅用於checkbox輸入) */
	public $label = '';

	/** @var array<string,string> 選項 (僅用於select/multiselect輸入) */
	public $options = [];

	/** 取得欄位名稱 @return string  */
	public function get_name(): string {
		return $this->field_name;
	}
}
