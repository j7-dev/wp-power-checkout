<?php
/**
 * 藍新 NewebPay MPG 交易查詢 QueryTradeInfo API client（對帳用，NPA-B02）
 *
 * 端點：/API/QueryTradeInfo（POST form-encoded，回應 JSON）。
 * 依 MerchantOrderNo + Amt 查藍新交易狀態，供對帳 / 補單判斷使用。
 *
 * ⚠️ 重要：
 *  - CheckValue 用 IV= / Key= 鍵（非 TradeSha 的 HashKey= / HashIV=），順序 IV,Amt,MerchantID,MerchantOrderNo,Key。
 *  - Version=1.3、RespondType=JSON。
 *  - TimeStamp 為 Unix 秒，±120s，每次呼叫重新產生。
 *  - TradeStatus：'0' 未付 / '1' 已付 / '2' 失敗 / '3' 取消 / '6' 退款。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/newebpay-mpg/references/backend-apis.md §Query Trade Info API (NPA-B02)
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Plugin;

/** 藍新 MPG 交易查詢 QueryTradeInfo API client */
final class QueryTradeClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string QueryTradeInfo 測試環境端點 */
	private const ENDPOINT_TEST = 'https://ccore.newebpay.com/API/QueryTradeInfo';

	/** @var string QueryTradeInfo 正式環境端點 */
	private const ENDPOINT_PROD = 'https://core.newebpay.com/API/QueryTradeInfo';

	/** @var string Version（固定 1.3） */
	private const VERSION = '1.3';

	/** @var MpgSettingsDTO 設定 */
	private readonly MpgSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（提供 MerchantOrderNo 與 order note） */
		private readonly \WC_Order $order,
	) {
		$this->settings = MpgSettingsDTO::instance();
	}

	/**
	 * 依訂單 MerchantOrderNo 查詢藍新交易資訊
	 *
	 * @return array<string, mixed> 查詢結果 Result（含 TradeStatus / PaymentType / Amt 等）
	 * @throws \Exception 缺 MerchantOrderNo / 連線失敗 / 回應狀態非 SUCCESS
	 */
	public function query(): array {
		$params   = $this->build_request_params();
		$order_no = (string) $params['MerchantOrderNo'];
		$amt      = (int) $params['Amt'];

		// MOCK 模式：不打真 API，回固定 fixture
		if ( self::is_mock() ) {
			return [
				'MerchantID'      => $this->settings->merchantId,
				'MerchantOrderNo' => $order_no,
				'TradeNo'         => '26060512345678',
				'Amt'             => $amt,
				'TradeStatus'     => MpgStatus::TRADE_STATUS_PAID,
				'PaymentType'     => 'CREDIT',
				'PayTime'         => \wp_date( 'Y-m-d H:i:s' ) ?: '',
			];
		}

		$response_body = $this->request( $params );

		return $this->parse_response( $response_body );
	}

	/**
	 * 組裝查詢請求參數（含 TimeStamp + CheckValue）
	 *
	 * 公開以利測試重算 CheckValue 一致性。TimeStamp 每次呼叫重新產生（±120s）。
	 *
	 * @return array<string, string|int>
	 * @throws \Exception 缺 MerchantOrderNo
	 */
	public function build_request_params(): array {
		$order_no = ( new MpgMetaKeys( $this->order ) )->get_order_no();

		if ( '' === $order_no ) {
			$msg = '藍新 MPG 交易查詢缺少 MerchantOrderNo';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$amt    = (int) \ceil( (float) $this->order->get_total() );
		$crypto = new TradeInfoCrypto( $this->settings->hashKey, $this->settings->hashIv );

		return [
			'MerchantID'      => $this->settings->merchantId,
			'Version'         => self::VERSION,
			'RespondType'     => 'JSON',
			'CheckValue'      => $crypto->generate_check_value( $this->settings->merchantId, $order_no, $amt ),
			'TimeStamp'       => (string) \time(),
			'MerchantOrderNo' => $order_no,
			'Amt'             => $amt,
		];
	}

	/**
	 * 解析 QueryTradeInfo JSON 回應，回傳 Result
	 *
	 * 拆為獨立方法以利測試（不需真 HTTP）。頂層 Status 非 SUCCESS 則 throw。
	 *
	 * @param string $body 原始回應 body（JSON）
	 * @return array<string, mixed> Result
	 * @throws \Exception JSON 解析失敗 / Status 非 SUCCESS
	 */
	public function parse_response( string $body ): array {
		$decoded = \json_decode( \trim( $body ), true );
		if ( ! \is_array( $decoded ) ) {
			$msg = '藍新 MPG 交易查詢回應解析失敗';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$status = (string) ( $decoded['Status'] ?? '' );
		if ( ! MpgStatus::is_status_success( $status ) ) {
			$message = (string) ( $decoded['Message'] ?? '' );
			$msg     = "藍新 MPG 交易查詢失敗 Status={$status}：{$message}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		/** @var array<string, mixed> $result */
		$result = \is_array( $decoded['Result'] ?? null ) ? $decoded['Result'] : [];
		return $result;
	}

	/**
	 * 由查詢結果判定是否已付款（TradeStatus='1'）
	 *
	 * @param array<string, mixed> $result 查詢結果 Result
	 * @return bool
	 */
	public static function is_paid( array $result ): bool {
		return MpgStatus::is_trade_paid( (string) ( $result['TradeStatus'] ?? '' ) );
	}

	/**
	 * 發送 POST 請求（form-urlencoded），回傳原始 body 字串
	 *
	 * @param array<string, string|int> $params 請求參數（含 CheckValue）
	 * @return string 原始回應 body
	 * @throws \Exception 連線失敗
	 */
	private function request( array $params ): string {
		Plugin::logger(
			"藍新 MPG QueryTradeInfo 查詢請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $this->get_endpoint() ]
		);

		$response = \wp_remote_post(
			$this->get_endpoint(),
			[
				'body'       => $params,
				'blocking'   => true,
				'timeout'    => self::TIMEOUT,
				// 藍新 ccore/core API 前的 Akamai WAF 會擋 WordPress/* 與 curl/* UA
				//（回 403 Access Denied HTML），送產品識別 UA 通過（sandbox 實測）
				'user-agent' => 'PowerCheckout/1.0',
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "藍新 MPG 交易查詢連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/** @return string QueryTradeInfo 端點（依 mode 切換 test / prod） */
	private function get_endpoint(): string {
		return 'prod' === $this->settings->mode ? self::ENDPOINT_PROD : self::ENDPOINT_TEST;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
