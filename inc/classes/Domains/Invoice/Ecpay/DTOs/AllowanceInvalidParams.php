<?php
/**
 * 綠界發票折讓作廢參數 DTO（內層 Data）
 *
 * B2C `/B2CInvoice/AllowanceInvalid`：Data = { MerchantID, InvoiceNo, AllowanceNo, Reason }
 * B2B（存證模式）`/B2BInvoice/AllowanceInvalid`：Data = { MerchantID, AllowanceNo, Reason }
 *
 * 作廢需要原折讓單號（AllowanceNo），取自 allowance_data meta。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §折讓作廢
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md §存證模式－作廢折讓範例
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界發票折讓作廢參數 DTO */
final class AllowanceInvalidParams extends DTO {

	/** @var string 特店編號 */
	public string $MerchantID = '';

	/** @var string 原發票號碼（B2C 必填，B2B 不需要） */
	public string $InvoiceNo = '';

	/** @var string 折讓單號 */
	public string $AllowanceNo = '';

	/** @var string 作廢原因 */
	public string $Reason = '折讓作廢';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
		'AllowanceNo',
	];

	/**
	 * 從已開立折讓資料建立作廢參數
	 *
	 * @param string               $merchant_id    特店編號
	 * @param array<string, mixed> $allowance_data 已開立折讓 meta（含 allowance_number / invoice_number）
	 * @param bool                 $is_b2b         是否為 B2B
	 *
	 * @return self
	 * @throws \Exception 找不到折讓單號
	 */
	public static function from_allowance_data( string $merchant_id, array $allowance_data, bool $is_b2b ): self {
		$allowance_no = (string) ( $allowance_data['allowance_number'] ?? '' );
		if (!$allowance_no) {
			throw new \Exception( '找不到折讓單號，無法作廢折讓' );
		}

		$args = [
			'MerchantID'  => $merchant_id,
			'AllowanceNo' => $allowance_no,
		];

		// B2C 作廢折讓需額外帶原發票號碼
		if (!$is_b2b) {
			$args['InvoiceNo'] = (string) ( $allowance_data['invoice_number'] ?? '' );
		}

		return new self( $args );
	}

	/**
	 * 依端點輸出對應 Data（B2B 不送 InvoiceNo）
	 *
	 * @param bool $is_b2b 是否為 B2B
	 *
	 * @return array<string, mixed>
	 */
	public function to_request_data( bool $is_b2b ): array {
		$data = [
			'MerchantID'  => $this->MerchantID,
			'AllowanceNo' => $this->AllowanceNo,
			'Reason'      => $this->Reason,
		];
		if (!$is_b2b) {
			$data['InvoiceNo'] = $this->InvoiceNo;
		}
		return $data;
	}
}
