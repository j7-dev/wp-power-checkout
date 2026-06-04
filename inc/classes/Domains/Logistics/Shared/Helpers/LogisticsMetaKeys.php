<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Helpers;

/**
 * 綠界全方位物流 / 統一物流抽象 專用 Order Meta Key 存取
 *
 * 設計比照 Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys；統一抽象 ILogisticsProvider
 * 共用同一組 meta key（未來 PAYUNi 亦寫入相同 key）。
 * 一律透過 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * _pc_logistics_ref 為統一主鍵：貨態 callback（ServerReplyURL）以 LogisticsID 反查訂單（計畫 T6），
 * 防重以「LogisticsID + LogisticsStatus」組合碼為 key（計畫 T7）。
 */
final class LogisticsMetaKeys {

	/** @var string 使用的物流 Provider ID（ecpay_logistics） */
	private const PROVIDER_ID_KEY = '_pc_logistics_provider_id';

	/** @var string 物流子類型（FAMI/UNIMART/HILIFE/HOME，結帳選擇寫入） */
	private const SUB_TYPE_KEY = '_pc_logistics_sub_type';

	/** @var string 付款情境（online / cod，結帳選擇寫入） */
	private const PAYMENT_SCENARIO_KEY = '_pc_logistics_payment_scenario';

	/** @var string 暫存物流單號 TempLogisticsID（選店回呼寫入；成立物流單的必要憑證） */
	private const TEMP_ID_KEY = '_pc_logistics_temp_id';

	/** @var string 統一物流單號（= 綠界 LogisticsID；成立物流單後寫入；下游主鍵） */
	private const REF_KEY = '_pc_logistics_ref';

	/** @var string 選定門市代碼（超商，選店回呼寫入） */
	private const STORE_ID_KEY = '_pc_logistics_store_id';

	/** @var string 選定門市名稱（超商） */
	private const STORE_NAME_KEY = '_pc_logistics_store_name';

	/** @var string 選定門市地址（超商） */
	private const STORE_ADDR_KEY = '_pc_logistics_store_addr';

	/** @var string 物流貨態（貨態 callback 寫入綠界 LogisticsStatus 原始碼字串） */
	private const STATUS_KEY = '_pc_logistics_status';

	/** @var string C2C 寄貨編號 CVSPaymentNo（C2C 成立物流單後寫入；取消單需用） */
	private const CVS_PAYMENT_NO_KEY = '_pc_logistics_cvs_payment_no';

	/** @var string C2C 驗證碼 CVSValidationNo（C2C 成立物流單後寫入；取消單需用） */
	private const CVS_VALIDATION_NO_KEY = '_pc_logistics_cvs_validation_no';

	/** @var string COD 取貨付款完成標記（yes；取件完成貨態時寫入） */
	private const COLLECTION_PAID_KEY = '_pc_logistics_collection_paid';

	/** @var string 已處理貨態碼陣列（防重；元素格式 "{LogisticsID}:{LogisticsStatus}"） */
	private const PROCESSED_STATUS_KEY = '_pc_logistics_processed_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/**
	 * 統一字串型 meta 讀取
	 *
	 * @param string $key meta key
	 * @return string
	 */
	private function get_string( string $key ): string {
		return (string) ( $this->_order->get_meta( $key ) ?: '' );
	}

	/**
	 * 統一字串型 meta 寫入
	 *
	 * @param string $key   meta key
	 * @param string $value 值
	 * @return void
	 */
	private function update_string( string $key, string $value ): void {
		$this->_order->update_meta_data( $key, $value );
		$this->_order->save_meta_data();
	}

	// region Provider ID

	/** @return string 取得物流 Provider ID */
	public function get_provider_id(): string {
		return $this->get_string( self::PROVIDER_ID_KEY );
	}

	/**
	 * 儲存物流 Provider ID
	 *
	 * @param string $value Provider ID
	 * @return void
	 */
	public function update_provider_id( string $value ): void {
		$this->update_string( self::PROVIDER_ID_KEY, $value );
	}

	// endregion

	// region 子類型 / 付款情境

