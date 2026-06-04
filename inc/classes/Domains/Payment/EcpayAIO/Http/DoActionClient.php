<?php
/**
 * 綠界 AIO 信用卡 DoAction（請款 / 退款 / 取消授權）API client
 *
 * 端點：/CreditDetail/DoAction（CMV-SHA256 協議，回應為 pipe-separated `RtnCode|RtnMsg`）。
 * 本階段僅用於退款（Action=R 退刷）。
 *
 * ⚠️ 重要限制（Source: ECPay-API-Skill guides/01-payment-aio.md §信用卡請款/退款，2026-06）：
 *  - DoAction **僅正式環境可用**（payment.ecpay.com.tw），Stage 環境因無法實際授權故不支援。
 *  - TradeNo 必須是綠界回傳的交易編號（存於 _pc_ecpay_payment_detail），非 MerchantTradeNo。
 *  - Action=R 時 TotalAmount 為「欲退款金額」（非原訂單金額），支援多次部分退款，累計不得超過原交易。
 *  - 僅信用卡類付款方式可呼叫；ATM/CVS/BARCODE/WebATM/ApplePay 由呼叫端先擋下（見 EcpayPaymentType）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture（`1|OK`），不打真 API（DoAction 連 Stage 都不可用）。
 *
 * @see https://developers.ecpay.com.tw/?p=2901 §信用卡請退款功能
 * @see ECPay-API-Skill guides/01-payment-aio.md §信用卡請款 / 退款 / 取消
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Http;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\CheckMacValueService;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Plugin;

/** 綠界 AIO 信用卡 DoAction API client */
final class DoActionClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string DoAction 正式環境端點（Stage 不支援 DoAction） */
	private const ENDPOINT_PROD = 'https://payment.ecpay.com.tw/CreditDetail/DoAction';

	/** @var AioSettingsDTO 設定 */
	private readonly AioSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（用於 order note 與冪等鍵） */
		private readonly \WC_Order $order,
	) {
		$this->settings = AioSettingsDTO::instance();
	}

	/**
	 * 信用卡退款（Action=R 退刷）
	 *
	 * @param string $trade_no     綠界交易編號 TradeNo（非 MerchantTradeNo）
	 * @param float  $amount       欲退款金額（來自 WC refund 物件，非前端）
	 *
	 * @return array{RtnCode: string, RtnMsg: string} DoAction 回應（RtnCode='1' 為成功）
	 * @throws \Exception 連線失敗 / 缺 TradeNo / RtnCode 非 1
	 */
	public function refund( string $trade_no, float $amount ): array {
		$merchant_trade_no = ( new EcpayMetaKeys( $this->order ) )->get_trade_no();

		if ( '' === $trade_no ) {
			$msg = '綠界 AIO 退款缺少 TradeNo（綠界交易編號）';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		// 綠界僅收新台幣整數，無條件進位（與建單 TotalAmount 一致，避免少退）
		$total_amount = (int) \ceil( $amount );

		$params = [
			'MerchantID'      => $this->settings->merchantId,
			'MerchantTradeNo' => $merchant_trade_no,
			'TradeNo'         => $trade_no,
			'Action'          => 'R', // R=退款（退刷）
			'TotalAmount'     => $total_amount,
		];

		// CheckMacValue（SHA256），演算法與建單一致
		$params['CheckMacValue'] = CheckMacValueService::get_check_value(
			$params,
			$this->settings->hashKey,
			$this->settings->hashIv,
			'sha256'
		);

		// MOCK 模式：不打真 API（DoAction 連 Stage 都不可用），回固定 fixture
		if ( self::is_mock() ) {
			return [
				'RtnCode' => '1',
				'RtnMsg'  => 'OK',
			];
		}

		$response_body = $this->request( $params );

		return $this->parse_response( $response_body );
	}

	/**
	 * 發送 POST 請求（form-urlencoded），回傳原始 body 字串
	 *
	 * @param array<string, string|int> $params 請求參數（含 CheckMacValue）
	 *
	 * @return string 原始回應 body（pipe-separated `RtnCode|RtnMsg`）
	 * @throws \Exception 連線失敗
	 */
	private function request( array $params ): string {
		Plugin::logger(
			"綠界 AIO DoAction 退款請求 #{$this->order->get_id()}",
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
			$msg = "綠界 AIO DoAction 連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 解析 DoAction 回應（pipe-separated `RtnCode|RtnMsg`）
	 *
	 * 拆為獨立方法以利測試（不需真 HTTP）。RtnCode='1' 為成功，其餘 throw。
	 *
	 * @param string $body 原始回應 body
	 *
	 * @return array{RtnCode: string, RtnMsg: string}
	 * @throws \Exception RtnCode 非 1
	 */
	public function parse_response( string $body ): array {
		$parts    = \explode( '|', \trim( $body ), 2 );
		$rtn_code = $parts[0];
		$rtn_msg  = $parts[1] ?? '';

		if ( '1' !== $rtn_code ) {
			$msg = "綠界 AIO DoAction 退款失敗 RtnCode={$rtn_code}：{$rtn_msg}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return [
			'RtnCode' => $rtn_code,
			'RtnMsg'  => $rtn_msg,
		];
	}

	/** @return string DoAction 端點（一律正式環境，Stage 不支援） */
	private function get_endpoint(): string {
		// DoAction 僅正式環境可用，無論 mode 皆指向 prod 端點（呼叫端 MOCK 模式不會走到此）
		return self::ENDPOINT_PROD;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
