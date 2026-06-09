<?php
/**
 * PAYUNi UNi Embed V3 merchant_trade API client（/api/iframe/merchant_trade）
 *
 * V3 第二段（前端 SDK 取得綁定 TOKEN 結果後，後端以「原 SDK_TOKEN」呼叫此 API 完成幕後授權）：
 *   請求 Version 固定 1.0、回傳 Version 固定 1.2（PAYUNi 設計，非 bug）。
 *   ⚠️ 解析時「不對 Version 分支」——僅依 Status / TradeStatus / URL 判定授權結果與是否 3D。
 *
 * 內層 payload（本階段才送訂單欄位，與 V3 token_get「不送訂單」相對）：
 *   MerID + MerTradeNo + Token(原 SDK_TOKEN) + TradeAmt + Timestamp + ProdDesc + NotifyURL（+ UsrMail）。
 *   ⚠️ 硬約束：TradeAmt 一律後端從 $order->get_total() 計算（ceil 整數），絕不信任前端傳入金額
 *      （防 SDK 等候期竄改，對齊 SKILL §V3 核心改變 + §注意事項 12）。
 *
 * 三層請求結構（與 UPP / token_get 一致）：
 *   { MerID, Version: '1.0', EncryptInfo: AES-256-GCM(payload), HashInfo: SHA256(...) }
 *   加密複用 Payuni\Shared\Helpers\PayuniCrypto（不開第 3 份副本）。
 *
 * 回應分流（解密後）：
 *   - API3D=1 強制 3D：Status=SUCCESS + Message=建立幕後3D成功 + URL（導頁網址）→ 前端導向 3D。
 *   - 非 3D 直接授權：Status=SUCCESS + TradeStatus(1/2/3/8) + Gateway=9 + PaymentType=1（無 URL）。
 *
 * 測試 mock 介接（不在 prod 路徑造成副作用，比照 Cycle 1 TokenGetClient 慣例）：
 *   - filter `payuni_uni_embed_mock_merchant_trade_request`：攔截實際送出的內層 payload 供測試斷言
 *     （TradeAmt / MerTradeNo 等）。
 *   - filter `payuni_uni_embed_mock_merchant_trade_response`：注入 mock 外層 / 解密後回應（陣列），跳過真 HTTP。
 *   - filter `payuni_uni_embed_mock_merchant_trade_exception`：注入 \Throwable，模擬連線 / 例外。
 *   - 無上述 filter 時，API_MODE=mock 回固定成功 fixture（非 3D 直接授權），仍不打真 API。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §API 2 merchant_trade §回傳（API3D=1） §注意事項 15
 * @see \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\TokenGetClient mock filter 慣例
 * @see \J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgApiClient::create_payment 內嵌式骨架對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http;

use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use J7\PowerCheckout\Plugin;

/** PAYUNi UNi Embed V3 merchant_trade API client */
final class MerchantTradeClient {

	/** @var int HTTP 逾時秒數 */
	private const TIMEOUT = 60;

	/** @var string merchant_trade 請求 Version（V3 固定 1.0；回傳固定 1.2，解析時不分支） */
	private const VERSION = '1.0';

	/** @var string merchant_trade 端點路徑（V3 內嵌式） */
	private const PATH = '/api/iframe/merchant_trade';

	/** @var string NotifyURL REST 路徑（Cycle 3 callback 接此；本 Cycle 先產生 URL 供 merchant_trade 帶入） */
	private const NOTIFY_PATH = 'wp-json/power-checkout/payuni/uni-embed/notify';

	/** @var string 攔截實際送出內層 payload 的 filter（測試斷言 TradeAmt / MerTradeNo 用） */
	public const FILTER_MOCK_REQUEST = 'payuni_uni_embed_mock_merchant_trade_request';

	/** @var string 注入 mock 回應的 filter（測試用） */
	public const FILTER_MOCK_RESPONSE = 'payuni_uni_embed_mock_merchant_trade_response';

	/** @var string 注入例外的 filter（測試用） */
	public const FILTER_MOCK_EXCEPTION = 'payuni_uni_embed_mock_merchant_trade_exception';

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
	 * @var string 授權失敗標記 key（execute 回傳陣列含此鍵代表失敗；不與 PAYUNi 欄位衝突）
	 *
	 * execute 採「catch-and-return」契約：成功回原始解密結果；失敗回 ['status'=>'FAILED',
	 * 'message'=>通用訊息, FAILED_FLAG=>true]，呼叫端（FrontendApi）依此鍵判定失敗。
	 */
	public const FAILED_FLAG = '_pc_uni_failed';

