<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Session\Create;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\Shared\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums\PaymentMethod;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Settings;

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

	/** @var array<PaymentMethod::value> *設定 SessionURL 上可以使用的付款方式，陣列的順序為實際在 Session URL 顯示的付款方式順序。傳入範例：["CreditCard", "VirtualAccount", "JKOPay", "ApplePay", "LinePay", "ChaileaseBNPL"] */
	public array $allowPaymentMethodList;

	/** @var Components\PaymentMethodOptions 設定不同付款方式的資訊。Applepay 和 LINE Pay 暫不支援設定 */
	public Components\PaymentMethodOptions $paymentMethodOptions;

	/** @var Components\Order\Order *訂單資訊 */
	public Components\Order\Order $order;

	/** @var Components\Billing *帳單資訊 */
	public Components\Billing $billing;

	/** @var Components\Customer *客戶資訊 */
	public Components\Customer $customer;

	/** @var Components\Client 客戶端資訊 */
	public Components\Client $client;

	/** @var array<string, string|int> 原始資料 */
	protected array $require_properties = [
		'referenceId',
		'amount',
		'returnUrl',
		'mode',
		'allowPaymentMethodList',
		'order',
		'billing',
		'customer',
		'client',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 *  @param AbstractPaymentGateway $gateway 付款方式
	 *  @param \WC_Order              $order 訂單
	 */
	public static function create( AbstractPaymentGateway $gateway, \WC_Order $order, ): self {
		$settings = Settings::instance();
		$total    = $order->get_total();
		$args     = [
			'referenceId'            => $order->get_id(),
			'amount'                 => Components\Amount::create( (float) $total ),
			'language'               => \get_locale() === 'zh_TW' ? 'zh-TW' : 'en',
			// 'expireTime'             => 360,
			'returnUrl'              => $gateway->get_return_url( $order ),
			'allowPaymentMethodList' => $settings->allowPaymentMethodList,
			// 'paymentMethodOptions'   =>  $settings->paymentMethodOptions,
			'order'                  => Components\Order\Order::create( $order ),
			'billing'                => Components\Billing::create( $order ),
			'customer'               => Components\Customer::create( $order ),
			'client'                 => Components\Client::create( $order ),
		];
		return new self($args);
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	public function validate(): void {
		parent::validate();
		foreach ( $this->allowPaymentMethodList as $payment_method ) {
			PaymentMethod::from( $payment_method );
		}
	}
}
