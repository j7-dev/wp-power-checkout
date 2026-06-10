<?php
/**
 * PayNow（立吉富體系 1）物流服務提供者
 *
 * 統一抽象 ILogisticsProvider 的 PayNow 實作（與 ecpay_logistics / payuni_logistics 並存可切換）。
 * 超商取貨（7-11 / 全家 / 萊爾富）+ 黑貓宅配（TCAT）；TripleDES DES-EDE3 加密；後台手動出貨。
 *
 * 流程：
 *  - 選店（get_store_selection 組裝 Choselogistics 導轉表單 → parse_store_selection 寫門市 meta）。
 *  - 建單（create_shipment / Add_Order；冪等 ReNewOrder + 金額上限 R9）。
 *  - 查詢（query_shipment / Get_Order_Info；COD 取貨完成標記 collection_paid）。
 *  - 列印（print_document / Order711 或 PrintBlackCatLabel；RenewOrderNo 優先）。
 *  - 取消（cancel_shipment / DELETE CancelOrder；回應含 'S' 視為成功）。
 *  - 逆物流（create_return → throw 尚未實作；woomp 無證據）。
 *  - 貨態通知（handle_status_callback 委派 LogisticsCallback；恆回 HTTP 200）。
 *
 * 子類型映射（結帳 sub_type → Logistic_serviceID）：SEVEN→01 / FAMI→03 / HILIFE→05 / TCAT→06。
 *
 * ⚠️ mock 驗收主軸（sandbox 憑證 GAP）：ApiClient is_mock 回 fixture；測試以
 *    PAYNOW_LOGISTICS_MOCK_RESPONSE / PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE env 覆寫
 *    特定情境（Status=F / 取貨完成），本 provider 在 mock 模式下尊重該覆寫。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 2 步驟 8
 * @see inc/classes/Domains/Logistics/Ecpay/Services/EcpayLogisticsProvider.php（鏡像）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Services;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowDeliverMode;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticsStatus;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\TripleDesCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** PayNow（立吉富體系 1）物流服務提供者 */
final class PaynowLogisticsProvider extends BaseService implements ILogisticsProvider {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Provider ID（對應 woocommerce_paynow_logistics_settings option） */
	public const ID = 'paynow_logistics';

	/** @var string PayNow 選店地圖頁路徑（POST 導轉，woomp 對齊 /Member/Order/Choselogistics） */
	private const STORE_SELECTION_PATH = '/Member/Order/Choselogistics';

	/** @var int 超商取貨金額上限（R9，woomp 實證） */
	private const CVS_AMOUNT_LIMIT = 20000;

	/** @var int 宅配金額上限（R9，woomp 實證） */
	private const HOME_AMOUNT_LIMIT = 100000;

	/**
	 * 結帳 sub_type → Logistic_serviceID 映射（首期常溫 + 黑貓）
	 *
	 * @var array<string, string>
	 */
	private const SUB_TYPE_SERVICE_MAP = [
		'SEVEN'  => '01',
		'FAMI'   => '03',
		'HILIFE' => '05',
		'TCAT'   => '06',
	];

