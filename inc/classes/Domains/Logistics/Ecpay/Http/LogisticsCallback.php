<?php
/**
 * 綠界全方位物流 v2（AllInOne）callback 接收（ServerReplyURL + ClientReplyURL）
 *
 * 比照 Payment\Ecpg\Http\EcpgCallback 採 ApiBase + SingletonTrait，但 ⚠️ 回應格式不同：
 * 全方位物流貨態 callback 必須回 AES-JSON 三層結構（非站內付的純文字 `1|OK`，計畫 R2）。
 *
 * 兩個 REST 端點（namespace power-checkout/ecpay，permission_callback __return_true）：
 *  - logistics/status-callback（貨態通知 ServerReplyURL）：JSON body POST，回 AES-JSON 三層。
 *  - logistics/selection-callback（選店回呼 ClientReplyURL）：Form POST ResultData，回 HTTP 200。
 *
 * ★ R2 鐵律（貨態 callback 所有路徑，含例外）：
 *   - 一律回 HTTP 200 + AES-JSON 三層 { MerchantID, RqHeader{ Timestamp }, TransCode=1, Data }。
 *   - Data = AesCrypto::encrypt(['RtnCode' => 0|1, 'RtnMsg' => ...])（用 active 帳號憑證）。
 *   - 回錯誤格式（如 1|OK）綠界會每 60 分重送最多 3 次，故絕不複用 EcpgCallback 的純文字回應。
 *   - 任何 \Throwable 一律 catch，仍回 AES-JSON（RtnCode=0），禁拋 HTTP 500。
 *
 * 安全清單：
 *   1. 驗 MerchantID 為本商店（active merchant id），不符則記安全 log（遮蔽 HashKey/IV）不更新。
 *   2. 以 LogisticsID 反查訂單（get_order_by_ref），查無不更新（避免重送風暴）。
 *   3. 防重：以「LogisticsID + LogisticsStatus」組合碼冪等（重送命中回 RtnCode=1）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §步驟 4（回應 AES-JSON）
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 * @see .claude/skills/ECPay-API-Skill/guides/21-webhook-events-reference.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\Http;

use J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs\EcpayLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsStatus;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 綠界全方位物流 v2 callback 接收（貨態 + 選店回呼） */
final class LogisticsCallback extends ApiBase {
	use SingletonTrait;

	/** @var string 全方位物流 v2 RqHeader 固定版本號（計畫 R3） */
	private const REVISION = '1.0.0';

	/** @var string Namespace power-checkout/ecpay */
	protected $namespace = 'power-checkout/ecpay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'logistics/status-callback',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'logistics/selection-callback',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region 貨態 callback（ServerReplyURL；R2 回 AES-JSON 三層）

