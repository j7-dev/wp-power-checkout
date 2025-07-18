<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;

/**
 * Shopline Payment 跳轉式支付 ResponseParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/session/
 */
final class ResponseParams extends DTO {

	/** @var string *SLP 結帳交易訂單編號 (32) */
	public string $sessionId;

	/** @var string *特店訂單號 (32) */
	public string $referenceId;

	/** @var Enums\ResponseStatus::value *結帳交易狀態 (16) */
	public string $status;

	/** @var string *結帳交易提供給顧客付款的 URL (256) */
	public string $sessionUrl;

	/** @var int *訂單建立時間 timestamp 13位毫秒 */
	public int $createTime;

	/** @var Components\Amount *商品金額 */
	public Components\Amount $amount;

	/** @var Components\PaymentDetail[] 付款方式詳細資訊 */
	public array $paymentDetails;

	/**
	 * 創建實例
	 *
	 * @param array $args 參數
	 * @return self 實例
	 */
	public static function create( array $args ): self {
		$args['amount'] = Components\Amount::parse( $args['amount'] );
		if ( isset( $args['paymentDetails'] ) ) {
			$args['paymentDetails'] = array_map( fn( $payment_detail ) => Components\PaymentDetail::parse( $payment_detail ), $args['paymentDetails'] );
		}

		return new self($args);
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	public function validate(): void {
		parent::validate();
		Enums\ResponseStatus::from( $this->status );
	}
}
