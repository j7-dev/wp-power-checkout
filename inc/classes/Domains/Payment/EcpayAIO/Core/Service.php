<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Core;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Utils\Base as EcpayUtils;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\Settings;

/** Service */
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
		$this->set_properties();
	}




	/**
	 * 取得參數
	 *
	 * @param \WC_Order              $order 訂單
	 * @param AbstractPaymentGateway $gateway 付款方式
	 * @return array<string, mixed> 綠界參數
	 * @throws \Exception 如果參數不符合規定
	 *  */
	public function get_params( \WC_Order $order, AbstractPaymentGateway $gateway ): array {
		$params_dto = RequestParams::instance( $order, $gateway );
		return $params_dto->to_array();
	}

	/**
	 * 生成 CheckMacValue
	 *
	 * @see https://developers.ecpay.com.tw/?p=2902
	 *
	 * @param array<string, string|int> $args 參數
	 * @param string                    $hash_algo 'sha256' | 'md5' 雜湊演算法
	 * @return string CheckMacValue
	 * @throws \Exception 如果雜湊演算法不符合規定
	 */
	public static function get_check_value( array $args, string $hash_algo ): string {

		if ( ! in_array( $hash_algo, [ 'sha256', 'md5' ], true ) ) {
			throw new \Exception( __( 'Invalid hash algorithm', 'power_checkout' ) );
		}

		unset( $args['CheckMacValue'] ); // 確保不會用 CheckMacValue 生成
		ksort( $args, SORT_STRING | SORT_FLAG_CASE );   // 依照 key 字母排序

		$settings = Settings::instance();

		$args_string   = [];
		$args_string[] = "HashKey={$settings->hash_key}";// 開頭加上 HashKey
		foreach ( $args as $key => $value ) {
			$args_string[] = "{$key}={$value}";
		}
		$args_string[] = "HashIV={$settings->hash_iv}";// 結尾加上 HashIV

		$args_string = implode( '&', $args_string ); // 用 & 連接
		$args_string = EcpayUtils::urlencode( $args_string ); // 綠界要求 urlencode 的規則
		$args_string = strtolower( $args_string ); // 轉小寫
		$check_value = hash( $hash_algo, $args_string ); // 生成 CheckMacValue
		$check_value = strtoupper( $check_value ); // 轉大寫

		return $check_value;
	}




	/**
	 * 設定屬性
	 * TODO 看有沒要補充的
	 */
	private function set_properties(): void {
		switch ($this->settings->mode) {
			case 'prod':
				$this->settings->merchant_id               = '3002599';
				$this->settings->hash_key                  = 'spPjZn66i0OhqJsQ';
				$this->settings->hash_iv                   = 'hT5OJckN45isQTTs';
				$this->settings->aio_checkout_endpoint     = 'https://payment.ecpay.com.tw/Cashier/AioCheckOut/V5';
				$this->settings->query_trade_info_endpoint = 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5';
				$this->settings->sptoken_endpoint          = 'https://payment.ecpay.com.tw/SP/CreateTrade';
				break;
			default: // test
				$this->settings->merchant_id               = '3002599';
				$this->settings->hash_key                  = 'spPjZn66i0OhqJsQ';
				$this->settings->hash_iv                   = 'hT5OJckN45isQTTs';
				$this->settings->aio_checkout_endpoint     = 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5';
				$this->settings->query_trade_info_endpoint = 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5';
				$this->settings->sptoken_endpoint          = 'https://payment-stage.ecpay.com.tw/SP/CreateTrade';
				break;
		}
	}
}