	/** @var string 對前端 / 呼叫端的通用失敗訊息（不外洩 PAYUNi 錯誤碼 / stack trace） */
	private const GENERIC_FAIL_MESSAGE = '授權失敗，請稍後再試或聯繫商家';

	/**
	 * 以原 SDK_TOKEN 對訂單執行幕後授權（merchant_trade）
	 *
	 * 採「catch-and-return」契約（不拋例外至呼叫端）：
	 *  - 成功 → 回原始解密授權結果陣列（含 Status / TradeStatus / URL / Gateway 等）。
	 *  - 失敗（缺 SDK_TOKEN / 傳輸層 / 業務層 Status≠SUCCESS / 注入例外）→ catch 後回
	 *    ['status'=>'FAILED', 'message'=>通用訊息, FAILED_FLAG=>true]，並寫 order note + log。
	 *
	 * 流程：
	 *  1. 取訂單 SDK_TOKEN（token_get 階段寫入；缺則流程異常）。
	 *  2. 冪等：取 / 生成 MerTradeNo（PCE{order_id}）寫入 meta。
	 *  3. 組內層 payload（TradeAmt 後端 ceil 計算，忽略前端傳入金額）。
	 *  4. apply request filter（測試攔截）→ mock 例外 / mock 回應分流 → 否則 mock fixture / 真 HTTP。
	 *  5. 解析回應（不對 Version 分支）→ 回解密後授權結果陣列（含 TradeStatus / URL 等）。
	 *
	 * ⚠️ $options['trade_amt']（若有）一律被忽略；TradeAmt 永遠取自 $order->get_total()。
	 *
	 * @param \WC_Order            $order   訂單
	 * @param array<string, mixed> $options 選項（force_3ds: 強制 3D；trade_amt: 一律忽略，僅為相容測試簽章）
	 * @return array<string, mixed> merchant_trade 解密後授權結果（成功）或失敗標記陣列（失敗）
	 */
	public function execute( \WC_Order $order, array $options = [] ): array {
		try {
			$meta_keys = new PayuniUniEmbedMetaKeys( $order );

			$sdk_token = $meta_keys->get_sdk_token();
			if ( '' === $sdk_token ) {
				// 缺 SDK_TOKEN 代表未走過 token_get，流程異常（亦防越權直接打 merchant_trade）
				throw new \Exception( 'PAYUNi UNi Embed merchant_trade 缺少 SDK_TOKEN（未走過 token_get）' );
			}

			// 冪等：沿用既有 MerTradeNo（重試授權不重新編號），首次則生成並寫入
			$trade_no = $meta_keys->get_trade_no();
			if ( '' === $trade_no ) {
				$trade_no = PayuniUniEmbedTradeNo::generate( $order->get_id() );
				$meta_keys->update_trade_no( $trade_no );
			}

			$payload = $this->build_payload( $order, $sdk_token, $trade_no, $options );

			// 測試攔截：讓測試取得實際送出的內層 payload 以斷言 TradeAmt 後端計算 / MerTradeNo 前綴
			/** @var array<string, mixed> $payload */
			$payload = (array) \apply_filters( self::FILTER_MOCK_REQUEST, $payload );

			// 測試注入例外（模擬連線逾時 / Throwable）
			$injected_exception = \apply_filters( self::FILTER_MOCK_EXCEPTION, null );
			if ( $injected_exception instanceof \Throwable ) {
				throw $injected_exception;
			}

			// 測試注入 mock 回應（陣列）→ 跳過真 HTTP，直接走回應解析
			$mock_response = \apply_filters( self::FILTER_MOCK_RESPONSE, null );
			if ( \is_array( $mock_response ) ) {
				/** @var array<string, mixed> $mock_response */
				return $this->parse_response( $mock_response );
			}

			// API_MODE=mock：回固定成功 fixture（非 3D 直接授權），不打真 API
			if ( self::is_mock() ) {
				return $this->parse_response( $this->mock_success_response( $trade_no, (int) $payload['TradeAmt'] ) );
			}

			$body = $this->request( $payload );
			return $this->parse_response( $body );
		} catch ( \Throwable $e ) {
			// catch-and-return：細節寫 order note / log（供商家除錯），回通用訊息不外洩
			Plugin::logger(
				'PAYUNi UNi Embed merchant_trade 失敗',
				'error',
				[
					'order_id' => $order->get_id(),
					'error'    => $e->getMessage(),
				]
			);
			$order->add_order_note( "❌ PAYUNi 站內付授權失敗：{$e->getMessage()}" );

			return [
				'status'          => 'FAILED',
				'message'         => self::GENERIC_FAIL_MESSAGE,
				self::FAILED_FLAG => true,
			];
		}
	}

