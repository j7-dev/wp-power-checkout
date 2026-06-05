<?php
/**
 * 綠界電子收據作廢參數 DTO（內層 Data）
 *
 * 端點 /Receipt/Invalid：Data = { MerchantID, ReceiptNo, Reason }
 * 作廢需要原開立的綠界收據編號（ReceiptNo），取自 issued_data meta。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md §Invalid
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界電子收據作廢參數 DTO */
final class ReceiptCancelParams extends DTO {

	/** @var string 特店編號 */
	public string $MerchantID = '';

	/** @var string 綠界收據編號 */
	public string $ReceiptNo = '';

	/** @var string 作廢原因 */
	public string $Reason = '訂單退款作廢';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
		'ReceiptNo',
	];

	/**
	 * 從已開立資料建立作廢參數
	 *
	 * @param string               $merchant_id 特店編號
	 * @param array<string, mixed> $issued_data 已開立收據資料 meta
	 *
	 * @return self
	 * @throws \Exception 找不到收據編號
	 */
	public static function from_issued_data( string $merchant_id, array $issued_data ): self {
		$receipt_no = (string) ( $issued_data['receipt_number'] ?? '' );

		if (!$receipt_no) {
			throw new \Exception( '找不到收據編號，無法作廢' );
		}

		return new self(
			[
				'MerchantID' => $merchant_id,
				'ReceiptNo'  => $receipt_no,
			]
		);
	}
}
