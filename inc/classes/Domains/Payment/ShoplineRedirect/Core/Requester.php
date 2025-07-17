<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Settings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestParams;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestHeader;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;


/**
 * Requester 請求器
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class Requester {
	use \J7\WpUtils\Traits\SingletonTrait;

	const API_VERSION = '/api/v1';

	/** @var Settings 設定 */
	public Settings $settings;

	/** Constructor */
	public function __construct(
		public AbstractPaymentGateway $gateway,
		public \WC_Order $order
	) {
		$this->settings = Settings::instance();
	}

	/** 取得 API 端點 @param string $endpoint 端點 /trade/payment/create @return string 端點 */
	public function get_endpoint( string $endpoint ): string {
		return $this->settings->apiUrl . self::API_VERSION . $endpoint;
	}

	/** 發送請求 */
	public function post(): void {
		$response = \wp_remote_post(
			$this->get_endpoint( '/trade/payment/create' ),
			[
				'body'    => RequestParams::create( $this->order, $this->gateway )->to_array(),
				'headers' => RequestHeader::create( $this->order )->to_array(),
			]
			);
	}
}
