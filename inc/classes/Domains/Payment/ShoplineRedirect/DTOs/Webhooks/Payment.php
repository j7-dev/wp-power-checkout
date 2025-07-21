<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;

/**
 * 付款交易
 *
 * @see https://docs.shoplinepayments.com/api/event/model/payment/
 */
final class Payment extends DTO {

	/** @var string *特店訂單號 (32)*/
	public string $referenceOrderId;

	/** @var string *SLP 付款交易訂單編號 (32)*/
	public string $tradeOrderId;

	/** @var ResponseStatus::value *付款狀態 (32) 參考 */
	public string $status;

	/** @var ResponseSubStatus::value *子付款狀態 (32) 參考，採用手動請款時需要關注此參數 */
	public string $subStatus;

	/** @var Components\PaymentError 支付錯誤訊息 (PaymentError) 選填 */
	public Components\PaymentError $paymentMsg;

	/** @var string 'SDK' 指示下一步動作 (16) 參考，對應 nextAction 欄位處理方式 選填 */
	public string $actionType;

	/** @var mixed 指示下一步動作，特店可忽略，傳送給 SDK 即可 (NextAction) 選填 */
	public $nextAction;

	/** @var  *訂單資訊 */
	public $order;

	/** @var mixed 附加資訊，可能是 array */
	public mixed $additionalData;


	/** @var array 必填屬性 */
	protected array $require_properties = [];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 */
	public static function create( array $args ): self {

		return new self( $args );
	}
}
