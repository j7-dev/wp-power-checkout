<?php

declare(strict_types=1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Utils;

use J7\PowerPayment\Utils\Base as Utils;
use J7\PowerPayment\Domains\Payment\Abstract_Payment_Gateway;

/** Utils */
abstract class Base {

	/**
	 * 取得商品名稱，用 #連接
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public static function get_item_name( \WC_Order $order ): string {
		$item_names = [];
		foreach ($order->get_items() as $item) {
			// 移除商品名稱中的 # 符號
			$item_name    = Utils::filter_special_char($item->get_name());
			$item_names[] = $item_name;

			// 檢查累計字串長度是否超過 400
			if (Utils::strlen(implode('#', $item_names)) >= 400) {
				// 如果超過 400 ，則去除剛剛加入的商品名稱
				$item_names = array_slice($item_names, 0, -1);
				break;
			}
		}

		return implode('#', $item_names);
	}

	/** 取得語系 @return string|null */
	public static function get_language(): string|null {
		$locale = \get_locale();
		switch ( $locale ) {
			case 'zh_HK':
			case 'zh_TW':
				return null;
			case 'ko_KR':
				return 'KOR';
			case 'ja':
				return 'JPN';
			case 'zh_CN':
				return 'CHI';
			case 'en_US':
			case 'en_AU':
			case 'en_CA':
			case 'en_GB':
			default:
				return 'ENG';
		}
	}
}
