<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Params;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 RequestParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/session/
 */
final class RequestParams extends DTO {

	/** @var string *特店訂單號 (32) */
	public string $referenceId;

	/** @var Components\Amount *金額 */
	public Components\Amount $amount;

	/** @var 'en' | 'zh-TW' 語言 (6) */
	public string $language;

	/** @var int 設定結帳交易的逾時時間，若不設定則默认為 360, 單位：min */
	public string $expireTime;

	/** @var string *顧客付款完成之後回到特店的頁面 */
	public string $returnUrl;

	/** @var string *固定填：regular */
	public string $mode = 'regular';

	/** @var Enums\PaymentMethod[] *設定 SessionURL 上可以使用的付款方式，陣列的順序為實際在 Session URL 顯示的付款方式順序。傳入範例：["CreditCard", "VirtualAccount", "JKOPay", "ApplePay", "LinePay", "ChaileaseBNPL"] */
	public array $allowPaymentMethodList;

	/** @var Components\PaymentMethodOptions 設定不同付款方式的資訊。Applepay 和 LINE Pay 暫不支援設定 */
	public Components\PaymentMethodOptions $paymentMethodOptions;

	/** @var Components\Order\Order 訂單資訊 */
	public Components\Order\Order $order;

	/** @var Components\Billing 帳單資訊 */
	public Components\Billing $billing;

	/** @var Components\Customer 客戶資訊 */
	public Components\Customer $customer;

	/** @var Components\Client 客戶端資訊 */
	public Components\Client $client;

	/** @var array<string, string|int> 原始資料 */
	protected array $dto_data = [];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 *  @param \WC_Order              $order 訂單
	 *  @param AbstractPaymentGateway $gateway 付款方式
	 */
	public static function create( \WC_Order $order, AbstractPaymentGateway $gateway ): self {

		$args = [];

		// 將 request params 存到訂單
		( new Params($order) )->save_request( $args );

		return new self($args);
	}
}
