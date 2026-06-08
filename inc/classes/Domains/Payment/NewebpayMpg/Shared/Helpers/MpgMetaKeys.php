<?php
/**
 * 藍新 NewebPay MPG 專用 Order Meta Key 存取（HPOS 相容）
 *
 * 設計比照 EcpayAIO\Shared\Helpers\EcpayMetaKeys，但藍新有專屬冪等鍵（MerchantOrderNo，存 _pc_newebpay_order_no）
 * 與藍新交易編號（TradeNo，存 _pc_newebpay_trade_no），語意與綠界 / SLP 不同，故獨立 helper。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * Meta keys（對齊 CLAUDE.md Order Meta Keys 規劃）：
 *  - _pc_newebpay_order_no       MerchantOrderNo（冪等鍵，建單時寫入，反查訂單主鍵）
 *  - _pc_newebpay_trade_no       藍新 TradeNo（callback / 退款用，藍新回傳）
 *  - _pc_newebpay_payment_detail 付款結果明細（NotifyURL / ReturnURL 解密後的 Result）
 *  - _pc_newebpay_payment_info   offline 取號繳費資訊（CodeNo/BankCode/Barcode_1~3/ExpireDate）
 *  - _pc_newebpay_credit_variant 信用卡變體（''｜'installment'｜'period'）
 *  - _pc_newebpay_installment    信用卡分期期數（如 '6'）
 *  - _pc_newebpay_capture_status 請款 / 授權狀態（''｜'captured'｜'voided'）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

/** 藍新 MPG Order Meta Key 存取 Helper */
final class MpgMetaKeys {

	/** @var string MerchantOrderNo（冪等鍵） */
	private const ORDER_NO_KEY = '_pc_newebpay_order_no';

	/** @var string 藍新 TradeNo（藍新交易編號） */
	private const TRADE_NO_KEY = '_pc_newebpay_trade_no';

	/** @var string 付款結果明細 */
	private const PAYMENT_DETAIL_KEY = '_pc_newebpay_payment_detail';

	/** @var string offline 取號繳費資訊 */
	private const PAYMENT_INFO_KEY = '_pc_newebpay_payment_info';

	/** @var string 信用卡付款變體（''｜'installment'｜'period'） */
	private const CREDIT_VARIANT_KEY = '_pc_newebpay_credit_variant';

	/** @var string 信用卡分期期數 */
	private const INSTALLMENT_KEY = '_pc_newebpay_installment';

	/** @var string 信用卡請款 / 授權狀態（''｜'captured'｜'voided'） */
	private const CAPTURE_STATUS_KEY = '_pc_newebpay_capture_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得 MerchantOrderNo */
	public function get_order_no(): string {
		return (string) ( $this->_order->get_meta( self::ORDER_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存 MerchantOrderNo（冪等鍵）
	 *
	 * @param string $value MerchantOrderNo
	 * @return void
	 */
	public function update_order_no( string $value ): void {
		$this->_order->update_meta_data( self::ORDER_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得藍新 TradeNo */
	public function get_trade_no(): string {
		return (string) ( $this->_order->get_meta( self::TRADE_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存藍新 TradeNo
	 *
	 * @param string $value 藍新 TradeNo
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得付款結果明細 */
	public function get_payment_detail(): array {
		$value = $this->_order->get_meta( self::PAYMENT_DETAIL_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存付款結果明細
	 *
	 * @param array<string, mixed> $value 付款結果明細（藍新 Result）
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得 offline 取號繳費資訊 */
	public function get_payment_info(): array {
		$value = $this->_order->get_meta( self::PAYMENT_INFO_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存 offline 取號繳費資訊
	 *
	 * @param array<string, mixed> $value 取號繳費資訊（CodeNo/BankCode/Barcode_1~3/ExpireDate 等）
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_INFO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得信用卡付款變體（''｜'installment'｜'period'） */
	public function get_credit_variant(): string {
		return (string) ( $this->_order->get_meta( self::CREDIT_VARIANT_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡付款變體
	 *
	 * @param string $value ''｜'installment'｜'period'
	 * @return void
	 */
	public function update_credit_variant( string $value ): void {
		$this->_order->update_meta_data( self::CREDIT_VARIANT_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得信用卡分期期數（如 '6'；無則空字串） */
	public function get_installment(): string {
		return (string) ( $this->_order->get_meta( self::INSTALLMENT_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡分期期數
	 *
	 * @param string $value 期數（如 '6'）
	 * @return void
	 */
	public function update_installment( string $value ): void {
		$this->_order->update_meta_data( self::INSTALLMENT_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得信用卡請款 / 授權狀態（''｜'captured'｜'voided'） */
	public function get_capture_status(): string {
		return (string) ( $this->_order->get_meta( self::CAPTURE_STATUS_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡請款 / 授權狀態
	 *
	 * @param string $value ''｜'captured' 已請款｜'voided' 已取消授權
	 * @return void
	 */
	public function update_capture_status( string $value ): void {
		$this->_order->update_meta_data( self::CAPTURE_STATUS_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * 以 MerchantOrderNo 反查訂單
	 *
	 * @param string $order_no MerchantOrderNo
	 * @return \WC_Order|null
	 */
	public static function get_order_by_order_no( string $order_no ): \WC_Order|null {
		if ( '' === $order_no ) {
			return null;
		}

		$args = [
			'limit'      => 1,
			'meta_key'   => self::ORDER_NO_KEY, // phpcs:ignore
			'meta_value' => $order_no,          // phpcs:ignore
		];

		$orders = \wc_get_orders( $args );
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}
}
