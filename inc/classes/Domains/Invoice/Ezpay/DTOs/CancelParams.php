<?php
/**
 * 藍新 ezPay 發票作廢參數 DTO（invoice_invalid 的 PostData_ 業務欄位）
 *
 * 作廢發票 PostData_ 欄位（不含 RespondType / Version / TimeStamp，由 client 注入）：
 *   { InvoiceNumber, InvalidReason }
 *
 * ⚠️ 欄位名為 `InvoiceNumber`（開立 / 作廢同；折讓開立才改用 `InvoiceNo`）。
 * InvalidReason 限中文 6 字或英文 20 字，超長截斷。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §3. 作廢發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票作廢參數 DTO */
final class CancelParams {

	/** @var int 作廢原因字元上限（中文 6 字；以 6 截斷防呆） */
	private const REASON_MAX = 6;

	/**
	 * Constructor（private，經 from_issued_data() 建立）
	 *
	 * @param string $invoice_number 發票號碼.
	 * @param string $invalid_reason 作廢原因.
	 */
	private function __construct(
		private readonly string $invoice_number,
		private readonly string $invalid_reason,
	) {}

	/**
	 * 由已開立發票 meta 建立作廢參數
	 *
	 * @param array<string, mixed> $issued_data    已開立發票 meta（含 invoice_number）.
	 * @param string               $invalid_reason 作廢原因（預設「訂單退款作廢」）.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 找不到發票號碼.
	 */
	public static function from_issued_data( array $issued_data, string $invalid_reason = '訂單退款作廢' ): self {
		$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
		if ( '' === $invoice_number ) {
			throw new \InvalidArgumentException( '找不到發票號碼，無法作廢' );
		}

		$reason = \function_exists( 'mb_substr' )
		? \mb_substr( $invalid_reason, 0, self::REASON_MAX )
		: \substr( $invalid_reason, 0, self::REASON_MAX );

		return new self( $invoice_number, $reason ?: '訂單退款作廢' );
	}

	/**
	 * 輸出 ezPay PostData_ 業務欄位陣列
	 *
	 * @return array<string, string> 作廢 PostData_ 欄位.
	 */
	public function to_array(): array {
		return [
			'InvoiceNumber' => $this->invoice_number,
			'InvalidReason' => $this->invalid_reason,
		];
	}
}
