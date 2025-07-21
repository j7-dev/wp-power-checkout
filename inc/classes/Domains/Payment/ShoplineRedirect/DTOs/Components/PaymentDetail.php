<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

use J7\WpUtils\Classes\DTO;

/**
 * PaymentDetail 付款方式詳細資訊
 * 回應會帶
 *  */
final class PaymentDetail extends DTO {

	/** @var string (64) *SLP 付款交易訂單編號 */
	public string $tradeOrderId;

	/** @var string (128) *付款狀態 */
	public string $status;

	/** @var int *付款成功时间 */
	public int $paymentSuccessTime;

	/** @var string (512) *付款方式 */
	public string $paymentMethod;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [ 'tradeOrderId', 'status', 'paymentSuccessTime', 'paymentMethod' ];
}
