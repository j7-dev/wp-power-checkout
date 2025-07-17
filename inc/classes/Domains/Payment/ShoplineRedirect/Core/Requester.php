<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Settings;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestHeader;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestParams;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\ResponseParams;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Params;


/**
 * Requester 請求器
 * 用來發請求 & 格式化回應
 * 預先填好 Header
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class Requester {
	use \J7\WpUtils\Traits\SingletonTrait;

	const API_VERSION = '/api/v1';

	const TIMEOUT = 60;

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

	/**
	 * 發送請求
	 *
	 *  @param string               $endpoint 端點
	 *  @param array<string, mixed> $request_body 請求參數
	 *  @return ResponseParams|null 回應參數
	 *  @throws \Exception 發生錯誤時拋出
	 */
	public function post( string $endpoint, array $request_body = [] ): ResponseParams|null {
		$api_url = $this->get_endpoint( $endpoint );
		// 儲存請求參數
		( new Params( $this->order ) )->save_request( $request_body, $api_url );

		$request_header = RequestHeader::create( $this->order )->to_array();
		try {
			$response = \wp_remote_post(
			$api_url,
			[
				'body'     => $request_body,
				'headers'  => $request_header,
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
			);

			if ( \is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			/** @var array<string, mixed>|array{code: int, msg: string} $response_body */
			$response_body = json_decode( \wp_remote_retrieve_body( $response ), true );
			// 儲存回應參數
			( new Params( $this->order ) )->save_response( $response_body );
			// LOG 記錄
			$this->gateway->logger(
				"{$this->gateway->payment_label} Payment Response #{$this->order->get_id()}",
				'info',
				[
					'api_url'        => $api_url,
					'request_header' => $request_header,
					'request_body'   => $request_body,
					'response_body'  => $response_body,
				]
				);

			if ( isset( $response_body['code'] ) ) {
				throw new \Exception( (string) $response_body['msg'], (int) $response_body['code'] );
			}

			return ResponseParams::create( $response_body );
		} catch (\Throwable $th) {
			$this->gateway->logger(
				$th->getMessage(),
				'error',
				[
					'api_url'        => $api_url,
					'request_header' => $request_header,
					'request_body'   => $request_body,
				],
				5
				);
			return null;
		}
	}
}
