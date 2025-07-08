<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpay\Utils;

use J7\PowerCheckout\Utils\Base as Utils;

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

	/** 取得語系 @return 'ENG' | 'KOR' | 'JPN' | 'CHI' | null  */
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

	/**
	 * 生成唯一的 MerchantTradeNo
	 * TS + 反轉後的 timestamp (共12碼)
	 *
	 * @see https://www.ecpay.com.tw/CascadeFAQ/CascadeFAQ_Qa?nID=1454
	 * @param int $order_id 訂單 ID
	 * @return string
	 */
	public static function encode_trade_no( $order_id ): string {
		$order_prefix = '';
		// $order_prefix = RY_WT::get_option( 'ecpay_gateway_order_prefix');
		$trade_no = $order_prefix . $order_id . 'TS' . strrev( (string) time() );
		return substr( $trade_no, 0, 20 );
	}

	/**
	 * 從唯一的 MerchantTradeNo 取得訂單 ID
	 *
	 * @param string $trade_no 唯一的 MerchantTradeNo
	 * @return string
	 */
	public static function decode_trade_no( string $trade_no ): string {
		$order_prefix = '';
		// $order_prefix = RY_WT::get_option( 'ecpay_gateway_order_prefix');
		$order_id = substr( $trade_no, strlen( $order_prefix ), strrpos( $trade_no, 'TS' ) ?: 0 );
		return $order_id;
	}


	/**
	 * 綠界要求 urlencode 的規則
	 *
	 * @see https://developers.ecpay.com.tw/?p=2904
	 */
	public static function urlencode( string $str ): string {
		$str = str_replace(
			[ '%2D', '%2d', '%5F', '%5f', '%2E', '%2e', '%2A', '%2a', '%21', '%28', '%29' ],
			[ '-', '-', '_', '_', '.', '.', '*', '*', '!', '(', ')' ],
			urlencode( $str )
		);
		return $str;
	}
}
