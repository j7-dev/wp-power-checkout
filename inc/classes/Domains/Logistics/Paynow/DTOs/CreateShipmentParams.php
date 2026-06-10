<?php
/**
 * PayNow 物流 Add_Order 請求參數組裝器（立吉富體系 1，woomp 對齊）
 *
 * 由訂單 + provider 設定組裝 PayNow `/api/Orderapi/Add_Order` 的內層 args（加密前明文）。
 * 對齊 woomp `class-paynow-shipping-request.php::build_add_order_args()`（L605-647）。
 *
 * ⚠️ 本類為「組裝器」而非 wp-utils DTO：`parse()` 接收（訂單 + 設定）兩參數並回傳
 *    `array<string, mixed>`（非物件）。原因：Add_Order 欄位需依超商 / 黑貓兩條路徑動態增減
 *    （超商有 receiver_storeid / receiver_storename；黑貓有 DeliveryType / Weight / Length /
 *    Width / Height），以陣列回傳最貼近 woomp 語意，下游 TripleDES 直接 json_encode 加密。
 *
 * 兩條路徑分流（依訂單 meta 的 Logistic_serviceID）：
 *  - 超商（is_cvs() = true，01-05 / 21-24）：帶 receiver_storeid / receiver_storename，
 *    Receiver_address 為門市地址；不帶黑貓 s60 規格欄位。
 *  - 黑貓宅配（Tcat = 06）：帶 DeliveryType / Weight / Length / Width / Height（s60 固定值，
 *    woomp 實證 5/5/4/3），Receiver_address 為宅配地址，receiver_storeid 留空字串。
 *
 * ⚠️ TotalAmount 與 PassCode 共用同一個 `$order->get_total()` 原值（R6 格式敏感）：
 *    PassCode = sha1 串接，"1000" 與 "1000.00" 結果不同，故兩處必須引用同一字串來源。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 1 步驟 6 / §R6 §R9
 * @see ../woomp/.../shippings/api/class-paynow-shipping-request.php L605-647
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\DTOs;

use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PassCodeService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;

/** PayNow 物流 Add_Order 請求參數組裝器 */
final class CreateShipmentParams {

	/** @var string EC 平台欄位固定值（woomp 對齊） */
	private const EC_PLATFORM = 'EC 平台';

	/** @var string 黑貓宅配 s60 規格 — 重量（woomp 固定值） */
	private const TCAT_WEIGHT = '5';

	/** @var string 黑貓宅配 s60 規格 — 長（woomp 固定值） */
	private const TCAT_LENGTH = '5';

	/** @var string 黑貓宅配 s60 規格 — 寬（woomp 固定值） */
	private const TCAT_WIDTH = '4';

	/** @var string 黑貓宅配 s60 規格 — 高（woomp 固定值） */
	private const TCAT_HEIGHT = '3';

	/**
	 * 由訂單 + 設定組裝 Add_Order 請求參數（加密前明文）
	 *
	 * @param \WC_Order            $order    訂單
	 * @param array<string, mixed> $settings provider 設定（user_account / apicode / sender_*）
	 *
	 * @return array<string, mixed> Add_Order 內層 args
	 */
	public static function parse( \WC_Order $order, array $settings ): array {
		$meta = new PaynowLogisticsMetaKeys( $order );

		$user_account = (string) ( $settings['user_account'] ?? '' );
		$apicode      = (string) ( $settings['apicode'] ?? '' );

		$service_id = $meta->get_service_id();
		$order_no   = self::resolve_order_no( $order, $meta );
		// ⚠️ TotalAmount 與 PassCode 共用同一字串（R6）：get_total() 原值，不轉型不格式化。
		$total = $order->get_total();

		$is_cvs = self::is_cvs( $service_id );

		$args = [
			'Description'      => ItemName::get( $order ),
			'DeliverMode'      => self::resolve_deliver_mode( $order ),
			'Logistic_service' => $service_id,
			'user_account'     => $user_account,
			'apicode'          => $apicode,
			'OrderNo'          => $order_no,

			'Receiver_Name'    => self::resolve_receiver_name( $order ),
			'Receiver_Phone'   => self::resolve_receiver_phone( $order ),
			'Receiver_Email'   => $order->get_billing_email(),
			'Receiver_address' => self::resolve_receiver_address( $order, $meta, $is_cvs ),

			'Remark'           => '',

			'Sender_Name'      => (string) ( $settings['sender_name'] ?? '' ),
			'Sender_Phone'     => (string) ( $settings['sender_phone'] ?? '' ),
			'Sender_address'   => (string) ( $settings['sender_address'] ?? '' ),
			'Sender_Email'     => (string) ( $settings['sender_email'] ?? '' ),

			'PassCode'         => PassCodeService::build( $user_account, $order_no, (string) $total, $apicode ),
			'TotalAmount'      => $total,
			'EC'               => self::EC_PLATFORM,
		];

		if ( $is_cvs ) {
			// 超商路徑：門市資訊（黑貓宅配不帶）
			$args['receiver_storeid']   = $meta->get_store_id();
			$args['receiver_storename'] = $meta->get_store_name();
			$args['return_storeid']     = '';
		} else {
			// 黑貓宅配路徑：s60 規格欄位（超商不帶；測試 test_超商路徑不含TCAT專屬欄位 把關）
			$args['DeliveryType'] = $meta->get_delivery_type();
			$args['Weight']       = self::TCAT_WEIGHT;
			$args['Length']       = self::TCAT_LENGTH;
			$args['Width']        = self::TCAT_WIDTH;
			$args['Height']       = self::TCAT_HEIGHT;
		}

		return $args;
	}

