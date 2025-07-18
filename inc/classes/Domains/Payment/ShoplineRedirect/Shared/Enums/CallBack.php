<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Core\Init;

/**
 * Shopline Payment 跳轉式支付 callback
 */
enum CallBack: string {
	/** @var string 建立交易 */
	case CREATE_TRADE = 'create_trade';

	/** @return string action 取得 WC API action name */
	public function action(): string {
		return 'woocommerce_api_' . Init::PREFIX . $this->value;
	}

	/** @return string action 取得 WC API callback name */
	public function callback(): string {
		return $this->value . '_callback';
	}

	/** @return string action 取得 WC API endpoint */
	public function endpoint( \WC_Order $order ): string {
		return \WC()->api_request_url( Init::PREFIX . $this->value, true );
	}
}
