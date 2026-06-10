<?php
/**
 * PayNow（立吉富體系 1）物流 callback 接收（選店回呼 + 貨態通知）
 *
 * 兩個 REST 端點（namespace power-checkout/paynow，permission_callback __return_true）：
 *  - logistics/selection-callback（選店回呼 returnUrl）：Form POST 門市資訊，委派 provider 解析寫 meta。
 *  - logistics/status-callback（貨態通知）：Form POST 貨態推送，以 orderno 反查訂單 + 冪等 + 更新 meta。
 *
 * ★ R1 鐵律（貨態 callback 所有路徑，含例外）：
 *   一律回 HTTP 200，避免 PayNow 重送風暴；任何 \Throwable catch 後仍回 200。
 *
 * 安全清單（permission __return_true，認證上限為內部弱驗證；woomp 無簽章證據）：
 *   - 選店回呼：缺 storeid / storename → 回錯「選店回呼缺少門市資訊」，不寫 meta。
 *   - 貨態通知：缺 orderno / 反查不到訂單 → 不寫 meta（仍回 200）。
 *   - 冪等：以「{OrderNo}:{LogisticCode}」組合碼防重（重送命中跳過）。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 2 步驟 9 / §R1
 * @see inc/classes/Domains/Logistics/Ecpay/Http/LogisticsCallback.php（鏡像）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Http;

use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** PayNow 物流 callback 接收（選店回呼 + 貨態通知） */
final class LogisticsCallback extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace power-checkout/paynow */
	protected $namespace = 'power-checkout/paynow';

	/**
	 * APIs
	 *
	 * Callback 於 constructor 綁定至 handle_* 方法（覆寫 ApiBase 自動推導），
	 * 使 REST 路由與測試直呼共用同一組方法。
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [];

	/** Constructor — 綁定 REST callback 至自訂方法名（覆寫 ApiBase 自動推導） */
	public function __construct() {
		$this->apis = [
			[
				'endpoint'            => 'logistics/selection-callback',
				'method'              => 'post',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'handle_selection_callback' ],
			],
			[
				'endpoint'            => 'logistics/status-callback',
				'method'              => 'post',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'handle_status_callback' ],
			],
		];
		parent::__construct();
	}

	/**
	 * Register hooks
	 *
	 * 以 instance() 建立單例（constructor 會掛 rest_api_init → register_apis）。
	 * ⚠️ SingletonTrait 的 instance 跨請求 / 跨測試持久；當 WP 在請求 / 測試結束還原 hooks 後，
	 *   後續再呼叫 register_hooks() 不會重建 instance，導致 rest_api_init 未被重新掛載、路由消失。
	 *   故此處在 instance 已存在時，以 has_action 守衛補掛 register_apis，確保路由必被註冊。
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$instance = self::instance();
		if (false === \has_action( 'rest_api_init', [ $instance, 'register_apis' ] )) {
			\add_action( 'rest_api_init', [ $instance, 'register_apis' ] );
		}
	}

	// region 選店回呼（returnUrl；回 HTTP 200）

	/**
	 * 選店回呼（returnUrl，Form POST 門市資訊）
	 *
	 * 流程：合併 query + body → 委派 provider 解析寫門市 meta → 回 success / error。
	 * 缺門市資訊 / 反查不到訂單 → 回非 success，仍回 HTTP 200（瀏覽器導轉，不拋 500）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_selection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$params = \array_merge(
				(array) $request->get_query_params(),
				(array) $request->get_body_params()
			);

			$parsed = PaynowLogisticsProvider::instance()->parse_store_selection( $params );

			// 反查不到訂單 → 不視為成功（避免偽造回呼誤判）
			if (false === ( $parsed['order_found'] ?? false )) {
				return new \WP_REST_Response(
					[
						'code'    => 'order_not_found',
						'message' => \__( '選店回呼找不到對應訂單', 'power_checkout' ),
					],
					200
				);
			}

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => \__( '選店成功', 'power_checkout' ),
					'data'    => $parsed,
				],
				200
			);
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'PayNow 物流選店回呼處理失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
			return new \WP_REST_Response(
				[
					'code'    => 'selection_failed',
					'message' => $e->getMessage(),
				],
				400
			);
		}
	}

	// endregion

	// region 貨態通知（status-callback；R1 恆回 200）

	/**
	 * 貨態通知（status-callback，Form POST 貨態推送）
	 *
	 * ★ R1：所有路徑（含例外）一律回 HTTP 200，避免 PayNow 重送風暴。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 恆 HTTP 200
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->process_status_push( $request );
		} catch ( \Throwable $e ) {
			// 任何未預期例外仍回 200，避免 PayNow 60 分重送風暴
			Plugin::logger(
				'PayNow 物流貨態 callback 例外',
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	/**
	 * 貨態推送處理（解析 payload → orderno 反查 → 冪等 → 更新 meta → COD 標記）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return void
	 */
	private function process_status_push( \WP_REST_Request $request ): void {
		$params = \array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params()
		);

		$order_no      = (string) ( $params['orderno'] ?? '' );
		$logistic_code = (string) ( $params['PayNowLogisticCode'] ?? '' );
		$description   = (string) ( $params['Detail_Status_Description'] ?? '' );
		$payment_no    = (string) ( $params['paymentno'] ?? '' );

		// 缺 orderno → 不處理（仍回 200）
		if ('' === \trim( $order_no )) {
			return;
		}

		// 以 orderno（PCN{order_id}）反查訂單（R4）
		$order = PaynowLogisticsMetaKeys::get_order_by_order_no( $order_no );
		if (!$order instanceof \WC_Order) {
			Plugin::logger(
				'PayNow 物流貨態 callback 查無對應訂單',
				'warning',
				[ 'orderno' => $order_no ]
			);
			return;
		}

		$meta = new PaynowLogisticsMetaKeys( $order );

		// 冪等：已處理過該（OrderNo + LogisticCode）→ 跳過
		if ($meta->is_processed( $order_no, $logistic_code )) {
			Plugin::logger(
				'PayNow 物流貨態 callback 重複推送（冪等略過）',
				'info',
				[
					'orderno'       => $order_no,
					'logistic_code' => $logistic_code,
				]
			);
			return;
		}

		// 更新貨態 meta
		if ('' !== $logistic_code) {
			$meta->update_logistic_code( $logistic_code );
		}
		$meta->update_delivery_status( '' !== $description ? $description : $logistic_code );
		if ('' !== $payment_no) {
			$meta->update_payment_no( $payment_no );
		}

		// 記已處理碼（冪等防重）
		$meta->mark_processed( $order_no, $logistic_code );

		// COD + 取貨完成貨態 → 標記 collection_paid=yes
		if ($this->is_cod_order( $order ) && $this->is_pickup_completed( $logistic_code, $description )) {
			$meta->update_collection_paid( 'yes' );
		}

		PaynowLogisticsProvider::logger(
			\sprintf( '📦 PayNow 物流貨態更新：%s（%s）', $logistic_code, $description ),
			'info',
			[
				'orderno'       => $order_no,
				'logistic_code' => $logistic_code,
				'description'   => $description,
				'payment_no'    => $payment_no,
			],
			0,
			$order
		);
	}

	// endregion

	// region helpers

	/**
	 * 是否為取貨完成貨態（買家已取件 8000，或描述含取貨 / 取件完成字樣）
	 *
	 * @param string $logistic_code 貨態碼
	 * @param string $description    貨態描述
	 * @return bool
	 */
	private function is_pickup_completed( string $logistic_code, string $description ): bool {
		if ('8000' === $logistic_code) {
			return true;
		}
		if ('PICKUP_DONE' === \strtoupper( $logistic_code )) {
			return true;
		}
		return \str_contains( $description, '取貨完成' )
		|| \str_contains( $description, '取件' );
	}

	/**
	 * 是否為 COD（貨到付款）訂單
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private function is_cod_order( \WC_Order $order ): bool {
		return 'cod' === $order->get_payment_method();
	}

	/** @return string 選店回呼 URL（returnUrl，Form POST） */
	public static function get_selection_callback_url(): string {
		return \site_url( 'wp-json/power-checkout/paynow/logistics/selection-callback', 'https' );
	}

	/** @return string 貨態通知 URL（status-callback，Form POST） */
	public static function get_status_callback_url(): string {
		return \site_url( 'wp-json/power-checkout/paynow/logistics/status-callback', 'https' );
	}

	// endregion
}
