<?php
/**
 * 綠界發票作廢參數 DTO（內層 Data）
 *
 * B2C 端點 /B2CInvoice/Invalid：Data = { MerchantID, InvoiceNo, InvoiceDate, Reason }
 * B2B 端點 /B2BInvoice/Invalid：Data = { MerchantID, InvoiceNumber, InvoiceDate, Reason }
 *
 * 作廢需要原開立的發票號碼與開立日期，皆取自 issued_data meta。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §作廢發票
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md §作廢發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界發票作廢參數 DTO */
final class CancelParams extends DTO {

	/** @var string 特店編號 */
	public string $MerchantID = '';

	/** @var string B2C 發票號碼 */
	public string $InvoiceNo = '';

	/** @var string B2B 發票號碼 */
	public string $InvoiceNumber = '';

	/** @var string 發票開立日期（須與開立時一致） */
	public string $InvoiceDate = '';

	/** @var string 作廢原因 */
	public string $Reason = '訂單退款作廢';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
		'InvoiceDate',
	];

	/**
	 * 從已開立資料建立作廢參數
	 *
	 * @param string               $merchant_id 特店編號
	 * @param array<string, mixed> $issued_data 已開立發票資料 meta
	 * @param bool                 $is_b2b      是否為 B2B
	 *
	 * @return self
	 * @throws \Exception 找不到發票號碼
	 */
	public static function from_issued_data( string $merchant_id, array $issued_data, bool $is_b2b ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		$invoice_date   = (string) ( $issued_data['invoice_date'] ?? '' );

		if (!$invoice_number) {
			throw new \Exception( '找不到發票號碼，無法作廢' );
		}

		$args = [
			'MerchantID'  => $merchant_id,
			'InvoiceDate' => $invoice_date,
		];

		if ($is_b2b) {
			$args['InvoiceNumber'] = $invoice_number;
		} else {
			$args['InvoiceNo'] = $invoice_number;
		}

		return new self( $args );
	}
}
