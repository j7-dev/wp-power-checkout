<?php
/**
 * 物流 Api Service（對外 REST 端點，namespace power-checkout/v1）
 *
 * 鏡像 Invoice\Shared\Services\InvoiceApiService：extends ApiBase + SingletonTrait，
 * callback 取 provider（ProviderUtils）→ 委派 ILogisticsProvider 對應方法 → 回 {code,message,data}。
 *
 * 5 個端點：
 *  - POST  logistics/{id}/store-selection  選店導轉（階段 A）→ data.redirect_target
 *  - POST  logistics/{id}/create-shipment  成立物流單（階段 B）→ data.logistics_id
 *  - GET   logistics/{id}                   查詢物流單 → data.{logistics_id,status,store_info}
 *  - POST  logistics/{id}/print             列印託運單 → HTML（rest_pre_echo_response 直接 echo）
 *  - POST  logistics/{id}/cancel            C2C 取消物流單 → data.{logistics_id,cancelled}
 *
 * ⚠️ 錯誤映射：provider 各方法失敗一律 throw，本層 catch \Throwable 轉對應 HTTP code
 *   （400 參數 / 403 狀態 / 404 找不到 / 500 例外）。訊息關鍵字對應計畫資料流：
 *     - 「未啟用」→ 403
 *     - 「找不到訂單」→ 404
 *     - 「尚未選店」/「尚未成立物流單」/「僅支援 C2C」→ 403
 *     - 「必須為公開可訪問的 URL」/「已啟用的綠界物流子類型」/「寄貨編號」/「TransCode」/「RtnCode」→ 400
 *   不外洩內部細節（細節已由 provider / ApiClient 寫 order note / log）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 物流 Api Service */
final class LogisticsApiService extends ApiBase {
	use SingletonTrait;

