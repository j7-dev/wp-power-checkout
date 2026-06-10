<?php
/**
 * PayNow 物流專用 Order Meta Key 存取（HPOS 相容，立吉富體系 1）
 *
 * R4 裁決：PayNow 物流自建獨立前綴 `_pc_paynow_logistics_`，不復用 shared
 * `LogisticsMetaKeys`（前綴 `_pc_logistics_`）。原因：PayNow 的 LogisticNumber /
 * sno / paymentno / validationno 語意與 ECPay TempLogisticsID / CVSPaymentNo 不同，
 * 共用前綴會造成跨 provider 的 order meta 汙染與反查誤撈。
 *
 * 反查主鍵（R1 / woomp 實證）：
 *  - 貨態推送 callback 只帶 OrderNo（PCN{order_id}），故以 ORDER_NO 反查訂單為主鍵。
 *  - get_order_by_ref()（LogisticNumber）保留為輔助反查。
 *
 * 冪等防重：以「{OrderNo}:{LogisticCode}」組合碼為元素，存於 PROCESSED_STATUS 陣列。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 0 步驟 4 / §R4
 * @see CLAUDE.md §Order Meta Keys（前綴 _pc_paynow_logistics_）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers;

/** PayNow 物流 Order Meta Key 存取 Helper */
final class PaynowLogisticsMetaKeys {

	/** @var string 使用的物流 Provider ID（paynow_logistics） */
	public const PROVIDER_ID = '_pc_paynow_logistics_provider_id';

	/** @var string 物流服務類別（Logistic_serviceID，01-06 / 21-24） */
	public const SERVICE_ID = '_pc_paynow_logistics_service_id';

	/** @var string PayNow 訂單編號（OrderNo，PCN{order_id}；貨態 callback 反查主鍵） */
	public const ORDER_NO = '_pc_paynow_logistics_order_no';

	/** @var string 選定門市代碼（超商，選店回呼寫入） */
	public const STORE_ID = '_pc_paynow_logistics_store_id';

	/** @var string 選定門市名稱（超商） */
	public const STORE_NAME = '_pc_paynow_logistics_store_name';

	/** @var string 選定門市地址（超商） */
	public const STORE_ADDR = '_pc_paynow_logistics_store_addr';

	/** @var string PayNow 物流單號（LogisticNumber；下游主鍵） */
	public const REF = '_pc_paynow_logistics_ref';

	/** @var string 物流單序號（sno，預設 "1"） */
	public const SNO = '_pc_paynow_logistics_sno';

	/** @var string 物流商託運單號（paymentno） */
	public const PAYMENT_NO = '_pc_paynow_logistics_payment_no';

	/** @var string 物流商驗證碼（validationno） */
	public const VALIDATION_NO = '_pc_paynow_logistics_validation_no';

	/** @var string 重新取號後 PayNow 訂單編號（ReNewOrder 後，列印用） */
	public const RENEW_ORDER_NO = '_pc_paynow_logistics_renew_order_no';

	/** @var string 物流單狀態（0=成立中 / 1=無效） */
	public const STATUS = '_pc_paynow_logistics_status';

	/** @var string 貨態描述（Delivery_Status，人類可讀字串） */
	public const DELIVERY_STATUS = '_pc_paynow_logistics_delivery_status';

	/** @var string 貨態碼（LogisticCode，例 5000 已到店） */
	public const LOGISTIC_CODE = '_pc_paynow_logistics_logistic_code';

	/** @var string 黑貓宅配溫層（DeliveryType） */
	public const DELIVERY_TYPE = '_pc_paynow_logistics_delivery_type';

	/** @var string COD 取貨付款完成標記（yes） */
	public const COLLECTION_PAID = '_pc_paynow_logistics_collection_paid';

