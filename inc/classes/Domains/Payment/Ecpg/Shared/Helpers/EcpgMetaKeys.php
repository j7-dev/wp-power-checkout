<?php
/**
 * 綠界站內付 2.0（ECPG）專屬 Order Meta Key 存取
 *
 * 站內付 2.0 比信用卡額外需要記錄「顧客選擇的付款方式」（Credit / ATM / CVS / BARCODE），
 * 以便 before_process_payment 分流（信用卡走前端 JS SDK；非信用卡走後端幕後取號）。
 *
 * 此語意為 ECPG 子域專屬，故獨立於 Ecpg 子域內，不污染 EcpayAIO 的共用 EcpayMetaKeys。
 * 一律透過 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;

/** 綠界站內付 2.0 專屬 Order Meta Key 存取 */
final class EcpgMetaKeys {

	/** @var string 站內付 2.0 顧客選擇的付款方式（EcpayPaymentMethod::value，如 Credit/ATM/CVS/BARCODE） */
	private const PAYMENT_KEY = '_pc_ecpay_ecpg_payment';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/**
	 * 取得站內付 2.0 顧客選擇的付款方式
	 *
	 * 預設回 EcpayPaymentMethod::CREDIT：未明確指定時沿用既有信用卡 SDK 流程（向後相容）。
	 *
	 * @return EcpayPaymentMethod
	 */
	public function get_payment(): EcpayPaymentMethod {
		$value = (string) ( $this->_order->get_meta( self::PAYMENT_KEY ) ?: '' );
		if ( '' === $value ) {
			return EcpayPaymentMethod::CREDIT;
		}
		return EcpayPaymentMethod::tryFrom( $value ) ?? EcpayPaymentMethod::CREDIT;
	}

	/**
	 * 儲存站內付 2.0 顧客選擇的付款方式
	 *
	 * @param EcpayPaymentMethod|string $payment 付款方式（enum 或 EcpayPaymentMethod::value）
	 * @return void
	 */
	public function update_payment( EcpayPaymentMethod|string $payment ): void {
		$value = $payment instanceof EcpayPaymentMethod ? $payment->value : $payment;
		$this->_order->update_meta_data( self::PAYMENT_KEY, $value );
		$this->_order->save_meta_data();
	}
}