	/** @var string 物流 provider id（本次唯一 provider；委派目標） */
	private const PROVIDER_ID = 'ecpay_logistics';

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint' => 'logistics/(?P<id>\d+)/store-selection',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/create-shipment',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/print',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/cancel',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)',
			'method'   => 'get',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callbacks（回 {code, message, data}）

	/**
	 * 選店導轉（階段 A）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_store_selection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			function ( ILogisticsProvider $provider, \WC_Order $order ) use ( $request ): array {
				$sub_type = (string) ( $request->get_param( 'sub_type' ) ?? '' );
				$scenario = (string) ( $request->get_param( 'payment_scenario' ) ?? '' );

				$ctx = [ 'sub_type' => $sub_type ];
				if ('' !== $scenario) {
					$ctx['payment_scenario'] = $scenario;
				}

				$result = $provider->get_store_selection( $order, $ctx );
				return [
					'message' => \__( '取得選店頁成功', 'power_checkout' ),
					'data'    => $result,
				];
			}
		);
	}

	/**
	 * 成立物流單（階段 B）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_create_shipment_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->create_shipment( $order );
				return [
					'message' => \__( '物流單成立成功', 'power_checkout' ),
					'data'    => $result,
				];
			}
		);
	}

	/**
	 * 查詢物流單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function get_logistics_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->query_shipment( $order );
				return [
					'message' => \__( '查詢成功', 'power_checkout' ),
					'data'    => $result,
				];
			}
		);
	}

	/**
	 * 列印託運單（回 HTML body）
	 *
	 * 透過 rest_pre_echo_response 直接 echo HTML，避免 WP 序列化為 JSON（鏡像 EcpgCallback 技巧）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_print_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			[ $provider, $order ] = $this->resolve( $request );
			$html                 = $provider->print_document( $order );
			return self::html_response( $html );
		} catch ( \Throwable $e ) {
			return self::error_response( $e->getMessage(), self::map_status( $e->getMessage() ) );
		}
	}

	/**
	 * C2C 取消物流單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_cancel_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->cancel_shipment( $order );
				return [
					'message' => \__( '取消成功', 'power_checkout' ),
					'data'    => $result,
				];
			}
		);
	}

	// endregion

	// region helpers

	/**
	 * 共用執行框架：解析 provider + 訂單 → 執行 handler → 回 {code,message,data}；失敗轉 HTTP code
	 *
	 * Handler 簽章：fn(ILogisticsProvider $provider, \WC_Order $order): array{message: string, data: mixed}
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param callable         $handler 業務 handler，回 {message, data}
	 *
	 * @return \WP_REST_Response
	 */
	private function run( \WP_REST_Request $request, callable $handler ): \WP_REST_Response {
		try {
			[ $provider, $order ] = $this->resolve( $request );
			$result               = $handler( $provider, $order );

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => (string) ( $result['message'] ?? '成功' ),
					'data'    => $result['data'] ?? null,
				],
				200
			);
		} catch ( \Throwable $e ) {
			return self::error_response( $e->getMessage(), self::map_status( $e->getMessage() ) );
		}
	}

	/**
	 * 解析請求：取得已啟用的 provider 與訂單
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return array{0: ILogisticsProvider, 1: \WC_Order}
	 * @throws \Exception Provider 未啟用 / 不是物流服務
	 * @throws \InvalidArgumentException 訂單不存在
	 */
	private function resolve( \WP_REST_Request $request ): array {
		// provider 未啟用 → 403
		if (!ProviderUtils::is_enabled( self::PROVIDER_ID )) {
			throw new \Exception( \__( '綠界全方位物流未啟用', 'power_checkout' ) );
		}

		$provider = ProviderUtils::get_provider( self::PROVIDER_ID );
		if (!$provider instanceof ILogisticsProvider) {
			throw new \Exception( \__( '綠界全方位物流未啟用', 'power_checkout' ) );
		}

		// 訂單不存在 → 404
		$order_id = (int) ( $request['id'] ?? 0 );
		$order    = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			throw new \InvalidArgumentException( \__( '找不到訂單', 'power_checkout' ) );
		}

		return [ $provider, $order ];
	}

	/**
	 * 依錯誤訊息關鍵字映射 HTTP 狀態碼
	 *
	 * @param string $message 錯誤訊息
	 * @return int HTTP 狀態碼
	 */
	private static function map_status( string $message ): int {
		// 404：找不到訂單
		if (\str_contains( $message, '找不到訂單' )) {
			return 404;
		}

		// 403：狀態類前置（未啟用 / 尚未選店 / 尚未成立物流單 / 僅支援 C2C）
		$status_keywords = [ '未啟用', '尚未選店', '尚未成立物流單', '僅支援 C2C' ];
		foreach ( $status_keywords as $keyword ) {
			if (\str_contains( $message, $keyword )) {
				return 403;
			}
		}

		// 其餘（參數錯誤 / 傳輸層 / 業務層 / 缺寄貨編號 / reply URL）→ 400
		return 400;
	}

	/**
	 * 統一錯誤回應（{code, message, data}）
	 *
	 * @param string $message 錯誤訊息
	 * @param int    $status  HTTP 狀態碼
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'error',
				'message' => $message,
				'data'    => null,
			],
			$status
		);
	}

	/**
	 * HTML 回應（列印託運單；透過 rest_pre_echo_response 直接 echo，避免序列化為 JSON）
	 *
	 * @param string $html 託運單 HTML
	 * @return \WP_REST_Response
	 */
	private static function html_response( string $html ): \WP_REST_Response {
		$response = new \WP_REST_Response( $html, 200 );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		\add_filter(
			'rest_pre_echo_response',
			static function ( $result, $server, $request ) use ( $html ) {
				$route = $request->get_route();
				if (\is_string( $route ) && \str_contains( $route, '/logistics/' ) && \str_ends_with( $route, '/print' )) {
					echo $html; // phpcs:ignore
					return null;
				}
				return $result;
			},
			10,
			3
		);
		return $response;
	}

	// endregion
}
