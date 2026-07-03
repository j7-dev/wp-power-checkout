<?php
/**
 * 藍新 NewebPay MPG e-wallet 退款 API client（NPA-B06）
 *
 * 適用付款方式：LINE Pay / 台灣 Pay / 玉山 Wallet（PaymentType=LINEPAY / TAIWANPAY / ESUNWALLET）。
 *
 * ⚠️ 端點為 /API/EWallet/refund（小寫 r）；用 /Refund 會 404。
 * ⚠️ 依 skill backend-apis.md，本 API 參數為「明文 form」（MerchantID / TradeNo / Amt / PaymentType），
 *    不走 TradeInfo / PostData_ 加密信封；回應為 JSON。
 *
 * TradeNo 必須是藍新回傳的交易編號（存於 _pc_newebpay_trade_no / payment_detail）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture（Status=SUCCESS），不打真 API。
 *
 * @see .claude/skills/newebpay-mpg/references/backend-apis.md §E-Wallet Refund API (NPA-B06)
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Plugin;

/** 藍新 MPG e-wallet 退款 API client */
final class EWalletRefundClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string EWallet refund 測試環境端點（小寫 refund） */
	private const ENDPOINT_TEST = 'https://ccore.newebpay.com/API/EWallet/refund';

	/** @var string EWallet refund 正式環境端點 */
	private const ENDPOINT_PROD = 'https://core.newebpay.com/API/EWallet/refund';

	/** @var array<string> 支援的 e-wallet PaymentType */
	private const SUPPORTED_TYPES = [ 'LINEPAY', 'TAIWANPAY', 'ESUNWALLET' ];

	/** @var MpgSettingsDTO 設定 */
	private readonly MpgSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（用於 order note） */
		private readonly \WC_Order $order,
	) {
		$this->settings = MpgSettingsDTO::instance();
	}

	/**
	 * E-wallet 退款
	 *
	 * @param string $trade_no     藍新交易編號 TradeNo
	 * @param float  $amount       欲退款金額（來自 WC refund 物件，非前端）
	 * @param string $payment_type e-wallet PaymentType（LINEPAY / TAIWANPAY / ESUNWALLET）
	 *
	 * @return array<string, mixed> 回應 Result
	 * @throws \Exception 缺 TradeNo / PaymentType 不支援 / 連線失敗 / Status 非 SUCCESS
	 */
	public function refund( string $trade_no, float $amount, string $payment_type ): array {
		if ( '' === $trade_no ) {
			$msg = '藍新 MPG e-wallet 退款缺少 TradeNo';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		if ( ! \in_array( $payment_type, self::SUPPORTED_TYPES, true ) ) {
			$msg = "藍新 MPG e-wallet 退款不支援的 PaymentType：{$payment_type}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$body = [
			'MerchantID'  => $this->settings->merchantId,
			'TradeNo'     => $trade_no,
			'Amt'         => (int) \ceil( $amount ),
			'PaymentType' => $payment_type,
		];

		// MOCK 模式：不打真 API，回固定 fixture
		if ( self::is_mock() ) {
			return [
				'MerchantID' => $this->settings->merchantId,
				'TradeNo'    => $trade_no,
				'Amt'        => (int) \ceil( $amount ),
			];
		}

		$response_body = $this->request( $body );

		return $this->parse_response( $response_body );
	}

	/**
	 * 發送 POST 請求（form-urlencoded），回傳原始 body 字串
	 *
	 * @param array<string, string|int> $body 請求 body
	 * @return string 原始回應 body（JSON）
	 * @throws \Exception 連線失敗
	 */
	private function request( array $body ): string {
		Plugin::logger(
			"藍新 MPG e-wallet 退款請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $this->get_endpoint() ]
		);

		$response = \wp_remote_post(
			$this->get_endpoint(),
			[
				'body'       => $body,
				'blocking'   => true,
				'timeout'    => self::TIMEOUT,
				// 藍新 ccore/core API 前的 Akamai WAF 會擋 WordPress/* 與 curl/* UA
				//（回 403 Access Denied HTML），送產品識別 UA 通過（sandbox 實測）
				'user-agent' => 'PowerCheckout/1.0',
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "藍新 MPG e-wallet 退款連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 解析 e-wallet 退款 JSON 回應
	 *
	 * 拆為獨立方法以利測試。Status='SUCCESS' 為成功，其餘 throw。
	 *
	 * @param string $body 原始回應 body（JSON）
	 * @return array<string, mixed> 回應 Result
	 * @throws \Exception JSON 解析失敗 / Status 非 SUCCESS
	 */
	public function parse_response( string $body ): array {
		$decoded = \json_decode( \trim( $body ), true );
		if ( ! \is_array( $decoded ) ) {
			$msg = '藍新 MPG e-wallet 退款回應解析失敗';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$status = (string) ( $decoded['Status'] ?? '' );
		if ( ! MpgStatus::is_status_success( $status ) ) {
			$message = (string) ( $decoded['Message'] ?? '' );
			$msg     = "藍新 MPG e-wallet 退款失敗 Status={$status}：{$message}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		/** @var array<string, mixed> $result */
		$result = \is_array( $decoded['Result'] ?? null ) ? $decoded['Result'] : [];
		return $result;
	}

	/** @return string EWallet refund 端點（依 mode 切換 test / prod） */
	private function get_endpoint(): string {
		return 'prod' === $this->settings->mode ? self::ENDPOINT_PROD : self::ENDPOINT_TEST;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
