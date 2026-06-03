<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Utils;

use J7\PowerCheckout\Shared\Abstracts\BaseService;

/** Provider Utils 整合 Utils，包含金流、物流、電子發票 */
abstract class ProviderUtils {

	/** @var array<string, BaseService> 存放【已啟用】服務的容器  */
	public static array $container = [];


	/** @return BaseService|\WC_Payment_Gateway|null 取得指定的【已啟用】 provider 實例  */
	public static function get_provider( string $id ): BaseService|\WC_Payment_Gateway|null {
		return self::$container[ $id ] ?? null;
	}

	/**
	 * 取得指定的【已啟用】 provider 實例
	 *
	 * @param array<string> $ids provider ids
	 * @return array<BaseService|\WC_Payment_Gateway> 取得實例
	 */
	public static function get_providers( array $ids ): array {
		$providers = [];
		foreach ($ids as $id) {
			if (isset( self::$container[ $id ])) {
				$providers[] = self::$container[ $id ];
			}
		}

		return $providers;
	}

	/**
	 * 是否包含任何【已啟用】 Provider
	 *
	 * @param array<string> $ids provider ids
	 * @return bool 是否包含任何 Provider
	 */
	public static function has_providers( array $ids ): bool {
		return (bool) self::get_providers($ids);
	}



	/**
	 * @param string $provider_id Payment Gateway ID
	 *
	 * @return bool 是否成功
	 */
	public static function toggle( string $provider_id ): bool {
		$from_value = self::get_option( $provider_id, 'enabled' );
		$to_value   = $from_value === 'yes' ? 'no' : 'yes';
		return self::update_option( $provider_id, 'enabled', $to_value );
	}


	/**
	 * @param string $provider_id Payment Gateway ID
	 * @param string $key         設定 key
	 *
	 * @return mixed 值
	 */
	public static function get_option( string $provider_id, string $key = '' ): mixed {
		$settings_array = \get_option( self::get_option_name( $provider_id ), [] );
		$settings_array = \is_array( $settings_array) ? $settings_array : [];
		if ($key) {
			return $settings_array[ $key ] ?? null;
		}

		return $settings_array;
	}

	/**
	 * 更新設定
	 *
	 * 寫入前會自動 trim 所有 string 與陣列內 string 元素的前後不可見字元，
	 * 確保 wp_options 不殘留複製貼上時夾帶的隱形字元（Issue #16）。
	 * 數值、布林、enum、物件型別不受影響。
	 *
	 * @param string                      $provider_id   Payment Gateway ID
	 * @param string|array<string, mixed> $key_or_values 設定 key 或 values
	 * @param mixed                       $value         值
	 *
	 * @return bool 儲存成功
	 */
	public static function update_option( string $provider_id, string|array $key_or_values, mixed $value = '' ): bool {
		$settings_array = \get_option( self::get_option_name( $provider_id ), [] );
		$settings_array = \is_array( $settings_array) ? $settings_array : [];

		if (\is_array( $key_or_values )) {
			/** @var array<string, mixed> $values */
			$values = StrHelper::trim_invisible_deep( $key_or_values );
			return \update_option( self::get_option_name( $provider_id ), \wp_parse_args( $values, $settings_array) );
		}

		$key                    = $key_or_values;
		$settings_array[ $key ] = StrHelper::trim_invisible_deep( $value );
		return \update_option( self::get_option_name( $provider_id ), $settings_array );
	}

	/** @return string payment gateway 儲存在 wp_option 的 option_name */
	public static function get_option_name( string $provider_id ): string {
		return "woocommerce_{$provider_id}_settings";
	}


	/**
	 * 該整合服務是否已啟用
	 *
	 * @param string $id 整合 id
	 *
	 * @return bool
	 */
	public static function is_enabled( string $id ): bool {
		$enabled = self::get_option( $id, 'enabled' ) ?? 'no';
		return 'yes' === $enabled;
	}
}