	/**
	 * 是否為超商取貨路徑（需門市資訊）
	 *
	 * Service_id 無法對應已知 enum 時，保守視為超商（帶門市欄位），與 woomp「非 TCAT 即超商」一致。
	 *
	 * @param string $service_id Logistic_serviceID（01-06 / 21-24）
	 * @return bool 超商回 true，黑貓宅配回 false
	 */
	private static function is_cvs( string $service_id ): bool {
		$service = PaynowLogisticService::tryFrom( $service_id );
		if ( null === $service ) {
			return true;
		}
		return $service->is_cvs();
	}

	/**
	 * 解析取貨付款模式（DeliverMode）
	 *
	 * COD 付款方式（payment_method = 'cod'）→ '01' 取貨付款；其餘 → '02' 取貨不付款。
	 * 對齊 woomp：`( 'cod' === $order->get_payment_method() ) ? '01' : '02'`。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string '01'（COD）或 '02'（非 COD）
	 */
	private static function resolve_deliver_mode( \WC_Order $order ): string {
		return 'cod' === $order->get_payment_method() ? '01' : '02';
	}

	/**
	 * 解析 PayNow OrderNo（貨態反查主鍵）
	 *
	 * 優先取訂單 meta（before_process_payment 寫入的 PCN{order_id}）；
	 * meta 缺漏時 fallback 為 PCN{order_id}，確保 OrderNo 必含 order_id（測試把關）。
	 *
	 * @param \WC_Order               $order 訂單
	 * @param PaynowLogisticsMetaKeys $meta meta 存取器
	 * @return string OrderNo
	 */
	private static function resolve_order_no( \WC_Order $order, PaynowLogisticsMetaKeys $meta ): string {
		$order_no = $meta->get_order_no();
		if ( '' !== $order_no ) {
			return $order_no;
		}
		return 'PCN' . $order->get_id();
	}

	/**
	 * 解析收件人姓名（shipping last + first；缺漏時 fallback billing）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 收件人姓名
	 */
	private static function resolve_receiver_name( \WC_Order $order ): string {
		$name = \trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ( '' !== $name ) {
			return $name;
		}
		return \trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
	}

	/**
	 * 解析收件人電話（shipping phone 缺漏時 fallback billing phone）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string 收件人電話
	 */
	private static function resolve_receiver_phone( \WC_Order $order ): string {
		$phone = \trim( (string) $order->get_shipping_phone() );
		if ( '' !== $phone ) {
			return $phone;
		}
		return (string) $order->get_billing_phone();
	}

	/**
	 * 解析收件人地址
	 *
	 * 超商路徑回門市地址（store_addr meta）；黑貓宅配路徑回宅配地址（shipping city + state +
	 * address_1 + address_2，對齊 woomp paynow_get_api_order_address）。
	 *
	 * @param \WC_Order               $order  訂單
	 * @param PaynowLogisticsMetaKeys $meta   meta 存取器
	 * @param bool                    $is_cvs 是否為超商路徑
	 * @return string 收件人地址
	 */
	private static function resolve_receiver_address(
		\WC_Order $order,
		PaynowLogisticsMetaKeys $meta,
		bool $is_cvs
	): string {
		if ( $is_cvs ) {
			return $meta->get_store_addr();
		}

		return $order->get_shipping_city()
		. $order->get_shipping_state()
		. $order->get_shipping_address_1()
		. $order->get_shipping_address_2();
	}
}
