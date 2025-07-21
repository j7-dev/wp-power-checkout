<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Webhooks\Components;

use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\Shared\Enums;
use J7\PowerCheckout\Domains\Payment\ShoplineRedirect\DTOs\Components\InstrumentCard;

/**
 * PaymentInstrument 付款指令
 *  */
final class PaymentInstrument extends DTO {

	/** @var string 付款工具 ID*/
	public string $instrumentId;

	/** @var Enums\PaymentMethod::CREDITCARD::value 付款工具類型*/
	public string $instrumentType;

	/** @var Enums\InstrumentStatus::value 付款工具狀態*/
	public string $instrumentStatus;

	/** @var InstrumentCard 付款工具資訊*/
	public InstrumentCard $instrumentCard;

	/** @var Billing 顧客帳單資訊*/
	public Billing $billing;

	/**
	 * 創建實例
	 *
	 * @param array<string, mixed> $args 原始資料
	 * @return self
	 */
	public static function create( array $args ): self {
		if ( isset( $args['instrumentCard'] ) ) {
			$args['instrumentCard'] = InstrumentCard::parse( $args['instrumentCard'] );
		}
		if ( isset( $args['billing'] ) ) {
			$args['billing'] = Billing::create( $args['billing'] );
		}
		return new self( $args );
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 */
	public function validate(): void {
		if ( isset( $this->instrumentType ) && Enums\PaymentMethod::CREDITCARD->value !== $this->instrumentType ) {
			throw new \Exception( '付款工具類型不合法' );
		}
		if ( isset( $this->instrumentStatus ) && Enums\InstrumentStatus::SUCCEEDED->value !== $this->instrumentStatus ) {
			Enums\InstrumentStatus::from( $this->instrumentStatus );
		}
	}
}
