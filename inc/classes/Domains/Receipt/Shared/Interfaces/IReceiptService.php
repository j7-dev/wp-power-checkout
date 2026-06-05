<?php
/**
 * 電子收據服務介面
 *
 * 與電子發票 IInvoiceService 平行（語意相近但非同物）：
 *  - 發票（Invoice）受統一發票使用辦法規範，有字軌/中獎/進項稅，方法 get_invoice_number。
 *  - 收據（Receipt）為一般商業/捐贈憑證，無字軌、無稅務抵免，方法 get_receipt_number。
 *
 * 兩者並存可選：後台可同時啟用發票 provider 與收據 provider，互不影響。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Shared\Interfaces;

interface IReceiptService {

	/**
	 * @param bool $with_default 是否帶預設值（true）或只拿 DB 值（false）
	 *
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array;

	/**
	 * 開立收據（冪等）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> API 資料
	 */
	public function issue( \WC_Order|int $order_or_id ): array;

	/**
	 * 作廢收據（冪等）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> API 資料
	 */
	public function cancel( \WC_Order|int $order_or_id ): array;

	/**
	 * 取得收據編號（綠界 ReceiptNo）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	public function get_receipt_number( \WC_Order $order ): string;
}
