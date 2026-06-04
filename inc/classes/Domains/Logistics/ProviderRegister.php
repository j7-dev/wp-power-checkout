<?php
/**
 * Logistics domain Loader（鏡像 Invoice\ProviderRegister）
 *
 * 1. $logistics_providers：本次唯一 provider ecpay_logistics（設計可容未來 PAYUNi）。
 * 2. register_hooks：
 *    - 啟用的 provider 才實例化放入 ProviderUtils::$container。
 *    - 任一 provider 啟用才註冊對外 REST（LogisticsApiService）+ callback（LogisticsCallback）。
 *    - 透過 woocommerce_shipping_methods filter 加入 WC_EcpayLogisticsShipping（classic 結帳運送方式）。
 *    - 透過 woocommerce_checkout_create_order 寫結帳選擇的物流子類型 / 付款情境 meta。
 * 3. get_registered_provider_dtos：給 SettingApiService GET /settings 的 data.logistics。
 *
 * @see Domains\Invoice\ProviderRegister
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\WC_EcpayLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** Loader 載入物流方式 */
final class ProviderRegister {

	/** @var array<string, string> $logistics_providers [id, class] */
	private static array $logistics_providers = [
		EcpayLogisticsProvider::ID => EcpayLogisticsProvider::class,
	];

	/** 註冊 hooks @return void */
	public static function register_hooks(): void {
		// WC 運送方式（classic 結帳）— 不論啟用與否皆註冊類別，啟用狀態由運送方式自身設定控制
		\add_filter( 'woocommerce_shipping_methods', [ __CLASS__, 'add_shipping_method' ] );
		\add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_checkout_meta' ], 10, 2 );

		$any_enabled = false;
		foreach ( self::$logistics_providers as $id => $class_name ) {
			// 啟用才實例化放入容器
			if (!ProviderUtils::is_enabled( $id )) {
				continue;
			}
			self::register_provider_hooks( $id, $class_name );
			$any_enabled = true;
		}

		// 有啟用的物流服務才註冊 REST + callback
		if ($any_enabled) {
			LogisticsApiService::instance();
			LogisticsCallback::register_hooks();
		}
	}

	/**
	 * 註冊服務的鉤子（放入 ProviderUtils 容器）
	 *
	 * @param string $id         服務 id
	 * @param string $class_name 服務類別
	 * @return void
	 */
	private static function register_provider_hooks( string $id, string $class_name ): void {
		if (!\class_exists( $class_name )) {
			return;
		}
		/** @var callable $callable */
		$callable = [ $class_name, 'instance' ];
		/** @var \J7\PowerCheckout\Shared\Abstracts\BaseService $instance */
		$instance                        = \call_user_func( $callable );
		ProviderUtils::$container[ $id ] = $instance;
	}

	/**
	 * 加入綠界物流 WC_Shipping_Method（classic 結帳運送方式）
	 *
	 * @param array<int|string, string> $methods 運送方式
	 * @return array<int|string, string>
	 */
	public static function add_shipping_method( array $methods ): array {
		$methods[ WC_EcpayLogisticsShipping::METHOD_ID ] = WC_EcpayLogisticsShipping::class;
		return $methods;
	}

	/**
	 * Classic 結帳：寫入物流子類型 / 付款情境 meta（委派運送方式）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $data  結帳送出資料
	 * @return void
	 */
	public static function save_checkout_meta( \WC_Order $order, array $data = [] ): void {
		WC_EcpayLogisticsShipping::save_checkout_meta( $order, $data );
	}

	/** @return BaseSettingsDTO[] 取得 provider 設定 dtos（給 SettingApiService GET /settings） */
	public static function get_registered_provider_dtos(): array {
		return \array_map(
			static fn( string $class_name ): BaseSettingsDTO => BaseSettingsDTO::create( $class_name ),
			self::$logistics_providers
		);
	}
}