	/**
	 * 判定 execute 回傳結果是否為失敗
	 *
	 * @param array<string, mixed> $result execute 回傳陣列
	 * @return bool 失敗回 true
	 */
	public static function is_failed( array $result ): bool {
		return ! empty( $result[ self::FAILED_FLAG ] );
	}

	/**
	 * 組裝 merchant_trade 內層 payload
	 *
	 * ⚠️ TradeAmt 一律後端從 order total 計算（ceil 整數），不接受外部傳入金額（防竄改）。
	 *
	 * @param \WC_Order            $order     訂單
	 * @param string               $sdk_token 原 SDK_TOKEN（token_get 取得）
	 * @param string               $trade_no  MerTradeNo（PCE 前綴）
	 * @param array<string, mixed> $options   選項（force_3ds）
	 * @return array<string, mixed> 內層 payload
	 */
	private function build_payload( \WC_Order $order, string $sdk_token, string $trade_no, array $options ): array {
		// TradeAmt 後端計算（無條件進位為整數，避免少收）；前端傳入金額一律忽略
		$trade_amt = (int) \ceil( (float) $order->get_total() );

		$payload = [
			'MerID'      => $this->settings->merchant_id,
			'MerTradeNo' => $trade_no,
			'Token'      => $sdk_token,
			'TradeAmt'   => $trade_amt,
			'Timestamp'  => \time(),
			'ProdDesc'   => ItemName::get( $order ),
			'NotifyURL'  => self::get_notify_url(),
			'UsrMail'    => $order->get_billing_email(),
		];

		// 強制 3D（API3D=1）：商店可要求即使後台關閉仍走 3D
		if ( ! empty( $options['force_3ds'] ) ) {
			$payload['API3D'] = 1;
		}

		return $payload;
	}

