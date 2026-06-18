<?php
/**
 * 正規化錯誤工廠（領域中立，發票 + 金流共用）
 *
 * einvoice 導入第一階段地基：統一封裝 \WP_Error 的建構，
 * 把 $code 設為正規化 {@see ErrorCode} 的 value，$data 固定帶
 * raw_code / raw_message / provider / raw 四鍵。
 *
 * 設計鐵律：所有 provider / REST 一律經此 factory 建構帶 $data 的 WP_Error，
 * 禁止散裝 `new \WP_Error(..., ..., $data)`，以免 $data 結構走樣。
 * （本專案在此之前從未使用 WP_Error 的 $data param。）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Errors;

/**
 * 正規化錯誤工廠
 */
final class NormalizedError {

	/**
	 * 建立正規化的 \WP_Error
	 *
	 * @param ErrorCode            $code    正規化錯誤碼（其 value 作為 WP_Error 的 $code）
	 * @param string               $message 使用者可讀的錯誤訊息（text domain：power_checkout）
	 * @param array<string, mixed> $context 原始上下文，可含 raw_code / raw_message / provider / raw；
	 *                                      其餘鍵一律忽略。未提供的鍵以 null 填入。
	 * @return \WP_Error code = $code->value，data = 固定四鍵結構
	 */
	public static function from( ErrorCode $code, string $message, array $context = [] ): \WP_Error {
		$data = [
			'raw_code'    => $context['raw_code'] ?? null,
			'raw_message' => $context['raw_message'] ?? null,
			'provider'    => $context['provider'] ?? null,
			'raw'         => $context['raw'] ?? null,
		];

		return new \WP_Error( $code->value, $message, $data );
	}

	/**
	 * 型別守衛：判斷值是否為正規化錯誤
	 *
	 * 條件：必須是 \WP_Error 實例，且其 error_code 能對應到 {@see ErrorCode} 的某個 case。
	 * 用於呼叫端區分「正規化錯誤」與「裸 WP_Error / 一般值」。
	 *
	 * @param mixed $value 待檢查的值
	 * @return bool 是正規化錯誤回 true，否則回 false
	 */
	public static function is_normalized_error( mixed $value ): bool {
		return $value instanceof \WP_Error
			&& ErrorCode::tryFrom( (string) $value->get_error_code() ) !== null;
	}

	/**
	 * 從 \WP_Error 取出正規化錯誤碼
	 *
	 * @param \WP_Error $error 錯誤物件
	 * @return ErrorCode|null 對應的 enum case；若 code 非正規化值則回 null
	 */
	public static function get_code( \WP_Error $error ): ?ErrorCode {
		return ErrorCode::tryFrom( (string) $error->get_error_code() );
	}

	/**
	 * 從 \WP_Error 取出 provider 原始錯誤碼（raw_code）
	 *
	 * @param \WP_Error $error 錯誤物件
	 * @return string|null raw_code；若無 data 或無 raw_code 則回 null
	 */
	public static function get_raw_code( \WP_Error $error ): ?string {
		$data = $error->get_error_data();

		if ( ! is_array( $data ) ) {
			return null;
		}

		$raw_code = $data['raw_code'] ?? null;

		return null === $raw_code ? null : (string) $raw_code;
	}
}
