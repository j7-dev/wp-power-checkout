<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;

/**
 * VirtualAccount
 *  */
final class VirtualAccount extends DTO {
	/** @var string *轉帳截止日期 */
	public string $dueDate;

	/** @var string *轉帳截止日期說明 */
	public string $dueDateDesc;

	/** @var string *轉帳虛擬帳號 */
	public string $recipientAccountNum;

	/** @var string *轉帳虛擬帳號銀行代碼 */
	public string $recipientBankCode;

	/** @var string 付款人轉出帳號 */
	public string $paymentAccountNum;

	/** @var string 付款人轉出帳號銀行代碼 */
	public string $paymentBankCode;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'dueDate',
		'dueDateDesc',
		'recipientAccountNum',
		'recipientBankCode',
	];
}
