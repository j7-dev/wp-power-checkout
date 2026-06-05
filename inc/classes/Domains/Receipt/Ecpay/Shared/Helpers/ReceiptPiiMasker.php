<?php
/**
 * 綠界電子收據 PII 遮蔽 helper
 *
 * 收據明細含個資（Name / Email / Phone / CellPhone / Identifier / 地址），
 * 寫入 WC log 或 order note 前必須遮蔽，避免 PII 明文落地（資安審查 Medium）。
 *
 * 設計原則：
 *  - 純函式、無副作用，可獨立單元測試。
 *  - 遮蔽原語（mask_email / mask_phone / mask_partial）複用既有電子發票 PiiMasker，
 *    收據僅補上收據專屬的欄位白名單對應，避免重複實作遮蔽演算法。
 *  - 非 PII 欄位（RelateNumber / Amount / RtnCode / ReceiptNo / 商品明細）原值保留。
 *
 * @see \J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\PiiMasker
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Helpers;

use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\PiiMasker;

/** 綠界電子收據 PII 遮蔽 helper */
final class ReceiptPiiMasker {

	/**
	 * 需完整遮蔽（地址 / 姓名等高敏感自由文字）的欄位
	 *
	 * @var array<int, string>
	 */
	private const FULL_MASK_KEYS = [
		'Name',
		'CompanyAddress',
		'DeliveryAddress',
	];

	/**
	 * 遮蔽收據明細中的 PII 欄位（遞迴），非 PII 欄位原值保留
	 *
	 * @param array<string, mixed> $data 收據明細
	 * @return array<string, mixed> 遮蔽後的副本
	 */
	public static function mask_receipt_data( array $data ): array {
		$masked = [];
		foreach ( $data as $key => $value ) {
			$key            = (string) $key;
			$masked[ $key ] = self::mask_value( $key, $value );
		}
		return $masked;
	}

	/**
	 * 依欄位名遮蔽單一值（巢狀陣列遞迴；商品明細等非 PII 陣列原值保留結構）
	 *
	 * @param string $key   欄位名
	 * @param mixed  $value 值
	 * @return mixed
	 */
	private static function mask_value( string $key, mixed $value ): mixed {
		if (\is_array( $value )) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::mask_value( (string) $k, $v );
			}
			return $out;
		}

		if (!\is_string( $value )) {
			return $value;
		}

		return match ( $key ) {
			'Email'                 => PiiMasker::mask_email( $value ),
			'Phone', 'CellPhone'    => PiiMasker::mask_phone( $value ),
			'Identifier'            => PiiMasker::mask_partial( $value ),
			default                 => \in_array( $key, self::FULL_MASK_KEYS, true ) && '' !== $value
			? '***'
			: $value,
		};
	}
}
