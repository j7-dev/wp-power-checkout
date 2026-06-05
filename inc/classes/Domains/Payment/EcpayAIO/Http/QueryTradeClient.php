<?php
/**
 * 綠界 AIO 交易查詢 QueryTradeInfo API client（對帳用）
 *
 * 端點：/Cashier/QueryTradeInfo/V5（CMV-SHA256 協議，回應為 `key=value&key=value...` 字串）。
 * 依 MerchantTradeNo 查綠界交易狀態，供對帳 / 補單判斷使用。
 *
 * ⚠️ 重要（Source: ECPay-API-Skill guides/01-payment-aio.md §查詢訂單 / 一般查詢，2026-06）：
 *  - TimeStamp 為 Unix 秒，有效期僅 3 分鐘，每次呼叫前重新產生 time()，禁快取。
 *  - 回應字串內含 CheckMacValue，必須驗證（timing-safe）。
 *  - TradeStatus：'0'=未付款, '1'=已付款, '10200095'=交易未成立, '10200047'=訂單不存在。
 *  - 信用卡 / TWQR 建議付款後 10 分鐘再查（銀行尚未回覆時 TradeStatus=0）。
 *  - 高速查詢會收到 HTTP 403，請降低頻率。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API，避免測試外部相依。
 *
 * @see https://developers.ecpay.com.tw/?p=2901 §交易查詢
 * @see ECPay-API-Skill guides/01-payment-aio.md §查詢訂單 / QueryTradeInfo 回傳欄位
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Http;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Plugin;

/** 綠界 AIO 交易查詢 QueryTradeInfo API client */
final class QueryTradeClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string QueryTradeInfo 測試環境端點 */
	private const ENDPOINT_TEST = 'https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5';

	/** @var string QueryTradeInfo 正式環境端點 */
	private const ENDPOINT_PROD = 'https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5';

	/** @var string TradeStatus：已付款 */
	private const TRADE_STATUS_PAID = '1';

	/** @var AioSettingsDTO 設定 */
	private readonly AioSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（提供 MerchantTradeNo 與 order note） */
		private readonly \WC_Order $order,
	) {
		$this->settings = AioSettingsDTO::instance();
	}

	/**
	 * 依訂單 MerchantTradeNo 查詢綠界交易資訊
	 *
	 * @return array<string, string> 查詢結果（已驗章解析；含 TradeStatus / PaymentType / TradeAmt 等）
	 * @throws \Exception 缺 MerchantTradeNo / 連線失敗 / 驗章失敗
	 */
	public function query(): array {
		$params = $this->build_request_params();

		// MOCK 模式：不打真 API，回固定 fixture（保留 MerchantTradeNo 供對帳比對）
		if ( self::is_mock() ) {
			return [
				'MerchantID'      => $this->settings->merchantId,
				'MerchantTradeNo' => (string) $params['MerchantTradeNo'],
				'TradeNo'         => '2026060512345678',
				'TradeAmt'        => (string) (int) \ceil( (float) $this->order->get_total() ),
				'TradeStatus'     => self::TRADE_STATUS_PAID,
				'PaymentType'     => 'Credit_CreditCard',
				'PaymentDate'     => \wp_date( 'Y/m/d H:i:s' ) ?: '',
			];
		}

		$response_body = $this->request( $params );
		$parsed        = $this->parse_response( $response_body );

		if ( ! $this->is_valid( $parsed, $response_body ) ) {
			$msg = '綠界 AIO 交易查詢回應 CheckMacValue 驗章失敗';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return $parsed;
	}

	/**
	 * 組裝查詢請求參數（含 TimeStamp + CheckMacValue）
	 *
	 * 公開以利測試重算 CheckMacValue 一致性。TimeStamp 每次呼叫重新產生（3 分鐘有效期）。
	 *
	 * @return array<string, string|int>
	 * @throws \Exception 缺 MerchantTradeNo
	 */
	public function build_request_params(): array {
		$merchant_trade_no = ( new EcpayMetaKeys( $this->order ) )->get_trade_no();

		if ( '' === $merchant_trade_no ) {
			$msg = '綠界 AIO 交易查詢缺少 MerchantTradeNo';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$params = [
			'MerchantID'      => $this->settings->merchantId,
			'MerchantTradeNo' => $merchant_trade_no,
			'TimeStamp'       => \time(), // Unix 秒，3 分鐘有效期，每次重新產生
		];

		$params['CheckMacValue'] = CheckMacValueService::get_check_value(
			$params,
			$this->settings->hashKey,
			$this->settings->hashIv,
			'sha256'
		);

		return $params;
	}

	/**
	 * 解析 QueryTradeInfo 回應（`key=value&key=value...`）為陣列
	 *
	 * 拆為獨立方法以利測試（不需真 HTTP）。值以 urldecode 還原（PaymentDate 等含空格）。
	 *
	 * @param string $body 原始回應 body
	 * @return array<string, string>
	 */
	public function parse_response( string $body ): array {
		$result = [];
		$pairs  = \explode( '&', \trim( $body ) );
		foreach ( $pairs as $pair ) {
			if ( '' === $pair || ! \str_contains( $pair, '=' ) ) {
				continue;
			}
			[ $key, $value ] = \explode( '=', $pair, 2 );
			$result[ $key ]  = \urldecode( $value );
		}

		return $result;
	}

	/**
	 * 是否為「已付款」（TradeStatus='1'）
	 *
	 * @param string $trade_status 綠界 TradeStatus（字串）
	 * @return bool
	 */
	public static function is_paid( string $trade_status ): bool {
		return self::TRADE_STATUS_PAID === $trade_status;
	}

	/**
	 * 以 timing-safe 方式驗證回應 CheckMacValue
	 *
	 * @param array<string, string> $parsed 已解析的回應參數
	 * @param string                $body   原始回應 body
	 * @return bool
	 */
	private function is_valid( array $parsed, string $body ): bool {
		$received = (string) ( $parsed['CheckMacValue'] ?? '' );
		if ( '' === $received ) {
			return false;
		}

		$args = [];
		foreach ( $parsed as $key => $value ) {
			if ( 'CheckMacValue' === $key ) {
				continue;
			}
			$args[ $key ] = $value;
		}

		$calculated = CheckMacValueService::get_check_value(
			$args,
			$this->settings->hashKey,
			$this->settings->hashIv,
			'sha256'
		);

		return \hash_equals( $calculated, \strtoupper( $received ) );
	}

	/**
	 * 發送 POST 請求（form-urlencoded），回傳原始 body 字串
	 *
	 * @param array<string, string|int> $params 請求參數（含 CheckMacValue）
	 * @return string 原始回應 body
	 * @throws \Exception 連線失敗
	 */
	private function request( array $params ): string {
		Plugin::logger(
			"綠界 AIO QueryTradeInfo 查詢請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $this->get_endpoint() ]
		);

		$response = \wp_remote_post(
			$this->get_endpoint(),
			[
				'body'     => $params,
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "綠界 AIO 交易查詢連線失敗：{$response->get_error_message()}";
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
