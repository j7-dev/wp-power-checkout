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
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response AES-JSON 三層回應
	 */
	public function post_logistics_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
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
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_selection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$params = $request->get_body_params();

			$parsed = EcpayLogisticsProvider::instance()->parse_store_selection( $params );

			// 反查訂單（以 ctx 帶入的 order_id）
			$order_id = (int) ( $params['order_id'] ?? 0 );
			$order    = $order_id > 0 ? \wc_get_order( $order_id ) : false;

			if ($order instanceof \WC_Order) {
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

	// endregion
}
