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

	/** @var string 設定頁面上顯示的標題 */
	public string $title = '';

	/** @var string 設定頁面上顯示的描述 */
	public string $description = '';

	/** @var bool 是否顯示描述提示 */
	public bool $desc_tip = false;

	/** @var string placeholder */
	public string $placeholder = '';

	/** @var string 欄位類型 (text|safe_text|decimal|password|color|textarea|checkbox|select|multiselect|title) */
	public string $type = '';

	/** @var mixed 設定的預設值 */
	public mixed $default = '';

	/** @var string 輸入元素的CSS類別 */
	public string $class = '';

	/** @var string 在輸入元素上內嵌的CSS規則 */
	public string $css = '';

	/** @var string 標籤 (僅用於checkbox輸入) */
	public string $label = '';

	/** @var array<string,string> 選項 (僅用於select/multiselect輸入) */
	public array $options = [];

	/** @var array<string,string> 自訂屬性 */
	public array $custom_attributes = [];

	/**
	 * 實例化
	 *
	 * @param array<string,mixed> $data 資料
	 * @return self
	 * */
	public static function instance( array $data ): self {
		return new self( $data );
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @see WC_Settings_API::generate_settings_html 可以自己擴充 type 類型
	 * @return void
	 * @throws \Exception 型別不符合
	 * */
	protected function validate(): void {
		$allowed_types = [ 'text', 'safe_text', 'decimal', 'password', 'color', 'textarea', 'checkbox', 'select', 'multiselect', 'title' ];
		if ( ! in_array( $this->type, $allowed_types, true ) ) {
			throw new \Exception( 'Invalid field type, expected one of: ' . implode( ', ', $allowed_types ) . ". `{$this->type}` given." );
		}

		// 如果有設置 label ，則 type 必須為 checkbox
		if ( $this->label && 'checkbox' !== $this->type ) {
			throw new \Exception( "Label is only allowed for checkbox fields. `{$this->type}` given." );
		}

		// 如果有設置 options ，則 type 必須為 select 或 multiselect
		if ( $this->options && ( 'select' !== $this->type && 'multiselect' !== $this->type ) ) {
			throw new \Exception( "Options are only allowed for select or multiselect fields. `{$this->type}` given." );
		}
	}
}
