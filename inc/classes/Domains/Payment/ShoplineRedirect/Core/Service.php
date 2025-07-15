<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Abstracts\PaymentService;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestParams;
use J7\PowerCheckout\Domains\Payment\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Utils\Base as ShoplineUtils;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\Settings;

/**
 * Shopline Payment 跳轉式支付
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class Service extends PaymentService {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 服務 ID */
	public string $id = Settings::KEY;

	/** @var Settings 設定 */
	public Settings $settings;



	/** Constructor */
	public function __construct() {
		$this->settings = Settings::instance();
		$this->set_properties();
		parent::__construct();
	}

	/**
	 * 添加付款方式
	 *
	 * @param array<string> $methods 付款方式
	 *
	 * @return array<string>
	 */
	public function add_method( array $methods ): array {
		// $methods[] = Atm::class;
		// $methods[] = WebAtm::class;
		// $methods[] = Credit::class;
		// $methods[] = CreditInstallment::class;
		// $methods[] = Barcode::class;
		// $methods[] = CVS::class;
		return $methods;
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
		$params_dto = RequestParams::create( $order, $gateway );
		return $params_dto->to_array();
	}

	/**
	 * 生成 CheckMacValue
	 *
	 * @see https://developers.Shopline.com.tw/?p=2902
	 *
	 * @param array<string, string|int> $args 參數
	 * @param string                    $hash_algo 'sha256' | 'md5' 雜湊演算法
	 * @return string CheckMacValue
	 * @throws \Exception 如果雜湊演算法不符合規定
	 */
	public function get_check_value( array $args, string $hash_algo ): string {

		if ( ! in_array( $hash_algo, [ 'sha256', 'md5' ], true ) ) {
			throw new \Exception( __( 'Invalid hash algorithm', 'power_checkout' ) );
		}

		unset( $args['CheckMacValue'] ); // 確保不會用 CheckMacValue 生成
		ksort( $args, SORT_STRING | SORT_FLAG_CASE );   // 依照 key 字母排序

		$args_string   = [];
		$args_string[] = "HashKey={$this->hash_key}";// 開頭加上 HashKey
		foreach ( $args as $key => $value ) {
			$args_string[] = "{$key}={$value}";
		}
		$args_string[] = "HashIV={$this->hash_iv}";// 結尾加上 HashIV

		$args_string = implode( '&', $args_string ); // 用 & 連接
		$args_string = ShoplineUtils::urlencode( $args_string ); // 綠界要求 urlencode 的規則
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
				$this->settings->merchant_id = '';
				$this->settings->api_key     = '';
				$this->settings->clinet_key  = '';
				$this->settings->api_url     = 'https://api.shoplinepayments.com';
				break;
			default: // test
				$this->settings->merchant_id = '3252264968486264832';
				$this->settings->api_key     = 'sk_sandbox_fc8d1884a9064b6ba4b2cc16d124663c';
				$this->settings->clinet_key  = 'pk_sandbox_f03ae82192c946888fbf0901b8d2053a';
				$this->settings->api_url     = 'https://api-sandbox.shoplinepayments.com';
				break;
		}
	}
}
