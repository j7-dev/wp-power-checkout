<?php
/**
 * PAYUNi UPP V2 交易查詢 API client（對帳 / 補單用）
 *
 * 端點：/api/trade/query（POST form-urlencoded，Version=2.0，回應 JSON）。
 * 依 MerTradeNo 或 TradeNo 查 PAYUNi 交易狀態，供對帳 / 補單判斷使用。
 *
 * ⚠️ 重要：
 *  - 外層信封 MerID / Version / EncryptInfo / HashInfo；Header 須加 `User-Agent: payuni`。
 *  - 回應外層 JSON，Status=SUCCESS 後解密 EncryptInfo 取內層；Status=ERROR 時無 EncryptInfo。
 *  - 內層 TradeStatus：0=取號成功 / 9=未付款 / 1=已付款 / 2=失敗 / 3=取消 / 4=逾期 / 8=待確認。
 *  - DataSource：A=完整資料 / B=處理中（建議 10 分鐘後再查）。
 *  - 已付款判定：TradeStatus=1 且 DataSource=A（見 PayuniUppGateway::handle_query_action）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/payuni-upp-v2/references/api-reference.md §交易查詢 API
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Plugin;

/** PAYUNi UPP V2 交易查詢 API client */
final class QueryTradeClient {

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

	/** @var PayuniSettingsDTO 設定 */
	private readonly PayuniSettingsDTO $settings;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單（提供 MerTradeNo 與 order note） */
		private readonly \WC_Order $order,
	) {
		$this->settings = PayuniSettingsDTO::instance();
	}

	/**
	 * 依訂單 MerTradeNo 查詢 PAYUNi 交易資訊
	 *
	 * @return array<string, mixed> 查詢結果內層（含 TradeStatus / PaymentType / MerTradeNo / DataSource 等）
	 * @throws \Exception 缺 MerTradeNo / 連線失敗 / Status 非 SUCCESS / 驗章失敗
	 */
	public function query(): array {
		$mer_trade_no = $this->get_mer_trade_no();
		$amt          = (int) \ceil( (float) $this->order->get_total() );

		// MOCK 模式：不打真 API，回固定 fixture（已付款、完整資料）
		// TradeAmt 取訂單應收（供補單時 StatusManager 金額防竄改通過）；MerID 取商店設定（供 MerID 比對通過）。
		if ( self::is_mock() ) {
			return [
				'Status'      => self::STATUS_SUCCESS,
				'Message'     => '查詢成功',
				'MerID'       => $this->settings->merchant_id,
				'MerTradeNo'  => $mer_trade_no,
				'TradeNo'     => 'UNI' . $this->order->get_id(),
				'TradeAmt'    => $amt,
				'TradeStatus' => self::TRADE_STATUS_PAID,
				'PaymentType' => '1',
				'PaymentDay'  => \wp_date( 'Y-m-d H:i:s' ) ?: '',
				'DataSource'  => 'A',
			];
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
			throw new \Exception( 'PAYUNi 交易查詢回應解析失敗（非 JSON）' );
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
	 * 取號成功（0）/ 未付款（9）/ 失敗（2）/ 取消（3）/ 逾期（4）/ 待確認（8）皆不算已付款。
	 *
	 * @param string|int $trade_status 交易狀態值
	 * @return bool
	 */
	public static function is_paid( string|int $trade_status ): bool {
		return self::TRADE_STATUS_PAID === (string) $trade_status;
	}

	/**
	 * 解析查詢回應 querystring / JSON 為內層陣列
	 *
	 * PAYUNi 交易查詢內層解密後為 querystring 格式（key=value&...）；
	 * 拆為獨立方法以利測試（不需真 HTTP）。
	 *
	 * @param string $body 內層原始字串（querystring 格式）
	 * @return array<string, mixed> 解析後內層陣列
	 */
	public function parse_response( string $body ): array {
		\parse_str( \trim( $body ), $result );

		$normalized = [];
		foreach ( $result as $key => $value ) {
			$normalized[ (string) $key ] = $value;
		}

		return $normalized;
	}

	/**
	 * 驗證查詢回應外層 Status；非 SUCCESS（如 ERROR）→ throw
	 *
	 * 拆為獨立方法以利測試（防偽造 / 跨商店污染前的第一道防線）。
	 *
	 * @param array<string, mixed> $outer 外層回應陣列（含 Status）
	 * @return void
	 * @throws \Exception Status 非 SUCCESS（訊息含 Status 字串）
	 */
	public function parse_outer_response( array $outer ): void {
		$status = (string) ( $outer['Status'] ?? '' );
		if ( self::STATUS_SUCCESS !== $status ) {
			$message = (string) ( $outer['Message'] ?? '' );
			throw new \Exception( "PAYUNi 交易查詢回應 Status={$status}：{$message}" );
		}
	}

	/**
	 * 驗證查詢回應 HashInfo（timing-safe）；不符 → throw（防竄改）
	 *
	 * 拆為獨立方法以利測試。SHA256(HashKey + EncryptInfo + HashIV).toUpperCase() === HashInfo。
	 *
	 * @param string $encrypt_info EncryptInfo（hex 字串）
	 * @param string $hash_info    對方帶來的 HashInfo
	 * @return void
	 * @throws \Exception HashInfo 不一致（訊息含 'HashInfo'）
	 */
	public function verify_hash_info( string $encrypt_info, string $hash_info ): void {
		$crypto = new PayuniCrypto( $this->settings->hash_key, $this->settings->hash_iv );
		if ( ! $crypto->verify_hash( $encrypt_info, $hash_info ) ) {
			throw new \Exception( 'PAYUNi 交易查詢回應 HashInfo 驗章失敗（疑似竄改）' );
		}
	}

	/**
	 * 取得訂單 MerTradeNo（自 _pc_payuni_trade_no）
	 *
	 * @return string MerTradeNo
	 * @throws \Exception 缺 MerTradeNo
	 */
	private function get_mer_trade_no(): string {
		$mer_trade_no = ( new PayuniMetaKeys( $this->order ) )->get_trade_no();
		if ( '' === $mer_trade_no ) {
			$msg = 'PAYUNi 交易查詢缺少 MerTradeNo';
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
			"PAYUNi 交易查詢請求 #{$this->order->get_id()}",
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
			$msg = "PAYUNi 交易查詢連線失敗：{$response->get_error_message()}";
			$this->order->add_order_note( "❌ {$msg}" );
			throw new \Exception( $msg );
		}

		return (string) \wp_remote_retrieve_body( $response );
	}

	/**
	 * 取得查詢端點（依 mode 切換 sandbox / prod host + 查詢路徑）
	 *
	 * @return string 完整端點 URL
	 */
	private function get_endpoint(): string {
		$host = (string) \wp_parse_url( $this->settings->api_url, PHP_URL_SCHEME )
		. '://'
		. (string) \wp_parse_url( $this->settings->api_url, PHP_URL_HOST );
		return $host . self::QUERY_PATH;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		// mock 與 mock_fail 皆走 fixture（查詢無失敗變體需求）
		return \str_starts_with( \strtolower( $mode ), 'mock' );
	}
}
