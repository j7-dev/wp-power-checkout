<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Utils;

/** Order 相關 */
abstract class Order {
	const REQUEST_KEY  = 'pp_gateway_request_params'; // payment gateway 儲存在 order meta 的資料
	const RESPONSE_KEY = 'pp_gateway_response_params'; // payment gateway 儲存在 order meta 的資料
}