	/**
	 * 貨態通知（ServerReplyURL，JSON body POST）
	 *
	 * ★ R2：所有路徑（含例外）一律回 HTTP 200 + AES-JSON 三層，禁拋 500。
	 *
	 * 方法名須符合 ApiBase 自動推導規則：{method}_{endpoint 去除 -/ 轉底線}_callback，
	 * 即 logistics/status-callback → post_logistics_status_callback_callback。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response AES-JSON 三層回應
	 */
	public function post_logistics_status_callback_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$rtn_code = $this->handle_status( $request );
		} catch ( \Throwable $e ) {
			// 任何未預期例外仍回 AES-JSON（RtnCode=0），避免綠界 60 分重送風暴
			Plugin::logger(
				'綠界全方位物流貨態 callback 例外',
				'error',
				[ 'error' => $e->getMessage() ]
			);
			$rtn_code = 0;
		}

		return $this->make_aes_json_response( $rtn_code );
	}

	/**
	 * 貨態處理邏輯（雙層檢查 + MerchantID 驗證 + 反查訂單 + 防重 + COD 標記）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return int Data.RtnCode（1=成功處理或冪等命中，0=各種失敗情境）
	 */
	private function handle_status( \WP_REST_Request $request ): int {
		// ⚠️ 認證上限說明（資安審查確認）：綠界「全方位物流 v2」貨態 callback 採 AES-JSON 協議，
		// payload 不含 CheckMacValue（CMV 僅用於 AIO 金流與國內物流 CMV-MD5）。本協議的真實性
		// 由「MerchantID 比對 + AES-128-CBC 解密成功（需正確 HashKey/HashIV）」雙重保證，已是該
		// 協議的認證上限；不存在可補的 CMV 欄位，故不加假的 CMV 驗證。
		// @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md（§40 AES / §395 非 CMV）
		/** @var array<string, mixed> $body */
		$body = $this->decode_json_body( $request );

		// 外層第一層：傳輸層 TransCode（一律 (int) 後 ===1）
		$trans_code = (int) ( $body['TransCode'] ?? 0 );
		if (1 !== $trans_code) {
			Plugin::logger(
				'綠界全方位物流貨態 callback 傳輸層失敗',
				'warning',
				[ 'TransCode' => $trans_code ]
			);
			return 0;
		}

		$settings = EcpayLogisticsSettingsDTO::instance();
		$crypto   = new AesCrypto( $settings->get_active_hash_key(), $settings->get_active_hash_iv() );

		// 解密 Data（失敗 → 不更新，回 RtnCode=0，仍回 AES-JSON）
		try {
			$decrypted = $crypto->decrypt( (string) ( $body['Data'] ?? '' ) );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'綠界全方位物流貨態 callback Data 解密失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
			return 0;
		}

		// 安全清單 1：驗 MerchantID 為本商店（不符 → 安全 log 遮蔽憑證，不更新）
		$incoming_merchant_id = (string) ( $body['MerchantID'] ?? '' );
		$own_merchant_id      = $settings->get_active_merchant_id();
		if ($incoming_merchant_id !== $own_merchant_id) {
			Plugin::logger(
				'綠界全方位物流貨態 callback MerchantID 不符（疑似偽造）',
				'alert',
				[
					'incoming_merchant_id' => $incoming_merchant_id,
					// ⚠️ 安全：絕不記錄 HashKey / HashIV
				]
			);
			return 0;
		}

		$logistics_id     = (string) ( $decrypted['LogisticsID'] ?? '' );
		$logistics_status = (string) ( $decrypted['LogisticsStatus'] ?? '' );

		// 安全清單 2：以 LogisticsID 反查訂單（查無 → 不更新，仍回 AES-JSON 避免重送風暴）
		$order = LogisticsMetaKeys::get_order_by_ref( $logistics_id );
		if (!$order instanceof \WC_Order) {
			Plugin::logger(
				'綠界全方位物流貨態 callback 查無對應訂單',
				'warning',
				[ 'LogisticsID' => $logistics_id ]
			);
			return 0;
		}

		$meta = new LogisticsMetaKeys( $order );

		// 安全清單 3：防重（已處理過該 LogisticsID + LogisticsStatus → 冪等回 RtnCode=1）
		if ($meta->is_processed( $logistics_id, $logistics_status )) {
			Plugin::logger(
				'綠界全方位物流貨態 callback 重複貨態（冪等略過）',
				'info',
				[
					'LogisticsID'     => $logistics_id,
					'LogisticsStatus' => $logistics_status,
				]
			);
			return 1;
		}

		// 寫入貨態 + 記已處理碼
		$meta->update_status( $logistics_status );
		$meta->mark_processed( $logistics_id, $logistics_status );

		// COD + 取件完成（2067 / 3022）→ 標記 collection_paid=yes（計畫 T2：不改 WC 訂單狀態）
		$is_cod = LogisticsPaymentScenario::COD->value === $meta->get_payment_scenario();
		if ($is_cod && LogisticsStatus::is_pickup_completed( $logistics_status )) {
			$meta->update_collection_paid( 'yes' );
		}

		EcpayLogisticsProvider::logger(
			\sprintf( '📦 物流貨態更新：%s（LogisticsID：%s）', $logistics_status, $logistics_id ),
			'info',
			[
				'LogisticsID'     => $logistics_id,
				'LogisticsStatus' => $logistics_status,
			],
			0,
			$order
		);

		return 1;
	}

	// endregion

	// region 選店回呼（ClientReplyURL；回 HTTP 200，不拋 500）

	/**
	 * 選店回呼（ClientReplyURL，Form POST ResultData）
	 *
	 * 流程：取 ResultData → 委派 provider 解密 → 寫門市 meta。
	 * 空 ResultData / 解密失敗 → 記 log，仍回 HTTP 200（瀏覽器導轉，不拋 500）。
	 *
	 * 方法名須符合 ApiBase 自動推導規則：logistics/selection-callback →
	 * post_logistics_selection_callback_callback。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_selection_callback_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			// query string 也可能帶回綁定參數（pc_oid / pc_key / pc_st），合併 body + query
			$params = \array_merge(
				(array) $request->get_query_params(),
				(array) $request->get_body_params()
			);

			$parsed = EcpayLogisticsProvider::instance()->parse_store_selection( $params );

			// 兩條綁定路徑並存：
			// A. order-bound（既有）：ClientReplyURL 編入 pc_oid + pc_key（order_key），
			// timing-safe 驗證後寫入 order meta（已有訂單，如後台補選店）。
			// B. cart-bound（新增）：ClientReplyURL 編入 pc_st（session 權杖），
			// timing-safe 驗證後寫入 WC session（結帳下單前無訂單）。
			// 優先試 order-bound；無 pc_oid 時走 cart-bound。
			$order = $this->resolve_verified_order( $params );

			if ($order instanceof \WC_Order) {
				$this->write_store_to_order( $order, $parsed );
			} else {
				// cart-bound：以 session 權杖驗證後寫入 session（非 order meta）
				$this->write_store_to_session( $params, $parsed );
			}
		} catch ( \Throwable $e ) {
			// 空 ResultData / 解密失敗 / 其他例外 → 記 log，仍回 HTTP 200
			Plugin::logger(
				'綠界全方位物流選店回呼處理失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
		}

		$response = new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
		return $response;
	}

	/**
	 * 將解析後的門市寫入「訂單」meta（order-bound 路徑）
	 *
	 * @param \WC_Order            $order  已驗證訂單
	 * @param array<string, mixed> $parsed 解析後門市
	 * @return void
	 */
	private function write_store_to_order( \WC_Order $order, array $parsed ): void {
		$meta = new LogisticsMetaKeys( $order );
		$meta->update_temp_id( (string) ( $parsed['temp_id'] ?? '' ) );
		$meta->update_store_id( (string) ( $parsed['store_id'] ?? '' ) );
		$meta->update_store_name( (string) ( $parsed['store_name'] ?? '' ) );
		$meta->update_store_addr( (string) ( $parsed['store_addr'] ?? '' ) );
		$sub_type = (string) ( $parsed['sub_type'] ?? '' );
		if ('' !== $sub_type) {
			$meta->update_sub_type( $sub_type );
		}
		$meta->update_provider_id( EcpayLogisticsProvider::ID );
	}

	/**
	 * 將解析後的門市寫入「WC session」（cart-bound 路徑）
	 *
	 * 以 query 帶回的 pc_st（session 權杖）timing-safe 驗證後寫入對應 session。
	 * 缺 pc_st / 權杖偽造 → CartLogisticsSession::store_by_token 回 false，記安全 log 不寫入。
	 *
	 * @param array<string, mixed> $params 回呼參數（含 query 帶回的 pc_st）
	 * @param array<string, mixed> $parsed 解析後門市
	 * @return void
	 */
	private function write_store_to_session( array $params, array $parsed ): void {
		$token = (string) ( $params['pc_st'] ?? '' );
		if ('' === \trim( $token )) {
			Plugin::logger(
				'綠界全方位物流選店回呼缺少綁定（pc_oid/pc_key 與 pc_st 皆無）',
				'warning'
			);
			return;
		}

		$ok = \J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession::store_by_token(
			$token,
			[
				'temp_id'    => (string) ( $parsed['temp_id'] ?? '' ),
				'store_id'   => (string) ( $parsed['store_id'] ?? '' ),
				'store_name' => (string) ( $parsed['store_name'] ?? '' ),
				'store_addr' => (string) ( $parsed['store_addr'] ?? '' ),
				'sub_type'   => (string) ( $parsed['sub_type'] ?? '' ),
			]
		);

		if (!$ok) {
			Plugin::logger(
				'綠界全方位物流選店回呼 cart 權杖驗證失敗（疑似偽造 / 逾時）',
				'alert'
			);
		}
	}

	// endregion

	// region helpers

	/**
	 * 解析 JSON body（容錯：body 為 JSON 字串時優先，否則退回 get_params）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return array<string, mixed>
	 */
	private function decode_json_body( \WP_REST_Request $request ): array {
		$raw = $request->get_body();
		if ('' !== $raw) {
			$decoded = \json_decode( $raw, true );
			if (\is_array( $decoded )) {
				/** @var array<string, mixed> $decoded */
				return $decoded;
			}
		}

		/** @var array<string, mixed> $params */
		$params = $request->get_params();
		return $params;
	}

	/**
	 * 組裝 AES-JSON 三層回應（計畫 R2 核心）
	 *
	 * 結構：{ MerchantID, RqHeader{ Timestamp, Revision }, TransCode=1, TransMsg, Data }
	 * Data = AesCrypto::encrypt(['RtnCode' => $rtn_code, 'RtnMsg' => ...])（active 帳號憑證）。
	 * 外層 TransCode 一律為 1（商家回綠界時傳輸層均成功），業務結果以 Data.RtnCode 表達。
	 *
	 * @param int $rtn_code Data.RtnCode（1=成功，0=失敗 / 不處理）
	 * @return \WP_REST_Response HTTP 200 + AES-JSON 三層
	 */
	private function make_aes_json_response( int $rtn_code ): \WP_REST_Response {
		$settings = EcpayLogisticsSettingsDTO::instance();
		$crypto   = new AesCrypto( $settings->get_active_hash_key(), $settings->get_active_hash_iv() );

		$data_plain = [
			'RtnCode' => $rtn_code,
			'RtnMsg'  => 1 === $rtn_code ? 'OK' : 'NG',
		];

		$envelope = [
			'MerchantID' => $settings->get_active_merchant_id(),
			'RqHeader'   => [
				// 即時 time()（計畫 R1，5 分鐘視窗），禁止快取
				'Timestamp' => \time(),
				'Revision'  => self::REVISION,
			],
			'TransCode'  => 1,
			'TransMsg'   => '',
			'Data'       => $crypto->encrypt( $data_plain ),
		];

		return new \WP_REST_Response( $envelope, 200 );
	}

	/** @return string ServerReplyURL（貨態通知，JSON POST） */
	public static function get_server_reply_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/logistics/status-callback', 'https' );
	}

	/** @return string ClientReplyURL（選店回呼，Form POST） */
	public static function get_client_reply_url(): string {
		return \site_url( 'wp-json/power-checkout/ecpay/logistics/selection-callback', 'https' );
	}

	/**
	 * 將訂單綁定資訊（pc_oid + pc_key）編入 ClientReplyURL query（防 IDOR）
	 *
	 * 綠界選店頁以 Form POST 原樣帶回 ClientReplyURL（含 query），回呼時據此驗 order_key。
	 * order_key 為 WooCommerce 每筆訂單的不可猜測密鑰，作為選店回呼的綁定權杖。
	 *
	 * @param string    $base_url 設定中的 ClientReplyURL
	 * @param \WC_Order $order    訂單
	 * @return string 加上 pc_oid / pc_key query 的 ClientReplyURL
	 */
	public static function build_client_reply_url( string $base_url, \WC_Order $order ): string {
		return \add_query_arg(
			[
				'pc_oid' => $order->get_id(),
				'pc_key' => $order->get_order_key(),
			],
			$base_url
		);
	}

	/**
	 * 將 cart 級選店權杖（pc_st）編入 ClientReplyURL query（cart-bound 綁定）
	 *
	 * 結帳「下單前」無訂單，改以不可猜測的 session 權杖綁定當前 cart；回呼時 timing-safe 驗證。
	 *
	 * @param string $base_url 設定中的 ClientReplyURL
	 * @param string $token    CartLogisticsSession 產生的選店權杖
	 * @return string 加上 pc_st query 的 ClientReplyURL
	 */
	public static function build_cart_reply_url( string $base_url, string $token ): string {
		return \add_query_arg( [ 'pc_st' => $token ], $base_url );
	}

	/**
	 * 反查並驗證選店回呼對應的訂單（IDOR 防護）
	 *
	 * 以 query 帶回的 pc_oid 反查訂單，再以 timing-safe hash_equals 比對 pc_key 與
	 * order_key。缺 pc_key 或不符 → 記安全 log 並回傳 null（拒絕寫入）。
	 *
	 * @param array<string, mixed> $params 回呼參數（含 query 帶回的 pc_oid / pc_key）
	 * @return \WC_Order|null 驗證通過的訂單，否則 null
	 */
	private function resolve_verified_order( array $params ): ?\WC_Order {
		$order_id  = (int) ( $params['pc_oid'] ?? 0 );
		$order_key = (string) ( $params['pc_key'] ?? '' );

		// 無 pc_oid → 非 order-bound 路徑（可能是 cart-bound，帶 pc_st），靜默回 null 由上層改走 session 路徑。
		if ($order_id <= 0) {
			return null;
		}

		// 有 pc_oid 卻缺 pc_key → order-bound 綁定不完整，拒絕
		if ('' === $order_key) {
			Plugin::logger(
				'綠界全方位物流選店回呼缺少訂單綁定（有 pc_oid 但無 pc_key）',
				'warning',
				[ 'pc_oid' => $order_id ]
			);
			return null;
		}

		$order = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			return null;
		}

		// timing-safe 比對 order_key（防 IDOR / 時序側信道）
		if (!\hash_equals( $order->get_order_key(), $order_key )) {
			Plugin::logger(
				'綠界全方位物流選店回呼 order_key 不符（疑似 IDOR）',
				'alert',
				[ 'pc_oid' => $order_id ]
			);
			return null;
		}

		return $order;
	}

	// endregion
}
