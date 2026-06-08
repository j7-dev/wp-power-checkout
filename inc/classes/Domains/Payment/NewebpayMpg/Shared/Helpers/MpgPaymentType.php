<?php
/**
 * 藍新 NewebPay MPG 付款方式（PaymentType）判定 + 退款分流 Helper
 *
 * ⚠️ 資安：退款分流的依據是藍新「回傳並存於訂單 meta」的 PaymentType（_pc_newebpay_payment_detail
 *    的 Result.PaymentType），絕非前端傳入值。藍新於 NotifyURL / ReturnURL 回傳 PaymentType，
 *    由 StatusManager 寫入 meta，本 helper 一律由此 meta 讀取。
 *
 * 藍新 PaymentType 回覆值為單一 token（無底線，與綠界 Credit_CreditCard 格式不同）：
 *  - 信用卡（DoAction Close CloseType=2 退款）：CREDIT
 *  - e-wallet（/API/EWallet/refund 退款）：LINEPAY / TAIWANPAY / ESUNWALLET
 *  - BNPL（本期 defer，退款回 unsupported）：AFTEE
 *  - 無 API 退款（須藍新後台人工）：VACC / WEBATM / CVS / BARCODE / APPLEPAY / TWQR 等
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §PaymentType
 * @see .claude/skills/newebpay-mpg/references/backend-apis.md §E-Wallet Refund / Credit Card Close
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

/** 藍新 MPG PaymentType 判定 + 退款分流 */
final class MpgPaymentType {

	/** @var string 信用卡 PaymentType */
	public const CREDIT = 'CREDIT';

	/** @var string AFTEE BNPL PaymentType（本期 defer） */
	public const AFTEE = 'AFTEE';

	/** @var array<string> e-wallet PaymentType（走 /API/EWallet/refund） */
	private const EWALLET_TYPES = [ 'LINEPAY', 'TAIWANPAY', 'ESUNWALLET' ];

	/**
	 * 由訂單 meta 取得藍新回傳的 PaymentType
	 *
	 * @param \WC_Order $order 訂單
	 * @return string PaymentType（無則回空字串）
	 */
	public static function from_order( \WC_Order $order ): string {
		$detail = ( new MpgMetaKeys( $order ) )->get_payment_detail();
		return self::extract( $detail, 'PaymentType' );
	}

	/**
	 * 由付款明細取出指定欄位，容錯扁平與巢狀 Result 結構
	 *
	 * 藍新 callback 解密後通常為 { Status, Message, Result: {...} }，本外掛將整個 Result 存入 meta，
	 * 故扁平讀取即可；仍容錯巢狀 Result（若呼叫端存了整包）。
	 *
	 * @param array<string, mixed> $detail 付款明細
	 * @param string               $key    欄位名（PaymentType / TradeNo）
	 * @return string
	 */
	private static function extract( array $detail, string $key ): string {
		if ( isset( $detail[ $key ] ) && ( \is_string( $detail[ $key ] ) || \is_numeric( $detail[ $key ] ) ) ) {
			return (string) $detail[ $key ];
		}

		$result = $detail['Result'] ?? [];
		if ( \is_array( $result ) && isset( $result[ $key ] ) && ( \is_string( $result[ $key ] ) || \is_numeric( $result[ $key ] ) ) ) {
			return (string) $result[ $key ];
		}

		return '';
	}

	/**
	 * 是否為信用卡（可呼叫 DoAction Close CloseType=2 API 退款）
	 *
	 * @param string $payment_type 藍新 PaymentType
	 * @return bool
	 */
	public static function is_credit( string $payment_type ): bool {
		return self::CREDIT === $payment_type;
	}

	/**
	 * 是否為 e-wallet（走 /API/EWallet/refund）
	 *
	 * @param string $payment_type 藍新 PaymentType
	 * @return bool
	 */
	public static function is_ewallet( string $payment_type ): bool {
		return \in_array( $payment_type, self::EWALLET_TYPES, true );
	}

	/**
	 * 訂單是否為信用卡（依 meta PaymentType）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function order_is_credit( \WC_Order $order ): bool {
		return self::is_credit( self::from_order( $order ) );
	}

	/**
	 * 訂單是否為 e-wallet（依 meta PaymentType）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function order_is_ewallet( \WC_Order $order ): bool {
		return self::is_ewallet( self::from_order( $order ) );
	}

	/**
	 * 取得藍新交易編號 TradeNo（退款 / 請款 / 取消授權必填，須為藍新回傳值非 MerchantOrderNo）
	 *
	 * 優先讀 _pc_newebpay_trade_no（StatusManager 寫入的專屬欄位），
	 * 退而求其次由付款明細 Result.TradeNo 取得。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string TradeNo（無則回空字串）
	 */
	public static function get_trade_no( \WC_Order $order ): string {
		$meta_keys = new MpgMetaKeys( $order );
		$trade_no  = $meta_keys->get_trade_no();
		if ( '' !== $trade_no ) {
			return $trade_no;
		}

		return self::extract( $meta_keys->get_payment_detail(), 'TradeNo' );
	}
}
