<?php
/**
 * PAYUNi UNi Embed V3 專用 Order Meta Key 存取（HPOS 相容）
 *
 * 設計比照 Payuni\Shared\Helpers\PayuniMetaKeys，但所有 meta key 前綴一律改為
 * `_pc_payuni_uni_`（UPP 為 `_pc_payuni_`），確保兩個 gateway 的 order meta 完全隔離，
 * 即使以 `get_orders([meta_key => '_pc_payuni_'])` 查詢也不會誤撈 UNi Embed 資料。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * 6 個 meta key（對齊 specs/open-issue/payuni-uni-embed-execution-plan.md §Phase 02）：
 *  - _pc_payuni_uni_trade_no       MerTradeNo（冪等鍵，merchant_trade 階段寫入，NotifyURL 反查主鍵）
 *  - _pc_payuni_uni_sdk_token      token_get 取得的 SDK_TOKEN（10 分鐘有效，供前端 SDK + merchant_trade 共用）
 *  - _pc_payuni_uni_payment_detail merchant_trade / NotifyURL 解密後授權結果（含 TradeNo / Gateway=9 / PaymentType=1）
 *  - _pc_payuni_uni_capture_status 信用卡請款 / 取消授權狀態（''｜'captured'｜'voided'｜'refunded'）
 *  - _pc_payuni_uni_credit_hash    買方 Token Hash（CreditHash，⚠️ 僅存 hash，絕不存卡號 / CVC）
 *  - _pc_payuni_uni_credit_life    買方 Token 有效日期（CreditLife，MMYY 格式）
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers;

/** PAYUNi UNi Embed V3 Order Meta Key 存取 Helper */
final class PayuniUniEmbedMetaKeys {

	/** @var string MerTradeNo（冪等鍵） */
	public const TRADE_NO = '_pc_payuni_uni_trade_no';

	/** @var string token_get 取得的 SDK_TOKEN（10 分鐘有效，供前端 SDK） */
	public const SDK_TOKEN = '_pc_payuni_uni_sdk_token';

	/** @var string 授權結果明細（merchant_trade / NotifyURL 解密後） */
	public const PAYMENT_DETAIL = '_pc_payuni_uni_payment_detail';

	/** @var string 請款 / 取消授權狀態機（''｜'captured'｜'voided'｜'refunded'） */
	public const CAPTURE_STATUS = '_pc_payuni_uni_capture_status';

	/** @var string 買方 Token Hash（CreditHash，⚠️ 僅存 hash，絕不存卡號 / CVC） */
	public const CREDIT_HASH = '_pc_payuni_uni_credit_hash';

	/** @var string 買方 Token 有效日期（CreditLife，MMYY） */
	public const CREDIT_LIFE = '_pc_payuni_uni_credit_life';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $order,
	) {}

	/** @return string 取得 MerTradeNo（未設定回空字串） */
	public function get_trade_no(): string {
		return (string) ( $this->order->get_meta( self::TRADE_NO ) ?: '' );
	}

	/**
	 * 儲存 MerTradeNo（冪等鍵）
	 *
	 * @param string $value MerTradeNo
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->order->update_meta_data( self::TRADE_NO, $value );
		$this->order->save_meta_data();
	}

	/** @return string 取得 SDK_TOKEN（未設定回空字串） */
	public function get_sdk_token(): string {
		return (string) ( $this->order->get_meta( self::SDK_TOKEN ) ?: '' );
	}

	/**
	 * 儲存 SDK_TOKEN（token_get 成功後寫入，供前端 SDK 收卡）
	 *
	 * @param string $value SDK_TOKEN
	 * @return void
	 */
	public function update_sdk_token( string $value ): void {
		$this->order->update_meta_data( self::SDK_TOKEN, $value );
		$this->order->save_meta_data();
	}

	/** @return array<string, mixed> 取得授權結果明細（未設定回空陣列） */
	public function get_payment_detail(): array {
		$value = $this->order->get_meta( self::PAYMENT_DETAIL ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存授權結果明細
	 *
	 * @param array<string, mixed> $value 授權結果明細（PAYUNi 回傳）
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->order->update_meta_data( self::PAYMENT_DETAIL, $value );
		$this->order->save_meta_data();
	}

	/** @return string 取得請款 / 取消授權狀態（未設定回空字串） */
	public function get_capture_status(): string {
		return (string) ( $this->order->get_meta( self::CAPTURE_STATUS ) ?: '' );
	}

	/**
	 * 儲存請款 / 取消授權狀態機
	 *
	 * 值集：''（未請款）｜'captured' 已請款｜'voided' 已取消授權｜'refunded' 已退款。
	 *
	 * @param string $value 狀態機值
	 * @return void
	 */
	public function update_capture_status( string $value ): void {
		$this->order->update_meta_data( self::CAPTURE_STATUS, $value );
		$this->order->save_meta_data();
	}

	/** @return string 取得買方 Token Hash（未設定回空字串） */
	public function get_credit_hash(): string {
		return (string) ( $this->order->get_meta( self::CREDIT_HASH ) ?: '' );
	}

	/**
	 * 儲存買方 Token Hash（CreditHash）
	 *
	 * ⚠️ 僅允許儲存 PAYUNi 回傳的 CreditHash（壓碼後的 Token），絕不存卡號 / CVC。
	 *
	 * @param string $value CreditHash
	 * @return void
	 */
	public function update_credit_hash( string $value ): void {
		$this->order->update_meta_data( self::CREDIT_HASH, $value );
		$this->order->save_meta_data();
	}

	/** @return string 取得買方 Token 有效日期（未設定回空字串） */
	public function get_credit_life(): string {
		return (string) ( $this->order->get_meta( self::CREDIT_LIFE ) ?: '' );
	}

	/**
	 * 儲存買方 Token 有效日期（CreditLife，MMYY）
	 *
	 * @param string $value CreditLife（MMYY 格式）
	 * @return void
	 */
	public function update_credit_life( string $value ): void {
		$this->order->update_meta_data( self::CREDIT_LIFE, $value );
		$this->order->save_meta_data();
	}

	/**
	 * 以 MerTradeNo 反查訂單
	 *
	 * @param string $trade_no MerTradeNo
	 * @return \WC_Order|null 找不到回 null
	 */
	public static function get_order_by_trade_no( string $trade_no ): \WC_Order|null {
		if ( '' === $trade_no ) {
			return null;
		}

		$args = [
			'limit'      => 1,
			'meta_key'   => self::TRADE_NO, // phpcs:ignore
			'meta_value' => $trade_no,      // phpcs:ignore
		];

		$orders = \wc_get_orders( $args );
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}
}
