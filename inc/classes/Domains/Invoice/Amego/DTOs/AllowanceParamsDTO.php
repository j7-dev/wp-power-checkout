<?php
/**
 * 光貿開立折讓請求參數 DTO（g0401）
 *
 * 對「已開立」的發票開立折讓單（部分退款）。`/json/g0401` 的 `data` 為「陣列」，
 * 一張折讓單對應一張原發票。本 DTO 以單一彙總品項表示折讓金額（含稅），
 * 對應綠界折讓 Items 加總 = 折讓金額之慣例，再依 5% 反推未稅 / 稅額。
 *
 * 折讓 ProductItem 單價 / 小計均為「不含稅」，Tax 為品項稅金（整數）：
 *   TotalAmount = 各品項 Amount（未稅）加總
 *   TaxAmount   = 各品項 Tax 加總
 *   折讓金額（含稅）不可大於原發票金額。
 *
 * @see .claude/skills/amego-invoice/references/api-reference.md §開立折讓 /json/g0401
 * @see .claude/skills/amego-invoice/references/api-reference.md §折讓金額（g0401）
 */

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ETaxType;
use J7\WpUtils\Classes\DTO;

/** 光貿開立折讓請求參數 DTO（g0401） */
final class AllowanceParamsDTO extends DTO {

	/** @var string 折讓單編號，不可重複，≤ 16 字 */
	public string $AllowanceNumber = '';

	/** @var string 折讓單日期 YYYYMMDD */
	public string $AllowanceDate = '';

	/** @var string 折讓類型 1 買方開立 / 2 賣方開立。114/1/1 起經雙方合意之退回或折讓，賣方營業人應開立 */
	public string $AllowanceType = '2';

	/** @var string 買方統編；無填 0000000000 */
	public string $BuyerIdentifier = '0000000000';

	/** @var string 買方名稱 */
	public string $BuyerName = '消費者';

	/** @var string 買方信箱 */
	public string $BuyerEmailAddress = '';

	/** @var array<int, array<string, mixed>> 折讓商品陣列，最多 9999 筆 */
	public array $ProductItem = [];

	/** @var int 營業稅額 */
	public int $TaxAmount = 0;

	/** @var int 金額合計（不含稅） */
	public int $TotalAmount = 0;

	/** @var string[] 必填 */
	protected array $require_properties = [
		'AllowanceNumber',
		'AllowanceDate',
		'ProductItem',
		'TotalAmount',
	];

	/**
	 * 建立折讓參數
	 *
	 * @param \WC_Order            $order            訂單
	 * @param array<string, mixed> $issued_data      已開立發票 meta（含 invoice_number / invoice_time）
	 * @param int                  $allowance_amount 折讓金額（含稅，整數）
	 * @param string               $notify_mail      折讓通知 Email（空字串則不寄送）
	 *
	 * @return self
	 * @throws \Exception 找不到原發票號碼
	 */
	public static function from_order( \WC_Order $order, array $issued_data, int $allowance_amount, string $notify_mail = '' ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		if (!$invoice_number) {
			throw new \Exception( '找不到原發票號碼，無法開立折讓' );
		}

		$original_invoice_date = self::to_ymd( $issued_data['invoice_time'] ?? null );

		// 含稅折讓金額 → 未稅 / 稅額（5% 營業稅；B2C 不打統編稅額仍需於折讓明細帶 Tax）
		$total_amount = (int) \round( $allowance_amount / 1.05 );
		$tax_amount   = $allowance_amount - $total_amount;

		// 以單一彙總品項表示折讓（單價 / 小計為未稅）
		$product_item = [
			[
				'OriginalInvoiceNumber' => $invoice_number,
				'OriginalInvoiceDate'   => $original_invoice_date,
				'OriginalDescription'   => '訂單折讓',
				'Quantity'              => 1,
				'UnitPrice'             => $total_amount,
				'Amount'                => $total_amount,
				'Tax'                   => $tax_amount,
				'TaxType'               => ETaxType::TAXABLE->value,
			],
		];

		return new self(
			[
				'AllowanceNumber'   => self::generate_allowance_number( $order ),
				'AllowanceDate'     => \gmdate( 'Ymd' ),
				'BuyerName'         => $order->get_billing_first_name() . $order->get_billing_last_name() ?: '消費者',
				'BuyerEmailAddress' => $notify_mail,
				'ProductItem'       => $product_item,
				'TaxAmount'         => $tax_amount,
				'TotalAmount'       => $total_amount,
			]
		);
	}

	/**
	 * 取得公開的屬性 array
	 *
	 * 開立折讓 g0401 的 data 為「陣列」（一張折讓單一筆 Object），故 to_array 回傳 list。
	 * 與 CancelInvoiceParamsDTO 相同：回傳 list 但以 array<string,mixed> 標註相容基類。
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$item = [
			'AllowanceNumber' => $this->AllowanceNumber,
			'AllowanceDate'   => $this->AllowanceDate,
			'AllowanceType'   => $this->AllowanceType,
			'BuyerIdentifier' => $this->BuyerIdentifier,
			'BuyerName'       => $this->BuyerName,
			'ProductItem'     => $this->ProductItem,
			'TaxAmount'       => $this->TaxAmount,
			'TotalAmount'     => $this->TotalAmount,
		];

		if ('' !== $this->BuyerEmailAddress) {
			$item['BuyerEmailAddress'] = $this->BuyerEmailAddress;
		}

		// g0401 的 data 為 list（一張折讓單一筆 Object）；array_merge 擦除 sealed shape 後以 @var 相容基類
		/** @var array<string, mixed> $result */
		$result = \array_merge( [], [ $item ] );
		return $result;
	}

	/**
	 * 產生不重複的折讓單編號（≤ 16 字）
	 *
	 * 格式：訂單 ID + 時間後綴，截斷至 16 字。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	private static function generate_allowance_number( \WC_Order $order ): string {
		$candidate = $order->get_id() . \gmdate( 'YmdHis' );
		return \substr( $candidate, 0, 16 );
	}

	/**
	 * 將原發票時間正規化為 YYYYMMDD（g0401 OriginalInvoiceDate 需要）
	 *
	 * @param mixed $invoice_time Unix 時間戳記或日期字串
	 *
	 * @return string
	 */
	private static function to_ymd( mixed $invoice_time ): string {
		if (\is_numeric( $invoice_time )) {
			return \gmdate( 'Ymd', (int) $invoice_time );
		}
		if (\is_string( $invoice_time ) && '' !== $invoice_time) {
			$ts = \strtotime( $invoice_time );
			if (false !== $ts) {
				return \gmdate( 'Ymd', $ts );
			}
		}
		return \gmdate( 'Ymd' );
	}
}
