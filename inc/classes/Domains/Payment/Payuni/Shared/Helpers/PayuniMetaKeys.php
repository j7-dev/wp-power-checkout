<?php
/**
 * PAYUNi UPP V2 專用 Order Meta Key 存取（HPOS 相容）
 *
 * 設計比照 NewebpayMpg\Shared\Helpers\MpgMetaKeys，但 PAYUNi 的冪等鍵為交易單號
 * MerTradeNo（存 _pc_payuni_trade_no），語意與綠界 / 藍新 / SLP 不同，故獨立 helper。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * Meta keys（對齊 CLAUDE.md §Order Meta Keys 規劃）：
 *  - _pc_payuni_trade_no       MerTradeNo（冪等鍵，建單時寫入，反查訂單主鍵）
 *  - _pc_payuni_payment_detail 付款結果明細（NotifyURL / ReturnURL 解密後的回傳）
 *  - _pc_payuni_payment_info   offline 取號繳費資訊（ATM 虛擬帳號 / CVS 繳費代碼 / ExpireDate）
 *  - _pc_payuni_capture_status 請退款 / 取消授權狀態機（''｜'captured'｜'voided'｜'refunded'）
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers;

/** PAYUNi UPP V2 Order Meta Key 存取 Helper */
final class PayuniMetaKeys {

	/** @var string MerTradeNo（冪等鍵） */
	private const TRADE_NO_KEY = '_pc_payuni_trade_no';

	/** @var string 付款結果明細 */
	private const PAYMENT_DETAIL_KEY = '_pc_payuni_payment_detail';

	/** @var string offline 取號繳費資訊 */
	private const PAYMENT_INFO_KEY = '_pc_payuni_payment_info';

	/** @var string 請退款 / 取消授權狀態機（''｜'captured'｜'voided'｜'refunded'） */
	private const CAPTURE_STATUS_KEY = '_pc_payuni_capture_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得 MerTradeNo（未設定回空字串） */
	public function get_trade_no(): string {
		return (string) ( $this->_order->get_meta( self::TRADE_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存 MerTradeNo（冪等鍵）
	 *
	 * @param string $value MerTradeNo
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得付款結果明細（未設定回空陣列） */
	public function get_payment_detail(): array {
		$value = $this->_order->get_meta( self::PAYMENT_DETAIL_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存付款結果明細
	 *
	 * @param array<string, mixed> $value 付款結果明細（PAYUNi 回傳）
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得 offline 取號繳費資訊（未設定回空陣列） */
	public function get_payment_info(): array {
		$value = $this->_order->get_meta( self::PAYMENT_INFO_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存 offline 取號繳費資訊
	 *
	 * @param array<string, mixed> $value 取號繳費資訊（BankCode / VAccount / ExpireDate 等）
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_INFO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得請退款 / 取消授權狀態（未設定回空字串） */
	public function get_capture_status(): string {
		return (string) ( $this->_order->get_meta( self::CAPTURE_STATUS_KEY ) ?: '' );
	}

	/**
	 * 儲存請退款 / 取消授權狀態機
	 *
	 * 值集：''（未請款）｜'captured' 已請款｜'voided' 已取消授權｜'refunded' 已退款。
	 *
	 * @param string $value 狀態機值（''｜'captured'｜'voided'｜'refunded'）
	 * @return void
	 */
	public function update_capture_status( string $value ): void {
		$this->_order->update_meta_data( self::CAPTURE_STATUS_KEY, $value );
		$this->_order->save_meta_data();
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
			'meta_key'   => self::TRADE_NO_KEY, // phpcs:ignore
			'meta_value' => $trade_no,          // phpcs:ignore
		];

		$orders = \wc_get_orders( $args );
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}
}
