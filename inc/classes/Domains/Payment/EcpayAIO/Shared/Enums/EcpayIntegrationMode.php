<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums;

/**
 * 綠界整合模式
 *
 *  - AIO：全方位金流（導轉式），消費者跳轉至綠界付款頁，採 CheckMacValue (SHA256)。
 *  - ECPG：站內付 2.0（內嵌式），AES-JSON 加密，站內付款不跳轉。
 *
 * @see https://developers.ecpay.com.tw/?p=2862
 */
enum EcpayIntegrationMode: string {
	/** 全方位金流 AIO（導轉式，CheckMacValue SHA256） */
	case AIO = 'aio';
	/** 站內付 2.0 ECPG（內嵌式，AES-JSON） */
	case ECPG = 'ecpg';

	/**
	 * 取得整合模式的中文標籤
	 *
	 * @return string 標籤
	 */
	public function label(): string {
		return match ( $this ) {
			self::AIO => '全方位金流（導轉式）',
			self::ECPG => '站內付 2.0（內嵌式）',
		};
	}
}
