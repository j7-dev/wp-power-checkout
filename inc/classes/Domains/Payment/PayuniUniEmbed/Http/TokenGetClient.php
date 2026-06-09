<?php
/**
 * PAYUNi UNi Embed V3 token_get API client（/api/iframe/token_get，Version 3.0）
 *
 * V3 核心硬約束（與 V2 最大差異）：
 *   token_get 內層 payload「只送 MerID + Timestamp + IFrameDomain」，
 *   絕不送 MerTradeNo / TradeAmt / ProdDesc / NotifyURL / ReturnURL 等訂單欄位。
 *   訂單欄位在後續 merchant_trade（Cycle 2）才送。本 client 僅負責「取 SDK_TOKEN」。
 *
 * 三層請求結構（與 UPP 一致）：
 *   { MerID, Version: '3.0', EncryptInfo: AES-256-GCM(payload), HashInfo: SHA256(...) }
 *   加密複用 Payuni\Shared\Helpers\PayuniCrypto（不開第 3 份副本）。
 *
 * 回應驗證：
 *   外層 Status === 'SUCCESS' 才解密 EncryptInfo；非 SUCCESS → throw（gateway 記 order note，不轉狀態）。
 *   解密後取 Token（SDK_TOKEN，10 分鐘有效）。
 *
 * 測試 mock 介接（不在 prod 路徑造成副作用，比照 EcpgApiClient is_mock 慣例）：
 *   - filter `payuni_uni_embed_token_get_payload`：攔截內層 payload 供測試斷言 V3 硬約束。
 *   - filter `payuni_uni_embed_mock_token_get_response`：注入 mock 外層回應（陣列），跳過真 HTTP。
 *   - filter `payuni_uni_embed_mock_token_get_exception`：注入 \Throwable，模擬連線 / 例外。
 *   - 無上述 filter 時，API_MODE=mock 回固定成功 fixture（含 Token），仍不打真 API。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §API 1 token_get（Version 3.0）
 * @see \J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgApiClient is_mock / mock filter 慣例
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Plugin;

/** PAYUNi UNi Embed V3 token_get API client */
final class TokenGetClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string token_get 請求 / 回傳 Version（V3 固定 3.0） */
	private const VERSION = '3.0';

	/** @var string 攔截內層 payload 的 filter（測試斷言 V3 硬約束用） */
	public const FILTER_PAYLOAD = 'payuni_uni_embed_token_get_payload';

	/** @var string 注入 mock 外層回應的 filter（測試用） */
	public const FILTER_MOCK_RESPONSE = 'payuni_uni_embed_mock_token_get_response';

	/** @var string 注入例外的 filter（測試用） */
	public const FILTER_MOCK_EXCEPTION = 'payuni_uni_embed_mock_token_get_exception';

	/** @var PayuniUniEmbedSettingsDTO 設定 */
	private readonly PayuniUniEmbedSettingsDTO $settings;

	/** @var PayuniCrypto 加解密器（複用 UPP 同源 PayuniCrypto） */
	private readonly PayuniCrypto $crypto;

	/** Constructor */
	public function __construct() {
		$this->settings = PayuniUniEmbedSettingsDTO::instance();
		$this->crypto   = new PayuniCrypto( $this->settings->hash_key, $this->settings->hash_iv );
	}

	/**
	 * 呼叫 token_get 取得 SDK_TOKEN
	 *
	 * 流程：apply payload filter（測試攔截）→ mock 例外 / mock 回應分流 → 否則 mock fixture
	 * 或真 HTTP（組三層結構 EncryptInfo + HashInfo）→ 驗 Status=SUCCESS → 解密取 Token。
	 *
	 * ⚠️ 傳入的 $payload 必須只含 MerID + Timestamp + IFrameDomain（V3 硬約束，由 gateway 組裝）。
	 *
	 * @param array<string, mixed> $payload token_get 內層 payload（MerID + Timestamp + IFrameDomain）
	 * @return string SDK_TOKEN
	 * @throws \Throwable Token_get 失敗（外層 Status≠SUCCESS / 缺 Token / 傳輸層 / 注入例外）
	 */
	public function get_sdk_token( array $payload ): string {
		// 測試攔截：讓測試取得實際送出的內層 payload 以斷言 V3 硬約束（prod 預設不掛此 filter → 原值返回）
		/** @var array<string, mixed> $payload */
		$payload = (array) \apply_filters( self::FILTER_PAYLOAD, $payload );

		// 測試注入例外（模擬連線逾時 / Throwable）
		$injected_exception = \apply_filters( self::FILTER_MOCK_EXCEPTION, null );
		if ( $injected_exception instanceof \Throwable ) {
			throw $injected_exception;
		}

		// 測試注入 mock 外層回應（陣列）→ 跳過真 HTTP，直接走回應解析
		$mock_response = \apply_filters( self::FILTER_MOCK_RESPONSE, null );
		if ( \is_array( $mock_response ) ) {
			/** @var array<string, mixed> $mock_response */
			return $this->parse_response( $mock_response );
		}

		// API_MODE=mock：回固定成功 fixture（含 Token），不打真 API
		if ( self::is_mock() ) {
			return $this->parse_response( $this->mock_success_response() );
		}

		$body = $this->request( $payload );
		return $this->parse_response( $body );
	}

	/**
	 * 發送 token_get 請求（組三層結構）
	 *
	 * @param array<string, mixed> $payload token_get 內層 payload
	 * @return array<string, mixed> 外層回應（含 Status / EncryptInfo / HashInfo）
	 * @throws \Exception 連線失敗 / 回應非 JSON
	 */
	private function request( array $payload ): array {
		$encrypt_info = $this->crypto->encrypt( $payload );
		$envelope     = [
			'MerID'       => $this->settings->merchant_id,
			'Version'     => self::VERSION,
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $this->crypto->hash_info( $encrypt_info ),
		];

		Plugin::logger(
			'PAYUNi UNi Embed token_get 請求',
			'info',
			[ 'url' => $this->settings->token_get_url ]
		);

		$response = \wp_remote_post(
			$this->settings->token_get_url,
			[
				'body'     => $envelope,
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			throw new \Exception( "PAYUNi UNi Embed token_get 連線失敗：{$response->get_error_message()}" );
		}

		$decoded = \json_decode( \wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $decoded ) ) {
			throw new \Exception( 'PAYUNi UNi Embed token_get 回應非合法 JSON' );
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * 解析外層回應 → 驗 Status=SUCCESS → 解密 EncryptInfo → 取 SDK_TOKEN
	 *
	 * 拆為獨立方法以利測試（mock 回應直接走此處）。
	 *
	 * @param array<string, mixed> $body 外層回應
	 * @return string SDK_TOKEN
	 * @throws \Exception 外層 Status≠SUCCESS / 缺 EncryptInfo / 解密後缺 Token
	 */
	public function parse_response( array $body ): string {
		$status = (string) ( $body['Status'] ?? '' );
		if ( 'SUCCESS' !== $status ) {
			$message = (string) ( $body['Message'] ?? 'unknown' );
			throw new \Exception( "PAYUNi UNi Embed token_get 失敗 Status={$status}：{$message}" );
		}

		// mock 回應可能直接帶 Token（不經 EncryptInfo），優先採用
		$token = (string) ( $body['Token'] ?? '' );

		if ( '' === $token ) {
			$encrypt_info = (string) ( $body['EncryptInfo'] ?? '' );
			if ( '' !== $encrypt_info ) {
				$decrypted = $this->crypto->decrypt( $encrypt_info );
				$token     = (string) ( $decrypted['Token'] ?? '' );
			}
		}

		if ( '' === $token ) {
			throw new \Exception( 'PAYUNi UNi Embed token_get 回應缺少 Token（SDK_TOKEN）' );
		}

		return $token;
	}

	/**
	 * MOCK：token_get 成功外層回應（固定 fixture，含 SDK_TOKEN）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_success_response(): array {
		return [
			'Status'  => 'SUCCESS',
			'MerID'   => $this->settings->merchant_id,
			'Message' => 'mock success',
			'Token'   => 'mock_sdk_token_' . \substr( \md5( (string) \time() ), 0, 16 ),
		];
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
