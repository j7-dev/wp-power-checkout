<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Core;

/**
 * Init 初始化付款方式 單例
 */
final class Init {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 付款方式 callback 的 action 前綴 */
	const PREFIX = 'pc_slp_';

	/** Constructor */
	public function __construct() {
		\add_filter( 'woocommerce_payment_gateways', [ $this, 'add_method' ] );

		WebHook::instance();
	}

	/** 添加付款方式 @param array<string> $methods 付款方式 @return array<string> */
	public function add_method( array $methods ): array {
		$methods[] = RedirectGateway::class;
		return $methods;
	}
}
