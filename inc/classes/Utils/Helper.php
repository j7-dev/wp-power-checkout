<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Utils;

/**
 * Helper 輔助函數，協助字串轉換、驗證
 *
 * @example
 * // 過濾特殊字元+最大長度10
 * $name = (new Helper( 'Hello, World!' ))->filter()->max( 10 )->value;
 * */
final class Helper {

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
		$strlen = mb_strlen($this->value, 'UTF-8');
		if ( $throw_error && $strlen > $this->max_length ) {
			throw new \Exception("{$this->name} 字串長度不能超過 {$this->max_length} 個字，目前為 {$strlen} 個字");
		}
		return $strlen;
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
		if ( $throw_error && $has_special_char ) {
			throw new \Exception("不能包含特殊字元，{$this->name}:{$this->value}");
		}
		return $has_special_char;
	}

	/**
	 * 過濾掉字串中的所有特殊字符（非中文、英文、數字）
	 *
	 * @return self 處理後的字串，只保留中文、英文和數字
	 */
	public function filter(): self {
		// 使用正則表達式替換所有非中文、英文和數字的字符為空字串
		$this->value = preg_replace('/[^\p{Han}a-zA-Z0-9 ]/u', '', $this->value) ?? '';
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
		$this->get_strlen(true);
		$this->has_special_char(true);
	}
}
