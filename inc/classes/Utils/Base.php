<?php
/**
 * Base
 */

declare (strict_types = 1);

namespace J7\PowerPayment\Utils;

/**
 * Class Utils
 */
abstract class Base {
	const BASE_URL      = '/';
	const APP1_SELECTOR = '#power_payment';
	const APP2_SELECTOR = '#power_payment_metabox';
	const API_TIMEOUT   = '30000';
	const DEFAULT_IMAGE = 'http://1.gravatar.com/avatar/1c39955b5fe5ae1bf51a77642f052848?s=96&d=mm&r=g';

	/**
	 * 計算中文 & 英文 & 數字字數長度
	 *
	 * @param string $str 要檢查的字串
	 * @return int
	 */
	public static function strlen( string $str ): int {
		return mb_strlen($str, 'UTF-8');
	}

	/**
	 * 使用正則表達式匹配所有非中文、英文和數字的字符
	 * \p{Han} 匹配所有中文字符
	 * a-zA-Z 匹配所有英文字母
	 * 0-9 匹配所有數字
	 *
	 * @param string $str 要檢查的字串
	 * @return bool
	 */
	public static function include_special_char( string $str ): bool {
		return preg_match('/[^\p{Han}a-zA-Z0-9]/u', $str) === 1;
	}


	/**
	 * 過濾掉字串中的所有特殊字符（非中文、英文、數字）
	 *
	 * @param string $str 需要處理的字串
	 * @return string 處理後的字串，只保留中文、英文和數字
	 */
	public static function filter_special_char( $str ): string {
		// 使用正則表達式替換所有非中文、英文和數字的字符為空字串
		return preg_replace('/[^\p{Han}a-zA-Z0-9]/u', '', $str) ?? '';
	}
}
