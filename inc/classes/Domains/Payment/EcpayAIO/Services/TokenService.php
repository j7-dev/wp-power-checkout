<?php
/**
 * 綠界 AIO 記憶卡號（Token 綁卡）服務
 *
 * 負責：
 *  1. 結帳時依「登入會員 + 勾選記憶卡號」決定是否帶 BindingCard=1 + MerchantMemberID 建單。
 *  2. 綠界 ReturnURL 回傳綁卡資訊（CardID / Card4No）後，存為 WC_Payment_Token_CC（綁該 user）。
 *  3. 提供回購時以 CardID 幕後扣款（搭配 BackgroundChargeClient）。
 *
 * 資料流（Source: ECPay-API-Skill guides/01-payment-aio.md §記憶卡號、guides/03-payment-backend.md §11/§16）：
 *   勾選記憶卡號 → BindingCard=1 + MerchantMemberID(MerchantID+會員編號) → 綠界回 CardID/Card6No/Card4No
 *   → WC_Payment_Token_CC（token=CardID, last4=Card4No）→ 回購用 CardID 呼叫 CreatePaymentWithCardID 幕後扣款。
 *
 * ⚠️ 記憶卡號需會員系統（訪客 customer_id=0 不綁卡），且僅支援 Visa/MasterCard/JCB。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Services;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayPaymentType;

/** 綠界 AIO 記憶卡號（Token 綁卡）服務 */
final class TokenService {

	/** @var string order meta：顧客是否勾選記憶卡號（'yes' 表示勾選） */
	private const BIND_CARD_META_KEY = '_pc_ecpay_bind_card';

	/** @var string WC payment token meta：MerchantMemberID（回購幕後扣款用） */
	private const TOKEN_MEMBER_ID_META = 'pc_ecpay_member_id';

	/** @var string order meta：建單時使用的 MerchantMemberID（綁卡成功後寫入 token） */
	private const MEMBER_ID_USED_META = '_pc_ecpay_member_id_used';

	/** @var int MerchantMemberID 最大長度（綠界 String(30)） */
	private const MEMBER_ID_MAX_LEN = 30;

	/**
	 * 結帳時套用記憶卡號參數（BindingCard + MerchantMemberID）
	 *
	 * 僅登入會員（customer_id > 0）且勾選記憶卡號時才綁卡；訪客或未勾選維持原樣。
	 *
	 * @param array<string, mixed> $data        已組裝的建單資料
	 * @param \WC_Order            $order       訂單
	 * @param string               $merchant_id 綠界特店編號（MerchantMemberID 前綴）
	 * @return array<string, mixed>
	 */
	public static function apply_binding( array $data, \WC_Order $order, string $merchant_id ): array {
		if ( ! self::should_bind_card( $order ) ) {
			return $data;
		}

		$member_id                = self::get_merchant_member_id( $order, $merchant_id );
		$data['BindingCard']      = 1;
		$data['MerchantMemberID'] = $member_id;

		// 記住建單時的 MerchantMemberID，綁卡成功後寫入 token 供回購幕後扣款
		$order->update_meta_data( self::MEMBER_ID_USED_META, $member_id );
		$order->save_meta_data();

		return $data;
	}

	/**
	 * 是否應綁卡（登入會員 + 勾選記憶卡號）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function should_bind_card( \WC_Order $order ): bool {
		// 記憶卡號需會員系統：訪客（customer_id=0）不綁卡
		if ( $order->get_customer_id() <= 0 ) {
			return false;
		}
		return 'yes' === (string) $order->get_meta( self::BIND_CARD_META_KEY );
	}

	/**
	 * 取得 MerchantMemberID（MerchantID + 會員編號，≤30）
	 *
	 * @param \WC_Order $order       訂單
	 * @param string    $merchant_id 綠界特店編號
	 * @return string
	 */
	public static function get_merchant_member_id( \WC_Order $order, string $merchant_id ): string {
		$member_id = $merchant_id . $order->get_customer_id();
		return \substr( $member_id, 0, self::MEMBER_ID_MAX_LEN );
	}

	/**
	 * 由綠界 ReturnURL 通知 payload 儲存 WC_Payment_Token_CC
	 *
	 * 條件：付款成功（RtnCode='1'）+ 信用卡類 + 有 CardID + 訂單綁該 user。
	 * 冪等：同一 user + 同 CardID 已存在則不重複建立。
	 *
	 * @param \WC_Order            $order   訂單
	 * @param array<string, mixed> $payload ReturnURL 通知參數（容錯扁平 / 巢狀 CardInfo）
	 * @return void
	 */
	public static function save_token_from_payload( \WC_Order $order, array $payload ): void {
		$user_id = $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// 僅付款成功才存
		if ( '1' !== (string) ( $payload['RtnCode'] ?? '' ) ) {
			return;
		}

		// 僅信用卡類（非信用卡無記憶卡號）
		$payment_type = (string) ( $payload['PaymentType'] ?? '' );
		if ( '' !== $payment_type && ! EcpayPaymentType::is_credit( $payment_type ) ) {
			return;
		}

		$card_id = self::extract( $payload, 'CardID' );
		if ( '' === $card_id ) {
			return; // 無 CardID 不存（可能未勾選記憶卡號或綠界未回傳）
		}

		$gateway_id = $order->get_payment_method();

		// 冪等：同 user 同 CardID 已存在則 skip
		foreach ( \WC_Payment_Tokens::get_customer_tokens( $user_id, $gateway_id ) as $existing ) {
			if ( $existing->get_token() === $card_id ) {
				return;
			}
		}

		$last4 = self::extract( $payload, 'Card4No' );
		$card6 = self::extract( $payload, 'Card6No' );

		$token = new \WC_Payment_Token_CC();
		$token->set_token( $card_id );
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( $user_id );
		$token->set_last4( $last4 ?: '0000' );
		$token->set_card_type( 'credit' );
		// 綠界記憶卡號 ReturnURL 不一定回有效期；以遠期占位，避免 WC 視為過期卡（實際扣款由綠界驗證）
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) \gmdate( 'Y' ) + 5 ) );
		$token->add_meta_data( 'card6no', $card6, true );
		// MerchantMemberID 供回購幕後扣款（CreatePaymentWithCardID）使用
		$token->add_meta_data( self::TOKEN_MEMBER_ID_META, (string) ( $order->get_meta( self::MEMBER_ID_USED_META ) ?: '' ), true );
		$token->save();
	}

	/**
	 * 由 payload 取出欄位，容錯扁平（AIO ReturnURL）與巢狀 CardInfo（幕後授權 callback）
	 *
	 * @param array<string, mixed> $payload payload
	 * @param string               $key     欄位名（CardID / Card4No / Card6No）
	 * @return string
	 */
	private static function extract( array $payload, string $key ): string {
		if ( isset( $payload[ $key ] ) && ( \is_string( $payload[ $key ] ) || \is_numeric( $payload[ $key ] ) ) ) {
			return (string) $payload[ $key ];
		}

		$card_info = $payload['CardInfo'] ?? [];
		if ( \is_array( $card_info ) && isset( $card_info[ $key ] ) && ( \is_string( $card_info[ $key ] ) || \is_numeric( $card_info[ $key ] ) ) ) {
			return (string) $card_info[ $key ];
		}

		return '';
	}
}
