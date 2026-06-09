<?php
/**
 * 綠界綁卡代扣（CreatePaymentWithCardID）幕後扣款 API client
 *
 * 回購情境：消費者已綁卡（取得 CardID），後續以 CardID 於背景授權扣款，不需消費者再次互動、
 * 不跳轉，且無需 PCI-DSS 認證（卡號由綠界安全頁面處理）。此為綠界官方推薦的後台扣款方案。
 *
 * 端點：/Merchant/CreatePaymentWithCardID（ecpg domain），AES-JSON 三層協議：
 *   外層 { MerchantID, RqHeader{Timestamp}, Data }；Data = AES(明文 JSON)。
 *   回應外層 TransCode（1=成功）+ 解密後 Data.RtnCode（1=成功），須雙層檢查。
 *   ⚠️ RqHeader 僅需 Timestamp（不需 Revision，與全方位物流不同）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/03-payment-backend.md §16 綁卡代扣 CreatePaymentWithCardID
 * @see .claude/skills/ECPay-API-Skill/guides/21-webhook-events-reference.md（AES-JSON 回應 / 重試）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Http;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\AesJsonCrypto;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\TradeNo;
use J7\PowerCheckout\Plugin;

/** 綠界綁卡代扣（CreatePaymentWithCardID）幕後扣款 API client */
final class BackgroundChargeClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string 綁卡代扣測試環境端點（ecpg domain，非 ecpayment） */
	private const ENDPOINT_TEST = 'https://ecpg-stage.ecpay.com.tw/Merchant/CreatePaymentWithCardID';

	/** @var string 綁卡代扣正式環境端點 */
	private const ENDPOINT_PROD = 'https://ecpg.ecpay.com.tw/Merchant/CreatePaymentWithCardID';

	/** @var AioSettingsDTO 設定 */
	private readonly AioSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（提供金額、order note 與冪等鍵） */
		private readonly \WC_Order $order,
	) {
		$this->settings = AioSettingsDTO::instance();
	}

	/**
	 * 以 CardID 幕後扣款（背景授權，不跳轉）
	 *
	 * @param string $card_id           綁卡代碼 BindCardID（綁卡時綠界回傳並儲存於 WC_Payment_Token_CC）
	 * @param string $merchant_member_id 會員識別碼 MerchantMemberID（綁卡時使用）
	 *
	 * @return array{RtnCode: string, RtnMsg: string, TradeNo: string} 扣款結果（RtnCode='1' 為成功）
	 * @throws \Exception 缺 CardID / 連線失敗 / TransCode 或 RtnCode 非 1
	 */
	public function charge_with_card_id( string $card_id, string $merchant_member_id ): array {
		if ( '' === $card_id ) {
			$msg = '綠界 AIO 幕後扣款缺少 CardID（綁卡代碼）';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$merchant_trade_no = TradeNo::encode( $this->order->get_id() );
		$total_amount      = (int) \ceil( (float) $this->order->get_total() );

		// 內層 Data（明文，待 AES 加密）— 巢狀 OrderInfo / ConsumerInfo（Source: 03 §16）
		$data = [
			'PlatformID'   => '',
			'MerchantID'   => $this->settings->merchantId,
			'BindCardID'   => $card_id,
			'OrderInfo'    => [
				'MerchantTradeDate' => \wp_date( 'Y/m/d H:i:s' ) ?: \gmdate( 'Y/m/d H:i:s', \time() + 8 * 3600 ),
				'MerchantTradeNo'   => $merchant_trade_no,
				'TotalAmount'       => (string) $total_amount,
				'ReturnURL'         => '', // 幕後扣款結果以同步回應為準；非同步通知沿用 AIO ReturnURL（選填）
				'TradeDesc'         => "Order #{$this->order->get_id()}",
				'ItemName'          => "Order #{$this->order->get_id()}",
			],
			'ConsumerInfo' => [
				'MerchantMemberID' => $merchant_member_id,
			],
			'CustomField'  => '',
		];

		// MOCK 模式：不打真 API，回固定成功 fixture，並記錄 order note
		if ( self::is_mock() ) {
			$this->order->add_order_note(
				\sprintf( '✅ 綠界 ECPay 信用卡幕後扣款成功（MOCK），金額 %d 元', $total_amount )
			);
			return [
				'RtnCode' => '1',
				'RtnMsg'  => 'Success',
				'TradeNo' => '2026060512345678',
			];
		}

		$crypto  = new AesJsonCrypto( $this->settings->hashKey, $this->settings->hashIv );
		$payload = [
			'MerchantID' => $this->settings->merchantId,
			'RqHeader'   => [ 'Timestamp' => \time() ], // 僅 Timestamp，不需 Revision
			'Data'       => $crypto->encrypt( $data ),
		];

		$decoded = $this->request( $payload );

		// 外層 TransCode 檢查（1=成功）
		if ( 1 !== (int) ( $decoded['TransCode'] ?? 0 ) ) {
			$msg = '綠界 AIO 幕後扣款外層 TransCode 非 1：' . (string) ( $decoded['TransMsg'] ?? '' );
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$inner  = $crypto->decrypt( (string) ( $decoded['Data'] ?? '' ) );
		$result = $this->parse_decrypted_data( $inner );

		$this->order->add_order_note(
			\sprintf(
				'✅ 綠界 ECPay 信用卡幕後扣款成功，金額 %1$d 元，TradeNo：%2$s',
				$total_amount,
				$result['TradeNo']
			)
		);

		return $result;
	}

	/**
	 * 解析解密後的內層 Data（RtnCode=1 為成功）
	 *
	 * 拆為獨立方法以利測試（不需真 HTTP / AES）。
	 *
	 * @param array<string, mixed> $data 解密後的內層 Data
	 * @return array{RtnCode: string, RtnMsg: string, TradeNo: string}
	 * @throws \Exception 內層 RtnCode 非 1
	 */
	public function parse_decrypted_data( array $data ): array {
		$rtn_code = (string) ( $data['RtnCode'] ?? '' );
		$rtn_msg  = (string) ( $data['RtnMsg'] ?? '' );

		if ( '1' !== $rtn_code ) {
			$msg = "綠界 AIO 幕後扣款失敗 RtnCode={$rtn_code}：{$rtn_msg}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$order_info = $data['OrderInfo'] ?? [];
		$trade_no   = ( \is_array( $order_info ) && isset( $order_info['TradeNo'] ) )
		? (string) $order_info['TradeNo']
		: (string) ( $data['TradeNo'] ?? '' );

		return [
			'RtnCode' => $rtn_code,
			'RtnMsg'  => $rtn_msg,
			'TradeNo' => $trade_no,
		];
	}

	/**
	 * 發送 POST 請求（JSON），回傳已 json_decode 的外層回應
	 *
	 * @param array<string, mixed> $payload AES-JSON 外層 payload
	 * @return array<string, mixed> 外層回應（含 TransCode / Data）
	 * @throws \Exception 連線失敗 / 回應非 JSON
	 */
	private function request( array $payload ): array {
		Plugin::logger(
			"綠界 AIO 綁卡代扣幕後扣款請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $this->get_endpoint() ]
		);

		$response = \wp_remote_post(
			$this->get_endpoint(),
			[
				'headers'  => [ 'Content-Type' => 'application/json' ],
				'body'     => (string) \wp_json_encode( $payload ),
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "綠界 AIO 幕後扣款連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		$body    = (string) \wp_remote_retrieve_body( $response );
		$decoded = \json_decode( $body, true );
		if ( ! \is_array( $decoded ) ) {
			throw new \Exception( '綠界 AIO 幕後扣款回應非 JSON' );
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/** @return string 綁卡代扣端點（依 mode 切換 test / prod） */
	private function get_endpoint(): string {
		return 'prod' === $this->settings->mode ? self::ENDPOINT_PROD : self::ENDPOINT_TEST;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
