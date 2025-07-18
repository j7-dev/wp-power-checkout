<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Service\GeneralGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\CallBack;

/**
 * Init 初始化付款方式 單例
 * 添加 hook，例如 callback
 */
final class Init {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 付款方式 callback 的 action 前綴 */
	const PREFIX = 'pc_slp_';

	/** Constructor */
	public function __construct() {
		\add_filter( 'woocommerce_payment_gateways', [ $this, 'add_method' ] );

		foreach ( CallBack::cases() as $callback ) {
			\add_action( $callback->action(), [ $this, $callback->callback() ] );
		}
	}

	/** 添加付款方式 @param array<string> $methods 付款方式 @return array<string> */
	public function add_method( array $methods ): array {
		$methods[] = GeneralGateway::class;
		return $methods;
	}

	/** 建立交易 callback */
	public function create_trade_callback() {
		// TEST ----- ▼ 印出 WC Logger 記得移除 ----- //
		\J7\WpUtils\Classes\WC::logger(
			'',
			'info',
			[
				'get'         => $_GET,
				'post'        => $_POST,
				'raw_request' => $_REQUEST,
			]
			);
		// TEST ---------- END ---------- //

		// 清空購物車
		\WC()->cart->empty_cart();
		// 重導回首頁
		\wp_safe_redirect( \home_url() );
		exit;
	}
}
