<?php
/**
 * 購物車（cart / session）級物流選店 REST 端點（namespace power-checkout/v1）
 *
 * 與 order-bound 的 LogisticsApiService 並存：那組端點皆帶 {id}（訂單 ID），用於「已有訂單」
 * 的後台補選店 / 建單；本服務提供「結帳下單前」（無訂單）的 cart 級選店。
 *
 * 端點：
 *   - POST logistics/store-selection（不帶 order_id）→ data.redirect_target（RWD 選店頁 HTML）
 *
 * 安全：
 *   - permission_callback 沿用 ApiBase nonce 機制（X-WP-Nonce），與其餘 power-checkout/v1 一致。
 *   - 選店權杖綁定由 provider::get_cart_store_selection() → CartLogisticsSession 處理。
 *
 * 錯誤映射比照 LogisticsApiService（403 未啟用 / 400 參數）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 購物車（cart）級物流選店 Api Service */
final class CartLogisticsApiService extends ApiBase {
	use SingletonTrait;

	/**
	 * 已知物流 provider id 解析順序（cart 級選店目前僅綠界支援 RWD 選店頁）
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_IDS = [
		EcpayLogisticsProvider::ID,
	];

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'logistics/store-selection',
			'method'              => 'post',
			// ⚠️ 顧客導向端點：結帳顧客多非管理員，故不可用 ApiBase 預設 manage_options 權限。
			// 以 wp_rest nonce 驗證（X-WP-Nonce）取代角色檢查；選店結果僅綁定呼叫者自身 session 權杖。
			'permission_callback' => [ self::class, 'verify_rest_nonce' ],
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 驗證 wp_rest nonce（X-WP-Nonce header；顧客導向端點權限）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return bool
	 */
	public static function verify_rest_nonce( \WP_REST_Request $request ): bool {
		$nonce = (string) ( $request->get_header( 'X-WP-Nonce' ) ?? '' );
		return false !== \wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * 購物車（cart）級選店導轉（下單前，無訂單）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_store_selection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$provider = $this->resolve_provider( $request );

			$sub_type = (string) ( $request->get_param( 'sub_type' ) ?? '' );
			$scenario = (string) ( $request->get_param( 'payment_scenario' ) ?? '' );

			$ctx = [ 'sub_type' => $sub_type ];
			if ('' !== $scenario) {
				$ctx['payment_scenario'] = $scenario;
			}

			$result = $provider->get_cart_store_selection( $ctx );

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => \__( '取得選店頁成功', 'power_checkout' ),
					'data'    => $result,
				],
				200
			);
		} catch ( \Throwable $e ) {
			return self::error_response( $e->getMessage(), self::map_status( $e->getMessage() ) );
		}
	}

	/**
	 * 解析委派的物流 provider（cart 級選店）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return EcpayLogisticsProvider
	 * @throws \Exception Provider 未啟用 / 不支援 cart 級選店
	 */
	private function resolve_provider( \WP_REST_Request $request ): EcpayLogisticsProvider {
		$requested = (string) ( $request->get_param( 'provider' ) ?? '' );

		// 指定 provider：須在已知清單（目前僅 ecpay_logistics 支援 cart 級 RWD 選店）
		if ('' !== $requested && !\in_array( $requested, self::PROVIDER_IDS, true )) {
			throw new \Exception( \__( '指定的物流服務未啟用', 'power_checkout' ) );
		}

		foreach ( self::PROVIDER_IDS as $id ) {
			if (!ProviderUtils::is_enabled( $id )) {
				continue;
			}
			$provider = ProviderUtils::get_provider( $id );
			if ($provider instanceof EcpayLogisticsProvider) {
				return $provider;
			}
		}

		throw new \Exception( \__( '物流服務未啟用', 'power_checkout' ) );
	}

	/**
	 * 依錯誤訊息關鍵字映射 HTTP 狀態碼（比照 LogisticsApiService）
	 *
	 * @param string $message 錯誤訊息
	 * @return int HTTP 狀態碼
	 */
	private static function map_status( string $message ): int {
		if (\str_contains( $message, '未啟用' )) {
			return 403;
		}
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
}
