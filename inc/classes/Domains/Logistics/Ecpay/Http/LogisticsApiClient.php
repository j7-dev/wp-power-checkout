<?php
/**
 * 綠界全方位物流 v2（AllInOne）API client
 *
 * 三層請求結構：{ MerchantID, RqHeader{ Timestamp, Revision }, Data: AES-128-CBC 加密(JSON) }
 *  - ⚠️ 全方位物流 v2 的 RqHeader 必須含 Revision="1.0.0"（與站內付 2.0 不同：站內付不可帶 Revision，
 *    帶了反而報錯）。因此本 client 自有 build_envelope，絕不複用 Ecpg 的 envelope（風險 R3）。
 *  - ⚠️ Timestamp 驗證視窗僅 5 分鐘（比 ECPG / 跨境物流的 10 分鐘短，風險 R1）：每次 build_envelope
 *    內即時呼叫 \time()，禁止快取 / 預算 / 複製。
 *  - MerchantID / HashKey / HashIV 一律透過 EcpayLogisticsSettingsDTO::get_active_*() 依 account_type
 *    取得（B2C / C2C，風險 R5），不直接讀 b2c_* / c2c_*。
 *
 * AES-128-CBC 加解密直接複用站內付 2.0 的 {@see AesCrypto}（guide 07 確認規則與物流 v2 完全一致：
 * JSON → urlencode → AES-128-CBC → base64），不複製、不提取（計畫 T5）。
 *
 * 雙層錯誤檢查（AES-JSON 回應，風險 R4，鏡像 EcpgApiClient::parse_response）：
 *  1. 傳輸層 TransCode（外層）：一律 (int) 後 ===1 才可解密 Data；非 1 → throw「傳輸層」+ order note。
 *  2. 業務層 RtnCode（解密後 Data 內）：一律 (int) 後 ===1 才視為成功；非 1 → throw「業務層」+ order note。
 *     ⚠️ 型別陷阱：AES-JSON 解密後 RtnCode/TransCode 可能為整數或字串，一律 (int) 比對。
 *
 * 端點：stage logistics-stage.ecpay.com.tw、prod logistics.ecpay.com.tw（由 SettingsDTO::api_url 提供），
 * 前綴 /Express/v2/。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 * @see .claude/skills/ECPay-API-Skill/guides/19-http-protocol-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\Http;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateReturnParams;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\CreateShipmentParams;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\StoreSelectionParams;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Plugin;

/** 綠界全方位物流 v2 API client */
final class LogisticsApiClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string 全方位物流 v2 RqHeader 固定版本號（風險 R3） */
	private const REVISION = '1.0.0';

	/** @var string 物流選擇頁面重導端點路徑（回 HTML body） */
	private const PATH_REDIRECT_SELECTION = '/Express/v2/RedirectToLogisticsSelection';

	/** @var string 成立物流單端點路徑（AES-JSON 回應） */
	private const PATH_CREATE_BY_TEMP_TRADE = '/Express/v2/CreateByTempTrade';

	/** @var string 查詢物流單端點路徑（AES-JSON 回應） */
	private const PATH_QUERY = '/Express/v2/QueryLogisticsTradeInfo';

	/** @var string 列印物流單端點路徑（回 HTML body） */
	private const PATH_PRINT = '/Express/v2/PrintTradeDocument';

	/** @var string C2C 取消物流單端點路徑（AES-JSON 回應） */
	private const PATH_CANCEL_C2C = '/Express/v2/CancelC2COrder';

	/** @var string 全家逆物流（退貨）端點路徑（AES-JSON 回應） */
	private const PATH_RETURN_CVS = '/Express/v2/ReturnCVS';

	/** @var string 統一超商逆物流（退貨）端點路徑（AES-JSON 回應） */
	private const PATH_RETURN_UNIMART_CVS = '/Express/v2/ReturnUniMartCVS';

	/** @var string 萊爾富逆物流（退貨）端點路徑（AES-JSON 回應） */
	private const PATH_RETURN_HILIFE_CVS = '/Express/v2/ReturnHilifeCVS';

	/** @var string 宅配逆物流（退貨）端點路徑（AES-JSON 回應） */
	private const PATH_RETURN_HOME = '/Express/v2/ReturnHome';

	/** @var EcpayLogisticsSettingsDTO 設定 */
	private readonly EcpayLogisticsSettingsDTO $settings;

	/** @var AesCrypto 加解密器（依 account_type 取啟用帳號憑證；複用站內付 2.0 AesCrypto） */
	private readonly AesCrypto $crypto;

	/** Constructor */
	public function __construct(
		/**
		 * 訂單（用於 order note 記錄）；cart 級選店（下單前）無訂單，故可為 null。
		 *
		 * @var \WC_Order|null
		 */
		private readonly ?\WC_Order $order = null,
	) {
		$this->settings = EcpayLogisticsSettingsDTO::instance();
		$this->crypto   = new AesCrypto(
			$this->settings->get_active_hash_key(),
			$this->settings->get_active_hash_iv()
		);
	}

	/**
	 * 安全寫 order note（cart 級選店無訂單時靜默略過）
	 *
	 * @param string $note 訊息
	 * @return void
	 */
	private function add_order_note( string $note ): void {
		if ($this->order instanceof \WC_Order) {
			$this->order->add_order_note( $note );
		}
	}

	/**
	 * 取得訂單 id（無訂單時回 0，供 log 用）
	 *
	 * @return int
	 */
	private function get_order_id(): int {
		return $this->order instanceof \WC_Order ? $this->order->get_id() : 0;
	}

	/**
	 * 階段 A：產生物流選擇頁面（建立暫存訂單）— 回 HTML body
	 *
	 * 使用 AES-Str 回應（回應是 HTML 重導頁面，非 JSON）。
	 *
	 * @param StoreSelectionParams $params 選店參數（含 IsCollection / Temperature / LogisticsSubType）
	 *
	 * @return string HTML body（RWD 選店頁面，前端輸出後消費者進行選店）
	 * @throws \Exception 連線失敗
	 */
	public function redirect_to_logistics_selection( StoreSelectionParams $params ): string {
		// MOCK 模式：不打真 API，回固定 HTML fixture
		if (self::is_mock()) {
			return $this->mock_redirect_html();
		}

		$url = $this->settings->api_url . self::PATH_REDIRECT_SELECTION;
		return $this->request_html( $url, $params->to_ecpay_data() );
	}

	/**
	 * 階段 B：成立物流單（CreateByTempTrade）— AES-JSON 雙層檢查
	 *
	 * @param CreateShipmentParams $params 成立物流單參數（TempLogisticsID）
	 *
	 * @return array<string, mixed> 解密後 Data（含 LogisticsID）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function create_by_temp_trade( CreateShipmentParams $params ): array {
		// MOCK 模式：回固定 fixture（已是解密後 Data 格式，RtnCode 整數 1）
		if (self::is_mock()) {
			return $this->mock_create_shipment_response( $params->TempLogisticsID );
		}

		$url = $this->settings->api_url . self::PATH_CREATE_BY_TEMP_TRADE;
		return $this->request_json( $url, $params->to_ecpay_data() );
	}

	/**
	 * 查詢物流單（QueryLogisticsTradeInfo）— AES-JSON 雙層檢查
	 *
	 * @param string $logistics_id 統一物流單號 LogisticsID
	 *
	 * @return array<string, mixed> 解密後 Data（含 LogisticsID / LogisticsStatus）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function query( string $logistics_id ): array {
		// MOCK 模式：回固定 fixture
		if (self::is_mock()) {
			return $this->mock_query_response( $logistics_id );
		}

		$data = [
			'MerchantID'  => $this->settings->get_active_merchant_id(),
			'LogisticsID' => $logistics_id,
		];

		$url = $this->settings->api_url . self::PATH_QUERY;
		return $this->request_json( $url, $data );
	}

	/**
	 * 列印物流單（PrintTradeDocument）— 回 HTML body
	 *
	 * @param array<int, string> $logistics_ids LogisticsID 陣列（可多筆）
	 * @param string             $sub_type      物流子類型（FAMI / UNIMART / HILIFE / HOME）
	 *
	 * @return string HTML body（列印文件）
	 * @throws \Exception 連線失敗
	 */
	public function print_trade_document( array $logistics_ids, string $sub_type ): string {
		// MOCK 模式：回固定 HTML fixture
		if (self::is_mock()) {
			return $this->mock_print_html( $logistics_ids );
		}

		$data = [
			'MerchantID'       => $this->settings->get_active_merchant_id(),
			'LogisticsID'      => \array_values( $logistics_ids ),
			'LogisticsSubType' => $sub_type,
		];

		$url = $this->settings->api_url . self::PATH_PRINT;
		return $this->request_html( $url, $data );
	}

	/**
	 * 取消 C2C 物流單（CancelC2COrder）— AES-JSON 雙層檢查
	 *
	 * ⚠️ 僅 C2C 帳號支援；B2C 帳號的限制由 provider 前置驗證把關。
	 *
	 * @param string $logistics_id      統一物流單號 LogisticsID
	 * @param string $cvs_payment_no    C2C 寄貨編號 CVSPaymentNo
	 * @param string $cvs_validation_no C2C 驗證碼 CVSValidationNo
	 *
	 * @return array<string, mixed> 解密後 Data
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function cancel_c2c( string $logistics_id, string $cvs_payment_no, string $cvs_validation_no ): array {
		// MOCK 模式：回固定 fixture
		if (self::is_mock()) {
			return $this->mock_cancel_c2c_response( $logistics_id, $cvs_payment_no );
		}

		$data = [
			'MerchantID'      => $this->settings->get_active_merchant_id(),
			'LogisticsID'     => $logistics_id,
			'CVSPaymentNo'    => $cvs_payment_no,
			'CVSValidationNo' => $cvs_validation_no,
		];

		$url = $this->settings->api_url . self::PATH_CANCEL_C2C;
		return $this->request_json( $url, $data );
	}

	// region 逆物流（退貨）— 依原物流子類型分派四個端點，AES-JSON 雙層檢查

	/**
	 * 全家逆物流（退貨）ReturnCVS — AES-JSON 雙層檢查
	 *
	 * @param CreateReturnParams $params 退貨參數（超商欄位：ServiceType / SenderName / [SenderPhone]）
	 *
	 * @return array<string, mixed> 解密後 Data（含 ReturnLogisticsID）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function return_cvs( CreateReturnParams $params ): array {
		return $this->request_return( self::PATH_RETURN_CVS, $params );
	}

	/**
	 * 統一超商逆物流（退貨）ReturnUniMartCVS — AES-JSON 雙層檢查
	 *
	 * @param CreateReturnParams $params 退貨參數
	 *
	 * @return array<string, mixed> 解密後 Data（含 ReturnLogisticsID）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function return_unimart_cvs( CreateReturnParams $params ): array {
		return $this->request_return( self::PATH_RETURN_UNIMART_CVS, $params );
	}

	/**
	 * 萊爾富逆物流（退貨）ReturnHilifeCVS — AES-JSON 雙層檢查
	 *
	 * @param CreateReturnParams $params 退貨參數
	 *
	 * @return array<string, mixed> 解密後 Data（含 ReturnLogisticsID）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function return_hilife_cvs( CreateReturnParams $params ): array {
		return $this->request_return( self::PATH_RETURN_HILIFE_CVS, $params );
	}

	/**
	 * 宅配逆物流（退貨）ReturnHome — AES-JSON 雙層檢查
	 *
	 * @param CreateReturnParams $params 退貨參數（宅配欄位：Temperature / Distance / Specification）
	 *
	 * @return array<string, mixed> 解密後 Data（含 ReturnLogisticsID）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function return_home( CreateReturnParams $params ): array {
		return $this->request_return( self::PATH_RETURN_HOME, $params );
	}

	/**
	 * 共用逆物流請求：注入啟用帳號 MerchantID（綠界逆物流 Data 內亦需）→ 雙層檢查
	 *
	 * MOCK 模式回固定 fixture（已是解密後 Data 格式，RtnCode 整數 1，含 ReturnLogisticsID）。
	 *
	 * @param string             $path   端點路徑
	 * @param CreateReturnParams $params 退貨參數
	 *
	 * @return array<string, mixed> 解密後 Data（含 ReturnLogisticsID）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	private function request_return( string $path, CreateReturnParams $params ): array {
		// MOCK 模式：回固定 fixture
		if (self::is_mock()) {
			return $this->mock_return_response( $params->LogisticsID );
		}

		$data = \array_merge(
			[ 'MerchantID' => $this->settings->get_active_merchant_id() ],
			$params->to_ecpay_data()
		);

		$url = $this->settings->api_url . $path;
		return $this->request_json( $url, $data );
	}

	// endregion

	/**
	 * 組裝三層請求結構
	 *
	 * ⚠️ 全方位物流 v2 的 RqHeader 必須含 Revision="1.0.0"（風險 R3）。
	 * ⚠️ Timestamp 每次即時呼叫 \time()（風險 R1，5 分鐘視窗），禁止快取 / 預算 / 複製。
	 *
	 * 公開以利測試（驗證 Revision + 即時 Timestamp + Data 可解密還原）。
	 *
	 * @param array<string, mixed> $data 內層 Data 明文
	 *
	 * @return array<string, mixed>
	 */
	public function build_envelope( array $data ): array {
		return [
			'MerchantID' => $this->settings->get_active_merchant_id(),
			'RqHeader'   => [
				// 即時 time()，禁止快取（風險 R1，5 分鐘視窗）
				'Timestamp' => \time(),
				// 全方位物流 v2 固定版本號（風險 R3），與站內付 2.0 不同
				'Revision'  => self::REVISION,
			],
			'Data'       => $this->crypto->encrypt( $data ),
		];
	}

	/**
	 * 解析 AES-JSON 回應：雙層整數檢查（TransCode 傳輸層 → 解密 Data → RtnCode 業務層）
	 *
	 * 拆為獨立 public 方法以利測試（不需真 HTTP）。
	 * ⚠️ 型別陷阱（風險 R4）：TransCode / RtnCode 可能為整數或字串，一律 (int) 後 ===1 比對。
	 *
	 * @param array{TransCode?: int|string, TransMsg?: string, Data?: string} $body 外層回應
	 *
	 * @return array<string, mixed> 解密後的 Data
	 * @throws \Exception 傳輸層（TransCode≠1）或業務層（RtnCode≠1）失敗
	 */
	public function parse_response( array $body ): array {
		// 第一層：傳輸層 TransCode（一律 (int) 後 ===1）
		$trans_code = (int) ( $body['TransCode'] ?? 0 );
		if (1 !== $trans_code) {
			$trans_msg = (string) ( $body['TransMsg'] ?? 'unknown' );
			$msg       = "綠界全方位物流傳輸層失敗 TransCode={$trans_code}：{$trans_msg}";
			$this->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 解密 Data（複用站內付 2.0 AesCrypto）
		$decrypted = $this->crypto->decrypt( (string) ( $body['Data'] ?? '' ) );

		// 第二層：業務層 RtnCode（一律 (int) 後 ===1）
		$rtn_code = (int) ( $decrypted['RtnCode'] ?? 0 );
		if (1 !== $rtn_code) {
			$rtn_msg = (string) ( $decrypted['RtnMsg'] ?? 'unknown' );
			$msg     = "綠界全方位物流業務層失敗 RtnCode={$rtn_code}：{$rtn_msg}";
			$this->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return $decrypted;
	}

	/**
	 * 發送 AES-JSON 請求並做雙層錯誤檢查
	 *
	 * @param string               $url  端點 URL
	 * @param array<string, mixed> $data 內層 Data 明文
	 *
	 * @return array<string, mixed> 解密後的 Data
	 * @throws \Exception 連線失敗 / 傳輸層 / 業務層失敗
	 */
	private function request_json( string $url, array $data ): array {
		$body = $this->post( $url, $data );

		/** @var array{TransCode?: int|string, TransMsg?: string, Data?: string} $decoded */
		$decoded = \json_decode( $body, true, 512, JSON_THROW_ON_ERROR );

		return $this->parse_response( $decoded );
	}

	/**
	 * 發送 AES-Str 請求並回傳 HTML body（選店頁 / 列印文件）
	 *
	 * AES-Str 回應為 HTML（非 JSON），不做雙層 JSON 解析。
	 *
	 * @param string               $url  端點 URL
	 * @param array<string, mixed> $data 內層 Data 明文
	 *
	 * @return string HTML body
	 * @throws \Exception 連線失敗
	 */
	private function request_html( string $url, array $data ): string {
		return $this->post( $url, $data );
	}

	/**
	 * 共用 HTTP POST（組 envelope → wp_remote_post → 回 raw body）
	 *
	 * @param string               $url  端點 URL
	 * @param array<string, mixed> $data 內層 Data 明文
	 *
	 * @return string raw response body
	 * @throws \Exception 連線失敗
	 */
	private function post( string $url, array $data ): string {
		$envelope = $this->build_envelope( $data );

		Plugin::logger(
			"綠界全方位物流 v2 請求 #{$this->get_order_id()}",
			'info',
			[ 'url' => $url ]
		);

		$response = \wp_remote_post(
			$url,
			[
				'body'     => (string) \wp_json_encode( $envelope ),
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if (\is_wp_error( $response )) {
			$msg = "綠界全方位物流 v2 連線失敗：{$response->get_error_message()}";
			$this->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return \wp_remote_retrieve_body( $response );
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * MOCK：RedirectToLogisticsSelection 回應（固定 HTML fixture）
	 *
	 * @return string
	 */
	private function mock_redirect_html(): string {
		return '<!DOCTYPE html><html><body>'
		. '<form id="mock_ecpay_logistics" method="post" action="https://logistics-stage.ecpay.com.tw/Express/v2/RedirectToLogisticsSelection">'
		. '<input type="hidden" name="MerchantID" value="' . \esc_attr( $this->settings->get_active_merchant_id() ) . '" />'
		. '</form>'
		. '<p>ECPay 全方位物流選店頁面（MOCK）</p>'
		. '</body></html>';
	}

	/**
	 * MOCK：PrintTradeDocument 回應（固定 HTML fixture）
	 *
	 * @param array<int, string> $logistics_ids LogisticsID 陣列
	 *
	 * @return string
	 */
	private function mock_print_html( array $logistics_ids ): string {
		$ids = \implode( ', ', \array_map( 'strval', $logistics_ids ) );
		return '<!DOCTYPE html><html><body>'
		. '<div class="mock-ecpay-print">ECPay 物流單列印（MOCK）LogisticsID: ' . \esc_html( $ids ) . '</div>'
		. '</body></html>';
	}

	/**
	 * MOCK：CreateByTempTrade 回應（固定 fixture，已是解密後 Data 格式）
	 *
	 * @param string $temp_logistics_id 暫存物流單號
	 *
	 * @return array<string, mixed>
	 */
	private function mock_create_shipment_response( string $temp_logistics_id ): array {
		return [
			'RtnCode'         => 1,
			'RtnMsg'          => 'OK',
			'TempLogisticsID' => $temp_logistics_id,
			'LogisticsID'     => 'mock_lg_' . $temp_logistics_id,
			'CVSPaymentNo'    => 'mock_cvspn_' . $temp_logistics_id,
			'CVSValidationNo' => 'mock_cvsvn_' . $temp_logistics_id,
		];
	}

	/**
	 * MOCK：QueryLogisticsTradeInfo 回應（固定 fixture）
	 *
	 * @param string $logistics_id 統一物流單號
	 *
	 * @return array<string, mixed>
	 */
	private function mock_query_response( string $logistics_id ): array {
		return [
			'RtnCode'         => 1,
			'RtnMsg'          => 'OK',
			'LogisticsID'     => $logistics_id,
			'LogisticsStatus' => '300',
			'LogisticsType'   => 'CVS',
			'StoreID'         => 'mock_store_001',
			'StoreName'       => 'MOCK 門市',
		];
	}

	/**
	 * MOCK：CancelC2COrder 回應（固定 fixture）
	 *
	 * @param string $logistics_id   統一物流單號
	 * @param string $cvs_payment_no C2C 寄貨編號
	 *
	 * @return array<string, mixed>
	 */
	private function mock_cancel_c2c_response( string $logistics_id, string $cvs_payment_no ): array {
		return [
			'RtnCode'      => 1,
			'RtnMsg'       => 'OK',
			'LogisticsID'  => $logistics_id,
			'CVSPaymentNo' => $cvs_payment_no,
		];
	}

	/**
	 * MOCK：逆物流（退貨）回應（固定 fixture，已是解密後 Data 格式，含 ReturnLogisticsID）
	 *
	 * @param string $logistics_id 原正向物流單號
	 *
	 * @return array<string, mixed>
	 */
	private function mock_return_response( string $logistics_id ): array {
		return [
			'RtnCode'           => 1,
			'RtnMsg'            => 'OK',
			'LogisticsID'       => $logistics_id,
			'ReturnLogisticsID' => 'mock_ret_' . $logistics_id,
		];
	}
}
