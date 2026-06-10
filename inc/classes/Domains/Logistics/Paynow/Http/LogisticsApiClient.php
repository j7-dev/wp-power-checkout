<?php
/**
 * PayNow 物流（立吉富體系 1）API client
 *
 * 五個端點（woomp 對齊 `class-paynow-shipping-request.php`）：
 *  - add_order()    POST /api/Orderapi/Add_Order       body['JsonOrder'] = base64(TripleDES(json))
 *  - renew_order()  POST /api/Orderapi/ReNewOrder      body['JsonOrder'] = base64(TripleDES(json))
 *  - cancel_order() DELETE /api/Orderapi/CancelOrder   body[LogisticNumber/sno/PassCode]（回純文字）
 *  - query_order()  GET /api/Orderapi/Get_Order_Info?LogisticNumber=...&sno=1（回 JSON）
 *  - print_label()  Seven → GET /api/Order711?...；Tcat → POST /Member/Order/PrintBlackCatLabel
 *
 * 加密：JsonOrder = base64( TripleDesCrypto::encrypt_order_json( json_encode($args) ) )。
 *   TripleDesCrypto::encrypt_order_json() 內部已 base64，故 JsonOrder 直接為其回傳值。
 *
 * 端點：test logistic.paynow.com.tw → testlogistic.paynow.com.tw（由 SettingsDTO::api_url() 提供，R8）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API（驗收主軸；sandbox 憑證 GAP）。
 *   ⚠️ is_mock() 讀 getenv('API_MODE')；非 'mock'（如 'live'）一律走真 HTTP（測試以
 *   pre_http_request filter 攔截）。
 *
 * 回應解析：
 *  - add_order / renew_order / query_order：json_decode 為 array 原樣回傳（Status=S/F 由呼叫端判讀）。
 *  - cancel_order：回純文字 raw body（含 'S' 代表成功，woomp strpos 邏輯）。
 *  - print_label：回 raw body（HTML / PDF / 標籤連結字串），呼叫端依 service 自行處理。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 1 步驟 7
 * @see ../woomp/.../shippings/api/class-paynow-shipping-request.php
 * @see inc/classes/Domains/Logistics/Ecpay/Http/LogisticsApiClient.php（鏡像）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Http;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\CreateShipmentParams;
use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Enums\PaynowLogisticService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PassCodeService;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\TripleDesCrypto;
use J7\PowerCheckout\Plugin;

/** PayNow 物流 API client */
final class LogisticsApiClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 45;

	/** @var string 建立物流單端點路徑 */
	private const PATH_ADD_ORDER = '/api/Orderapi/Add_Order';

	/** @var string 重新取號端點路徑 */
	private const PATH_RENEW_ORDER = '/api/Orderapi/ReNewOrder';

	/** @var string 取消物流單端點路徑 */
	private const PATH_CANCEL_ORDER = '/api/Orderapi/CancelOrder';

	/** @var string 查詢物流單端點路徑 */
	private const PATH_QUERY_ORDER = '/api/Orderapi/Get_Order_Info';

	/** @var string 7-11 列印標籤端點路徑 */
	private const PATH_PRINT_SEVEN = '/api/Order711';

	/** @var string 全家列印標籤端點路徑 */
	private const PATH_PRINT_FAMI = '/api/OrderFamiC2C';

	/** @var string 萊爾富列印標籤端點路徑 */
	private const PATH_PRINT_HILIFE = '/api/OrderHiLife';

	/** @var string 黑貓宅配列印標籤端點路徑 */
	private const PATH_PRINT_TCAT = '/Member/Order/PrintBlackCatLabel';

	/** @var PaynowLogisticsSettingsDTO 設定 */
	private readonly PaynowLogisticsSettingsDTO $settings;

	/** @var PaynowLogisticsMetaKeys 訂單 meta 存取器 */
	private readonly PaynowLogisticsMetaKeys $meta;

	/** @var TripleDesCrypto JsonOrder 加密器 */
	private readonly TripleDesCrypto $crypto;

	/** Constructor */
	public function __construct(
		/**
		 * 訂單（用於組裝請求 + order note 記錄）。
		 *
		 * @var \WC_Order
		 */
		private readonly \WC_Order $order,
	) {
		$this->settings = PaynowLogisticsSettingsDTO::instance();
		$this->meta     = new PaynowLogisticsMetaKeys( $order );
		$this->crypto   = new TripleDesCrypto();
	}

	// region 建立 / 重新取號

	/**
	 * 建立物流單（Add_Order）
	 *
	 * @return array<string, mixed> 解析後回應（Status=S/F 由呼叫端判讀）
	 * @throws \Exception 連線失敗
	 */
	public function add_order(): array {
		if (self::is_mock()) {
			return $this->mock_add_order_response();
		}

		$args         = CreateShipmentParams::parse( $this->order, $this->settings->to_array() );
		$encrypt_json = $this->build_json_order( $args );
		$url          = $this->settings->api_url() . self::PATH_ADD_ORDER;

		$body = $this->post( $url, [ 'JsonOrder' => $encrypt_json ] );
		return $this->decode_json( $body );
	}

	/**
	 * 重新取號（ReNewOrder）— 既有物流單號重新取號
	 *
	 * Renew args 含 LogisticNumber（與 add_order 不同），對齊 woomp renew_order()。
	 *
	 * @return array<string, mixed> 解析後回應
	 * @throws \Exception 連線失敗
	 */
	public function renew_order(): array {
		if (self::is_mock()) {
			return $this->mock_renew_order_response();
		}

		$args         = $this->build_renew_args();
		$encrypt_json = $this->build_json_order( $args );
		$url          = $this->settings->api_url() . self::PATH_RENEW_ORDER;

		$body = $this->post( $url, [ 'JsonOrder' => $encrypt_json ] );
		return $this->decode_json( $body );
	}

	// endregion

	// region 取消 / 查詢

	/**
	 * 取消物流單（CancelOrder）— HTTP DELETE，回純文字
	 *
	 * 對齊 woomp cancel_order()：wp_remote_request method=DELETE，body 含 LogisticNumber / sno / PassCode。
	 * 回應為純文字（含 'S' 代表成功）。
	 *
	 * @return string raw response body（含 'S' 代表成功）
	 * @throws \Exception 連線失敗
	 */
	public function cancel_order(): string {
		if (self::is_mock()) {
			return $this->mock_cancel_order_response();
		}

		$logistic_no = $this->meta->get_ref();
		$pass_code   = PassCodeService::build(
			(string) $this->settings->user_account,
			$logistic_no,
			(string) $this->order->get_total(),
			(string) $this->settings->apicode
		);

		$url = $this->settings->api_url() . self::PATH_CANCEL_ORDER;

		return $this->request(
			$url,
			[
				'method' => 'DELETE',
				'body'   => [
					'LogisticNumber' => $logistic_no,
					'sno'            => '1',
					'PassCode'       => $pass_code,
				],
			]
		);
	}

	/**
	 * 查詢物流單貨態（Get_Order_Info）— HTTP GET，回 JSON
	 *
	 * 對齊 woomp query_order()：GET /api/Orderapi/Get_Order_Info?LogisticNumber=...&sno=1。
	 *
	 * @return array<string, mixed> 解析後貨態回應
	 * @throws \Exception 連線失敗
	 */
	public function query_order(): array {
		if (self::is_mock()) {
			return $this->mock_query_order_response();
		}

		$logistic_no = $this->meta->get_ref();
		$url         = $this->settings->api_url() . self::PATH_QUERY_ORDER
		. '?LogisticNumber=' . \rawurlencode( $logistic_no )
		. '&sno=1';

		$body = $this->get( $url );
		return $this->decode_json( $body );
	}

	// endregion

	// region 列印標籤

	/**
	 * 列印物流標籤（依 service_id 分派端點）
	 *
	 * Seven / Fami / Hilife → GET 列印 API（回標籤連結字串）；
	 * Tcat → POST PrintBlackCatLabel（回 PDF）。
	 * 對齊 woomp paynow_print_label() 的端點分派。
	 *
	 * @return string raw response body（標籤連結 / PDF 內容）
	 * @throws \Exception 連線失敗
	 */
	public function print_label(): string {
		if (self::is_mock()) {
			return $this->mock_print_label_response();
		}

		$service_id = $this->meta->get_service_id();
		$service    = PaynowLogisticService::tryFrom( $service_id );

		// 黑貓宅配：POST PrintBlackCatLabel（回 PDF）
		if (PaynowLogisticService::Tcat === $service) {
			$url = $this->settings->api_url() . self::PATH_PRINT_TCAT;
			return $this->request(
				$url,
				[
					'method' => 'POST',
					'body'   => [ 'LogisticNumbers' => $this->resolve_print_order_no() . '_1' ],
				]
			);
		}

		// 超商：GET 列印 API（回標籤連結字串）
		$path = match ( $service ) {
			PaynowLogisticService::Fami   => self::PATH_PRINT_FAMI,
			PaynowLogisticService::Hilife => self::PATH_PRINT_HILIFE,
			default                       => self::PATH_PRINT_SEVEN,
		};

		$url = $this->settings->api_url() . $path
		. '?orderNumberStr=' . \rawurlencode( $this->resolve_print_order_no() )
		. '&user_account=' . \rawurlencode( (string) $this->settings->user_account );

		return $this->get( $url );
	}

	// endregion

	// region 私有：請求組裝

	/**
	 * 組裝 JsonOrder（base64(TripleDES(json_encode(args)))）
	 *
	 * @param array<string, mixed> $args Add_Order / ReNewOrder 內層 args
	 * @return string base64 後密文
	 */
	private function build_json_order( array $args ): string {
		$json = (string) \wp_json_encode( $args );
		return $this->crypto->encrypt_order_json( $json );
	}

	/**
	 * 組裝重新取號 args（含 LogisticNumber，對齊 woomp renew_order）
	 *
	 * @return array<string, mixed>
	 */
	private function build_renew_args(): array {
		$order_no    = $this->resolve_order_no();
		$logistic_no = $this->meta->get_ref();
		$total       = (string) $this->order->get_total();

		return [
			'user_account'   => (string) $this->settings->user_account,
			'apicode'        => (string) $this->settings->apicode,
			'OrderNo'        => $order_no,
			'LogisticNumber' => $logistic_no,
			'PassCode'       => PassCodeService::build(
				(string) $this->settings->user_account,
				$order_no,
				$total,
				(string) $this->settings->apicode
			),
			'TotalAmount'    => $this->order->get_total(),
			'sno'            => 1,
		];
	}

	/**
	 * 解析 OrderNo（meta 優先，缺漏 fallback PCN{id}）
	 *
	 * @return string
	 */
	private function resolve_order_no(): string {
		$order_no = $this->meta->get_order_no();
		return '' !== $order_no ? $order_no : 'PCN' . $this->order->get_id();
	}

	/**
	 * 解析列印用訂單編號（RenewOrderNo 優先，缺漏 fallback order_number）
	 *
	 * @return string
	 */
	private function resolve_print_order_no(): string {
		$renew = $this->meta->get_renew_order_no();
		return '' !== $renew ? $renew : (string) $this->order->get_order_number();
	}

	// endregion

	// region 私有：HTTP

	/**
	 * HTTP GET，回 raw body
	 *
	 * @param string $url 端點 URL
	 * @return string raw response body
	 * @throws \Exception 連線失敗
	 */
	private function get( string $url ): string {
		Plugin::logger(
			"PayNow 物流 GET #{$this->order->get_id()}",
			'info',
			[ 'url' => $url ]
		);

		$response = \wp_remote_get(
			$url,
			[
				'timeout'  => self::TIMEOUT,
				'blocking' => true,
				'headers'  => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
			]
		);

		return $this->retrieve_body( $response );
	}

	/**
	 * HTTP POST，回 raw body
	 *
	 * @param string               $url  端點 URL
	 * @param array<string, mixed> $body 表單 body
	 * @return string raw response body
	 * @throws \Exception 連線失敗
	 */
	private function post( string $url, array $body ): string {
		Plugin::logger(
			"PayNow 物流 POST #{$this->order->get_id()}",
			'info',
			[ 'url' => $url ]
		);

		$response = \wp_remote_post(
			$url,
			[
				'timeout'  => self::TIMEOUT,
				'blocking' => true,
				'headers'  => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
				'body'     => $body,
			]
		);

		return $this->retrieve_body( $response );
	}

	/**
	 * HTTP 自訂方法（DELETE 等），回 raw body
	 *
	 * @param string               $url  端點 URL
	 * @param array<string, mixed> $args wp_remote_request 參數（method / body）
	 * @return string raw response body
	 * @throws \Exception 連線失敗
	 */
	private function request( string $url, array $args ): string {
		$method = (string) ( $args['method'] ?? 'GET' );
		Plugin::logger(
			"PayNow 物流 {$method} #{$this->order->get_id()}",
			'info',
			[ 'url' => $url ]
		);

		$response = \wp_remote_request(
			$url,
			\wp_parse_args(
				$args,
				[
					'timeout'  => self::TIMEOUT,
					'blocking' => true,
				]
			)
		);

		return $this->retrieve_body( $response );
	}

	/**
	 * 取回 response body（連線失敗時 throw + order note）
	 *
	 * @param array<mixed>|\WP_Error $response wp_remote_* 回應
	 * @return string raw response body
	 * @throws \Exception 連線失敗
	 */
	private function retrieve_body( array|\WP_Error $response ): string {
		if (\is_wp_error( $response )) {
			$msg = "PayNow 物流連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return \wp_remote_retrieve_body( $response );
	}

	/**
	 * 解析 JSON 回應為 array（解析失敗回空陣列）
	 *
	 * @param string $body raw JSON body
	 * @return array<string, mixed>
	 */
	private function decode_json( string $body ): array {
		$decoded = \json_decode( $body, true );
		return \is_array( $decoded ) ? $decoded : [];
	}

	// endregion

	// region MOCK fixture

	/** @return bool 是否為 MOCK 模式（讀 API_MODE，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * MOCK：Add_Order 回應（固定成功 fixture）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_add_order_response(): array {
		$order_no = $this->resolve_order_no();
		return [
			'Status'         => 'S',
			'LogisticNumber' => 'mock_ln_' . $this->order->get_id(),
			'orderno'        => $order_no,
			'paymentno'      => 'mock_pmt_' . $this->order->get_id(),
			'validationno'   => 'mock_val_' . $this->order->get_id(),
			'ReturnMsg'      => '建立成功（MOCK）',
		];
	}

	/**
	 * MOCK：ReNewOrder 回應（固定成功 fixture）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_renew_order_response(): array {
		return [
			'Status'         => 'S',
			'OrderNo'        => $this->resolve_order_no(),
			'LogisticNumber' => $this->meta->get_ref() ?: ( 'mock_ln_' . $this->order->get_id() ),
			'paynoworderno'  => 'mock_renew_' . $this->order->get_id(),
			'paymentno'      => 'mock_pmt_' . $this->order->get_id(),
			'validationno'   => 'mock_val_' . $this->order->get_id(),
			'ReturnMsg'      => '重新取號成功（MOCK）',
		];
	}

	/**
	 * MOCK：CancelOrder 回應（固定純文字，含 'S' 代表成功）
	 *
	 * @return string
	 */
	private function mock_cancel_order_response(): string {
		return 'S|取消物流單成功（MOCK）';
	}

	/**
	 * MOCK：Get_Order_Info 回應（固定貨態 fixture）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_query_order_response(): array {
		return [
			'LogisticNumber'     => $this->meta->get_ref() ?: ( 'mock_ln_' . $this->order->get_id() ),
			'sno'                => '1',
			'Status'             => '0',
			'Delivery_Status'    => '處理中（MOCK）',
			'PayNowLogisticCode' => '0001',
			'paymentno'          => 'mock_pmt_' . $this->order->get_id(),
			'validationno'       => 'mock_val_' . $this->order->get_id(),
		];
	}

	/**
	 * MOCK：print_label 回應（固定標籤連結字串）
	 *
	 * @return string
	 */
	private function mock_print_label_response(): string {
		return 'S|https://testlogistic.paynow.com.tw/label/mock_' . $this->order->get_id() . '.pdf';
	}

	// endregion
}
