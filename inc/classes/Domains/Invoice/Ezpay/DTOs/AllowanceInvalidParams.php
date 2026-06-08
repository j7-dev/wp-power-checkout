<?php
/**
 * 藍新 ezPay 發票折讓作廢參數 DTO（allowanceInvalid 的 PostData_ 業務欄位）
 *
 * 作廢折讓 PostData_ 欄位（不含 RespondType / Version / TimeStamp，由 client 注入）：
 *   { AllowanceNo, InvalidReason }
 *
 * 只能作廢「已確認」的折讓（本整合一律以 Status=1 即時確認開立折讓，故開立後即可作廢）。
 * InvalidReason 限中文 6 字或英文 20 字，超長截斷。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md §6. 作廢折讓
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs;

/** 藍新 ezPay 發票折讓作廢參數 DTO */
final class AllowanceInvalidParams {

	/** @var int 作廢原因字元上限（中文 6 字；以 6 截斷防呆） */
	private const REASON_MAX = 6;

	/**
	 * Constructor（private，經 from_allowance_data() 建立）
	 *
	 * @param string $allowance_no   折讓號.
	 * @param string $invalid_reason 作廢原因.
	 */
	private function __construct(
		private readonly string $allowance_no,
		private readonly string $invalid_reason,
	) {}

	/**
	 * 由已開立折讓 meta 建立折讓作廢參數
	 *
	 * @param array<string, mixed> $allowance_data 已開立折讓 meta（含 allowance_no）.
	 * @param string               $invalid_reason 作廢原因（預設「訂單退款取消」）.
	 *
	 * @return self
	 * @throws \InvalidArgumentException 找不到折讓號.
	 */
	public static function from_allowance_data( array $allowance_data, string $invalid_reason = '訂單退款取消' ): self {
		$allowance_no = (string) ( $allowance_data['allowance_no'] ?? '' );
		if ( '' === $allowance_no ) {
			throw new \InvalidArgumentException( '找不到折讓號，無法作廢折讓' );
		}

		$reason = \function_exists( 'mb_substr' )
		? \mb_substr( $invalid_reason, 0, self::REASON_MAX )
		: \substr( $invalid_reason, 0, self::REASON_MAX );

		return new self( $allowance_no, $reason ?: '訂單退款取消' );
	}

	/**
	 * 輸出 ezPay PostData_ 業務欄位陣列
	 *
	 * @return array<string, string> 折讓作廢 PostData_ 欄位.
	 */
	public function to_array(): array {
		return [
			'AllowanceNo'   => $this->allowance_no,
			'InvalidReason' => $this->invalid_reason,
		];
	}
}
