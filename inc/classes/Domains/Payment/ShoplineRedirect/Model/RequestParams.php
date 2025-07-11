<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Utils\Base as Utils;
use J7\PowerCheckout\Utils\Helper;
use J7\PowerCheckout\Domains\Payment\Ecpay\Core\Service;
use J7\PowerCheckout\Domains\Payment\Ecpay\Utils\Base as EcpayUtils;
use J7\PowerCheckout\Domains\Payment\AbstractPaymentGateway;
use J7\PowerCheckout\Utils\Order as OrderUtils;

/**
 * Shopline Payment 跳轉式支付 RequestParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/session/
 */
final class RequestParams extends DTO {

	use ParamsTrait; // 共用屬性

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

	/** @var array<string> *設定 SessionURL 上可以使用的付款方式，陣列的順序為實際在 Session URL 顯示的付款方式順序。傳入範例：["CreditCard", "VirtualAccount", "JKOPay", "ApplePay", "LinePay", "ChaileaseBNPL"] */
	public array $allowPaymentMethodList = [];

	/** @var array<string> *不允許的付款方式 */
	public array $denyPaymentMethodList = [];

	/** @var array 設定不同付款方式的資訊。Applepay 和 LINE Pay 暫不支援設定 */
	public $paymentMethodOptions;


	public $order;


	/** @var array<string, string|int> 原始資料 */
	protected array $dto_data = [];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 *  @param \WC_Order              $order 訂單
	 *  @param AbstractPaymentGateway $gateway 付款方式
	 */
	public static function instance( \WC_Order $order, AbstractPaymentGateway $gateway ): self {
		$notify_url = urldecode(\site_url('wp-json/power-checkout/ecpay-aio', 'https'));

		$return_url = urldecode($gateway->get_return_url($order));
		$service    = Service::instance();

		$default_args = [
			'MerchantID'        => $service->merchant_id,
			'MerchantTradeNo'   => EcpayUtils::encode_trade_no( $order->get_id() ),
			'MerchantTradeDate' => ( new \DateTime('now', new \DateTimeZone('Asia/Taipei')) )->format('Y/m/d H:i:s'),
			'TotalAmount'       => (int) ceil( (float) $order->get_total()), // 無條件進位
			'TradeDesc'         => \get_bloginfo('name'),
			'ItemName'          => EcpayUtils::get_item_name($order),
			'ReturnURL'         => $notify_url,
			'ChoosePayment'     => $gateway->payment_type,
			'ClientBackURL'     => $return_url,
			'OrderResultURL'    => $return_url,
			'PaymentInfoURL'    => $notify_url,
			'ClientRedirectURL' => $return_url,
		];

		// 加上語言
		$language = EcpayUtils::get_language();
		if ( $language ) {
			$default_args['Language'] = $language;
		}

		$args = \wp_parse_args( $gateway->extra_request_params(), $default_args );

		// 將 request params 存到訂單
		$order->update_meta_data( OrderUtils::REQUEST_KEY, $args );
		$order->save_meta_data();

		// $args = self::add_type_info( $args, $order, $gateway );

		return new self($args);
	}

	/**
	 * 從訂單取得 request params
	 *
	 * @param \WC_Order $order
	 * @return self
	 * */
	public static function instance_from_order( \WC_Order $order ): self {
		/** @var array<string, mixed> $args */
		$args = $order->get_meta( OrderUtils::REQUEST_KEY );
		return new self( $args );
	}

	protected function after_init(): void {
		$this->add_check_value( 'sha256' );
	}

	/** 自訂驗證邏輯 */
	protected function validate(): void {

		if ('aio' !== $this->PaymentType) {
			$this->dto_error->add(
				'PaymentType',
				"PaymentType 必須為 aio, 但目前為 {$this->PaymentType}"
			);
		}

		if (Helper::include_special_char($this->MerchantTradeNo)) {
			$this->dto_error->add(
				'MerchantTradeNo',
				"MerchantTradeNo 不能包含特殊字元, 但目前為 {$this->MerchantTradeNo}"
			);
		}

		// 檢查字串長度
		if (Helper::strlen($this->MerchantTradeNo) > 20) {
			$this->dto_error->add(
				'MerchantTradeNo',
				'MerchantTradeNo 長度不能超過 20 個字, 但目前為 ' . Helper::strlen($this->MerchantTradeNo) . ' 字'
			);
		}

		if (Helper::include_special_char($this->TradeDesc)) {
			$this->dto_error->add(
				'TradeDesc',
				"TradeDesc 不能包含特殊字元, 但目前為 {$this->TradeDesc}"
			);
		}

		if (Helper::strlen($this->TradeDesc) > 200) {
			$this->dto_error->add(
				'TradeDesc',
				'TradeDesc 長度不能超過 200 個字, 但目前為 ' . Helper::strlen($this->TradeDesc) . ' 字'
			);
		}

		$payment_options = [ 'Credit', 'TWQR', 'WebATM', 'ATM', 'CVS', 'BARCODE', 'ApplePay', 'BNPL' ];
		if (!in_array($this->ChoosePayment, [ ...$payment_options, 'ALL' ])) {
			$this->dto_error->add(
				'ChoosePayment',
				'ChoosePayment 必須為 ' . implode(', ', [ ...$payment_options, 'ALL' ]) . " 其中一個, 但目前為 {$this->ChoosePayment}"
			);
		}

		if ($this->EncryptType !== 1) {
			$this->dto_error->add(
				'EncryptType',
				"EncryptType 必須為 1, 但目前為 {$this->EncryptType}"
			);
		}

		if (isset($this->NeedExtraPaidInfo)) {
			if (!in_array($this->NeedExtraPaidInfo, [ 'N', 'Y' ])) {
				$this->dto_error->add(
					'NeedExtraPaidInfo',
					"NeedExtraPaidInfo 必須為 'N' | 'Y' 其中一個, 但目前為 {$this->NeedExtraPaidInfo}"
				);
			}
		}

		if (isset($this->IgnorePayment)) {
			if (!in_array($this->IgnorePayment, $payment_options)) {
				$this->dto_error->add(
					'IgnorePayment',
					'IgnorePayment 必須為 ' . implode(', ', $payment_options) . " 其中一個, 但目前為 {$this->IgnorePayment}"
				);
			}
		}

		if (isset($this->Language)) {
			if (!in_array($this->Language, [ 'ENG', 'KOR', 'JPN', 'CHI' ])) {
				$this->dto_error->add(
					'Language',
					"Language 必須為 'ENG' | 'KOR' | 'JPN' | 'CHI' 其中一個, 但目前為 {$this->Language}"
				);
			}
		}
	}

	/**
	 * 依照不同付款方式特性，加上額外參數
	 *
	 * @param string $hash_algo 'sha256' | 'md5' 雜湊演算法
	 */
	protected function add_check_value( string $hash_algo ): void {
		$service = Service::instance();
		/** @var array<string, string|int> $args */
		$args                = $this->to_array();
		$this->CheckMacValue = $service->get_check_value( $args, $hash_algo );
	}
}
