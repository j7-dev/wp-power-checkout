<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\PaymentService;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model\RequestParams;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
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
		$methods[] = GeneralGateway::class;
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
}
