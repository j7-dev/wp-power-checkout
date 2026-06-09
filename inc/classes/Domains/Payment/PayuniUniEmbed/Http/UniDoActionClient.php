<?php
/**
 * PAYUNi UNi Embed V3 信用卡 DoAction（請款 / 退款 / 取消授權）API client
 *
 * ⚠️ 架構決策 D1（specs/open-issue/payuni-uni-embed-execution-plan.md）：
 *   本 client 為 Payuni\Http\DoActionClient 的「同源複製 + 換注入」版本——
 *   - 注入 {@see PayuniUniEmbedSettingsDTO}（讀 woocommerce_payuni_uni_embed_settings），
 *     而非 UPP 的 PayuniSettingsDTO（woocommerce_payuni_upp_settings）。
 *   - 讀 _pc_payuni_uni_* meta（透過 {@see PayuniUniEmbedMetaKeys}），而非 UPP 的 _pc_payuni_*。
 *   - 加密一律 use 既有 {@see PayuniCrypto}（不開第 3 份副本）。
 *   絕不可直接 new Payuni\Http\DoActionClient（會抓 UPP settings + UPP meta，破壞隔離）。
 *
 * 涵蓋兩支 API（皆外層信封 MerID / Version / EncryptInfo / HashInfo，body form-urlencoded）：
 *  - Close（/api/trade/close，Version=1.0）：CloseType=1 請款（capture）/ CloseType=2 退款（refund）。
 *  - Cancel（/api/trade/cancel，Version=1.0）：取消授權（cancel_auth）。
 *
 * ⚠️ TradeNo 必須是 PAYUNi 回傳的 UNi 序號（存於 _pc_payuni_uni_payment_detail 的 TradeNo），
 *    非 MerTradeNo。退款 TradeAmt 為「欲退款金額」（來自 WC refund 物件，非前端）。
 *    僅信用卡（PaymentType=1）可呼叫；非信用卡由呼叫端先擋下（見 PayuniUniEmbedGateway）。
 *
 * 測試 mock（不打真 API，比照 UNi MerchantTradeClient / TokenGetClient 慣例）：
 *  - filter `payuni_uni_embed_mock_do_action_response`（傳入 $default + $action_type）：
 *    回傳陣列即作為解析輸入，跳過真 HTTP。
 *  - filter `payuni_uni_embed_mock_do_action_exception`：回 truthy 即拋例外（測退款 ROLLBACK 路徑）。
 *
 * @see .claude/skills/payuni-upp-v2/references/api-reference.md §交易請退款 / §交易取消授權
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient 同源藍本
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Plugin;

/** PAYUNi UNi Embed V3 信用卡 DoAction API client */
final class UniDoActionClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string Version（Close / Cancel 皆固定 1.0） */
	private const VERSION = '1.0';

	/** @var string Close 路徑（請款 / 退款） */
	private const CLOSE_PATH = '/api/trade/close';

	/** @var string Cancel 路徑（取消授權） */
	private const CANCEL_PATH = '/api/trade/cancel';

	/** @var int CloseType：請款（capture） */
	private const CLOSE_TYPE_CAPTURE = 1;

	/** @var int CloseType：退款（refund） */
	private const CLOSE_TYPE_REFUND = 2;

	/** @var string 外層成功狀態值 */
	private const STATUS_SUCCESS = 'SUCCESS';

	/** @var string 注入 mock 回應的 filter（測試用；傳入 $default + $action_type） */
	public const FILTER_MOCK_RESPONSE = 'payuni_uni_embed_mock_do_action_response';

	/** @var string 注入例外的 filter（測試用；回 truthy 即拋例外） */
	public const FILTER_MOCK_EXCEPTION = 'payuni_uni_embed_mock_do_action_exception';

	/** @var PayuniUniEmbedSettingsDTO 設定（D1：UNi Embed 專屬，非 UPP） */
	private readonly PayuniUniEmbedSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（用於 order note、取 TradeNo 與 API host 切換） */
		private readonly \WC_Order $order,
	) {
		$this->settings = PayuniUniEmbedSettingsDTO::instance();
	}

	/**
	 * 信用卡退款（Close CloseType=2）
	 *
	 * TradeNo 一律自 _pc_payuni_uni_payment_detail 取（PAYUNi 回傳的 UNi 序號），不信任外部傳入。
	 *
	 * @param \WC_Order $order  訂單（語意一致；TradeNo 取自 constructor 注入的訂單）
	 * @param float     $amount 欲退款金額（來自 WC refund 物件，非前端）
	 *
	 * @return array<string, mixed> 回應內層（Status=SUCCESS 才回傳）
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	public function refund( \WC_Order $order, float $amount ): array {
		$trade_no = $this->get_trade_no();
		return $this->do_close( self::CLOSE_TYPE_REFUND, $trade_no, $amount, '退款' );
	}

	/**
	 * 信用卡請款 / 關帳（Close CloseType=1）
	 *
	 * @param string $trade_no PAYUNi UNi 序號 TradeNo（非 MerTradeNo）
	 * @param float  $amount   欲請款金額
	 *
	 * @return array<string, mixed> 回應內層
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	public function capture( string $trade_no, float $amount ): array {
		return $this->do_close( self::CLOSE_TYPE_CAPTURE, $trade_no, $amount, '請款' );
	}

	/**
	 * 信用卡取消授權（Cancel，Version=1.0）
	 *
	 * @param string $trade_no PAYUNi UNi 序號 TradeNo（非 MerTradeNo）
	 *
	 * @return array<string, mixed> 回應內層
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	public function cancel_auth( string $trade_no ): array {
		$this->assert_trade_no( $trade_no, '取消授權' );

		$inner = [
			'MerID'     => $this->settings->merchant_id,
			'Timestamp' => \time(),
			'TradeNo'   => $trade_no,
		];

		return $this->send( $this->get_endpoint( self::CANCEL_PATH ), $inner, '取消授權', 'cancel' );
	}

	/**
	 * 發送 Close（請款 / 退款），共用組裝內層 + 信封 + 解析
	 *
	 * @param int    $close_type CloseType（1 請款 / 2 退款）
	 * @param string $trade_no   PAYUNi UNi 序號 TradeNo
	 * @param float  $amount     金額（新台幣整數，無條件進位）
	 * @param string $action_zh  動作中文（order note 用）
	 *
	 * @return array<string, mixed>
	 * @throws \Exception 缺 TradeNo / 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	private function do_close( int $close_type, string $trade_no, float $amount, string $action_zh ): array {
		$this->assert_trade_no( $trade_no, $action_zh );

		// PAYUNi 僅收新台幣整數，無條件進位（避免少收 / 少退）
		$inner = [
			'MerID'     => $this->settings->merchant_id,
			'Timestamp' => \time(),
			'TradeNo'   => $trade_no,
			'CloseType' => $close_type,
			'TradeAmt'  => (int) \ceil( $amount ),
		];

		$action_type = self::CLOSE_TYPE_REFUND === $close_type ? 'refund' : 'capture';
		return $this->send( $this->get_endpoint( self::CLOSE_PATH ), $inner, $action_zh, $action_type );
	}

	/**
	 * 共用發送：mock filter 分流 → 內層加密 → 外層信封 → POST → 解析
	 *
	 * @param string               $endpoint    端點
	 * @param array<string, mixed> $inner       內層明文（含 MerID / Timestamp / TradeNo ...）
	 * @param string               $action_zh   動作中文
	 * @param string               $action_type 動作類型（refund / capture / cancel；供 mock filter 分流）
	 *
	 * @return array<string, mixed>
	 * @throws \Exception 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	private function send( string $endpoint, array $inner, string $action_zh, string $action_type ): array {
		// 測試注入例外（模擬 API 失敗 → 退款 ROLLBACK 路徑）
		if ( \apply_filters( self::FILTER_MOCK_EXCEPTION, false, $action_type ) ) {
			$msg = "PAYUNi UNi Embed {$action_zh} API 失敗（mock exception）";
			$this->order->add_order_note( "❌ {$action_zh}失敗：{$msg}" );
			throw new \Exception( $msg );
		}

		// 測試注入 mock 回應（陣列）→ 跳過真 HTTP，直接走解析
		$mock_response = \apply_filters( self::FILTER_MOCK_RESPONSE, null, $action_type );
		if ( \is_array( $mock_response ) ) {
			/** @var array<string, mixed> $mock_response */
			return $this->parse_response( $mock_response, $action_zh );
		}

		$crypto       = new PayuniCrypto( $this->settings->hash_key, $this->settings->hash_iv );
		$encrypt_info = $crypto->encrypt( $inner );
		$hash_info    = $crypto->hash_info( $encrypt_info );

		$body = [
			'MerID'       => $this->settings->merchant_id,
			'Version'     => self::VERSION,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];

		$response_body = $this->request( $endpoint, $body, $action_zh );

		return $this->parse_response( $response_body, $action_zh );
	}

	/**
	 * 發送 POST 請求（form-urlencoded，Header User-Agent: payuni），回傳原始 body 字串
	 *
	 * @param string                $endpoint  端點
	 * @param array<string, string> $body      外層信封（MerID / Version / EncryptInfo / HashInfo）
	 * @param string                $action_zh 動作中文（log 用）
	 *
	 * @return string 原始回應 body（JSON）
	 * @throws \Exception 連線失敗
	 */
	private function request( string $endpoint, array $body, string $action_zh ): string {
		Plugin::logger(
			"PAYUNi UNi Embed DoAction {$action_zh}請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $endpoint ]
		);

		$response = \wp_remote_post(
			$endpoint,
			[
				'body'     => $body,
				'headers'  => [ 'User-Agent' => 'payuni' ],
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "PAYUNi UNi Embed DoAction 連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$action_zh}失敗：{$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 解析 DoAction JSON 回應（外層 JSON → 驗章 → 解密 → 內層）
	 *
	 *  1. JSON decode 外層；非陣列 → throw。
	 *  2. 外層 Status 非 SUCCESS（如 ERROR）→ order note 失敗 + throw（訊息含 Status）。
	 *  3. 有 EncryptInfo + HashInfo → verify_hash 驗章（不符 throw 含 'HashInfo'）→ 解密回內層。
	 *  4. 無 EncryptInfo（MOCK / 簡化 fixture）→ 直接回外層 decoded（Status=SUCCESS）。
	 *
	 * @param array<string, mixed>|string $response  原始回應 body（JSON 字串）或已 decode 陣列
	 * @param string                      $action_zh 動作中文
	 *
	 * @return array<string, mixed> 回應內層（或外層 decoded）
	 * @throws \Exception JSON 解析失敗 / Status 非 SUCCESS / 驗章失敗 / 解密失敗
	 */
	public function parse_response( array|string $response, string $action_zh = '退款' ): array {
		$decoded = \is_array( $response ) ? $response : \json_decode( \trim( $response ), true );
		if ( ! \is_array( $decoded ) ) {
			$msg = "PAYUNi UNi Embed {$action_zh} API 回應解析失敗";
			$this->order->add_order_note( "❌ {$action_zh}失敗：{$msg}" );
			throw new \Exception( $msg );
		}

		// 外層 Status 非 SUCCESS（PAYUNi 報錯，無 EncryptInfo）→ 直接拒絕
		$outer_status = (string) ( $decoded['Status'] ?? '' );
		if ( self::STATUS_SUCCESS !== $outer_status ) {
			$message = (string) ( $decoded['Message'] ?? '' );
			$msg     = "PAYUNi UNi Embed {$action_zh} API 回應 Status={$outer_status}：{$message}";
			$this->order->add_order_note( "❌ {$action_zh}失敗：{$msg}" );
			throw new \Exception( $msg );
		}

		$encrypt_info = (string) ( $decoded['EncryptInfo'] ?? '' );
		$hash_info    = (string) ( $decoded['HashInfo'] ?? '' );

		// 有加密內層 → 驗章 + 解密（防竄改）
		if ( '' !== $encrypt_info && '' !== $hash_info ) {
			$crypto = new PayuniCrypto( $this->settings->hash_key, $this->settings->hash_iv );

			if ( ! $crypto->verify_hash( $encrypt_info, $hash_info ) ) {
				$msg = "PAYUNi UNi Embed {$action_zh} API 回應 HashInfo 驗章失敗（疑似竄改）";
				$this->order->add_order_note( "❌ {$action_zh}失敗：{$msg}" );
				throw new \Exception( $msg );
			}

			return $crypto->decrypt( $encrypt_info );
		}

		// 無 EncryptInfo（MOCK / 簡化 fixture）→ 直接回外層 decoded
		return $decoded;
	}

	/**
	 * 取得 PAYUNi UNi 序號 TradeNo（自 _pc_payuni_uni_payment_detail）
	 *
	 * D1：讀 UNi Embed meta（_pc_payuni_uni_payment_detail），絕不讀 UPP 的 _pc_payuni_payment_detail。
	 *
	 * @return string TradeNo（未設定回空字串）
	 */
	private function get_trade_no(): string {
		$detail = ( new PayuniUniEmbedMetaKeys( $this->order ) )->get_payment_detail();
		return (string) ( $detail['TradeNo'] ?? '' );
	}

	/**
	 * 驗證 TradeNo 非空
	 *
	 * @param string $trade_no  PAYUNi UNi 序號
	 * @param string $action_zh 動作中文
	 *
	 * @return void
	 * @throws \Exception 缺 TradeNo
	 */
	private function assert_trade_no( string $trade_no, string $action_zh ): void {
		if ( '' === $trade_no ) {
			$msg = "PAYUNi UNi Embed {$action_zh}缺少 TradeNo（PAYUNi UNi 序號）";
			$this->order->add_order_note( "❌ {$action_zh}失敗：{$msg}" );
			throw new \Exception( $msg );
		}
	}

	/**
	 * 取得完整端點（依 mode 切換 sandbox / prod host + 指定路徑）
	 *
	 * 由 settings->token_get_url（已含 sandbox / prod 主機）取 host 部分，再串接 DoAction 路徑。
	 *
	 * @param string $path API 路徑（/api/trade/close 或 /api/trade/cancel）
	 * @return string 完整端點 URL
	 */
	private function get_endpoint( string $path ): string {
		$host = (string) \wp_parse_url( $this->settings->token_get_url, PHP_URL_SCHEME )
		. '://'
		. (string) \wp_parse_url( $this->settings->token_get_url, PHP_URL_HOST );
		return $host . $path;
	}
}
