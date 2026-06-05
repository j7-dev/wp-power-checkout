<?php
/**
 * PAYUNi 統一金流物流 API client
 *
 * 4 欄位 envelope（payuni-logistics-v3 encryption.md §3.5）：
 *   { MerID, Version, EncryptInfo, HashInfo }
 *   - EncryptInfo = PayuniCrypto::encrypt(內層業務參數，含 MerID + Timestamp)
 *   - HashInfo    = PayuniCrypto::hash_info(EncryptInfo)
 *   - Content-Type: application/x-www-form-urlencoded；Header User-Agent: payuni
 *
 * ⚠️ 與綠界（AES-128-CBC + 三層 MerchantID/RqHeader/Data）截然不同：
 *   - PAYUNi 用 AES-256-GCM（{@see PayuniCrypto}）。
 *   - 回應驗簽：先 verify_hash(EncryptInfo, HashInfo) → 再外層 Status==='SUCCESS' → 才 decrypt。
 *   - Timestamp 每次即時 \time()，禁止快取。
 *
 * 端點（payuni-logistics-v3 §2）：
 *   ship_map（1.1，Form POST → HTML 選店頁）/ trade（1.3，建單）/ query（1.1）/
 *   print_label（1.0，Form POST → HTML）/ refund（1.0，C2B 退貨便）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/payuni-logistics-v3/references/cvs-apis.md
 * @see .claude/skills/payuni-logistics-v3/references/encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Http;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\ShipMapParams;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\TradeParams;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Plugin;

/** PAYUNi 統一金流物流 API client */
final class PayuniLogisticsApiClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string ship_map 門市地圖端點路徑（Form POST → HTML） */
	private const PATH_SHIP_MAP = '/api/logistics/ship_map';

	/** @var string 建立超商物流單端點路徑 */
	private const PATH_TRADE = '/api/logistics/trade';

	/** @var string 物流單查詢端點路徑 */
	private const PATH_QUERY = '/api/logistics/query';

	/** @var string 出貨單列印端點路徑（Form POST → HTML） */
	private const PATH_PRINT = '/api/logistics/print_label';

	/** @var string C2B 退貨便要號端點路徑 */
	private const PATH_REFUND = '/api/logistics/refund';

	/** @var string ship_map API 版本 */
	private const VER_SHIP_MAP = '1.1';

	/** @var string trade API 版本 */
	private const VER_TRADE = '1.3';

	/** @var string query API 版本 */
	private const VER_QUERY = '1.1';

	/** @var string print_label API 版本 */
	private const VER_PRINT = '1.0';

	/** @var string refund API 版本 */
	private const VER_REFUND = '1.0';

	/** @var PayuniLogisticsSettingsDTO 設定 */
	private readonly PayuniLogisticsSettingsDTO $settings;

	/** @var PayuniCrypto AES-256-GCM 加解密器 */
	private readonly PayuniCrypto $crypto;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（用於 order note 記錄） */
		private readonly \WC_Order $order,
	) {
		$this->settings = PayuniLogisticsSettingsDTO::instance();
		$this->crypto   = new PayuniCrypto(
			$this->settings->hash_key,
			$this->settings->hash_iv
		);
	}

	/**
	 * 階段 A：產生 ship_map 門市地圖選店頁（Form POST 自動送出）— 回 HTML body
	 *
	 * PAYUNi ship_map 是前景 Form POST，需後端產出 auto-submit form 讓瀏覽器 POST 至 PAYUNi。
	 *
	 * @param ShipMapParams $params ship_map 參數
	 *
	 * @return string HTML body（auto-submit form，前端輸出後消費者進行選店）
	 */
	public function ship_map( ShipMapParams $params ): string {
		// MOCK 模式：不打真 API，回固定 HTML fixture
		if (self::is_mock()) {
			return $this->mock_ship_map_html();
		}

		$url      = $this->settings->api_url . self::PATH_SHIP_MAP;
		$envelope = $this->build_envelope( $params->to_array(), self::VER_SHIP_MAP );
		return $this->build_auto_submit_form( $url, $envelope );
	}

	/**
	 * 階段 B：建立超商物流單（trade）— 驗簽 + 解密
	 *
	 * @param TradeParams $params 建單參數（含 StoreID + 完整收件人）
	 *
	 * @return array<string, mixed> 解密後內層（含 ShipTradeNo）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function trade( TradeParams $params ): array {
		// MOCK 模式：回固定 fixture（已是解密後內層格式）
		if (self::is_mock()) {
			return $this->mock_trade_response( $params->MerTradeNo );
		}

		$url = $this->settings->api_url . self::PATH_TRADE;
		return $this->request_json( $url, $params->to_payuni_data(), self::VER_TRADE );
	}

	/**
	 * 物流單查詢（query）— 驗簽 + 解密
	 *
	 * @param string $ship_trade_no UNi 物流序號 ShipTradeNo
	 * @param string $lgs_type      物流型態（B2C / C2C / HOME）
	 *
	 * @return array<string, mixed> 解密後內層（含 ShipStatus）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function query( string $ship_trade_no, string $lgs_type ): array {
		// MOCK 模式：回固定 fixture
		if (self::is_mock()) {
			return $this->mock_query_response( $ship_trade_no, $lgs_type );
		}

		$data = [
			'LgsType'     => $lgs_type,
			'ShipTradeNo' => $ship_trade_no,
		];

		$url = $this->settings->api_url . self::PATH_QUERY;
		return $this->request_json( $url, $data, self::VER_QUERY );
	}

	/**
	 * 出貨單列印（print_label）— 回 HTML body（Form POST → PDF / 列印頁）
	 *
	 * @param array<int, string> $ship_trade_nos UNi 物流序號陣列（最多 50 筆）
	 * @param string             $lgs_type       物流型態（B2C / C2C）
	 * @param int                $goods_type     寄件型態（1=常溫 / 2=冷凍）
	 * @param string             $ship_date      出貨日期 YYYYMMDD（B2C 不得為當日）
	 *
	 * @return string HTML body（auto-submit form）
	 */
	public function print_label( array $ship_trade_nos, string $lgs_type, int $goods_type, string $ship_date ): string {
		// MOCK 模式：回固定 HTML fixture
		if (self::is_mock()) {
			return $this->mock_print_html( $ship_trade_nos );
		}

		$data = [
			'ShipTradeNo' => \implode( ',', \array_values( $ship_trade_nos ) ),
			'GoodsType'   => $goods_type,
			'LgsType'     => $lgs_type,
			'ShipType'    => 1,
			'ShipDate'    => $ship_date,
			'LabelMode'   => 1,
		];

		$url      = $this->settings->api_url . self::PATH_PRINT;
		$envelope = $this->build_envelope( $data, self::VER_PRINT );
		return $this->build_auto_submit_form( $url, $envelope );
	}

	/**
	 * C2B 退貨便要號（refund）— 驗簽 + 解密
	 *
	 * ⚠️ 僅 B2C 大宗寄倉常溫商店可用（GoodsType 固定 1，LgsType 固定 C2B）。
	 *
	 * @param string $ship_trade_no 原 UNi 物流序號
	 * @param int    $trade_amt     商品金額（1~20,000）
	 * @param int    $service_type  4=退貨付款 / 5=退貨不付款
	 *
	 * @return array<string, mixed> 解密後內層（含 RefundODNO + ValidationNo）
	 * @throws \Exception 傳輸層 / 業務層失敗
	 */
	public function refund( string $ship_trade_no, int $trade_amt, int $service_type ): array {
		// MOCK 模式：回固定 fixture
		if (self::is_mock()) {
			return $this->mock_refund_response( $ship_trade_no );
		}

		$data = [
			'ShipTradeNo' => $ship_trade_no,
			'GoodsType'   => 1,      // C2B 退貨便固定常溫
			'LgsType'     => 'C2B',
			'ShipType'    => 1,
			'TradeAmt'    => $trade_amt,
			'ServiceType' => $service_type,
			'ShipAmt'     => 5 === $service_type ? 0 : $trade_amt,
			'ProcessType' => 1,      // 固定 1
		];

		$url = $this->settings->api_url . self::PATH_REFUND;
		return $this->request_json( $url, $data, self::VER_REFUND );
	}

	/**
	 * 組裝 4 欄位 envelope（MerID / Version / EncryptInfo / HashInfo）
	 *
	 * 內層業務參數一律注入 MerID + 即時 Timestamp（禁快取）。公開以利測試。
	 *
	 * @param array<string, mixed> $data    內層業務參數明文
	 * @param string               $version API 版本
	 *
	 * @return array{MerID: string, Version: string, EncryptInfo: string, HashInfo: string}
	 */
	public function build_envelope( array $data, string $version ): array {
		$inner = \array_merge(
			[
				'MerID'     => $this->settings->mer_id,
				// 即時 time()，禁止快取
				'Timestamp' => \time(),
			],
			$data
		);

		$encrypt_info = $this->crypto->encrypt( $inner );
		$hash_info    = $this->crypto->hash_info( $encrypt_info );

		return [
			'MerID'       => $this->settings->mer_id,
			'Version'     => $version,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];
	}

	/**
	 * 解析 PAYUNi JSON 回應：驗簽 → 外層 Status → 解密內層
	 *
	 * 拆為獨立 public 方法以利測試（不需真 HTTP）。
	 *
	 * @param array{Status?: string, Message?: string, EncryptInfo?: string, HashInfo?: string} $body 外層回應
	 *
	 * @return array<string, mixed> 解密後內層
	 * @throws \Exception 驗簽失敗 / 外層 Status 非 SUCCESS
	 */
	public function parse_response( array $body ): array {
		$encrypt_info  = (string) ( $body['EncryptInfo'] ?? '' );
		$received_hash = (string) ( $body['HashInfo'] ?? '' );

		// 第一道：HashInfo 驗簽（timing-safe；防竄改）
		if (!$this->crypto->verify_hash( $encrypt_info, $received_hash )) {
			$msg = 'PAYUNi 物流回應 HashInfo 驗簽失敗';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 第二道：外層 Status（SUCCESS 才繼續；UNKNOWN / 錯誤碼皆視為失敗）
		$status = (string) ( $body['Status'] ?? '' );
		if ('SUCCESS' !== $status) {
			$message = (string) ( $body['Message'] ?? 'unknown' );
			$msg     = "PAYUNi 物流業務層失敗 Status={$status}：{$message}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 解密內層
		return $this->crypto->decrypt( $encrypt_info );
	}

	/**
	 * 發送 4 欄位 form POST 並驗簽解密
	 *
	 * @param string               $url     端點 URL
	 * @param array<string, mixed> $data    內層業務參數明文
	 * @param string               $version API 版本
	 *
	 * @return array<string, mixed> 解密後內層
	 * @throws \Exception 連線失敗 / 驗簽失敗 / 業務層失敗
	 */
	private function request_json( string $url, array $data, string $version ): array {
		$envelope = $this->build_envelope( $data, $version );

		Plugin::logger(
			"PAYUNi 物流請求 #{$this->order->get_id()}",
			'info',
			[ 'url' => $url ]
		);

		$response = \wp_remote_post(
			$url,
			[
				'body'     => $envelope,
				'headers'  => [
					'Content-Type' => 'application/x-www-form-urlencoded',
					'User-Agent'   => 'payuni',
				],
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if (\is_wp_error( $response )) {
			$msg = "PAYUNi 物流連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$raw = \wp_remote_retrieve_body( $response );

		/** @var array{Status?: string, Message?: string, EncryptInfo?: string, HashInfo?: string} $decoded */
		$decoded = \json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );

		return $this->parse_response( $decoded );
	}

	/**
	 * 組裝 auto-submit form HTML（前景 Form POST：ship_map / print_label）
	 *
	 * @param string               $url      端點 URL
	 * @param array<string, mixed> $envelope 4 欄位 envelope
	 *
	 * @return string HTML body
	 */
	private function build_auto_submit_form( string $url, array $envelope ): string {
		$inputs = '';
		foreach ( $envelope as $name => $value ) {
			$inputs .= \sprintf(
				'<input type="hidden" name="%s" value="%s" />',
				\esc_attr( (string) $name ),
				\esc_attr( (string) $value )
			);
		}

		return '<!DOCTYPE html><html><body>'
		. \sprintf( '<form id="pc_payuni_logistics" method="post" action="%s">', \esc_url( $url ) )
		. $inputs
		. '</form>'
		. '<script>document.getElementById("pc_payuni_logistics").submit();</script>'
		. '</body></html>';
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	// region MOCK fixtures

	/**
	 * MOCK：ship_map 回應（固定 HTML fixture）
	 *
	 * @return string
	 */
	private function mock_ship_map_html(): string {
		return '<!DOCTYPE html><html><body>'
		. '<form id="mock_payuni_ship_map" method="post" action="https://sandbox-api.payuni.com.tw/api/logistics/ship_map">'
		. '<input type="hidden" name="MerID" value="' . \esc_attr( $this->settings->mer_id ) . '" />'
		. '</form>'
		. '<p>PAYUNi 7-ELEVEN 門市地圖選店頁面（MOCK）</p>'
		. '</body></html>';
	}

	/**
	 * MOCK：trade 回應（固定 fixture，已是解密後內層格式）
	 *
	 * @param string $mer_trade_no 商店訂單編號
	 *
	 * @return array<string, mixed>
	 */
	private function mock_trade_response( string $mer_trade_no ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '建立成功',
			'MerID'       => $this->settings->mer_id,
			'MerTradeNo'  => $mer_trade_no,
			'TradeNo'     => 'mock_trade_' . $mer_trade_no,
			'ShipTradeNo' => 'mock_ship_' . $mer_trade_no,
			'TradeStatus' => 0,
			'StoreID'     => '916712',
			'StoreName'   => 'MOCK 敦安門市',
			'StoreAddr'   => '台北市大安區安和路一段27號',
		];
	}

	/**
	 * MOCK：query 回應（固定 fixture）
	 *
	 * @param string $ship_trade_no UNi 物流序號
	 * @param string $lgs_type      物流型態
	 *
	 * @return array<string, mixed>
	 */
	private function mock_query_response( string $ship_trade_no, string $lgs_type ): array {
		return [
			'Status'         => 'SUCCESS',
			'Message'        => '查詢成功',
			'MerID'          => $this->settings->mer_id,
			'ShipTradeNo'    => $ship_trade_no,
			'LgsType'        => $lgs_type,
			'ShipType'       => 1,
			'ShipStatus'     => '31',
			'ShipStatusDesc' => '配送中',
			'StoreID'        => '916712',
			'StoreName'      => 'MOCK 敦安門市',
		];
	}

	/**
	 * MOCK：print_label 回應（固定 HTML fixture）
	 *
	 * @param array<int, string> $ship_trade_nos UNi 物流序號陣列
	 *
	 * @return string
	 */
	private function mock_print_html( array $ship_trade_nos ): string {
		$ids = \implode( ', ', \array_map( 'strval', $ship_trade_nos ) );
		return '<!DOCTYPE html><html><body>'
		. '<div class="mock-payuni-print">PAYUNi 出貨單列印（MOCK）ShipTradeNo: ' . \esc_html( $ids ) . '</div>'
		. '</body></html>';
	}

	/**
	 * MOCK：refund（C2B 退貨便）回應（固定 fixture，含 RefundODNO + ValidationNo）
	 *
	 * @param string $ship_trade_no 原 UNi 物流序號
	 *
	 * @return array<string, mixed>
	 */
	private function mock_refund_response( string $ship_trade_no ): array {
		return [
			'Status'       => 'SUCCESS',
			'Message'      => '退貨便要號成功',
			'MerID'        => $this->settings->mer_id,
			'LgsType'      => 'C2B',
			'ShipType'     => 1,
			'PartnerId'    => '991',
			'RefundODNO'   => '12345678',
			'ValidationNo' => '9999',
			'ShipTradeNo'  => $ship_trade_no,
			'DeadlineDate' => '2026-12-31 23:59:59',
		];
	}

	// endregion
}
