<?php
/**
 * 綠界發票折讓開立參數 DTO（內層 Data）
 *
 * 部分退款時對「已開立」的發票開立折讓單。B2C 與 B2B（存證模式）結構不同：
 *
 * B2C `/B2CInvoice/Allowance`（Revision 3.0.0）：
 *   Data = { MerchantID, InvoiceNo, InvoiceDate, AllowanceNotify, AllowanceAmount(含稅), Items[] }
 *   - AllowanceAmount 為「含稅」折讓總金額，需 > 0 且 ≤ 剩餘可折讓金額
 *   - Items[] 與開立發票相同結構（ItemSeq/ItemName/ItemCount/ItemWord/ItemPrice/ItemAmount[/ItemTaxType]）
 *
 * B2B 存證模式 `/B2BInvoice/Allowance`（Revision 1.0.0，需 RqID）：
 *   Data = { MerchantID, TaxAmount, TotalAmount(未稅), Details[] }
 *   - Details[] = { OriginalInvoiceNumber, OriginalInvoiceDate, ItemName, OriginalSequenceNumber, ItemCount, ItemPrice, ItemAmount }
 *   - 存證模式折讓直接生效，不需 AllowanceConfirm
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md §折讓（退款部分金額）
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md §折讓 / 存證模式－折讓範例
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界發票折讓開立參數 DTO */
final class AllowanceParams extends DTO {

	/** @var string 特店編號 */
	public string $MerchantID = '';

	// region B2C 欄位

	/** @var string B2C 原發票號碼 */
	public string $InvoiceNo = '';

	/** @var string B2C 原發票開立日期 */
	public string $InvoiceDate = '';

	/** @var string B2C 折讓通知 E=Email S=SMS A=全部 N=不通知 */
	public string $AllowanceNotify = 'N';

	/** @var string B2C 通知 Email（AllowanceNotify 含 E 時） */
	public string $NotifyMail = '';

	/** @var string B2C 通知手機（AllowanceNotify 含 S 時） */
	public string $NotifyPhone = '';

	/** @var int B2C 折讓總金額（含稅），需 > 0 且 ≤ 剩餘可折讓金額 */
	public int $AllowanceAmount = 0;

	/** @var array<int, array<string, mixed>> B2C 折讓商品明細 */
	public array $Items = [];

	// endregion B2C 欄位

	// region B2B 欄位

	/** @var int B2B 營業稅額 */
	public int $TaxAmount = 0;

	/** @var int B2B 折讓金額合計（未稅） */
	public int $TotalAmount = 0;

	/** @var array<int, array<string, mixed>> B2B 折讓明細 */
	public array $Details = [];

	// endregion B2B 欄位

	/** @var string 折讓原因（選填） */
	public string $Reason = '訂單部分退款折讓';

	/** @var string[] 必填 */
	protected array $require_properties = [
		'MerchantID',
	];

	/**
	 * 建立 B2C 折讓參數
	 *
	 * @param string               $merchant_id     特店編號
	 * @param array<string, mixed> $issued_data     已開立發票 meta（含 invoice_number / invoice_date）
	 * @param int                  $allowance_amount 折讓金額（含稅，整數）
	 * @param string               $notify_mail     通知 Email（空字串則不通知）
	 *
	 * @return self
	 * @throws \Exception 找不到原發票號碼
	 */
	public static function for_b2c( string $merchant_id, array $issued_data, int $allowance_amount, string $notify_mail = '' ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		$invoice_date   = (string) ( $issued_data['invoice_date'] ?? '' );

		if (!$invoice_number) {
			throw new \Exception( '找不到原發票號碼，無法開立折讓' );
		}

		// 折讓單以「單一彙總品項」表示折讓金額（含稅），符合綠界 Items 加總 = AllowanceAmount 規則
		$items = [
			[
				'ItemSeq'    => 1,
				'ItemName'   => '訂單折讓',
				'ItemCount'  => 1,
				'ItemWord'   => '式',
				'ItemPrice'  => $allowance_amount,
				'ItemAmount' => $allowance_amount,
			],
		];

		return new self(
			[
				'MerchantID'      => $merchant_id,
				'InvoiceNo'       => $invoice_number,
				'InvoiceDate'     => $invoice_date,
				'AllowanceNotify' => '' !== $notify_mail ? 'E' : 'N',
				'NotifyMail'      => $notify_mail,
				'AllowanceAmount' => $allowance_amount,
				'Items'           => $items,
			]
		);
	}

	/**
	 * 建立 B2B（存證模式）折讓參數
	 *
	 * B2B 折讓金額為「未稅 + 稅額」拆分；以 5% 營業稅反推未稅與稅額。
	 *
	 * @param string               $merchant_id      特店編號
	 * @param array<string, mixed> $issued_data      已開立發票 meta
	 * @param int                  $allowance_amount 折讓金額（含稅，整數）
	 *
	 * @return self
	 * @throws \Exception 找不到原發票號碼
	 */
	public static function for_b2b( string $merchant_id, array $issued_data, int $allowance_amount ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		$invoice_date   = (string) ( $issued_data['invoice_date'] ?? '' );

		if (!$invoice_number) {
			throw new \Exception( '找不到原發票號碼，無法開立折讓' );
		}

		// 含稅 → 未稅 / 稅額（5% 營業稅）
		$total_amount = (int) \round( $allowance_amount / 1.05 );
		$tax_amount   = $allowance_amount - $total_amount;

		$details = [
			[
				'OriginalInvoiceNumber'  => $invoice_number,
				'OriginalInvoiceDate'    => self::to_ymd( $invoice_date ),
				'ItemName'               => '訂單折讓',
				'OriginalSequenceNumber' => 1,
				'ItemCount'              => 1,
				'ItemPrice'              => $total_amount,
				'ItemAmount'             => $total_amount,
			],
		];

		return new self(
			[
				'MerchantID'  => $merchant_id,
				'TaxAmount'   => $tax_amount,
				'TotalAmount' => $total_amount,
				'Details'     => $details,
			]
		);
	}

	/**
	 * 依端點輸出對應的 Data（B2C / B2B 欄位不同，避免送出多餘欄位）
	 *
	 * @param bool $is_b2b 是否為 B2B
	 *
	 * @return array<string, mixed>
	 */
	public function to_request_data( bool $is_b2b ): array {
		if ($is_b2b) {
			return [
				'MerchantID'  => $this->MerchantID,
				'TaxAmount'   => $this->TaxAmount,
				'TotalAmount' => $this->TotalAmount,
				'Details'     => $this->Details,
			];
		}

		$data = [
			'MerchantID'      => $this->MerchantID,
			'InvoiceNo'       => $this->InvoiceNo,
			'InvoiceDate'     => $this->InvoiceDate,
			'AllowanceNotify' => $this->AllowanceNotify,
			'AllowanceAmount' => $this->AllowanceAmount,
			'Items'           => $this->Items,
		];
		if ('' !== $this->NotifyMail) {
			$data['NotifyMail'] = $this->NotifyMail;
		}
		if ('' !== $this->NotifyPhone) {
			$data['NotifyPhone'] = $this->NotifyPhone;
		}
		return $data;
	}

	/**
	 * 將日期正規化為 YYYY-MM-DD（B2B Details 需要）
	 *
	 * @param string $date 原日期字串（可能含時間）
	 *
	 * @return string
	 */
	private static function to_ymd( string $date ): string {
		$ts = \strtotime( $date );
		return false !== $ts ? \gmdate( 'Y-m-d', $ts ) : $date;
	}
}
