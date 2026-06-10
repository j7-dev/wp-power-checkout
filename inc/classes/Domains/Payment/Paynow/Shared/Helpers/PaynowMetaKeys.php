<?php
/**
 * PayNow 專用 Order Meta Key 存取（HPOS 相容）
 *
 * 設計比照 PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys，但所有 meta key
 * 前綴一律為 `_pc_paynow_`，確保與其他 gateway 的 order meta 完全隔離。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * ⚠️ Webhook 反查主鍵為 PaymentIntentId（pp_xxx，存於 _pc_paynow_payment_intent_id），
 *    非 TradeNo（PCN{order_id}）。PayNow Webhook payload 帶 PaymentIntentId，
 *    故 get_order_by_payment_intent_id() 以此 meta 反查訂單。
 *
 * 6 個 meta key（對齊 CLAUDE.md §Order Meta Keys）：
 *  - _pc_paynow_trade_no          交易單號 paymentNo（冪等鍵，PCN{order_id}）
 *  - _pc_paynow_payment_intent_id PaymentIntentId（pp_xxx，⚠️ Webhook 反查主鍵）
 *  - _pc_paynow_secret            PaymentIntent secret（pp_xxx_st_xxx，供前端 SDK）
 *  - _pc_paynow_payment_detail    付款結果明細（Webhook / 查詢解析後，供後台顯示與退款分流）
 *  - _pc_paynow_payment_info      離線付款（ATM / 超商代碼）待繳資訊
 *  - _pc_paynow_refund_detail     退款結果明細
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 7
 * @see .claude/skills/paynow/references/payment-rest-api.md §10 Webhook（PaymentIntentId）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers;

/** PayNow Order Meta Key 存取 Helper */
final class PaynowMetaKeys {

	/** @var string 交易單號 paymentNo（冪等鍵，PCN{order_id}） */
	public const TRADE_NO = '_pc_paynow_trade_no';

	/** @var string PaymentIntentId（pp_xxx，⚠️ Webhook 反查主鍵） */
	public const PAYMENT_INTENT_ID = '_pc_paynow_payment_intent_id';

	/** @var string PaymentIntent secret（pp_xxx_st_xxx，供前端 SDK） */
	public const SECRET = '_pc_paynow_secret';

	/** @var string 付款結果明細（Webhook / 查詢解析後） */
	public const PAYMENT_DETAIL = '_pc_paynow_payment_detail';

	/** @var string 離線付款（ATM / 超商代碼）待繳資訊 */
	public const PAYMENT_INFO = '_pc_paynow_payment_info';

	/** @var string 退款結果明細 */
	public const REFUND_DETAIL = '_pc_paynow_refund_detail';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $order,
	) {}

	/** @return string 取得交易單號（未設定回空字串） */
	public function get_trade_no(): string {
		return (string) ( $this->order->get_meta( self::TRADE_NO ) ?: '' );
	}

	/**
	 * 儲存交易單號（冪等鍵）
	 *
	 * @param string $value 交易單號
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->order->update_meta_data( self::TRADE_NO, $value );
		$this->order->save_meta_data();
	}

	/** @return string 取得 PaymentIntentId（未設定回空字串） */
	public function get_payment_intent_id(): string {
		return (string) ( $this->order->get_meta( self::PAYMENT_INTENT_ID ) ?: '' );
	}

	/**
	 * 儲存 PaymentIntentId（⚠️ Webhook 反查主鍵）
	 *
	 * @param string $value PaymentIntentId（pp_xxx）
	 * @return void
	 */
	public function update_payment_intent_id( string $value ): void {
		$this->order->update_meta_data( self::PAYMENT_INTENT_ID, $value );
		$this->order->save_meta_data();
	}

	/** @return string 取得 PaymentIntent secret（未設定回空字串） */
	public function get_secret(): string {
		return (string) ( $this->order->get_meta( self::SECRET ) ?: '' );
	}

	/**
	 * 儲存 PaymentIntent secret（供前端 SDK）
	 *
	 * @param string $value secret（pp_xxx_st_xxx）
	 * @return void
	 */
	public function update_secret( string $value ): void {
		$this->order->update_meta_data( self::SECRET, $value );
		$this->order->save_meta_data();
	}

	/** @return array<string, mixed> 取得付款結果明細（未設定回空陣列） */
	public function get_payment_detail(): array {
		$value = $this->order->get_meta( self::PAYMENT_DETAIL ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存付款結果明細
	 *
	 * @param array<string, mixed> $value 付款結果明細
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->order->update_meta_data( self::PAYMENT_DETAIL, $value );
		$this->order->save_meta_data();
	}

	/** @return array<string, mixed> 取得離線付款待繳資訊（未設定回空陣列） */
	public function get_payment_info(): array {
		$value = $this->order->get_meta( self::PAYMENT_INFO ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存離線付款待繳資訊（ATM / 超商代碼）
	 *
	 * @param array<string, mixed> $value 待繳資訊
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->order->update_meta_data( self::PAYMENT_INFO, $value );
		$this->order->save_meta_data();
	}

	/** @return array<string, mixed> 取得退款結果明細（未設定回空陣列） */
	public function get_refund_detail(): array {
		$value = $this->order->get_meta( self::REFUND_DETAIL ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存退款結果明細
	 *
	 * @param array<string, mixed> $value 退款結果明細
	 * @return void
	 */
	public function update_refund_detail( array $value ): void {
		$this->order->update_meta_data( self::REFUND_DETAIL, $value );
		$this->order->save_meta_data();
	}

	/**
	 * 以 PaymentIntentId 反查訂單（⚠️ Webhook 反查主鍵，非 TradeNo）
	 *
	 * 空字串守衛：空字串直接回 null（不查資料庫，避免誤撈）。
	 *
	 * @param string $payment_intent_id PaymentIntentId（pp_xxx）
	 * @return \WC_Order|null 找不到回 null
	 */
	public static function get_order_by_payment_intent_id( string $payment_intent_id ): \WC_Order|null {
		if ( '' === $payment_intent_id ) {
			return null;
		}

		$args = [
			'limit'      => 1,
			'meta_key'   => self::PAYMENT_INTENT_ID, // phpcs:ignore
			'meta_value' => $payment_intent_id,       // phpcs:ignore
		];

		$orders = \wc_get_orders( $args );
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}
}
