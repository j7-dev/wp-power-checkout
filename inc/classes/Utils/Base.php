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
	 * 檢查字串是否包含非字母、非數字和非空格的字符
	 * \p{L} 匹配任何語言的字母（包括中文字）
	 * \p{N} 匹配任何數字
	 * \s 匹配空格
	 *
	 * @param string $str 要檢查的字串
	 * @return bool
	 */
	public static function include_special_char( string $str ): bool {
		return preg_match('/[^\p{L}\p{N}\s]/u', $str);
	}
}