	/** @return string 取得物流子類型 */
	public function get_sub_type(): string {
		return $this->get_string( self::SUB_TYPE_KEY );
	}

	/**
	 * 儲存物流子類型
	 *
	 * @param string $value 子類型（FAMI/UNIMART/HILIFE/HOME）
	 * @return void
	 */
	public function update_sub_type( string $value ): void {
		$this->update_string( self::SUB_TYPE_KEY, $value );
	}

	/** @return string 取得付款情境 */
	public function get_payment_scenario(): string {
		return $this->get_string( self::PAYMENT_SCENARIO_KEY );
	}

	/**
	 * 儲存付款情境
	 *
	 * @param string $value 付款情境（online / cod）
	 * @return void
	 */
	public function update_payment_scenario( string $value ): void {
		$this->update_string( self::PAYMENT_SCENARIO_KEY, $value );
	}

	// endregion

	// region 暫存單號 / 統一單號

	/** @return string 取得暫存物流單號 TempLogisticsID */
	public function get_temp_id(): string {
		return $this->get_string( self::TEMP_ID_KEY );
	}

	/**
	 * 儲存暫存物流單號 TempLogisticsID
	 *
	 * @param string $value TempLogisticsID
	 * @return void
	 */
	public function update_temp_id( string $value ): void {
		$this->update_string( self::TEMP_ID_KEY, $value );
	}

	/** @return string 取得統一物流單號（LogisticsID） */
	public function get_ref(): string {
		return $this->get_string( self::REF_KEY );
	}

	/**
	 * 儲存統一物流單號（LogisticsID）
	 *
	 * @param string $value LogisticsID
	 * @return void
	 */
	public function update_ref( string $value ): void {
		$this->update_string( self::REF_KEY, $value );
	}

	// endregion

	// region 門市資訊

	/** @return string 取得選定門市代碼 */
	public function get_store_id(): string {
		return $this->get_string( self::STORE_ID_KEY );
	}

	/**
	 * 儲存選定門市代碼
	 *
	 * @param string $value 門市代碼
	 * @return void
	 */
	public function update_store_id( string $value ): void {
		$this->update_string( self::STORE_ID_KEY, $value );
	}

	/** @return string 取得選定門市名稱 */
	public function get_store_name(): string {
		return $this->get_string( self::STORE_NAME_KEY );
	}

	/**
	 * 儲存選定門市名稱
	 *
	 * @param string $value 門市名稱
	 * @return void
	 */
	public function update_store_name( string $value ): void {
		$this->update_string( self::STORE_NAME_KEY, $value );
	}

	/** @return string 取得選定門市地址 */
	public function get_store_addr(): string {
		return $this->get_string( self::STORE_ADDR_KEY );
	}

	/**
	 * 儲存選定門市地址
	 *
	 * @param string $value 門市地址
	 * @return void
	 */
	public function update_store_addr( string $value ): void {
		$this->update_string( self::STORE_ADDR_KEY, $value );
	}

	// endregion

	// region 貨態

	/** @return string 取得物流貨態（綠界 LogisticsStatus 原始碼） */
	public function get_status(): string {
		return $this->get_string( self::STATUS_KEY );
	}

	/**
	 * 儲存物流貨態（綠界 LogisticsStatus 原始碼）
	 *
	 * @param string $value 貨態碼
	 * @return void
	 */
	public function update_status( string $value ): void {
		$this->update_string( self::STATUS_KEY, $value );
	}

	// endregion

	// region C2C 寄貨編號 / 驗證碼

	/** @return string 取得 C2C 寄貨編號 CVSPaymentNo */
	public function get_cvs_payment_no(): string {
		return $this->get_string( self::CVS_PAYMENT_NO_KEY );
	}

	/**
	 * 儲存 C2C 寄貨編號 CVSPaymentNo
	 *
	 * @param string $value CVSPaymentNo
	 * @return void
	 */
	public function update_cvs_payment_no( string $value ): void {
		$this->update_string( self::CVS_PAYMENT_NO_KEY, $value );
	}

