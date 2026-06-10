<?php
/**
 * PayNow 退款開立請求參數（POST /api/v1/payment-intents/:id/refunds）
 *
 * 對齊 payment-rest-api.md §5.1 Request Body。本 Cycle（Foundation）僅組裝 + 守衛，
 * 實際 HTTP 串接於 Cycle 4（退款）。
 *
 *  - create()：通用退款（信用卡等即時授權型，不需 bank 欄位）。
 *  - create_for_atm()：ATM 退款，bankCode / bankBranchCode / bankAccount 三欄必填，
 *    缺一即於靜態工廠同步 throw \InvalidArgumentException（不依賴 DTO validate 生命週期，
 *    因底層 DTO 在非 local 環境會吞驗證例外）。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 10
 * @see .claude/skills/paynow/references/payment-rest-api.md §5.1 退款開立
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\DTOs;

use J7\WpUtils\Classes\DTO;

/** PayNow 退款開立請求參數 */
final class RefundParams extends DTO {

	/** @var int 退款金額 */
	public int $amount = 0;

	/** @var string 退款原因（<= 255 字） */
	public string $reason = '';

	/** @var string 退款銀行代碼（ATM 退款必填） */
	public string $bankCode = '';

	/** @var string 退款銀行分行代碼（ATM 退款必填） */
	public string $bankBranchCode = '';

	/** @var string 退款銀行帳號（ATM 退款必填） */
	public string $bankAccount = '';

	/**
	 * 由陣列建立退款參數（通用：信用卡等即時授權型，不需 bank 欄位）
	 *
	 * @param array<string, mixed> $data 退款資料
	 * @return self
	 */
	public static function create( array $data ): self {
		return new self( $data );
	}

	/**
	 * 轉為送往 PayNow refund API 的請求 body（資安 F-3：剔除空 bank 三欄）
	 *
	 * 現況漏洞：父類 DTO::to_array() 以反射輸出所有已初始化的 public 屬性，
	 * 信用卡退款（不需 bank）時 bankCode / bankBranchCode / bankAccount 仍為預設空字串，
	 * 會被一併送進 refund API body，導致 PayNow 收到非預期空欄位（validation_error / 付款方式誤判）。
	 *
	 * 加固：覆寫 to_array()，僅剔除「值為空字串」的 bank 三欄；
	 *  - 信用卡退款（bank 三欄皆空）→ 三欄被剔除，body 僅含 amount / reason。
	 *  - ATM 退款（bank 三欄有值，由 create_for_atm 守衛）→ 有值不被剔除，三欄完整保留（對照組）。
	 * 僅針對 bank 三欄過濾，amount / reason 等其他欄位語意不受影響（amount=0 仍保留）。
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$body        = parent::to_array();
		$bank_fields = [ 'bankCode', 'bankBranchCode', 'bankAccount' ];

		foreach ( $bank_fields as $field ) {
			if ( isset( $body[ $field ] ) && '' === $body[ $field ] ) {
				unset( $body[ $field ] );
			}
		}

		return $body;
	}

	/**
	 * 由陣列建立 ATM 退款參數（bankCode / bankBranchCode / bankAccount 三欄必填）
	 *
	 * @param array<string, mixed> $data 退款資料
	 * @return self
	 * @throws \InvalidArgumentException 任一 bank 欄位缺失 / 為空時
	 */
	public static function create_for_atm( array $data ): self {
		$required = [ 'bankCode', 'bankBranchCode', 'bankAccount' ];
		foreach ( $required as $field ) {
			if ( '' === (string) ( $data[ $field ] ?? '' ) ) {
				throw new \InvalidArgumentException(
					\sprintf( 'ATM 退款必填欄位 %s 缺失', $field )
				);
			}
		}

		return new self( $data );
	}
}
