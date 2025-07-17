<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment;

/** Loader 載入付款方式 */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter( 'woocommerce_payment_gateways', [ $this, 'add_method' ] );
	}

	/** 添加付款方式 @param array<string> $methods 付款方式 @return array<string> */
	public function add_method( array $methods ): array {
		$methods[] = ShoplineRedirect\Core\GeneralGateway::class;

		$methods[] = EcpayAIO\Core\Atm::class;
		$methods[] = EcpayAIO\Core\WebAtm::class;
		$methods[] = EcpayAIO\Core\Credit::class;
		$methods[] = EcpayAIO\Core\CreditInstallment::class;
		$methods[] = EcpayAIO\Core\Barcode::class;
		$methods[] = EcpayAIO\Core\CVS::class;
		return $methods;
	}
}
