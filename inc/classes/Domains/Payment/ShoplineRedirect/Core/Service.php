<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Settings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Requester;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;

/**
 * Shopline Payment 跳轉式支付服務類
 *
 * 1. 建立交易
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class Service {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 服務 ID */
	public string $id = Settings::KEY;

	/** @var Settings 設定 */
	public Settings $settings;

	/** Constructor */
	public function __construct(
		/** @var AbstractPaymentGateway 付款閘道 */
		public AbstractPaymentGateway $gateway,
		/** @var \WC_Order 訂單 */
		public \WC_Order $order
	) {
		$this->settings = Settings::instance();
	}





	/**
	 * 建立交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_trade(): void {
		$requester = Requester::instance( $this->gateway, $this->order );
		$response  = $requester->post( '/trade/payment/create' );
		if ( ! $response ) {
			exit;
		}
		\wp_safe_redirect( $response->sessionUrl );

		// 跳轉支付，就不繼續往下執行
		exit;
	}
}
