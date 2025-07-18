<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Service;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Settings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Service\Requester;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Session\Create\RequestParams;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Session\Create\ResponseParams;
use J7\PowerCheckout\Domains\Payment\Shared\Params;

/**
 * Shopline Payment 跳轉式支付服務類 工廠模式
 * 方法
 * 1. 建立交易
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class Service {

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
		$this->requester = new Requester( $this->gateway, $this->order );
	}

	/**
	 * 建立結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @return string  shopline payment return 的 session url
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_session(): string {
		$request_body = RequestParams::create( $this->gateway, $this->order )->to_array();
		$response     = $this->requester->post( '/trade/sessions/create', $request_body );
		return $response->sessionUrl;
	}

	/**
	 * 查詢結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
	 * @throws \Exception 如果交易查詢失敗
	 *  */
	public function query_session(): ResponseParams|null {
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