	/**
	 * 發送 merchant_trade 請求（組三層結構）
	 *
	 * @param array<string, mixed> $payload merchant_trade 內層 payload
	 * @return array<string, mixed> 外層回應（含 Status / EncryptInfo / HashInfo）
	 * @throws \Exception 連線失敗 / 回應非 JSON
	 */
	private function request( array $payload ): array {
		$encrypt_info = $this->crypto->encrypt( $payload );
		$envelope     = [
			'MerID'       => $this->settings->merchant_id,
			'Version'     => self::VERSION, // 固定 1.0（回傳為 1.2，解析時不分支）
			'EncryptInfo' => $encrypt_info,
			'HashInfo'    => $this->crypto->hash_info( $encrypt_info ),
		];

		Plugin::logger(
			'PAYUNi UNi Embed merchant_trade 請求',
			'info',
			[ 'url' => $this->get_merchant_trade_url() ]
		);

		$response = \wp_remote_post(
			$this->get_merchant_trade_url(),
			[
				'body'     => $envelope,
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
				'headers'  => [ 'User-Agent' => 'payuni' ], // 官方建議帶 payuni 便於對方 log 識別
			]
		);

		if ( \is_wp_error( $response ) ) {
			throw new \Exception( "PAYUNi UNi Embed merchant_trade 連線失敗：{$response->get_error_message()}" );
		}

		$decoded = \json_decode( \wp_remote_retrieve_body( $response ), true );
		if ( ! \is_array( $decoded ) ) {
			throw new \Exception( 'PAYUNi UNi Embed merchant_trade 回應非合法 JSON' );
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * 解析 merchant_trade 回應 → 驗外層 Status=SUCCESS → 解密 EncryptInfo → 回授權結果
	 *
	 * ⚠️ 不對 Version 分支（請求送 1.0、回傳固定 1.2，純依 Status / TradeStatus / URL 判定）。
	 *
	 * 拆為獨立方法以利測試（mock 回應直接走此處）。回應可能兩種形態：
	 *  - 真實 PAYUNi：外層 5 欄（Status / MerID / Version / EncryptInfo / HashInfo），授權結果在 EncryptInfo 內。
	 *  - 測試 mock：直接攤平帶 Status / TradeStatus / URL 等（無 EncryptInfo），直接採用。
	 *
	 * @param array<string, mixed> $body 外層回應
	 * @return array<string, mixed> 授權結果（解密後內層，含 Status / TradeStatus / URL / Gateway 等）
	 * @throws \Exception 外層 Status≠SUCCESS（業務層失敗，如 IFTRADE04001）
	 */
	public function parse_response( array $body ): array {
		$status = (string) ( $body['Status'] ?? '' );
		if ( 'SUCCESS' !== $status ) {
			$message = (string) ( $body['Message'] ?? 'unknown' );
			// 業務層失敗（含 SDK_TOKEN 逾期 IFTRADE04001）→ throw，上層統一 catch 回通用訊息
			throw new \Exception( "PAYUNi UNi Embed merchant_trade 失敗 Status={$status}：{$message}" );
		}

		// 真實回應：授權結果在 EncryptInfo（hex）；mock 回應直接攤平於外層
		$encrypt_info = (string) ( $body['EncryptInfo'] ?? '' );
		if ( '' !== $encrypt_info ) {
			$decrypted = $this->crypto->decrypt( $encrypt_info );
			// 解密後內層仍應為 Status=SUCCESS（PAYUNi 內外層一致）；保底回內層
			return $decrypted;
		}

		// mock / 攤平形態：直接回外層（已含 Status / TradeStatus / URL 等）
		return $body;
	}

	/**
	 * 判定 merchant_trade 授權結果是否為「需 3D 導頁」
	 *
	 * 依 SKILL §回傳（API3D=1）：強制 3D 時回 Message=建立幕後3D成功 + URL；
	 * 一般情況亦可能因銀行決定回 URL。判定基準：解密後內層含非空 URL 欄位即需 3D。
	 *
	 * @param array<string, mixed> $result merchant_trade 解密後授權結果
	 * @return string 3D 導頁 URL（不需 3D 時回空字串）
	 */
	public static function extract_three_d_url( array $result ): string {
		return (string) ( $result['URL'] ?? '' );
	}

	/**
	 * MOCK：merchant_trade 成功外層回應（固定 fixture，非 3D 直接授權）
	 *
	 * @param string $trade_no  MerTradeNo
	 * @param int    $trade_amt 訂單金額
	 * @return array<string, mixed>
	 */
	private function mock_success_response( string $trade_no, int $trade_amt ): array {
		return [
			'Status'      => 'SUCCESS',
			'Message'     => '授權成功',
			'MerID'       => $this->settings->merchant_id,
			'MerTradeNo'  => $trade_no,
			'Gateway'     => '9', // IFrame（與 UPP 的 2 不同）
			'TradeNo'     => 'UNI' . \gmdate( 'YmdHis' ) . \substr( (string) \wp_rand( 100000, 999999 ), 0, 6 ),
			'TradeAmt'    => $trade_amt,
			'TradeStatus' => '1', // 已付款（最終以 NotifyURL 為準）
			'PaymentType' => '1', // 信用卡
			'AuthCode'    => '123456',
		];
	}

	/**
	 * NotifyURL（PAYUNi 3D 完成後 Form POST 授權結果至此；Cycle 3 callback 處理）
	 *
	 * ⚠️ PAYUNi 規範 NotifyURL 僅 80/443 port，一律 https。本 Cycle 僅產生 URL 供 merchant_trade
	 *    帶入；實際 callback 端點於 Cycle 3 接上，故此處不依賴 Callback 類別（避免引入未實作類別）。
	 *
	 * @return string NotifyURL（https://{host}/wp-json/power-checkout/payuni/uni-embed/notify）
	 */
	public static function get_notify_url(): string {
		return \site_url( self::NOTIFY_PATH, 'https' );
	}

	/** @return string merchant_trade 端點 URL（依 mode：sandbox / prod 主機 + 路徑） */
	private function get_merchant_trade_url(): string {
		// token_get_url 已含 sandbox / prod 主機，取主機段後拼 merchant_trade 路徑
		$host = (string) \preg_replace( '#/api/iframe/token_get$#', '', $this->settings->token_get_url );
		return $host . self::PATH;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}
}
