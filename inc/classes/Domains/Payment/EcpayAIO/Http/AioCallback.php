<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Http;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\RtnCode;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 綠界 AIO 幕後通知接收（ReturnURL / PaymentInfoURL）
 *
 * 比照 ShoplinePayment\Http\WebHook 採 ApiBase + SingletonTrait。
 *
 * 協定鐵律：
 *  - CheckMacValue 以 hash_equals() timing-safe 驗章；不符仍回 1|OK（避免綠界重送風暴）
 *  - 冪等：以 MerchantTradeNo 為 key，已處理過則 skip
 *  - 回應必須是純文字 `1|OK`，HTTP 200（非 JSON）
 *
 * @see https://developers.ecpay.com.tw/?p=2878
 */
final class AioCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 純文字成功回應（綠界協定） */
	private const RESPONSE_OK = '1|OK';

	/** @var string Namespace power-checkout/ecpay */
	protected $namespace = 'power-checkout/ecpay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'aio/return',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'aio/payment-info',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callbacks（回純文字 1|OK）

	/**
	 * 付款結果通知（ReturnURL）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（純文字 1|OK）
	 */
	public function post_aio_return_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		try {
			$this->handle_return( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '綠界 AIO ReturnURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
		return self::ok_response();
	}

	/**
	 * 取號結果通知（PaymentInfoURL）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 回應（純文字 1|OK）
	 */
	public function post_aio_payment_info_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_params();
		try {
			$this->handle_payment_info( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger( '綠界 AIO PaymentInfoURL 處理失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
		return self::ok_response();
	}

	// endregion

	// region 處理邏輯（可獨立測試）

	/**
	 * 處理付款結果通知
	 *
	 * 驗章失敗 → 維持狀態、不更新明細（仍由呼叫端回 1|OK）。
	 * 冪等：MerchantTradeNo 已處理（已 processing）→ skip。
	 *
	 * @param array<string, mixed> $params ReturnURL 通知參數
	 * @return void
	 */
	public function handle_return( array $params ): void {
		if ( ! $this->is_valid( $params ) ) {
			Plugin::logger( '綠界 AIO ReturnURL CheckMacValue 驗章失敗', 'warning', [ 'params' => $params ] );
			return;
		}

		$trade_no = (string) ( $params['MerchantTradeNo'] ?? '' );
		$order    = EcpayMetaKeys::get_order_by_trade_no( $trade_no );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( "找不到訂單，MerchantTradeNo: {$trade_no}" );
		}

		// 冪等：已轉為 processing（已付款）則不重複處理
		if ( $order->has_status( \J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus::PROCESSING->value ) ) {
			return;
		}

		( new StatusManager( $params, $order ) )->update_order_status();
	}

	/**
	 * 處理取號結果通知（ATM/CVS/BARCODE）
	 *
	 * 驗章失敗 → 不寫入取號資訊、不改狀態。
	 * 取號成功（RtnCode='2' ATM / '10100073' CVS/BARCODE）→ 寫繳費資訊 + order note，不改狀態。
	 *
	 * @param array<string, mixed> $params PaymentInfoURL 通知參數
	 * @return void
	 */
	public function handle_payment_info( array $params ): void {
		if ( ! $this->is_valid( $params ) ) {
			Plugin::logger( '綠界 AIO PaymentInfoURL CheckMacValue 驗章失敗', 'warning', [ 'params' => $params ] );
			return;
		}

		$rtn_code = (string) ( $params['RtnCode'] ?? '' );
		// 僅處理取號成功碼，其餘忽略（不改狀態）
		if ( ! RtnCode::is_get_code_success( $rtn_code ) ) {
			return;
		}

		$trade_no = (string) ( $params['MerchantTradeNo'] ?? '' );
		$order    = EcpayMetaKeys::get_order_by_trade_no( $trade_no );
		if ( ! $order instanceof \WC_Order ) {
			throw new \Exception( "找不到訂單，MerchantTradeNo: {$trade_no}" );
		}

		$meta_keys = new EcpayMetaKeys( $order );

		// 冪等：已寫入取號資訊則不重複
		if ( $meta_keys->get_payment_info() ) {
			return;
		}

		$meta_keys->update_payment_info( $params );

		$note = WP::array_to_html(
			$params,
			[ 'title' => '綠界 ECPay 取號繳費資訊' ]
		);
		$order->add_order_note( $note );
		// 不改訂單狀態（維持等待付款）
	}

	// endregion

	// region 驗證

	/**
	 * 以 timing-safe 方式驗證 CheckMacValue
	 *
	 * @param array<string, mixed> $params 綠界通知參數
	 * @return bool
	 */
	private function is_valid( array $params ): bool {
		$received = (string) ( $params['CheckMacValue'] ?? '' );
		if ( '' === $received ) {
			return false;
		}

		$settings = AioSettingsDTO::instance();
		if ( '' === $settings->hashKey || '' === $settings->hashIv ) {
			return false;
		}

		// 僅取 string|int 值參與計算（CheckMacValueService 由本類傳入時已過濾）
		$args = [];
		foreach ( $params as $key => $value ) {
			if ( 'CheckMacValue' === $key ) {
				continue;
			}
			if ( \is_string( $value ) || \is_int( $value ) ) {
				$args[ (string) $key ] = $value;
			}
		}

		$calculated = CheckMacValueService::get_check_value( $args, $settings->hashKey, $settings->hashIv, 'sha256' );

		return \hash_equals( $calculated, \strtoupper( $received ) );
	}

	// endregion

	// region URL helpers

	/** @return string ReturnURL（付款結果通知） */
	public static function get_return_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/aio/return', 'https' );
	}

	/** @return string PaymentInfoURL（取號結果通知） */
	public static function get_payment_info_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/aio/payment-info', 'https' );
	}

	// endregion

	/**
	 * 純文字 1|OK 回應（HTTP 200，非 JSON）
	 *
	 * 透過 rest_pre_echo_response 過濾把 body 直接輸出為純文字，避免 WP 序列化為 JSON。
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok_response(): \WP_REST_Response {
		$response = new \WP_REST_Response( self::RESPONSE_OK, 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		\add_filter(
			'rest_pre_echo_response',
			static function ( $result, $server, $request ) {
				$route = $request->get_route();
				if ( \is_string( $route ) && \str_contains( $route, 'power-checkout/ecpay/aio/' ) ) {
					echo self::RESPONSE_OK; // phpcs:ignore
					return null;
				}
				return $result;
			},
			10,
			3
		);
		return $response;
	}
}
