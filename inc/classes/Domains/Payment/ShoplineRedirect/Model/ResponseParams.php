<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;

/**
 * Shopline Payment 跳轉式支付 ResponseParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/session/
 */
final class ResponseParams extends DTO {

	/** @var string *SLP 結帳交易訂單編號 (32) */
	public readonly string $sessionId;

	/** @var string *特店訂單號 (32) */
	public readonly string $referenceId;

	/** @var string *結帳交易狀態 (16) */
	public readonly Enums\ResponseStatus $status;

	/** @var string *結帳交易提供給顧客付款的 URL (256) */
	public readonly string $sessionUrl;

	/** @var int *訂單建立時間 timestamp 13位毫秒 */
	public readonly int $createTime;

	/** @var Components\Amount *商品金額 */
	public readonly Components\Amount $amount;

	/** @var Components\PaymentDetail[] *付款方式詳細資訊 */
	public readonly array $paymentDetails;

	public static function create(): self {
		$args = [];

		return new self($args);
	}
}
