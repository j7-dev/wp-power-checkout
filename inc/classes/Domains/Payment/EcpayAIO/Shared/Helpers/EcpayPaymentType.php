<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/**
 * 綠界付款方式（PaymentType）判定 Helper
 *
 * ⚠️ 資安：退款分流的依據是綠界「回傳並存於訂單 meta」的 PaymentType（_pc_ecpay_payment_detail），
 * 絕非前端傳入值。綠界於 ReturnURL / CreatePayment 回應內回傳 PaymentType，由 StatusManager /
 * EcpgCallback 寫入 _pc_ecpay_payment_detail，本 helper 一律由此 meta 讀取。
 *
 * 綠界 PaymentType 回覆值格式為 `{Prefix}_{Detail}`（部分無底線，如 ApplePay）：
 *  - 信用卡類（可呼叫 DoAction Action=R 退款）：
 *      Credit_CreditCard（一般）、Credit_*（分期 / 定期定額）、Flexible_Installment（永豐 30 期）
 *  - 非信用卡（無 API 退款，須綠界後台人工）：
 *      ATM_*（虛擬帳號）、CVS_*（超商代碼）、BARCODE_*（超商條碼）、WebATM_*、ApplePay
 *
 * ⚠️ ApplePay 雖底層為信用卡，但綠界 AIO 對 ApplePay 不支援 DoAction 退款（D5），故歸類為人工處理。
 *
 * @see https://developers.ecpay.com.tw/?p=2878 §PaymentType 回覆值對照
 * @see ECPay-API-Skill guides/01-payment-aio.md §PaymentType 回覆值對照（Source 2026-06）
 */
final class EcpayPaymentType {

	/**
	 * @var array<string> 可呼叫 DoAction(Action=R) API 退款的 PaymentType 前綴
	 *
	 * 信用卡一次付清 / 分期 / 定期定額皆為 Credit_*；永豐 30 期為 Flexible_*。
	 * 比對採「前綴 + 底線」或「前綴等值」，避免誤判（如不會把 WebATM 誤判為 ATM）。
	 */
	private const CREDIT_PREFIXES = [ 'Credit', 'Flexible' ];

	/**
	 * 由訂單 meta 取得綠界回傳的 PaymentType
	 *
	 * 容錯兩種結構：
	 *  - AIO（CMV）：扁平 _pc_ecpay_payment_detail['PaymentType']。
	 *  - ECPG（站內付 2.0，AES-JSON）：巢狀 _pc_ecpay_payment_detail['OrderInfo']['PaymentType']。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string PaymentType（無則回空字串）
	 */
	public static function from_order( \WC_Order $order ): string {
		$detail = ( new EcpayMetaKeys( $order ) )->get_payment_detail();
		return self::extract( $detail, 'PaymentType' );
	}

	/**
	 * 由付款明細取出指定欄位，容錯扁平（AIO）與巢狀 OrderInfo（ECPG）
	 *
	 * @param array<string, mixed> $detail 付款明細
	 * @param string               $key    欄位名（PaymentType / TradeNo）
	 * @return string
	 */
	private static function extract( array $detail, string $key ): string {
		if ( isset( $detail[ $key ] ) && ( \is_string( $detail[ $key ] ) || \is_numeric( $detail[ $key ] ) ) ) {
			return (string) $detail[ $key ];
		}

		$order_info = $detail['OrderInfo'] ?? [];
		if ( \is_array( $order_info ) && isset( $order_info[ $key ] ) && ( \is_string( $order_info[ $key ] ) || \is_numeric( $order_info[ $key ] ) ) ) {
			return (string) $order_info[ $key ];
		}

		return '';
	}

	/**
	 * 是否為信用卡類付款方式（可呼叫 DoAction Action=R API 退款）
	 *
	 * 依綠界回傳的 PaymentType 字串前綴判定，非前端可控值。
	 *
	 * @param string $payment_type 綠界 PaymentType
	 * @return bool 信用卡類回 true；ATM/CVS/BARCODE/WebATM/ApplePay 等回 false
	 */
	public static function is_credit( string $payment_type ): bool {
		if ( '' === $payment_type ) {
			return false;
		}

		foreach ( self::CREDIT_PREFIXES as $prefix ) {
			// 前綴等值（如 "Credit"）或前綴後接底線（如 "Credit_CreditCard"）
			if ( $payment_type === $prefix || \str_starts_with( $payment_type, $prefix . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 訂單是否可呼叫 API 退款（信用卡類）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function order_is_credit( \WC_Order $order ): bool {
		return self::is_credit( self::from_order( $order ) );
	}

	/**
	 * 取得綠界交易編號 TradeNo（DoAction 退款必填，須為綠界回傳值非 MerchantTradeNo）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string TradeNo（無則回空字串）
	 */
	public static function get_trade_no( \WC_Order $order ): string {
		$detail = ( new EcpayMetaKeys( $order ) )->get_payment_detail();
		return self::extract( $detail, 'TradeNo' );
	}
}
