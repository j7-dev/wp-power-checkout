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
	public function __construct( public string $value ) {}

	/**
	 * 計算中文 & 英文 & 數字字數長度
	 *
	 * @param string $text 要檢查的字串
	 * @return int
	 */
	public static function strlen( string $text ): int {
		return mb_strlen($text, 'UTF-8');
	}

	/**
	 * 使用正則表達式匹配所有非中文、英文和數字的字符
	 * \p{Han} 匹配所有中文字符
	 * a-zA-Z 匹配所有英文字母
	 * 0-9 匹配所有數字
	 *
	 * @param string $text 要檢查的字串
	 * @return bool
	 */
	public static function include_special_char( string $text ): bool {
		return preg_match('/[^\p{Han}a-zA-Z0-9]/u', $text) === 1;
	}

	/**
	 * 過濾掉字串中的所有特殊字符（非中文、英文、數字）
	 *
	 * @return self 處理後的字串，只保留中文、英文和數字
	 */
	public function filter(): self {
		// 使用正則表達式替換所有非中文、英文和數字的字符為空字串
		$this->value = preg_replace('/[^\p{Han}a-zA-Z0-9]/u', '', $this->value) ?? '';
		return $this;
	}

	/**
	 * 截取字串到指定長度
	 *
	 * @param int $max_length 最大長度
	 * @return self 截取後的字串
	 */
	public function max( int $max_length ): self {
		if ( self::strlen( $this->value ) <= $max_length ) {
			return $this;
		}

		$this->value = mb_substr( $this->value, 0, $max_length, 'UTF-8' );
		return $this;
	}
}
