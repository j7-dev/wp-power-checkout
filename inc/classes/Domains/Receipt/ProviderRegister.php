<?php
/**
 * 電子收據 domain loader
 *
 * 與電子發票 domain 平行（並存可選）：收據 provider 與發票 provider 互不影響，
 * 後台可同時啟用。僅將已啟用的收據 provider 實例化放入 ProviderUtils::$container，
 * 並依設定的訂單狀態註冊自動開立 / 自動作廢 hooks。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt;

use J7\PowerCheckout\Domains\Receipt\Ecpay\Services\EcpayReceiptProvider;
use J7\PowerCheckout\Domains\Receipt\Shared\Interfaces\IReceiptService;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** Loader 載入電子收據方式 */
final class ProviderRegister {

	/** @var array<string, string> $receipt_providers [id => class] */
	private static array $receipt_providers = [
		EcpayReceiptProvider::ID => EcpayReceiptProvider::class,
	];

	/** 註冊 hooks */
	public static function register_hooks(): void {
		foreach ( self::$receipt_providers as $id => $class_name ) {
			// 只有啟用的收據服務才實例化放入容器
			if (!ProviderUtils::is_enabled( $id )) {
				continue;
			}
			self::register_provider_hooks( $id, $class_name );
		}
	}

	/**
	 * 註冊單一收據服務的自動開立 / 作廢 hooks
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

		/** @var IReceiptService $provider */
		$provider          = ProviderUtils::$container[ $id ];
		$provider_settings = $provider->get_settings();

		// 自動開立收據
		if (isset( $provider_settings['auto_issue_order_statuses'] ) && \is_array( $provider_settings['auto_issue_order_statuses'] )) {
			foreach ($provider_settings['auto_issue_order_statuses'] as $status_with_prefix) {
				$status = OrderUtils::strip_prefix( (string) $status_with_prefix );
				\add_action( "woocommerce_order_status_{$status}", [ $provider, 'issue' ] );
			}
		}

		// 自動作廢收據
		if (isset( $provider_settings['auto_cancel_order_statuses'] ) && \is_array( $provider_settings['auto_cancel_order_statuses'] )) {
			foreach ($provider_settings['auto_cancel_order_statuses'] as $status_with_prefix) {
				$status = OrderUtils::strip_prefix( (string) $status_with_prefix );
				\add_action( "woocommerce_order_status_{$status}", [ $provider, 'cancel' ] );
			}
		}
	}

	/** @return BaseSettingsDTO[] 取得 provider 設定 dtos（供 /settings 列表） */
	public static function get_registered_provider_dtos(): array {
		return \array_map( static fn( $class_name ) => BaseSettingsDTO::create( $class_name ), self::$receipt_providers );
	}

	/** @return array<string, string> 已登記的收據 provider [id => class] */
	public static function get_receipt_providers(): array {
		return self::$receipt_providers;
	}
}