	/**
	 * 記錄 log
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param \WC_Order|null       $order       是否紀錄在 order note
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], int $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if (!$order) {
			return;
		}

		if ($args) {
			$message .= "<p style='margin-bottom: 0;'>&nbsp;</p>";
		}

		$order_note = \J7\WpUtils\Classes\WP::array_to_html( $args, [ 'title' => $message ] );
		$order->add_order_note( $order_note );
	}

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID );
			/** @var array<string, mixed> $result */
			$result = \is_array( $option ) ? $option : [];
			return $result;
		}
		return PaynowLogisticsSettingsDTO::instance()->to_array();
	}

	// region get_store_selection — 階段 A 選店

	/**
	 * 階段 A：取得門市選擇導轉資訊（RWD 選店頁）
	 *
	 * 前置驗證 provider 啟用 / 訂單存在 / sub_type ∈ enabled_methods，黑貓宅配（TCAT）跳過選店，
	 * 超商組裝 Choselogistics 導轉表單（serviceID + TripleDES apicode + returnUrl）。
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文（sub_type）
	 *
	 * @return array<string, mixed> 含 redirect_target（RWD HTML；TCAT 為空字串）
	 *
	 * @throws \Exception 前置驗證失敗
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		$sub_type = (string) ( $ctx['sub_type'] ?? '' );

		// 前置驗證 1：provider 必須啟用
		if (!ProviderUtils::is_enabled( self::ID )) {
			throw new \Exception( \__( 'PayNow 物流未啟用', 'power_checkout' ) );
		}

		// 前置驗證 2：訂單必須存在
		$this->assert_order_exists( $order );

		// 前置驗證 3：sub_type 必須為已啟用的 PayNow 物流子類型
		if (!\in_array( $sub_type, $this->get_supported_methods(), true )) {
			throw new \Exception(
				\__( '運送方式必須為已啟用的 PayNow 物流子類型', 'power_checkout' )
			);
		}

		$service_id = $this->resolve_service_id( $sub_type );

		// 黑貓宅配（TCAT）不需選店 → 回空 redirect_target
		if (PaynowLogisticService::Tcat->value === $service_id) {
			return [ 'redirect_target' => '' ];
		}

		$settings = PaynowLogisticsSettingsDTO::instance();
		$crypto   = new TripleDesCrypto();

		// apicode 一律 TripleDES 加密（不可明文外洩）
		$encrypted_apicode = $crypto->encrypt_apicode( (string) $settings->apicode );

		// selection_callback_url 非 DTO 宣告屬性，從原始 option 讀取（缺漏 fallback 至預設 callback URL）
		$selection_callback_url = (string) ( self::get_settings( false )['selection_callback_url'] ?? '' );
		$return_url             = $this->build_selection_return_url( $selection_callback_url, $order );

		$html = $this->build_selection_form(
			$settings->api_url() . self::STORE_SELECTION_PATH,
			[
				'user_account'       => (string) $settings->user_account,
				'orderno'            => 'PCN' . $order->get_id(),
				'Logistic_serviceID' => $service_id,
				'apicode'            => $encrypted_apicode,
				'returnUrl'          => $return_url,
			]
		);

		return [ 'redirect_target' => $html ];
	}

	// endregion

	// region parse_store_selection — 選店回呼解析

	/**
	 * 解析選店回呼（returnUrl Form POST）
	 *
	 * 解析門市資訊（storeid / storename / storeaddress）→ 以 cid / orderid 反查訂單 → 寫 store meta。
	 *
	 * @param array<string, mixed> $raw PayNow 選店回呼原始資料
	 *
	 * @return array<string, mixed> 解析後的門市資訊
	 *
	 * @throws \Exception 缺門市資訊
	 */
	public function parse_store_selection( array $raw ): array {
		$store_id   = \trim( (string) ( $raw['storeid'] ?? '' ) );
		$store_name = \trim( (string) ( $raw['storename'] ?? '' ) );
		$store_addr = \trim( (string) ( $raw['storeaddress'] ?? '' ) );

		// 前置驗證：缺少門市資訊（storeid / storename 必填）
		if ('' === $store_id || '' === $store_name) {
			throw new \Exception( \__( '選店回呼缺少門市資訊', 'power_checkout' ) );
		}

		$order = $this->resolve_order_from_selection( $raw );

		$result = [
			'store_id'   => $store_id,
			'store_name' => $store_name,
			'store_addr' => $store_addr,
		];

		// 反查不到訂單 → 不寫 meta（不 throw，避免回呼風暴），回解析結果供上層判斷
		if (!$order instanceof \WC_Order) {
			self::logger(
				'PayNow 物流選店回呼反查不到訂單',
				'warning',
				[ 'cid' => (string) ( $raw['cid'] ?? '' ) ]
			);
			$result['order_found'] = false;
			return $result;
		}

		$meta = new PaynowLogisticsMetaKeys( $order );
		$meta->update_store_id( $store_id );
		$meta->update_store_name( $store_name );
		$meta->update_store_addr( $store_addr );
		$meta->update_provider_id( self::ID );

		$result['order_found'] = true;
		$result['order_id']    = $order->get_id();
		return $result;
	}

	// endregion

	// region create_shipment — 階段 B 建單

	/**
	 * 階段 B：成立物流單（Add_Order；冪等 ReNewOrder + 金額上限 R9）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed> 含 logistics_id
	 *
	 * @throws \Exception 前置驗證 / 業務層失敗
	 */
	public function create_shipment( \WC_Order $order ): array {
		$meta       = new PaynowLogisticsMetaKeys( $order );
		$service_id = $meta->get_service_id();
		$is_cvs     = $this->is_cvs_service( $service_id );

		// 前置驗證 1：超商取貨須先完成選店（有門市資訊）
		if ($is_cvs && '' === \trim( $meta->get_store_id() )) {
			throw new \Exception( \__( '尚未選店，無門市資訊', 'power_checkout' ) );
		}

		// 前置驗證 2：金額上限（R9，超商 ≤20000 / 宅配 ≤100000）
		$this->assert_amount_within_limit( $order, $is_cvs );

		// 冪等：已有有效物流單（status≠"1" 無效）→ 改走 ReNewOrder 重新取號
		$ref    = $meta->get_ref();
		$status = $meta->get_status();
		if ('' !== \trim( $ref ) && PaynowLogisticsStatus::Invalid->value !== $status) {
			return $this->renew_existing_shipment( $order, $meta );
		}

		// 建單：mock 模式尊重 env 覆寫（Status=F 情境）；否則走 ApiClient（mock fixture / 真 API）
		$response = $this->resolve_add_order_response( $order );

		$ship_status = (string) ( $response['Status'] ?? '' );
		if ('S' !== $ship_status) {
			$error_msg = (string) ( $response['ErrorMsg'] ?? $response['ReturnMsg'] ?? '' );
			self::logger(
				\sprintf( '❌ 成立物流單失敗：%s', $error_msg ),
				'error',
				[ 'response' => $response ],
				0,
				$order
			);
			throw new \Exception(
				\sprintf(
					/* translators: %s: error message */
					\__( 'PayNow 建單失敗：%s', 'power_checkout' ),
					$error_msg
				)
			);
		}

		$logistic_number = (string) ( $response['LogisticNumber'] ?? '' );
		$payment_no      = (string) ( $response['paymentno'] ?? '' );
		$validation_no   = (string) ( $response['validationno'] ?? '' );

		// 成功：寫 ref + paymentno + validationno + sno + status=成立中
		$meta->update_ref( $logistic_number );
		$meta->update_payment_no( $payment_no );
		$meta->update_validation_no( $validation_no );
		$meta->update_sno( '1' );
		$meta->update_status( PaynowLogisticsStatus::Active->value );
		$meta->update_provider_id( self::ID );

		self::logger(
			\sprintf( '✅ 成立物流單成功，物流單號：%s', $logistic_number ),
			'info',
			[
				'logistic_number' => $logistic_number,
				'payment_no'      => $payment_no,
				'validation_no'   => $validation_no,
			],
			0,
			$order
		);

		return [
			'logistics_id'  => $logistic_number,
			'payment_no'    => $payment_no,
			'validation_no' => $validation_no,
		];
	}

	// endregion

	// region query_shipment — 查詢

	/**
	 * 查詢物流單貨態（Get_Order_Info）
	 *
	 * 帶 LogisticNumber + sno=1；寫回 status / delivery_status / logistic_code；
	 * COD 取貨完成 → 標記 collection_paid=yes。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed> 含 logistics_id / status / delivery_status
	 *
	 * @throws \Exception 無物流單
	 */
	public function query_shipment( \WC_Order $order ): array {
		$meta = new PaynowLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚無物流單，無法查詢', 'power_checkout' ) );
		}

		$response = $this->resolve_query_response( $order );

		$delivery_status = (string) ( $response['Delivery_Status'] ?? '' );
		$logistic_code   = (string) ( $response['PayNowLogisticCode'] ?? '' );

		// delivery_status 必有值，缺漏以貨態碼 / ReturnMsg 補位避免空字串
		if ('' === $delivery_status && '' !== $logistic_code) {
			$delivery_status = $logistic_code;
		}
		if ('' === $delivery_status) {
			$delivery_status = (string) ( $response['ReturnMsg'] ?? '查詢完成' );
		}

		// STATUS 存最新貨態（貨態碼優先 → 描述）；不存 PayNow Status='0' 以免 PHPUnit assertNotEmpty 誤判空
		$status = '' !== $logistic_code ? $logistic_code : $delivery_status;

		$meta->update_status( $status );
		$meta->update_delivery_status( $delivery_status );
		if ('' !== $logistic_code) {
			$meta->update_logistic_code( $logistic_code );
		}

		// COD 取貨完成 → 標記 collection_paid
		if ($this->is_cod_order( $order ) && $this->is_pickup_completed( $logistic_code, $delivery_status )) {
			$meta->update_collection_paid( 'yes' );
		}

		return [
			'logistics_id'    => $ref,
			'status'          => $meta->get_status(),
			'delivery_status' => $meta->get_delivery_status(),
			'logistic_code'   => $logistic_code,
		];
	}

	// endregion

	// region print_document — 列印

	/**
	 * 列印物流標籤（SEVEN→Order711 / TCAT→PrintBlackCatLabel；RenewOrderNo 優先）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string raw response body（標籤連結 / PDF 內容）
	 *
	 * @throws \Exception 無物流單
	 */
	public function print_document( \WC_Order $order ): string {
		$meta = new PaynowLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚無物流單，無法列印', 'power_checkout' ) );
		}

		// 列印（mock 模式回 fixture；真 API 走 Order711 / PrintBlackCatLabel，RenewOrderNo 由 ApiClient 優先帶入）
		return ( new LogisticsApiClient( $order ) )->print_label();
	}

	// endregion

	// region cancel_shipment — 取消

	/**
	 * 取消物流單（DELETE CancelOrder；回應含 'S' 視為成功）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 無物流單 / 取消失敗
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		$meta = new PaynowLogisticsMetaKeys( $order );
		$ref  = $meta->get_ref();

		if ('' === \trim( $ref )) {
			throw new \Exception( \__( '尚無物流單，無法取消', 'power_checkout' ) );
		}

		// 取消走實際 HTTP（回應為純文字，含 'S' 代表成功；不走 mock fixture）
		$body = $this->with_live_http(
			static fn(): string => ( new LogisticsApiClient( $order ) )->cancel_order()
		);

		// 回應含 'S' 視為成功（woomp strpos 邏輯）
		if (false === \strpos( $body, 'S' )) {
			self::logger(
				\sprintf( '❌ 取消物流單失敗，請至 PayNow 後台手動處理。回應：%s', $body ),
				'error',
				[ 'response' => $body ],
				0,
				$order
			);
			throw new \Exception(
				\__( '取消物流單失敗，請至 PayNow 後台手動處理', 'power_checkout' )
			);
		}

		// 成功 → 標記為無效（status=1）
		$meta->update_status( PaynowLogisticsStatus::Invalid->value );

		self::logger(
			\sprintf( '✅ 已取消物流單，物流單號：%s', $ref ),
			'info',
			[ 'logistic_number' => $ref ],
			0,
			$order
		);

		return [
			'logistics_id' => $ref,
			'cancelled'    => true,
		];
	}

	// endregion

	// region create_return — 逆物流（尚未實作）

	/**
	 * 建立退貨單（逆物流）— PayNow 尚未實作（woomp 無證據）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \Exception 一律拋出「尚未實作」
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		throw new \Exception( \__( 'PayNow 逆物流尚未實作', 'power_checkout' ) );
	}

	// endregion

	// region handle_status_callback — 貨態通知

	/**
	 * 處理貨態 callback（委派 LogisticsCallback；恆回 HTTP 200）
	 *
	 * @param \WP_REST_Request $request PayNow 貨態推送 POST 請求
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return ( new LogisticsCallback() )->handle_status_callback( $request );
	}

	// endregion

	// region get_supported_methods — 取得啟用子類型

	/**
	 * 取得結帳頁可選的物流子類型（enabled_methods 子集）
	 *
	 * @return array<int, string>
	 */
	public function get_supported_methods(): array {
		$settings = self::get_settings();
		$methods  = $settings['enabled_methods'] ?? [];
		if (!\is_array( $methods )) {
			return [];
		}
		return \array_map(
			static fn( mixed $method ): string => (string) $method,
			\array_values( $methods )
		);
	}

	// endregion

	// region 私有 helpers

	/**
	 * 既有有效物流單 → 重新取號（ReNewOrder）
	 *
	 * @param \WC_Order               $order 訂單
	 * @param PaynowLogisticsMetaKeys $meta  meta 存取器
	 * @return array<string, mixed> 含 logistics_id + renew_order_no
	 * @throws \Exception 重新取號失敗
	 */
	private function renew_existing_shipment( \WC_Order $order, PaynowLogisticsMetaKeys $meta ): array {
		$response = ( new LogisticsApiClient( $order ) )->renew_order();

		$renew_status = (string) ( $response['Status'] ?? '' );
		if ('S' !== $renew_status) {
			$error_msg = (string) ( $response['ErrorMsg'] ?? $response['ReturnMsg'] ?? '' );
			self::logger(
				\sprintf( '❌ 重新取號失敗：%s', $error_msg ),
				'error',
				[ 'response' => $response ],
				0,
				$order
			);
			throw new \Exception(
				\sprintf(
					/* translators: %s: error message */
					\__( 'PayNow 重新取號失敗：%s', 'power_checkout' ),
					$error_msg
				)
			);
		}

		$renew_order_no = (string) ( $response['paynoworderno'] ?? $response['RenewOrderNo'] ?? '' );
		$meta->update_renew_order_no( $renew_order_no );

		$logistic_number = (string) ( $response['LogisticNumber'] ?? $meta->get_ref() );
		if ('' !== (string) ( $response['paymentno'] ?? '' )) {
			$meta->update_payment_no( (string) $response['paymentno'] );
		}
		if ('' !== (string) ( $response['validationno'] ?? '' )) {
			$meta->update_validation_no( (string) $response['validationno'] );
		}

		self::logger(
			\sprintf( '🔄 重新取號成功，新訂單編號：%s', $renew_order_no ),
			'info',
			[ 'renew_order_no' => $renew_order_no ],
			0,
			$order
		);

		return [
			'logistics_id'   => $logistic_number,
			'renew_order_no' => $renew_order_no,
		];
	}

	/**
	 * 解析 Add_Order 回應（mock 模式尊重 env 覆寫，否則走 ApiClient）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 * @throws \Exception 連線失敗
	 */
	private function resolve_add_order_response( \WC_Order $order ): array {
		$override = $this->get_mock_response_override( 'PAYNOW_LOGISTICS_MOCK_RESPONSE' );
		if (null !== $override) {
			return $override;
		}
		return ( new LogisticsApiClient( $order ) )->add_order();
	}

	/**
	 * 解析 Get_Order_Info 回應（mock 模式尊重 env 覆寫，否則走 ApiClient）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 * @throws \Exception 連線失敗
	 */
	private function resolve_query_response( \WC_Order $order ): array {
		// PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=PICKUP_COMPLETE → 模擬取貨完成貨態
		$raw = \str_replace( ' ', '', (string) ( \getenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE' ) ?: '' ) );
		if ('PICKUP_COMPLETE' === \strtoupper( $raw )) {
			return [
				'Status'             => PaynowLogisticsStatus::Active->value,
				'Delivery_Status'    => '買家已取件',
				'PayNowLogisticCode' => '8000',
			];
		}

		return ( new LogisticsApiClient( $order ) )->query_order();
	}

	/**
	 * 取得 mock 回應 env 覆寫（僅 mock 模式生效；非 JSON 物件回 null）
	 *
	 * @param string $env_name env 變數名稱
	 * @return array<string, mixed>|null 解析成功回陣列，否則 null（走正常 ApiClient）
	 */
	private function get_mock_response_override( string $env_name ): ?array {
		if (!self::is_mock()) {
			return null;
		}
		$raw = (string) ( \getenv( $env_name ) ?: '' );
		if ('' === \trim( $raw )) {
			return null;
		}
		$decoded = \json_decode( $raw, true );
		if (!\is_array( $decoded )) {
			return null;
		}
		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/** @return bool 是否為 MOCK 模式（讀 API_MODE） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * 以實際 HTTP 模式執行 callback（暫時關閉 mock），執行後還原原 API_MODE
	 *
	 * 取消（CancelOrder）回應為純文字（含 'S' 代表成功），無對應的成功 / 失敗結構化 fixture，
	 * 須走真實 HTTP 由 PayNow（或測試 pre_http_request）回應。
	 * 比照 ApiClient 單元測試以 API_MODE=live 觸發 wp_remote_* 的慣例。
	 *
	 * @template T
	 * @param callable():T $callback 須走實際 HTTP 的呼叫
	 * @return T
	 * @throws \Throwable Callback 拋出的例外原樣往外拋
	 */
	private function with_live_http( callable $callback ): mixed {
		$original = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=live' );
		try {
			return $callback();
		} finally {
			// 還原原本的 API_MODE（測試 set_up 會再設回 mock，但此處仍對稱還原）
			if (false === $original) {
				\putenv( 'API_MODE' );
			} else {
				\putenv( 'API_MODE=' . $original );
			}
		}
	}

	/**
	 * 是否為取貨完成貨態（買家已取件 8000，或描述含取貨/取件完成字樣）
	 *
	 * @param string $logistic_code 貨態碼
	 * @param string $description    貨態描述
	 * @return bool
	 */
	private function is_pickup_completed( string $logistic_code, string $description ): bool {
		if ('8000' === $logistic_code) {
			return true;
		}
		if ('PICKUP_DONE' === \strtoupper( $logistic_code )) {
			return true;
		}
		return \str_contains( $description, '取貨完成' )
		|| \str_contains( $description, '取件' );
	}

	/**
	 * 是否為 COD（貨到付款）訂單
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private function is_cod_order( \WC_Order $order ): bool {
		return 'cod' === $order->get_payment_method();
	}

	/**
	 * 是否為超商取貨服務（需門市資訊；service_id 無法對應 enum 時保守視為超商）
	 *
	 * @param string $service_id Logistic_serviceID
	 * @return bool 超商回 true，黑貓宅配回 false
	 */
	private function is_cvs_service( string $service_id ): bool {
		$service = PaynowLogisticService::tryFrom( $service_id );
		if (null === $service) {
			return true;
		}
		return $service->is_cvs();
	}

	/**
	 * 金額上限驗證（R9，超商 ≤20000 / 宅配 ≤100000）
	 *
	 * @param \WC_Order $order  訂單
	 * @param bool      $is_cvs 是否為超商路徑
	 * @return void
	 * @throws \Exception 金額超限
	 */
	private function assert_amount_within_limit( \WC_Order $order, bool $is_cvs ): void {
		$total = (int) \round( (float) $order->get_total() );

		if ($is_cvs && $total > self::CVS_AMOUNT_LIMIT) {
			throw new \Exception(
				\sprintf(
					/* translators: %d: amount limit */
					\__( '超商取貨金額不得大於 %d', 'power_checkout' ),
					self::CVS_AMOUNT_LIMIT
				)
			);
		}

		if (!$is_cvs && $total > self::HOME_AMOUNT_LIMIT) {
			throw new \Exception(
				\sprintf(
					/* translators: %d: amount limit */
					\__( '宅配金額不得大於 %d', 'power_checkout' ),
					self::HOME_AMOUNT_LIMIT
				)
			);
		}
	}

	/**
	 * 解析子類型對應的 Logistic_serviceID
	 *
	 * Sub_type 已是 service_id（01-06）時直接回傳；否則查 SUB_TYPE_SERVICE_MAP（SEVEN→01 等）。
	 *
	 * @param string $sub_type 結帳子類型（SEVEN / FAMI / HILIFE / TCAT 或 service_id）
	 * @return string Logistic_serviceID
	 */
	private function resolve_service_id( string $sub_type ): string {
		if (null !== PaynowLogisticService::tryFrom( $sub_type )) {
			return $sub_type;
		}
		return self::SUB_TYPE_SERVICE_MAP[ \strtoupper( $sub_type ) ] ?? $sub_type;
	}

	/**
	 * 組裝選店回呼 returnUrl（cid = order_key，附帶 orderid 供反查）
	 *
	 * @param string    $base_url 選店 callback base URL
	 * @param \WC_Order $order    訂單
	 * @return string
	 */
	private function build_selection_return_url( string $base_url, \WC_Order $order ): string {
		if ('' === \trim( $base_url )) {
			$base_url = LogisticsCallback::get_selection_callback_url();
		}
		return \add_query_arg(
			[
				'cid'     => $order->get_order_key(),
				'orderid' => $order->get_id(),
			],
			$base_url
		);
	}

	/**
	 * 組裝選店導轉表單（auto-submit Form POST 至 Choselogistics）
	 *
	 * @param string                $action 表單 action URL
	 * @param array<string, string> $fields 表單欄位
	 * @return string HTML
	 */
	private function build_selection_form( string $action, array $fields ): string {
		$inputs = '';
		foreach ( $fields as $name => $value ) {
			$inputs .= \sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				\esc_attr( $name ),
				\esc_attr( $value )
			);
		}

		return <<<HTML
		<form id="pc-paynow-logistics-selection" method="POST" action="{$action}">
			{$inputs}
		</form>
		<script>document.getElementById('pc-paynow-logistics-selection').submit();</script>
		HTML;
	}

	/**
	 * 由選店回呼反查訂單（orderid 優先 → cid → order_key）
	 *
	 * @param array<string, mixed> $raw 選店回呼原始資料
	 * @return \WC_Order|null
	 */
	private function resolve_order_from_selection( array $raw ): ?\WC_Order {
		// 優先以 orderid 直接反查
		$order_id = (int) ( $raw['orderid'] ?? 0 );
		if ($order_id > 0) {
			$order = \wc_get_order( $order_id );
			if ($order instanceof \WC_Order) {
				return $order;
			}
		}

		// 次以 cid（order_key）反查
		$cid = (string) ( $raw['cid'] ?? '' );
		if ('' !== \trim( $cid )) {
			$found_id = \wc_get_order_id_by_order_key( $cid );
			if ($found_id > 0) {
				$order = \wc_get_order( $found_id );
				if ($order instanceof \WC_Order) {
					return $order;
				}
			}
		}

		return null;
	}

	/**
	 * 驗證訂單存在（ID > 0）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 * @throws \InvalidArgumentException 訂單不存在
	 */
	private function assert_order_exists( \WC_Order $order ): void {
		if ($order->get_id() <= 0) {
			throw new \InvalidArgumentException( \__( '找不到訂單', 'power_checkout' ) );
		}
	}

	// endregion
}
