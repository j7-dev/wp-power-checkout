<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers;

/**
 * 綠界 AIO 專用 Order Meta Key 存取
 *
 * 設計比照 Shared\Helpers\MetaKeys，但綠界有專屬的冪等鍵（MerchantTradeNo）與
 * 取號資訊（ATM/CVS/BARCODE），語意與 SLP 不同，故獨立一個 helper 避免污染共用 MetaKeys。
 * 一律透過 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 */
final class EcpayMetaKeys {

	/** @var string 綠界 MerchantTradeNo（冪等鍵，建單時寫入） */
	private const TRADE_NO_KEY = '_pc_ecpay_trade_no';

	/** @var string 付款結果明細（ReturnURL 通知寫入） */
	private const PAYMENT_DETAIL_KEY = '_pc_ecpay_payment_detail';

	/** @var string 取號繳費資訊（PaymentInfoURL 通知寫入） */
	private const PAYMENT_INFO_KEY = '_pc_ecpay_payment_info';

	/** @var string 信用卡付款變體（''｜'installment'｜'period'，checkout 顧客選擇寫入） */
	private const CREDIT_VARIANT_KEY = '_pc_ecpay_credit_variant';

	/** @var string 信用卡分期期數（顧客選擇的單一期數，如 '6'，checkout 寫入） */
	private const INSTALLMENT_KEY = '_pc_ecpay_installment';

	/** @var string 信用卡請款 / 授權狀態（''｜'captured' 已請款｜'voided' 已取消授權，DoAction C/N 寫入） */
	private const CAPTURE_STATUS_KEY = '_pc_ecpay_capture_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得 MerchantTradeNo */
	public function get_trade_no(): string {
		return (string) ( $this->_order->get_meta( self::TRADE_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存 MerchantTradeNo
	 *
	 * @param string $value MerchantTradeNo
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
	 * @param array<string, mixed> $value 付款結果明細
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得取號繳費資訊 */
	public function get_payment_info(): array {
		$value = $this->_order->get_meta( self::PAYMENT_INFO_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存取號繳費資訊
	 *
	 * @param array<string, mixed> $value 取號繳費資訊（BankCode/vAccount/PaymentNo/Barcode/ExpireDate 等）
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_INFO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * @return string 取得信用卡付款變體（''｜'installment'｜'period'）
	 */
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

	/**
	 * @return string 取得信用卡分期期數（顧客選擇的單一期數，如 '6'；無則空字串）
	 */
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

	/**
	 * @return string 取得信用卡請款 / 授權狀態（''｜'captured'｜'voided'）
	 */
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
	 * 以 MerchantTradeNo 反查訂單
	 *
	 * @param string $trade_no MerchantTradeNo
	 * @return \WC_Order|null
	 */
	public static function get_order_by_trade_no( string $trade_no ): \WC_Order|null {
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
