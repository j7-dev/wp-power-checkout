<?php

declare(strict_types=1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerPayment\Domains\Payment\Ecpay\Utils\Base as EcpayUtils;

/** Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	const ERROR_CODE   = '0|';
	const SUCCESS_CODE = '1|OK';

	/** @var string $namespace */
	protected $namespace = 'power-payment';

	/** @var array{endpoint:string,method:string,permission_callback: callable|null }[] APIs */
	protected $apis = [
		[
			'endpoint'            => 'ecpay-aio', // ReturnURL
			'method'              => 'post',
			'permission_callback' => null,
		],
	];

	/**
	 * 綠界 ReturnURL callback
	 *
	 * @see https://developers.ecpay.com.tw/?p=2878
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function post_ecpay_aio_callback( $request ) { // phpcs:ignore
		$params = $request->get_body_params();
		$params = \wp_unslash( $params ); // 去除轉譯斜線

		$service = Service::instance();

		if ( !$service->is_check_value_valid( $params ) ) { // 判斷檢查碼是否相符
			$service->error->add( 400, 'CheckMacValue 檢查碼不相符' );
			return new \WP_REST_Response( self::ERROR_CODE, 400 );
		}

		// ---
		$trade_no = $ipn_info['MerchantTradeNo'] ?? '';
		if ( !$trade_no ) {
			$service->error->add( 400, 'MerchantTradeNo 不存在' );
			return new \WP_REST_Response( self::ERROR_CODE, 400 );
		}

		$order_id = EcpayUtils::decode_trade_no( $trade_no );

		if ( !is_numeric( $order_id ) ) {
			$service->error->add( 400, "MerchantTradeNo 取得的 order_id 不是數字 {$order_id}" );
			return new \WP_REST_Response( self::ERROR_CODE, 400 );
		}

		$order = wc_get_order( $order_id );
		if ( !$order ) {
			$service->error->add( 400, "訂單 {$order_id} 不存在" );
			return new \WP_REST_Response( self::ERROR_CODE, 400 );
		}

		$payment_status = self::get_status( $ipn_info );

		/**  @see https://developers.ecpay.com.tw/?p=2878 */
		$payment_status = $ipn_info['RtnCode'];
		RY_ECPay_Gateway::log( 'Found order #' . $order->get_id() . ' Payment status: ' . $payment_status );

		$order = self::set_transaction_info( $order, $ipn_info );

		do_action( 'ry_ecpay_gateway_response_status_' . $payment_status, $ipn_info, $order );
		do_action( 'ry_ecpay_gateway_response', $ipn_info, $order );

		self::die_success();

		// ---

		return new \WP_REST_Response( self::SUCCESS_CODE, 200 );
	}
}