	/** @return string 取得 C2C 驗證碼 CVSValidationNo */
	public function get_cvs_validation_no(): string {
		return $this->get_string( self::CVS_VALIDATION_NO_KEY );
	}

	/**
	 * 儲存 C2C 驗證碼 CVSValidationNo
	 *
	 * @param string $value CVSValidationNo
	 * @return void
	 */
	public function update_cvs_validation_no( string $value ): void {
		$this->update_string( self::CVS_VALIDATION_NO_KEY, $value );
	}

	// endregion

	// region COD 取貨付款完成標記

	/** @return string 取得 COD 取貨付款完成標記（yes 或空字串） */
	public function get_collection_paid(): string {
		return $this->get_string( self::COLLECTION_PAID_KEY );
	}

	/**
	 * 儲存 COD 取貨付款完成標記
	 *
	 * @param string $value 標記值（通常為 'yes'）
	 * @return void
	 */
	public function update_collection_paid( string $value ): void {
		$this->update_string( self::COLLECTION_PAID_KEY, $value );
	}

	/** @return bool 是否已標記取貨付款完成 */
	public function is_collection_paid(): bool {
		return 'yes' === $this->get_collection_paid();
	}

	// endregion

	// region 已處理貨態（防重，T7）

	/** @return array<int, string> 取得已處理貨態碼陣列（元素格式 "{LogisticsID}:{LogisticsStatus}"） */
	public function get_processed_status(): array {
		$value = $this->_order->get_meta( self::PROCESSED_STATUS_KEY ) ?: [];
		if (!\is_array( $value )) {
			return [];
		}
		$list = \array_map(
			static fn( mixed $item ): string => (string) $item,
			\array_values( $value )
		);
		return $list;
	}

	/**
	 * 儲存已處理貨態碼陣列
	 *
	 * @param array<int, string> $value 已處理貨態碼陣列
	 * @return void
	 */
	public function update_processed_status( array $value ): void {
		$this->_order->update_meta_data( self::PROCESSED_STATUS_KEY, \array_values( $value ) );
		$this->_order->save_meta_data();
	}

	/**
	 * 組合防重 key（LogisticsID + LogisticsStatus）
	 *
	 * @param string $logistics_id LogisticsID
	 * @param string $status       LogisticsStatus
	 * @return string
	 */
	private function build_processed_key( string $logistics_id, string $status ): string {
		return "{$logistics_id}:{$status}";
	}

	/**
	 * 是否已處理過該（單號 + 貨態）組合
	 *
	 * @param string $logistics_id LogisticsID
	 * @param string $status       LogisticsStatus
	 * @return bool
	 */
	public function is_processed( string $logistics_id, string $status ): bool {
		$key = $this->build_processed_key( $logistics_id, $status );
		return \in_array( $key, $this->get_processed_status(), true );
	}

	/**
	 * 標記該（單號 + 貨態）組合為已處理（冪等，重複標記不會產生重複紀錄）
	 *
	 * @param string $logistics_id LogisticsID
	 * @param string $status       LogisticsStatus
	 * @return void
	 */
	public function mark_processed( string $logistics_id, string $status ): void {
		$key       = $this->build_processed_key( $logistics_id, $status );
		$processed = $this->get_processed_status();
		if (\in_array( $key, $processed, true )) {
			return;
		}
		$processed[] = $key;
		$this->update_processed_status( $processed );
	}

	// endregion

	/**
	 * 以統一物流單號（LogisticsID）反查訂單
	 *
	 * 貨態 callback（ServerReplyURL）只帶 LogisticsID，須反查訂單主鍵（計畫 T6）。
	 *
	 * @param string $logistics_id 統一物流單號（LogisticsID）
	 * @return \WC_Order|null
	 */
	public static function get_order_by_ref( string $logistics_id ): \WC_Order|null {
		if ('' === $logistics_id) {
			return null;
		}

		$args = [
			'limit'      => 1,
			'meta_key'   => self::REF_KEY, // phpcs:ignore
			'meta_value' => $logistics_id, // phpcs:ignore
		];

		$orders = \wc_get_orders( $args );
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}
}
