<?php
/**
 * PAYUNi UNi Embed V3 交易查詢 API client（對帳 / 補單用）
 *
 * ⚠️ 架構決策 D1（specs/open-issue/payuni-uni-embed-execution-plan.md）：
 *   本 client 為 Payuni\Http\QueryTradeClient 的「同源複製 + 換注入」版本——
 *   - 注入 {@see PayuniUniEmbedSettingsDTO}（讀 woocommerce_payuni_uni_embed_settings）。
 *   - 讀 _pc_payuni_uni_trade_no（透過 {@see PayuniUniEmbedMetaKeys}），非 UPP 的 _pc_payuni_trade_no。
 *   - 加密一律 use 既有 {@see PayuniCrypto}。
 *
 * 端點：/api/trade/query（POST form-urlencoded，Version=2.0，回應 JSON）。
 * 依 MerTradeNo 查 PAYUNi 交易狀態；已付款判定：TradeStatus=1 且 DataSource=A。
 *
 * 測試 mock：
 *  - filter `payuni_uni_embed_mock_query_response`：回傳陣列即作為查詢內層結果，跳過真 HTTP。
 *  - filter `payuni_uni_embed_mock_query_exception`：回 truthy 即拋例外。
 *
 * @see .claude/skills/payuni-upp-v2/references/api-reference.md §交易查詢 API
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\Http\QueryTradeClient 同源藍本
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Plugin;

/** PAYUNi UNi Embed V3 交易查詢 API client */
final class UniQueryTradeClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string Version（查詢固定 2.0） */
	private const VERSION = '2.0';

	/** @var string 查詢路徑 */
	private const QUERY_PATH = '/api/trade/query';

	/** @var string 外層 / 內層成功狀態值 */
	private const STATUS_SUCCESS = 'SUCCESS';

	/** @var string 已付款 TradeStatus 值 */
	private const TRADE_STATUS_PAID = '1';

	/** @var string 注入 mock 回應的 filter（測試用；回傳陣列即作為內層查詢結果） */
	public const FILTER_MOCK_RESPONSE = 'payuni_uni_embed_mock_query_response';

	/** @var string 注入例外的 filter（測試用；回 truthy 即拋例外） */
	public const FILTER_MOCK_EXCEPTION = 'payuni_uni_embed_mock_query_exception';

	/** @var PayuniUniEmbedSettingsDTO 設定（D1：UNi Embed 專屬，非 UPP） */
	private readonly PayuniUniEmbedSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（提供 MerTradeNo 與 order note） */
		private readonly \WC_Order $order,
	) {
		$this->settings = PayuniUniEmbedSettingsDTO::instance();
	}

	/**
	 * 依訂單 MerTradeNo 查詢 PAYUNi 交易資訊
	 *
	 * @return array<string, mixed> 查詢結果內層（含 TradeStatus / PaymentType / MerTradeNo / DataSource 等）
	 * @throws \Exception 缺 MerTradeNo / 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	public function query(): array {
		$mer_trade_no = $this->get_mer_trade_no();

		// 測試注入例外
		if ( \apply_filters( self::FILTER_MOCK_EXCEPTION, false ) ) {
			throw new \Exception( 'PAYUNi UNi Embed 交易查詢失敗（mock exception）' );
		}

		// 測試注入 mock 回應（陣列）→ 跳過真 HTTP
		$mock_response = \apply_filters( self::FILTER_MOCK_RESPONSE, null );
		if ( \is_array( $mock_response ) ) {
			/** @var array<string, mixed> $mock_response */
			return $mock_response;
		}

		$inner = [
			'MerID'      => $this->settings->merchant_id,
			'MerTradeNo' => $mer_trade_no,
			'Timestamp'  => \time(),
		];

		$crypto       = new PayuniCrypto( $this->settings->hash_key, $this->settings->hash_iv );
		$encrypt_info = $crypto->encrypt( $inner );
		$hash_info    = $crypto->hash_info( $encrypt_info );

		$body = [
			'MerID'       => $this->settings->merchant_id,
			'Version'     => self::VERSION,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $hash_info,
		];

		$response_json = $this->request( $body );
		$decoded       = \json_decode( \trim( $response_json ), true );
		if ( ! \is_array( $decoded ) ) {
			throw new \Exception( 'PAYUNi UNi Embed 交易查詢回應解析失敗（非 JSON）' );
		}

		// 正規化為 array<string, mixed>（json_decode 可能產生 int key）
		$outer = [];
		foreach ( $decoded as $key => $value ) {
			$outer[ (string) $key ] = $value;
		}

		// 外層 Status 非 SUCCESS → throw（含 Status 字串）
		$this->parse_outer_response( $outer );

		$resp_encrypt_info = (string) ( $outer['EncryptInfo'] ?? '' );
		$resp_hash_info    = (string) ( $outer['HashInfo'] ?? '' );

		// 驗章（不符 throw）→ 解密內層
		$this->verify_hash_info( $resp_encrypt_info, $resp_hash_info );

		return $crypto->decrypt( $resp_encrypt_info );
	}

	/**
	 * 判定 TradeStatus 是否為已付款（TradeStatus=1）
	 *
	 * @param string|int $trade_status 交易狀態值
	 * @return bool
	 */
	public static function is_paid( string|int $trade_status ): bool {
		return self::TRADE_STATUS_PAID === (string) $trade_status;
	}

	/**
	 * 驗證查詢回應外層 Status；非 SUCCESS（如 ERROR）→ throw
	 *
	 * @param array<string, mixed> $outer 外層回應陣列（含 Status）
	 * @return void
	 * @throws \Exception Status 非 SUCCESS（訊息含 Status 字串）
	 */
	public function parse_outer_response( array $outer ): void {
		$status = (string) ( $outer['Status'] ?? '' );
		if ( self::STATUS_SUCCESS !== $status ) {
			$message = (string) ( $outer['Message'] ?? '' );
			throw new \Exception( "PAYUNi UNi Embed 交易查詢回應 Status={$status}：{$message}" );
		}
	}

	/**
	 * 驗證查詢回應 HashInfo（timing-safe）；不符 → throw（防竄改）
	 *
	 * @param string $encrypt_info EncryptInfo（hex 字串）
	 * @param string $hash_info    對方帶來的 HashInfo
	 * @return void
	 * @throws \Exception HashInfo 不一致（訊息含 'HashInfo'）
	 */
	public function verify_hash_info( string $encrypt_info, string $hash_info ): void {
		$crypto = new PayuniCrypto( $this->settings->hash_key, $this->settings->hash_iv );
		if ( ! $crypto->verify_hash( $encrypt_info, $hash_info ) ) {
			throw new \Exception( 'PAYUNi UNi Embed 交易查詢回應 HashInfo 驗章失敗（疑似竄改）' );
		}
	}

	/**
	 * 取得訂單 MerTradeNo（自 _pc_payuni_uni_trade_no）
	 *
	 * D1：讀 UNi Embed meta（_pc_payuni_uni_trade_no），絕不讀 UPP 的 _pc_payuni_trade_no。
	 *
	 * @return string MerTradeNo
	 * @throws \Exception 缺 MerTradeNo
	 */
	private function get_mer_trade_no(): string {
		$mer_trade_no = ( new PayuniUniEmbedMetaKeys( $this->order ) )->get_trade_no();
		if ( '' === $mer_trade_no ) {
			$msg = 'PAYUNi UNi Embed 交易查詢缺少 MerTradeNo';
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}
		return $mer_trade_no;
	}

	/**
	 * 發送 POST 請求（form-urlencoded，Header User-Agent: payuni），回傳原始 body 字串
	 *
	 * @param array<string, string> $body 外層信封（MerID / Version / EncryptInfo / HashInfo）
	 * @return string 原始回應 body（JSON）
	 * @throws \Exception 連線失敗
	 */
	private function request( array $body ): string {
		Plugin::logger(
			"PAYUNi UNi Embed 交易查詢請求 #{$this->order->get_id()}",
			'info',
			[ 'endpoint' => $this->get_endpoint() ]
		);

		$response = \wp_remote_post(
			$this->get_endpoint(),
			[
				'body'     => $body,
				'headers'  => [ 'User-Agent' => 'payuni' ],
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			$msg = "PAYUNi UNi Embed 交易查詢連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 取得查詢端點（依 mode 切換 sandbox / prod host + 查詢路徑）
	 *
	 * 由 settings->token_get_url（已含 sandbox / prod 主機）取 host 部分，再串接查詢路徑。
	 *
	 * @return string 完整端點 URL
	 */
	private function get_endpoint(): string {
		$host = (string) \wp_parse_url( $this->settings->token_get_url, PHP_URL_SCHEME )
		. '://'
		. (string) \wp_parse_url( $this->settings->token_get_url, PHP_URL_HOST );
		return $host . self::QUERY_PATH;
	}
}
