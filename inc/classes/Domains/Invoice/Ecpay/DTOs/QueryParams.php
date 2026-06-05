<?php
/**
 * 綠界發票查詢參數 DTO（GetIssue，唯讀，內層 Data）
 *
 * GetIssue 支援兩種查詢模式（擇一）：
 *   1. InvoiceNo + InvoiceDate（已知發票號碼）
 *   2. RelateNumber（以開立時的自訂關聯編號反查）
 *
 * 本 DTO 以「已開立發票 meta」建立 InvoiceNo + InvoiceDate 模式。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §查詢發票（GetIssue）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界發票查詢參數 DTO（GetIssue） */
final class QueryParams extends DTO {

	/** @var string 特店編號 */
	public string $MerchantID = '';

	/** @var string 發票號碼 */
	public string $InvoiceNo = '';

	/** @var string 發票開立日期（YYYY-MM-DD） */
	public string $InvoiceDate = '';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
		'InvoiceNo',
	];

	/**
	 * 以已開立發票 meta 建立查詢參數（InvoiceNo + InvoiceDate 模式）
	 *
	 * @param string               $merchant_id 特店編號
	 * @param array<string, mixed> $issued_data 已開立發票 meta（含 invoice_number / invoice_date）
	 *
	 * @return self
	 * @throws \Exception 找不到原發票號碼
	 */
	public static function from_issued_data( string $merchant_id, array $issued_data ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		if (!$invoice_number) {
			throw new \Exception( '找不到原發票號碼，無法查詢' );
		}

		return new self(
			[
				'MerchantID'  => $merchant_id,
				'InvoiceNo'   => $invoice_number,
				'InvoiceDate' => self::to_ymd( (string) ( $issued_data['invoice_date'] ?? '' ) ),
			]
		);
	}

	/**
	 * 輸出查詢 Data
	 *
	 * @return array<string, mixed>
	 */
	public function to_request_data(): array {
		$data = [
			'MerchantID' => $this->MerchantID,
			'InvoiceNo'  => $this->InvoiceNo,
		];
		if ('' !== $this->InvoiceDate) {
			$data['InvoiceDate'] = $this->InvoiceDate;
		}
		return $data;
	}

	/**
	 * 將日期正規化為 YYYY-MM-DD
	 *
	 * @param string $date 原日期字串（可能含時間）
	 *
	 * @return string
	 */
	private static function to_ymd( string $date ): string {
		if ('' === $date) {
			return '';
		}
		$ts = \strtotime( $date );
		return false !== $ts ? \gmdate( 'Y-m-d', $ts ) : $date;
	}
}
