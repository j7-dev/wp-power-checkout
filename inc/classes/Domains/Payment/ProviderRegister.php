<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund\CreateRefundDTO;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** ProviderRegister 註冊付款方式 */
final class ProviderRegister {

	/** @var array<string, string> $gateway_services [id, class] */
	private static array $gateway_services = [
		ShoplinePayment\Services\RedirectGateway::ID => ShoplinePayment\Services\RedirectGateway::class,
		EcpayAIO\Services\AioRedirectGateway::ID     => EcpayAIO\Services\AioRedirectGateway::class,
		Ecpg\Services\EcpgGateway::ID                => Ecpg\Services\EcpgGateway::class,
		NewebpayMpg\Services\MpgRedirectGateway::ID  => NewebpayMpg\Services\MpgRedirectGateway::class,
		Payuni\Services\PayuniUppGateway::ID         => Payuni\Services\PayuniUppGateway::class,
	];

	/** Register hooks */
	public static function register_hooks(): void {
		Shared\Services\PaymentApiService::register_hooks();

		\add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'add_method' ] );
		\add_action( 'woocommerce_refund_created', [ __CLASS__, 'default_refund_reason' ], 10, 2 );
		\add_action( 'woocommerce_order_refunded', [ __CLASS__, 'add_order_note__manual_refund' ], 100, 2 );
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'refund_script' ], 20 );
		\add_action( 'wc_payment_gateways_initialized', [ __CLASS__, 'gateway_register_di' ], 20 );

		foreach ( self::$gateway_services as $gateway_id => $gateway_service ) {
			if ( !ProviderUtils::is_enabled( $gateway_id ) ) {
				continue;
			}

			if ( \method_exists( $gateway_service, 'init' ) ) {
				/** @var callable $callable */
				$callable = [ $gateway_service, 'init' ];
				\call_user_func( $callable );
			}
		}
	}

	/** 將 Gateway 實例放到 ProviderUtils::$container 內 */
	public static function gateway_register_di( \WC_Payment_Gateways $gateways ): void {
		foreach ( self::$gateway_services as $gateway_id => $gateway_service ) {
			// 取得 WC_Payment_Gateways 單例
			$all_gateways                            = $gateways?->payment_gateways();
			ProviderUtils::$container[ $gateway_id ] = $all_gateways[ $gateway_id ];
		}
	}

	/**
	 * 添加付款方式
	 *
	 * @param array<int|string, string> $methods 付款方式
	 * @return array<int|string, string>
	 */
	public static function add_method( array $methods ): array {
		foreach ( self::$gateway_services as $gateway_service ) {
			$methods[] = $gateway_service;
		}
		return $methods;
	}

	/**
	 * 修改預設的退款原因
	 *
	 * @param int                  $refund_id 退款ID
	 * @param array<string, mixed> $args      參數
	 */
    public static function default_refund_reason( int $refund_id, array $args ): void { // phpcs:ignore
		/** @var \WC_Order_Refund $refund */
		$refund = \wc_get_order( $refund_id );
		$reason = $refund->get_reason();

		if ( $reason ) {
			return;
		}

		$new_reason = CreateRefundDTO::get_default_reason( (float) $refund->get_amount() );

		$refund->set_reason( $new_reason );
		$refund->save();
	}


	/**
	 * 退款的 script
	 *
	 * @param string $hook 頁面 hook
	 */
	public static function refund_script( string $hook ): void {
		if ( !OrderUtils::is_order_detail( $hook ) ) {
			return;
		}
		SettingTabService::enqueue_vue_app();

		$order_id = OrderUtils::get_order_id( $hook );
		$order    = \wc_get_order( $order_id );
		if ( !$order instanceof \WC_Order ) {
			return;
		}

		// 要額外給前端的資料
		$obj_name = Plugin::$snake . '_order_data'; // power_checkout_order_data
		\wp_localize_script(
			SettingTabService::$handle,
			$obj_name,
			[
				'gateway' => [
					'id'           => $order->get_payment_method(),
					'method_title' => $order->get_payment_method_title(),
				],
				'order'   => [
					'id'                      => (string) $order->get_id(),
					'total'                   => \wc_price( (float) $order->get_total() ),
					'remaining_refund_amount' => \wc_price(
						(float) $order->get_remaining_refund_amount()
					),
				],

			]
		);
	}


	/**
	 * 手動退款就打 Order Note
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 *
	 * @return void
	 */
	public static function add_order_note__manual_refund( int $order_id, int $refund_id ): void {
		$refund = \wc_get_order( $refund_id );
		if ( !$refund instanceof \WC_Order_Refund ) {
			return;
		}
		if ( !$refund->get_refunded_payment() ) {
			$order = \wc_get_order( $order_id );
			if ( !$order instanceof \WC_Order ) {
				return;
			}
			$refund_amount = \wc_price( (float) $refund->get_amount() );
			$order->add_order_note( "手動退款 {$refund_amount} 元" );
		}
	}
}
