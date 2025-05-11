<?php

declare(strict_types=1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerPayment\Domains\Payment\Ecpay\Utils\Base as EcpayUtils;
use J7\PowerPayment\Domains\Payment\Ecpay\Model\ResponseParams;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;
use J7\PowerPayment\Utils\Order as OrderUtils;

/** Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	const ERROR_CODE   = '0|';
	const SUCCESS_CODE = '1|OK';

	/** @var string $namespace */
	protected $namespace = 'power-payment';

	/** @var array{endpoint:string,method:string,permission_callback?: callable|null, callback?: callable|null}[] APIs */
	protected $apis = [
		[
			'endpoint' => 'ecpay-aio', // ReturnURL
			'method'   => 'post',
		],
	];

	/**
	 * 綠界 ReturnURL 傳資料給我們後要做的 callback
	 *
	 * @see https://developers.ecpay.com.tw/?p=2878
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception 檢查碼不相符、訂單不存在或交易編號不存在
	 * @phpstan-ignore-next-line
	 */
	public function post_ecpay_aio_callback( $request ) { // phpcs:ignore
		$params               = $request->get_body_params();
		$params               = \wp_unslash( $params ); // 去除轉譯斜線
		$service              = Service::instance();
		$is_check_value_valid = false;

		try {
			$response_params = ResponseParams::instance( $params );
			if ( !$response_params->is_check_value_valid() ) { // 判斷檢查碼是否相符
				throw new \Exception( 'CheckMacValue 檢查碼不相符' );
			}
			$is_check_value_valid = true;

			// 收到綠界回傳值後儲存訂單的 transaction_id 以及相關資料，還有修改狀態
			$this->set_transaction_info( $response_params );
			return $this->response_to_ecpay( $is_check_value_valid );
		} catch (\Throwable $th) {
			$service->error->add( 400, $th->getMessage() );
			return $this->response_to_ecpay( $is_check_value_valid );
		}
	}

	/**
	 * 回應綠界
	 *
	 * @param bool $is_check_value_valid 是否驗證成功
	 * @return \WP_REST_Response
	 */
	private function response_to_ecpay( bool $is_check_value_valid ): \WP_REST_Response {
		if ( $is_check_value_valid ) {
			return new \WP_REST_Response( self::SUCCESS_CODE, 200 );
		}

		return new \WP_REST_Response( self::ERROR_CODE, 400 );
	}

	/**
	 * 收到綠界回傳值後儲存訂單的 transaction_id 以及相關資料，還有修改狀態
	 *
	 * @param ResponseParams $response_params 綠界回傳的付款資訊
	 * @return void
	 * @throws \Exception 訂單不存在或交易編號不存在
	 */
	protected static function set_transaction_info( $response_params ) {

		// ----- ▼ 確保訂單物件存在 ----- //

		$order_id = EcpayUtils::decode_trade_no( $response_params->MerchantTradeNo ); // phpcs:ignore

		if ( !is_numeric( $order_id ) ) {
			throw new \Exception( "MerchantTradeNo 取得的 order_id #{$order_id} 不是數字，MerchantTradeNo #{$response_params->MerchantTradeNo}" );
		}

		$order = \wc_get_order( $order_id );

		if ( !$order instanceof \WC_Order ) {
			throw new \Exception( "訂單 {$order_id} 不是 WC_Order 實例" );
		}

		/** @var \J7\PowerPayment\Domains\Payment\AbstractPaymentGateway $gateway */
		$gateway = \wc_get_payment_gateway_by_order( $order );

		// ----- ▼ 寫入 order_note, order_meta, log ----- //

		// 儲存綠界付款類型到訂單中繼資料
		$gateway->log( $response_params->to_array(), '綠界交易回傳資料' );

		// 新增訂單備註
		$table_html = PowerhouseUtils::array_to_html( $response_params->to_array(), [ 'title' => '綠界付款回傳資訊' ] );
		$order->add_order_note( $table_html );

		// 取得訂單的交易編號
		$transaction_id = (string) $order->get_transaction_id();

		if (!$transaction_id) {
			// 訂單已經有交易編號，什麼也不做
			$gateway->log( "訂單已經有交易編號 #{$transaction_id}，無法再透過綠界回傳值設定訂單狀態" );
			$order->add_order_note( "訂單已經有交易編號 #{$transaction_id}，無法再透過綠界回傳值設定訂單狀態" );
			return;
		}

		if ($order->is_paid()) {
			// 訂單已付款，什麼也不做
			// 需注意的是 is_paid 只檢查狀態，不代表真的有付款
			$gateway->log( '訂單已付款，無法再透過綠界回傳值設定訂單狀態' );
			$order->add_order_note( '訂單已付款，無法再透過綠界回傳值設定訂單狀態' );
			return;
		}

		// ----- ▼ 依照不同 RtnCode 修改訂單狀態 ----- //

		// phpcs:disable
		// 2 等 ATM 付款 ATM 回傳值時為2時，交易狀態為取號成功，其餘為失敗。
		// 10100073 等超商付款 CVS/BARCODE回傳值時為10100073時，交易狀態為取號成功，其餘為失敗。
		// 10300066：「交易付款結果待確認中，請勿出貨」，請至廠商管理後台確認已付款完成再出貨。
		if (in_array( $response_params->RtnCode, [ 2, 10100073, 10300066 ], true)) {
			// 等 ATM/CVS 付款  狀態轉為 on-hold 保留
			$order->update_status( 'on-hold' );
			return;
		}

		if (1 !==$response_params->RtnCode) {
			// 交易失敗 (非 1 2 10100073 10300066)
			// 轉為 pending 可以重新付款， WC 有機制，過幾天沒付款後會自動取消
			$order->update_status( 'pending' );
			return;
		}


		// 如果都不是以上狀況那就是 RtnCode=1 (交易成功)
		// 設定訂單的交易編號
		$order->set_transaction_id( $response_params->TradeNo );
		$order->update_meta_data( OrderUtils::RESPONSE_KEY, $response_params->to_array() );
		$order->payment_complete(); // 修改狀態為 processing | completed
		// phpcs:enable

		// 儲存訂單資料
		$order->save();
	}
}
