<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Shared\Utils;

/**
 * StrHelper 輔助函數，協助字串轉換、驗證
 *
 * @example
 * // 過濾特殊字元+最大長度10
 * $name = (new Helper( 'Hello, World!' ))->filter()->max( 10 )->value;
 * */
final class StrHelper {

	/** Constructor */
	public function __construct( public string $value, public string $name = '', public ?int $max_length = null ) {
	}

	/**
	 * 計算中文 & 英文 & 數字字數長度
	 *
	 * @return int
	 * @throws \Exception 如果字串長度超過最大長度
	 */
	public function get_strlen( bool $throw_error = false ): int {
		$strlen = \mb_strlen($this->value, 'UTF-8');
		if ( $throw_error ) {
			$this->validate_strlen();
		}
		return $strlen;
	}


	/**
	 * 驗證中文 & 英文 & 數字字數長度
	 *
	 * @return void
	 * @throws \Exception 如果字串長度超過最大長度
	 */
	public function validate_strlen(): void {
		$strlen = \mb_strlen($this->value, 'UTF-8');
		if ( $strlen > $this->max_length ) {
			throw new \Exception("{$this->name} 字串長度不能超過 {$this->max_length} 個字，目前為 {$strlen} 個字");
		}
	}


	/**
	 * 使用正則表達式匹配所有非中文、英文和數字的字符
	 * \p{Han} 匹配所有中文字符
	 * a-zA-Z 匹配所有英文字母
	 * 0-9 匹配所有數字
	 *
	 * @param bool $throw_error 是否拋出異常
	 * @return bool
	 * @throws \Exception 如果字串包含特殊字元
	 */
	public function has_special_char( bool $throw_error = false ): bool {
		$has_special_char = preg_match('/[^\p{Han}a-zA-Z0-9 ]/u', $this->value) === 1;
		if ( $throw_error) {
			$this->validate_special_char();
		}
		return $has_special_char;
	}


	/**
	 * 驗證配所有非中文、英文和數字的字符
	 * \p{Han} 匹配所有中文字符
	 * a-zA-Z 匹配所有英文字母
	 * 0-9 匹配所有數字
	 *
	 * @return void
	 * @throws \Exception 如果字串包含特殊字元
	 */
	public function validate_special_char(): void {
		$has_special_char = preg_match('/[^\p{Han}a-zA-Z0-9 ]/u', $this->value) === 1;
		if ( $has_special_char ) {
			throw new \Exception("不能包含特殊字元，{$this->name}:{$this->value}");
		}
	}

	/**
	 * 過濾掉字串中的所有特殊字符（非中文、英文）
	 *
	 * @return self 處理後的字串，只保留中文、英文和數字
	 */
	public function filter(): self {
		// 先過濾 html
		$value = \sanitize_text_field( $this->value );
		// 使用正則表達式替換所有非中文、英文和數字的字符為空字串
		$this->value = \preg_replace('/[^\p{Han}a-zA-Z0-9 ]/u', '', $value) ?? '';
		return $this;
	}

	/**
	 * 截取字串到指定長度
	 *
	 * @return self 截取後的字串
	 */
	public function substr(): self {
		if ( null === $this->max_length ) {
			return $this;
		}

		if ( $this->get_strlen() <= $this->max_length ) {
			return $this;
		}

		$this->value = mb_substr( $this->value, 0, $this->max_length, 'UTF-8' );
		return $this;
	}

	/**
	 * 驗證字串長度 & 特殊字元
	 *
	 * @throws \Exception 如果字串長度超過最大長度
	 */
	public function validate(): void {
		$this->validate_strlen();
		$this->validate_special_char();
	}

	/** @param string $separator 分隔符號 @return string 取得唯一字串 */
	public static function get_unique_string( string $separator = '' ): string {
		$milliseconds = (int) ( new \DateTimeImmutable() )->format( 'Uv' ); // 13位
		return $separator . \wp_unique_id() . $separator . $milliseconds;
	}

	/** 驗證載具 */
	public static function validate_carrier( string $value ): void {
		$pattern = '/^\/[0-9A-Z\+\-\.]{7}$/';
		if (!preg_match($pattern, $value)) {
			throw new \Exception("{$value} 載具格式不符");
		}
	}

	/** 驗證自然人憑證 */
	public static function validate_moica( string $value ): void {
		$pattern = '/^TP[0-9]{14}$/';
		if (!preg_match($pattern, $value)) {
			throw new \Exception("{$value} 自然人憑證格式不符");
		}
	}

	/**
	 * 修剪字串前後「肉眼看不見」的字元
	 *
	 * 涵蓋字元集（與前端 js/src/utils/trim.ts 同步，若調整任一邊請務必同步另一邊）：
	 *  - U+0009 Tab
	 *  - U+000A LF / U+000D CR
	 *  - U+000B 垂直 Tab / U+000C Form Feed（對齊 PHP trim() 預設字元集）
	 *  - U+0020 半形空白
	 *  - U+00A0 不換行空白（NBSP）
	 *  - U+3000 全形空白
	 *  - U+200B 零寬空白 / U+200C 零寬非連接 / U+200D 零寬連接
	 *  - U+FEFF BOM
	 *
	 * 設計原則：
	 *  - 僅處理「前後」，欄位中間的不可見字元保留（可能是合法的金鑰內容）
	 *  - 純不可見字元的字串會回傳空字串（給前端 form validator 接手提示）
	 *  - 不影響合法 UTF-8 字元（中文 / Emoji 等）
	 *
	 * @param string $value 要修剪的字串
	 * @return string 修剪後的字串
	 *
	 * @see https://github.com/zenbuapps/wp-power-checkout/issues/16
	 */
	public static function trim_invisible( string $value ): string {
		// regex 集合：以 \x{HHHH} 表示 Unicode codepoint，需 PCRE u flag
		$pattern = '/^[\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}\x{3000}\x{200B}\x{200C}\x{200D}\x{FEFF}]+|[\x{0009}\x{000A}\x{000B}\x{000C}\x{000D}\x{0020}\x{00A0}\x{3000}\x{200B}\x{200C}\x{200D}\x{FEFF}]+$/u';
		$result  = \preg_replace( $pattern, '', $value );
		return \is_string( $result ) ? $result : $value;
	}

	/**
	 * 遞迴修剪 mixed 值前後不可見字元
	 *
	 * 行為對照表：
	 *  - string  → 套用 trim_invisible
	 *  - array   → 遞迴每個元素（key 不動，value 套用本函式）
	 *  - 其他    → 原值返回（int / float / bool / null / object 不動）
	 *
	 * 用於 ProviderUtils::update_option，讓 wp_options 寫入前所有 string
	 * 與陣列內 string 元素都自動清理，數值 / enum / 巢狀物件不受影響。
	 *
	 * @param mixed $value 要遞迴修剪的值
	 * @return mixed 處理後的值（型別與輸入相同）
	 *
	 * @phpstan-return ($value is string ? string : ($value is array<string, mixed> ? array<string, mixed> : ($value is array ? array<int|string, mixed> : mixed)))
	 */
	public static function trim_invisible_deep( mixed $value ): mixed {
		if (\is_string( $value )) {
			return self::trim_invisible( $value );
		}

		if (\is_array( $value )) {
			$result = [];
			foreach ($value as $key => $item) {
				$result[ $key ] = self::trim_invisible_deep( $item );
			}
			return $result;
		}

		return $value;
	}
}
