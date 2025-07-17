<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Settings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Requester;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestParams;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\ResponseParams;
use J7\PowerCheckout\Domains\Payment\Shared\Params;

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

	/** @var Requester 請求器 */
	public Requester $requester;

	/** Constructor */
	public function __construct(
		/** @var AbstractPaymentGateway 付款閘道 */
		public AbstractPaymentGateway $gateway,
		/** @var \WC_Order 訂單 */
		public \WC_Order $order
	) {
		$this->settings  = Settings::instance();
		$this->requester = Requester::instance( $this->gateway, $this->order );
	}

	/**
	 * 建立結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_trade(): void {
		$request_body = RequestParams::create( $this->gateway, $this->order )->to_array();
		$response     = $this->requester->post( '/trade/payment/create', $request_body );
		if ( ! $response ) {
			exit;
		}
		\wp_safe_redirect( $response->sessionUrl );

		// 跳轉支付，就不繼續往下執行
		exit;
	}

	/**
	 * 查詢結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
	 * @throws \Exception 如果交易查詢失敗
	 *  */
	public function query_trade(): ResponseParams|null {
		$response_params_array = ( new Params( $this->order ) )->get_response();
		$response_params       = ResponseParams::create( $response_params_array );
		if (!isset($response_params->sessionId)) {
			throw new \Exception( 'Session ID not found' );
		}
		$response = $this->requester->post(
			'/trade/sessions/query',
			[
				'sessionId' => $response_params->sessionId,
			]
			);
		return $response;
	}
}
