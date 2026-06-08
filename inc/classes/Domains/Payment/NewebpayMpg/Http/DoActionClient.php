<?php
/**
 * 藍新 NewebPay MPG 信用卡 DoAction（請款 / 退款 / 取消授權）API client
 *
 * 涵蓋三支 API（皆 MerchantID_ / PostData_ 信封，PostData 為 AES-256-CBC 加密 hex）：
 *  - Close（NPA-B031~34）：CloseType=1 請款（capture）/ CloseType=2 退款（refund），Version=1.1。
 *  - Cancel（NPA-B01）：取消授權（cancel_auth），Version=1.0。
 *
 * ⚠️ 信封與 MPG 不同（MPG 是 MerchantID / TradeInfo / TradeSha）：
 *    這裡是 MerchantID_ / PostData_（尾底線），PostData 內層為 key=value 明文 → AES-256-CBC → hex。
 *    回應為 JSON { Status, Message, Result }，Status='SUCCESS' 為成功。
 *
 * ⚠️ TradeNo 必須是藍新回傳的交易編號（存於 _pc_newebpay_trade_no / payment_detail），非 MerchantOrderNo。
 *    退款 Amt 為「欲退款金額」（非原訂單金額），支援多次部分退款，累計不得超過原交易。
 *    僅信用卡（PaymentType=CREDIT）可呼叫；非信用卡由呼叫端先擋下（見 MpgPaymentType）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture（Status=SUCCESS），不打真 API。
 *
 * @see .claude/skills/newebpay-mpg/references/backend-apis.md §Credit Card Close / Cancel / Refund
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\TradeInfoCrypto;
use J7\PowerCheckout\Plugin;

/** 藍新 MPG 信用卡 DoAction API client */
final class DoActionClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string Close 測試環境端點（請款 / 退款） */
	private const CLOSE_ENDPOINT_TEST = 'https://ccore.newebpay.com/API/CreditCard/Close';

	/** @var string Close 正式環境端點 */
	private const CLOSE_ENDPOINT_PROD = 'https://core.newebpay.com/API/CreditCard/Close';

	/** @var string Cancel 測試環境端點（取消授權） */
	private const CANCEL_ENDPOINT_TEST = 'https://ccore.newebpay.com/API/CreditCard/Cancel';

	/** @var string Cancel 正式環境端點 */
	private const CANCEL_ENDPOINT_PROD = 'https://core.newebpay.com/API/CreditCard/Cancel';

	/** @var int CloseType：請款（capture） */
	private const CLOSE_TYPE_CAPTURE = 1;

	/** @var int CloseType：退款（refund） */
	private const CLOSE_TYPE_REFUND = 2;

	/** @var int IndexType：以 TradeNo 為索引 */
	private const INDEX_TYPE_TRADE_NO = 2;

	/** @var MpgSettingsDTO 設定 */
	private readonly MpgSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（用於 order note 與冪等鍵） */
		private readonly \WC_Order $order,
	) {
		$this->settings = MpgSettingsDTO::instance();
	}

	/**
	 * 信用卡退款（Close CloseType=2）
	 *
	 * @param string $trade_no 藍新交易編號 TradeNo（非 MerchantOrderNo）
	 * @param float  $amount   欲退款金額（來自 WC refund 物件，非前端）
	 *
	 * @return array<string, mixed> 回應 Result（Status=SUCCESS 才回傳）
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS
	 */
	public function refund( string $trade_no, float $amount ): array {
		return $this->do_close( self::CLOSE_TYPE_REFUND, $trade_no, $amount, '退款' );
	}

	/**
	 * 信用卡請款 / 關帳（Close CloseType=1）
	 *
	 * @param string $trade_no 藍新交易編號 TradeNo
	 * @param float  $amount   欲請款金額
	 *
	 * @return array<string, mixed> 回應 Result
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS
	 */
	public function capture( string $trade_no, float $amount ): array {
		return $this->do_close( self::CLOSE_TYPE_CAPTURE, $trade_no, $amount, '請款' );
	}

	/**
	 * 信用卡取消授權（Cancel，Version=1.0）
	 *
	 * @param string $trade_no 藍新交易編號 TradeNo
	 * @param float  $amount   原授權金額
	 *
	 * @return array<string, mixed> 回應 Result
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS
	 */
	public function cancel_auth( string $trade_no, float $amount ): array {
		$this->assert_trade_no( $trade_no, '取消授權' );

		$post_data = \sprintf(
			'RespondType=JSON&Version=1.0&Amt=%d&TradeNo=%s&IndexType=%d',
			(int) \ceil( $amount ),
			$trade_no,
			self::INDEX_TYPE_TRADE_NO
		);

		return $this->send( $this->get_cancel_endpoint(), $post_data, '取消授權' );
	}

	/**
	 * 發送 Close（請款 / 退款），共用組裝 PostData + 信封 + 解析
	 *
	 * @param int    $close_type CloseType（1 請款 / 2 退款）
	 * @param string $trade_no   藍新交易編號 TradeNo
	 * @param float  $amount     金額（新台幣整數，無條件進位）
	 * @param string $action_zh  動作中文（order note 用）
	 *
	 * @return array<string, mixed>
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS
	 */
	private function do_close( int $close_type, string $trade_no, float $amount, string $action_zh ): array {
		$this->assert_trade_no( $trade_no, $action_zh );

		// 藍新僅收新台幣整數，無條件進位（避免少收 / 少退）
		$post_data = \sprintf(
			'RespondType=JSON&Version=1.1&Amt=%d&TradeNo=%s&IndexType=%d&CloseType=%d',
			(int) \ceil( $amount ),
			$trade_no,
			self::INDEX_TYPE_TRADE_NO,
			$close_type
		);

		return $this->send( $this->get_close_endpoint(), $post_data, $action_zh );
	}

	/**
	 * 共用發送：PostData → AES-256 加密 → MerchantID_ / PostData_ 信封 → POST → 解析
	 *
	 * @param string $endpoint  端點
	 * @param string $post_data PostData 明文（key=value&...）
	 * @param string $action_zh 動作中文
	 *
	 * @return array<string, mixed>
	 * @throws \Exception 連線失敗 / Status 非 SUCCESS
	 */
	private function send( string $endpoint, string $post_data, string $action_zh ): array {
		$crypto    = new TradeInfoCrypto( $this->settings->hashKey, $this->settings->hashIv );
		$encrypted = $crypto->encrypt( $post_data );

		// MerchantID_ / PostData_ 信封（尾底線，與 MPG 不同）
		$body = [
			'MerchantID_' => $this->settings->merchantId,
			'PostData_'   => $encrypted,
		];

		// MOCK 模式：不打真 API，回固定 fixture
		if ( self::is_mock() ) {
			return [
				'MerchantID' => $this->settings->merchantId,
				'TradeNo'    => '26060512345678',
				'Amt'        => 0,
				'RtnCode'    => '1',
			];
		}

		$response_body = $this->request( $endpoint, $body, $action_zh );

		return $this->parse_response( $response_body, $action_zh );
	}

	/**
	 * 發送 POST 請求（form-urlencoded），回傳原始 body 字串
	 *
	 * @param string                $endpoint  端點
	 * @param array<string, string> $body      請求 body（MerchantID_ / PostData_）
	 * @param string                $action_zh 動作中文（log 用）
	 *
	 * @return string 原始回應 body（JSON）
	 * @throws \Exception 連線失敗
	 */
	private function request( string $endpoint, array $body, string $action_zh ): string {
		Plugin::logger(
			"藍新 MPG DoAction {$action_zh}請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $endpoint ]
		);

		$response = \wp_remote_post(
			$endpoint,
			[
				'body'     => $body,
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "藍新 MPG DoAction 連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 解析 DoAction JSON 回應
	 *
	 * 拆為獨立方法以利測試（不需真 HTTP）。Status='SUCCESS' 為成功，其餘 throw。
	 *
	 * @param string $body      原始回應 body（JSON）
	 * @param string $action_zh 動作中文
	 *
	 * @return array<string, mixed> 回應 Result
	 * @throws \Exception JSON 解析失敗 / Status 非 SUCCESS
	 */
	public function parse_response( string $body, string $action_zh = '退款' ): array {
		$decoded = \json_decode( \trim( $body ), true );
		if ( ! \is_array( $decoded ) ) {
			$msg = "藍新 MPG DoAction {$action_zh}回應解析失敗";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$status = (string) ( $decoded['Status'] ?? '' );
		if ( ! MpgStatus::is_status_success( $status ) ) {
			$message = (string) ( $decoded['Message'] ?? '' );
			$msg     = "藍新 MPG DoAction {$action_zh}失敗 Status={$status}：{$message}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		/** @var array<string, mixed> $result */
		$result = \is_array( $decoded['Result'] ?? null ) ? $decoded['Result'] : [];
		return $result;
	}

	/**
	 * 驗證 TradeNo 非空
	 *
	 * @param string $trade_no  藍新 TradeNo
	 * @param string $action_zh 動作中文
	 *
	 * @return void
	 * @throws \Exception 缺 TradeNo
	 */
	private function assert_trade_no( string $trade_no, string $action_zh ): void {
		if ( '' === $trade_no ) {
			$msg = "藍新 MPG {$action_zh}缺少 TradeNo（藍新交易編號）";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}
	}

	/** @return string Close 端點（依 mode 切換 test / prod） */
	private function get_close_endpoint(): string {
		return 'prod' === $this->settings->mode ? self::CLOSE_ENDPOINT_PROD : self::CLOSE_ENDPOINT_TEST;
	}

	/** @return string Cancel 端點（依 mode 切換 test / prod） */
	private function get_cancel_endpoint(): string {
		return 'prod' === $this->settings->mode ? self::CANCEL_ENDPOINT_PROD : self::CANCEL_ENDPOINT_TEST;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
