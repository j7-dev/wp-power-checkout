<?php
/**
 * PAYUNi 統一金流物流 callback 接收（門市 callback + 貨態 Notify）
 *
 * 比照 Logistics\Ecpay\Http\LogisticsCallback 採 ApiBase + SingletonTrait，但 ⚠️ 回應格式不同：
 *   - 綠界貨態 callback 須回 AES-JSON 三層；**PAYUNi 一律回純文字 `"OK"` + HTTP 200**
 *     （payuni-logistics-v3 notify-and-status.md §商店端處理慣例 / quick-checks §Check 6）。
 *   - 回非 200 或 4xx/5xx → PAYUNi 重送風暴，故所有路徑（含驗簽失敗 / 例外）一律回 200 "OK"。
 *
 * 兩個 REST 端點（namespace power-checkout/payuni，permission_callback __return_true）：
 *  - logistics/map-callback（門市 ship_map 回呼，Form POST）：解密 MapJson 寫門市 meta，回 HTML / 200。
 *  - logistics/status-notify（貨態 Notify，Form POST 4 欄位）：驗簽 + 解密 + 反查 + 防重，回 "OK"。
 *
 * ★ 鐵律（貨態 Notify 所有路徑，含例外）：
 *   - 一律回 HTTP 200 + 純文字 "OK"。
 *   - HashInfo 驗簽失敗也回 200（並寫安全 log），不回 4xx。
 *   - 任何 \Throwable 一律 catch，仍回 200 "OK"。
 *
 * 安全清單：
 *   1. HashInfo timing-safe 驗簽（PayuniCrypto::verify_hash）。
 *   2. 驗 MerID 為本商店，不符記安全 log（遮蔽憑證）不更新。
 *   3. 以 ShipTradeNo 反查訂單（get_order_by_ref），查無不更新。
 *   4. 防重：以「ShipTradeNo + ShipStatus」組合碼冪等（沿用 LogisticsMetaKeys::is_processed）。
 *
 * @see .claude/skills/payuni-logistics-v3/references/notify-and-status.md
 * @see .claude/skills/payuni-logistics-v3/references/cvs-apis.md#ship_map-1.1
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Http;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniShipStatus;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** PAYUNi 統一金流物流 callback 接收（門市 + 貨態 Notify） */
final class PayuniLogisticsCallback extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace power-checkout/payuni */
	protected $namespace = 'power-checkout/payuni';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'logistics/status-notify',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'logistics/map-callback',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region 貨態 Notify（status-notify；一律回 200 "OK"）

	/**
	 * 貨態 Notify（Form POST 4 欄位）
	 *
	 * ★ 所有路徑（含例外 / 驗簽失敗）一律回 HTTP 200 純文字 "OK"，禁回 4xx/5xx。
	 *
	 * 方法名須符合 ApiBase 自動推導規則：logistics/status-notify →
	 * post_logistics_status_notify_callback。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 純文字 "OK"
	 */
	public function post_logistics_status_notify_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->handle_status( $request );
		} catch ( \Throwable $e ) {
			// 任何例外仍回 200 "OK"，避免 PAYUNi 重送風暴
			Plugin::logger(
				'PAYUNi 物流貨態 Notify 例外',
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}

		return self::ok_response();
	}

	/**
	 * 貨態處理邏輯（驗簽 + MerID 驗證 + 反查訂單 + 防重 + COD 標記）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return void
	 */
	private function handle_status( \WP_REST_Request $request ): void {
		/** @var array<string, mixed> $body */
		$body = $request->get_body_params();

		$encrypt_info  = (string) ( $body['EncryptInfo'] ?? '' );
		$received_hash = (string) ( $body['HashInfo'] ?? '' );

		$settings = PayuniLogisticsSettingsDTO::instance();
		$crypto   = new PayuniCrypto( $settings->hash_key, $settings->hash_iv );

		// 安全清單 1：HashInfo timing-safe 驗簽（失敗 → 記 log，仍回 200 "OK"）
		if (!$crypto->verify_hash( $encrypt_info, $received_hash )) {
			Plugin::logger(
				'PAYUNi 物流貨態 Notify HashInfo 驗簽失敗',
				'warning',
				[ 'MerID' => (string) ( $body['MerID'] ?? '' ) ]
			);
			return;
		}

		// 解密內層（失敗會 throw，由外層 catch）
		$decrypted = $crypto->decrypt( $encrypt_info );

		// 外層 Status 須 SUCCESS、ApiType 須 ShipStatus（區分列印 / 取貨完成 Notify）
		if ('SUCCESS' !== (string) ( $decrypted['Status'] ?? '' )) {
			return;
		}
		if ('ShipStatus' !== (string) ( $decrypted['ApiType'] ?? '' )) {
			return;
		}

		// 安全清單 2：驗 MerID 為本商店（不符 → 安全 log 遮蔽憑證，不更新）
		$incoming_merchant_id = (string) ( $decrypted['MerID'] ?? '' );
		if ($incoming_merchant_id !== $settings->mer_id) {
			Plugin::logger(
				'PAYUNi 物流貨態 Notify MerID 不符（疑似偽造）',
				'alert',
				[ 'incoming_mer_id' => $incoming_merchant_id ]
			);
			return;
		}

		// 主鍵 ShipTradeNo（C2B 退貨便改帶 RefundODNO；以 get_order_by_ref 兼容反查）
		$ship_trade_no = (string) ( $decrypted['ShipTradeNo'] ?? $decrypted['RefundODNO'] ?? '' );
		$ship_status   = (string) ( $decrypted['ShipStatus'] ?? '' );

		// 安全清單 3：以 ShipTradeNo 反查訂單（查無 → 不更新，仍回 200 "OK"）
		$order = LogisticsMetaKeys::get_order_by_ref( $ship_trade_no );
		if (!$order instanceof \WC_Order) {
			Plugin::logger(
				'PAYUNi 物流貨態 Notify 查無對應訂單',
				'warning',
				[ 'ShipTradeNo' => $ship_trade_no ]
			);
			return;
		}

		$meta = new LogisticsMetaKeys( $order );

		// 安全清單 4：防重（已處理過該 ShipTradeNo + ShipStatus → 冪等略過）
		if ($meta->is_processed( $ship_trade_no, $ship_status )) {
			Plugin::logger(
				'PAYUNi 物流貨態 Notify 重複貨態（冪等略過）',
				'info',
				[
					'ShipTradeNo' => $ship_trade_no,
					'ShipStatus'  => $ship_status,
				]
			);
			return;
		}

		// 寫入貨態 + 記已處理碼
		$meta->update_status( $ship_status );
		$meta->mark_processed( $ship_trade_no, $ship_status );

		// COD + 取貨完成（11）→ 標記 collection_paid=yes（不改 WC 訂單狀態）
		$is_cod = LogisticsPaymentScenario::COD->value === $meta->get_payment_scenario();
		if ($is_cod && PayuniShipStatus::is_pickup_completed( $ship_status )) {
			$meta->update_collection_paid( 'yes' );
		}

		PayuniLogisticsProvider::logger(
			\sprintf( '📦 PAYUNi 物流貨態更新：%s（ShipTradeNo：%s）', $ship_status, $ship_trade_no ),
			'info',
			[
				'ShipTradeNo' => $ship_trade_no,
				'ShipStatus'  => $ship_status,
			],
			0,
			$order
		);
	}

	// endregion

	// region 門市 ship_map 回呼（map-callback；回 HTML / 200）

	/**
	 * 門市 ship_map 回呼（Form POST，內含 MapJson）
	 *
	 * 流程：取 EncryptInfo → 委派 provider 解析 MapJson → 寫門市 meta。
	 * 驗簽失敗 / 解析失敗 → 記 log，仍回 HTTP 200（瀏覽器導轉，不拋 500）。
	 *
	 * 方法名須符合 ApiBase 自動推導規則：logistics/map-callback →
	 * post_logistics_map_callback_callback。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_map_callback_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			/** @var array<string, mixed> $params */
			$params = $request->get_body_params();

			$parsed = PayuniLogisticsProvider::instance()->parse_store_selection( $params );

			// 反查訂單並驗證 order_key 綁定（防 IDOR）。
			$order = $this->resolve_verified_order( $params );

			if ($order instanceof \WC_Order) {
				$meta = new LogisticsMetaKeys( $order );
				$meta->update_store_id( (string) ( $parsed['store_id'] ?? '' ) );
				$meta->update_store_name( (string) ( $parsed['store_name'] ?? '' ) );
				$meta->update_store_addr( (string) ( $parsed['store_addr'] ?? '' ) );
				$meta->update_provider_id( PayuniLogisticsProvider::ID );
			}
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'PAYUNi 物流門市回呼處理失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
		}

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	// endregion

	// region helpers

	/**
	 * 純文字 "OK" 回應（PAYUNi 貨態 Notify 規範：HTTP 200 + body "OK"）
	 *
	 * PAYUNi 不檢查 body 內容，只看 HTTP 200；回純文字避免被序列化為 JSON。
	 *
	 * @return \WP_REST_Response
	 */
	private static function ok_response(): \WP_REST_Response {
		$response = new \WP_REST_Response( 'OK', 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		\add_filter(
			'rest_pre_echo_response',
			static function ( $result, $server, $request ) {
				$route = $request->get_route();
				if (\is_string( $route ) && \str_contains( $route, '/payuni/logistics/status-notify' )) {
					echo 'OK'; // phpcs:ignore
					return null;
				}
				return $result;
			},
			10,
			3
		);
		return $response;
	}

	/** @return string 貨態 Notify URL（PAYUNi 後台設定用） */
	public static function get_status_notify_url(): string {
		return \site_url( 'wp-json/power-checkout/payuni/logistics/status-notify', 'https' );
	}

	/** @return string 門市地圖回傳 URL（MapReturnURL） */
	public static function get_map_return_url(): string {
		return \site_url( 'wp-json/power-checkout/payuni/logistics/map-callback', 'https' );
	}

	/**
	 * 將訂單綁定資訊（pc_oid + pc_key）編入 MapReturnURL query（防 IDOR）
	 *
	 * @param string    $base_url 設定中的 MapReturnURL
	 * @param \WC_Order $order    訂單
	 * @return string 加上 pc_oid / pc_key query 的 MapReturnURL
	 */
	public static function build_map_return_url( string $base_url, \WC_Order $order ): string {
		return \add_query_arg(
			[
				'pc_oid' => $order->get_id(),
				'pc_key' => $order->get_order_key(),
			],
			$base_url
		);
	}

	/**
	 * 反查並驗證門市回呼對應的訂單（IDOR 防護，timing-safe）
	 *
	 * @param array<string, mixed> $params 回呼參數（含 query 帶回的 pc_oid / pc_key）
	 * @return \WC_Order|null 驗證通過的訂單，否則 null
	 */
	private function resolve_verified_order( array $params ): ?\WC_Order {
		$order_id  = (int) ( $params['pc_oid'] ?? 0 );
		$order_key = (string) ( $params['pc_key'] ?? '' );

		if ($order_id <= 0 || '' === $order_key) {
			Plugin::logger(
				'PAYUNi 物流門市回呼缺少訂單綁定（pc_oid / pc_key）',
				'warning',
				[ 'pc_oid' => $order_id ]
			);
			return null;
		}

		$order = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			return null;
		}

		if (!\hash_equals( $order->get_order_key(), $order_key )) {
			Plugin::logger(
				'PAYUNi 物流門市回呼 order_key 不符（疑似 IDOR）',
				'alert',
				[ 'pc_oid' => $order_id ]
			);
			return null;
		}

		return $order;
	}

	// endregion
}
