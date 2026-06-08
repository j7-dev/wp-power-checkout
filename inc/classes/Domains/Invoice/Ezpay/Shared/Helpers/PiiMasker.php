<?php
/**
 * 藍新 ezPay 電子發票 PII 遮蔽 helper
 *
 * 發票 PostData_ 含大量個資（BuyerEmail / BuyerName / BuyerUBN / CarrierNum / BuyerAddress 等），
 * 寫入 WC log 或 order note 前必須遮蔽，避免 PII 明文落地（資安審查 Medium）。
 *
 * 設計原則（對齊綠界 Ecpay PiiMasker，但欄位名改為 ezPay 規格）：
 *  - 純函式、無副作用，可獨立單元測試。
 *  - PII 欄位以白名單列舉，遮蔽後仍保留少量辨識度（首字 / 網域 / 尾碼）以利除錯。
 *  - 非 PII 欄位（MerchantOrderNo / 金額 / 商品明細 / Status）原值保留。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §1. 開立發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers;

/** 藍新 ezPay 電子發票 PII 遮蔽 helper */
final class PiiMasker {

	/**
	 * 需完整遮蔽（地址 / 姓名 / 載具號碼等高敏感自由文字）的欄位
	 *
	 * @var array<int, string>
	 */
	private const FULL_MASK_KEYS = [
		'BuyerName',
		'BuyerAddress',
		'CarrierNum',
	];

	/**
	 * 遮蔽 Email：保留首字與網域，中間以 *** 取代（alice@example.com → a***@example.com）；
	 * 非法格式（無 @）一律回固定遮蔽字串，不外洩原值。
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function mask_email( string $email ): string {
		if ( '' === $email ) {
			return '';
		}
		$at = \strpos( $email, '@' );
		if ( false === $at || $at < 1 ) {
			return '***';
		}
		$local  = \substr( $email, 0, $at );
		$domain = \substr( $email, $at );
		return \substr( $local, 0, 1 ) . '***' . $domain;
	}

	/**
	 * 遮蔽電話：保留前 4 碼與後 2 碼，中間以 * 取代
	 *
	 * 0912345678 → 0912****78；過短（≤6）一律全遮。
	 *
	 * @param string $phone 電話.
	 * @return string
	 */
	public static function mask_phone( string $phone ): string {
		$len = \strlen( $phone );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 6 ) {
			return \str_repeat( '*', $len );
		}
		$head = \substr( $phone, 0, 4 );
		$tail = \substr( $phone, -2 );
		return $head . \str_repeat( '*', $len - 6 ) . $tail;
	}

	/**
	 * 部分遮蔽（統編 / 一般辨識碼）：保留前 2 碼與後 2 碼，中間以 * 取代
	 *
	 * 12345678 → 12****78；過短（≤4）全遮。
	 *
	 * @param string $value 值.
	 * @return string
	 */
	public static function mask_partial( string $value ): string {
		$len = \strlen( $value );
		if ( 0 === $len ) {
			return '';
		}
		if ( $len <= 4 ) {
			return \str_repeat( '*', $len );
		}
		$head = \substr( $value, 0, 2 );
		$tail = \substr( $value, -2 );
		return $head . \str_repeat( '*', $len - 4 ) . $tail;
	}

	/**
	 * 遮蔽發票 PostData_ 中的 PII 欄位（遞迴），非 PII 欄位原值保留
	 *
	 * @param array<string, mixed> $data 發票 PostData_ 業務欄位.
	 * @return array<string, mixed> 遮蔽後的副本.
	 */
	public static function mask_invoice_data( array $data ): array {
		$masked = [];
		foreach ( $data as $key => $value ) {
			$key            = (string) $key;
			$masked[ $key ] = self::mask_value( $key, $value );
		}
		return $masked;
	}

	/**
	 * 依欄位名遮蔽單一值（巢狀陣列遞迴；商品明細等非 PII 結構原值保留）
	 *
	 * @param string $key   欄位名.
	 * @param mixed  $value 值.
	 * @return mixed
	 */
	private static function mask_value( string $key, mixed $value ): mixed {
		if ( \is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::mask_value( (string) $k, $v );
			}
			return $out;
		}

		if ( ! \is_string( $value ) ) {
			return $value;
		}

		return match ( $key ) {
			'BuyerEmail' => self::mask_email( $value ),
			'BuyerPhone' => self::mask_phone( $value ),
			'BuyerUBN'   => self::mask_partial( $value ),
			default      => \in_array( $key, self::FULL_MASK_KEYS, true ) && '' !== $value
			? '***'
			: $value,
		};
	}
}