	/** @var string 已處理貨態陣列（防重；元素格式 "{OrderNo}:{LogisticCode}"） */
	public const PROCESSED_STATUS = '_pc_paynow_logistics_processed_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $order,
	) {}

	/**
	 * 統一字串型 meta 讀取
	 *
	 * @param string $key meta key
	 * @return string
	 */
	private function get_string( string $key ): string {
		// ⚠️ 不可用 `?: ''`，否則字串 '0'（成立中狀態）會被當 falsy 抹成空字串。
		$value = $this->order->get_meta( $key );
		return ( null === $value || false === $value ) ? '' : (string) $value;
	}

	/**
	 * 統一字串型 meta 寫入
	 *
	 * @param string $key   meta key
	 * @param string $value 值
	 * @return void
	 */
	private function update_string( string $key, string $value ): void {
		$this->order->update_meta_data( $key, $value );
		$this->order->save_meta_data();
	}

	// region Provider ID / Service ID

	/** @return string 取得物流 Provider ID */
	public function get_provider_id(): string {
		return $this->get_string( self::PROVIDER_ID );
	}

	/**
	 * 儲存物流 Provider ID
	 *
	 * @param string $value Provider ID
	 * @return void
	 */
	public function update_provider_id( string $value ): void {
		$this->update_string( self::PROVIDER_ID, $value );
	}

	/** @return string 取得物流服務類別（Logistic_serviceID） */
	public function get_service_id(): string {
		return $this->get_string( self::SERVICE_ID );
	}

	/**
	 * 儲存物流服務類別（Logistic_serviceID）
	 *
	 * @param string $value 服務類別代碼（01-06 / 21-24）
	 * @return void
	 */
	public function update_service_id( string $value ): void {
		$this->update_string( self::SERVICE_ID, $value );
	}

	// endregion

	// region OrderNo（貨態反查主鍵）

	/** @return string 取得 PayNow 訂單編號（OrderNo） */
	public function get_order_no(): string {
		return $this->get_string( self::ORDER_NO );
	}

	/**
	 * 儲存 PayNow 訂單編號（OrderNo，貨態 callback 反查主鍵）
	 *
	 * @param string $value OrderNo（PCN{order_id}）
	 * @return void
	 */
	public function update_order_no( string $value ): void {
		$this->update_string( self::ORDER_NO, $value );
	}

	// endregion

	// region 門市資訊

	/** @return string 取得選定門市代碼 */
	public function get_store_id(): string {
		return $this->get_string( self::STORE_ID );
	}

	/**
	 * 儲存選定門市代碼
	 *
	 * @param string $value 門市代碼
	 * @return void
	 */
	public function update_store_id( string $value ): void {
		$this->update_string( self::STORE_ID, $value );
	}

	/** @return string 取得選定門市名稱 */
	public function get_store_name(): string {
		return $this->get_string( self::STORE_NAME );
	}

	/**
	 * 儲存選定門市名稱
	 *
	 * @param string $value 門市名稱
	 * @return void
	 */
	public function update_store_name( string $value ): void {
		$this->update_string( self::STORE_NAME, $value );
	}

	/** @return string 取得選定門市地址 */
	public function get_store_addr(): string {
		return $this->get_string( self::STORE_ADDR );
	}

	/**
	 * 儲存選定門市地址
	 *
	 * @param string $value 門市地址
	 * @return void
	 */
	public function update_store_addr( string $value ): void {
		$this->update_string( self::STORE_ADDR, $value );
	}

	// endregion

	// region 物流單號 / 序號 / 託運單號 / 驗證碼 / 重新取號

	/** @return string 取得 PayNow 物流單號（LogisticNumber） */
	public function get_ref(): string {
		return $this->get_string( self::REF );
	}

	/**
	 * 儲存 PayNow 物流單號（LogisticNumber）
	 *
	 * @param string $value LogisticNumber
	 * @return void
	 */
	public function update_ref( string $value ): void {
		$this->update_string( self::REF, $value );
	}

	/** @return string 取得物流單序號（sno） */
	public function get_sno(): string {
		return $this->get_string( self::SNO );
	}

	/**
	 * 儲存物流單序號（sno，預設 "1"）
	 *
	 * @param string $value 序號
	 * @return void
	 */
	public function update_sno( string $value ): void {
		$this->update_string( self::SNO, $value );
	}

	/** @return string 取得物流商託運單號（paymentno） */
	public function get_payment_no(): string {
		return $this->get_string( self::PAYMENT_NO );
	}

	/**
	 * 儲存物流商託運單號（paymentno）
	 *
	 * @param string $value paymentno
	 * @return void
	 */
	public function update_payment_no( string $value ): void {
		$this->update_string( self::PAYMENT_NO, $value );
	}

	/** @return string 取得物流商驗證碼（validationno） */
	public function get_validation_no(): string {
		return $this->get_string( self::VALIDATION_NO );
	}

	/**
	 * 儲存物流商驗證碼（validationno）
	 *
	 * @param string $value validationno
	 * @return void
	 */
	public function update_validation_no( string $value ): void {
		$this->update_string( self::VALIDATION_NO, $value );
	}

	/** @return string 取得重新取號後 PayNow 訂單編號（RenewOrderNo） */
	public function get_renew_order_no(): string {
		return $this->get_string( self::RENEW_ORDER_NO );
	}

	/**
	 * 儲存重新取號後 PayNow 訂單編號（ReNewOrder 後，列印用）
	 *
	 * @param string $value RenewOrderNo
	 * @return void
	 */
	public function update_renew_order_no( string $value ): void {
		$this->update_string( self::RENEW_ORDER_NO, $value );
	}

	// endregion

	// region 物流單狀態 / 貨態

	/** @return string 取得物流單狀態（0=成立中 / 1=無效） */
	public function get_status(): string {
		return $this->get_string( self::STATUS );
	}

	/**
	 * 儲存物流單狀態
	 *
	 * @param string $value 狀態碼（0=成立中 / 1=無效）
	 * @return void
	 */
	public function update_status( string $value ): void {
		$this->update_string( self::STATUS, $value );
	}

	/** @return string 取得貨態描述（Delivery_Status） */
	public function get_delivery_status(): string {
		return $this->get_string( self::DELIVERY_STATUS );
	}

	/**
	 * 儲存貨態描述（Delivery_Status，人類可讀字串）
	 *
	 * @param string $value 貨態描述
	 * @return void
	 */
	public function update_delivery_status( string $value ): void {
		$this->update_string( self::DELIVERY_STATUS, $value );
	}

	/** @return string 取得貨態碼（LogisticCode） */
	public function get_logistic_code(): string {
		return $this->get_string( self::LOGISTIC_CODE );
	}

	/**
	 * 儲存貨態碼（LogisticCode）
	 *
	 * @param string $value 貨態碼
	 * @return void
	 */
	public function update_logistic_code( string $value ): void {
		$this->update_string( self::LOGISTIC_CODE, $value );
	}

	/** @return string 取得黑貓宅配溫層（DeliveryType） */
	public function get_delivery_type(): string {
		return $this->get_string( self::DELIVERY_TYPE );
	}

	/**
	 * 儲存黑貓宅配溫層（DeliveryType）
	 *
	 * @param string $value 溫層代碼
	 * @return void
	 */
	public function update_delivery_type( string $value ): void {
		$this->update_string( self::DELIVERY_TYPE, $value );
	}

	// endregion

	// region COD 取貨付款完成標記

	/** @return string 取得 COD 取貨付款完成標記（yes 或空字串） */
	public function get_collection_paid(): string {
		return $this->get_string( self::COLLECTION_PAID );
	}

	/**
	 * 儲存 COD 取貨付款完成標記
	 *
	 * @param string $value 標記值（通常為 'yes'）
	 * @return void
	 */
	public function update_collection_paid( string $value ): void {
		$this->update_string( self::COLLECTION_PAID, $value );
	}

	/** @return bool 是否已標記取貨付款完成 */
	public function is_collection_paid(): bool {
		return 'yes' === $this->get_collection_paid();
	}

	// endregion

	// region 已處理貨態（冪等防重）

	/** @return array<int, string> 取得已處理貨態陣列（元素格式 "{OrderNo}:{LogisticCode}"） */
	public function get_processed_status(): array {
		$value = $this->order->get_meta( self::PROCESSED_STATUS ) ?: [];
		if ( !\is_array( $value ) ) {
			return [];
		}
		return \array_map(
			static fn( mixed $item ): string => (string) $item,
			\array_values( $value )
		);
	}

	/**
	 * 儲存已處理貨態陣列
	 *
	 * @param array<int, string> $value 已處理貨態陣列
	 * @return void
	 */
	public function update_processed_status( array $value ): void {
		$this->order->update_meta_data( self::PROCESSED_STATUS, \array_values( $value ) );
		$this->order->save_meta_data();
	}

	/**
	 * 組合防重 key（OrderNo + LogisticCode）
	 *
	 * @param string $order_no      OrderNo
	 * @param string $logistic_code 貨態碼
	 * @return string
	 */
	private function build_processed_key( string $order_no, string $logistic_code ): string {
		return "{$order_no}:{$logistic_code}";
	}

	/**
	 * 是否已處理過該（OrderNo + 貨態碼）組合
	 *
	 * @param string $order_no      OrderNo
	 * @param string $logistic_code 貨態碼
	 * @return bool
	 */
	public function is_processed( string $order_no, string $logistic_code ): bool {
		$key = $this->build_processed_key( $order_no, $logistic_code );
		return \in_array( $key, $this->get_processed_status(), true );
	}

	/**
	 * 標記該（OrderNo + 貨態碼）組合為已處理（冪等，重複標記不產生重複紀錄）
	 *
	 * @param string $order_no      OrderNo
	 * @param string $logistic_code 貨態碼
	 * @return void
	 */
	public function mark_processed( string $order_no, string $logistic_code ): void {
		$key       = $this->build_processed_key( $order_no, $logistic_code );
		$processed = $this->get_processed_status();
		if ( \in_array( $key, $processed, true ) ) {
			return;
		}
		$processed[] = $key;
		$this->update_processed_status( $processed );
	}

	// endregion

	// region 反查訂單

	/**
	 * 以 OrderNo 反查訂單（貨態 callback 反查主鍵，R1 / woomp 實證）
	 *
	 * 空字串守衛：空字串直接回 null（不查資料庫，避免誤撈）。
	 *
	 * @param string $order_no OrderNo（PCN{order_id}）
	 * @return \WC_Order|null 找不到回 null
	 */
	public static function get_order_by_order_no( string $order_no ): \WC_Order|null {
		if ( '' === $order_no ) {
			return null;
		}

		$orders = \wc_get_orders(
			[
				'limit'      => 1,
				'meta_key'   => self::ORDER_NO, // phpcs:ignore
				'meta_value' => $order_no,       // phpcs:ignore
			]
		);
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}

	/**
	 * 以 LogisticNumber（ref）反查訂單（輔助反查）
	 *
	 * 空字串守衛：空字串直接回 null。
	 *
	 * @param string $logistic_number LogisticNumber
	 * @return \WC_Order|null 找不到回 null
	 */
	public static function get_order_by_ref( string $logistic_number ): \WC_Order|null {
		if ( '' === $logistic_number ) {
			return null;
		}

		$orders = \wc_get_orders(
			[
				'limit'      => 1,
				'meta_key'   => self::REF, // phpcs:ignore
				'meta_value' => $logistic_number, // phpcs:ignore
			]
		);
		$order  = \reset( $orders );
		return ( $order instanceof \WC_Order ) ? $order : null;
	}

	// endregion
}
