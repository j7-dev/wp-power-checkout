<?php
/**
 * 發票 Api Service
 * 1. 開立發票
 * 2. 做廢發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Services;

use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Domains\Invoice\Shared\Utils\InvoiceUtils;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;

/** Invoice Api Service */
final class InvoiceApiService extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string $namespace */
	protected $namespace = 'power-checkout/v1/invoices';

	/**
	 * @var array<array{
	 * endpoint:string,
	 * method:string,
	 * permission_callback?: (callable(): mixed)|null,
	 * callback?: (callable(): mixed)|null,
	 * schema?: array<string, mixed>|null
	 * }> $apis APIs
	 * */
	protected $apis = [
		[
			'endpoint' => 'issue/(?P<id>\d+)', // order_id
			'method'   => 'post',
		],
		[
			'endpoint' => 'cancel/(?P<id>\d+)', // order_id
			'method'   => 'post',
		],
		[
			'endpoint' => 'allowance/(?P<id>\d+)', // order_id 開立折讓
			'method'   => 'post',
		],
		[
			'endpoint' => 'allowance-cancel/(?P<id>\d+)', // order_id 作廢折讓
			'method'   => 'post',
		],
		[
			'endpoint' => 'query/(?P<id>\d+)', // order_id 發票查詢（唯讀）
			'method'   => 'get',
		],
	];

	/**
	 * 開立發票
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_issue_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = (string) ( $request['id'] ?? '' );
		$args     = $request->get_params();

		[$service, $order] = self::get_service( $order_id, $args );
		( new MetaKeys($order) )->update_issue_params( $args );
		$result = $service->issue( $order  );
		return self::respond( $result );
	}

	/**
	 * 做廢發票
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_cancel_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id    = (string) ( $request['id'] ?? '' );
		$order       = OrderUtils::get_order( $order_id);
		$provider_id = ( new MetaKeys( $order) )->get_provider_id();
		$provider    = ProviderUtils::get_provider( $provider_id);
		if (!$provider instanceof IInvoiceService) {
			throw new \Exception("{$provider_id} 不是 Invoice Service");
		}
		$result = $provider->cancel( $order );
		return self::respond( $result );
	}


	/**
	 * 開立折讓（部分退款開折讓單）
	 *
	 * 目前僅綠界（ecpay）支援折讓；以訂單記錄的 provider 為準。
	 *
	 * @param \WP_REST_Request $request 請求（body: amount, [notify_mail]）
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_allowance_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id    = (string) ( $request['id'] ?? '' );
		$order       = OrderUtils::get_order( $order_id );
		$provider    = self::get_allowance_provider( $order );
		$amount      = (float) ( $request['amount'] ?? 0 );
		$notify_mail = (string) ( $request['notify_mail'] ?? '' );

		$result = $provider->issue_allowance( $order, $amount, $notify_mail );
		return self::respond( $result );
	}

	/**
	 * 作廢折讓
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_allowance_cancel_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = (string) ( $request['id'] ?? '' );
		$order    = OrderUtils::get_order( $order_id );
		$provider = self::get_allowance_provider( $order );

		$result = $provider->invalid_allowance( $order );
		return self::respond( $result );
	}

	/**
	 * 發票查詢（唯讀）
	 *
	 * 依訂單記錄的 provider 路由；provider 須支援 ISupportsQuery（目前 Ecpay / Amego 皆支援）。
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function get_query_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id    = (string) ( $request['id'] ?? '' );
		$order       = OrderUtils::get_order( $order_id );
		$provider_id = ( new MetaKeys( $order ) )->get_provider_id();
		$provider    = ProviderUtils::get_provider( $provider_id );

		if (!$provider instanceof ISupportsQuery) {
			throw new \Exception( "{$provider_id} 不支援發票查詢" );
		}

		$result = $provider->query_invoice( $order );
		return self::respond( $result );
	}

	/**
	 * 取得支援折讓的 provider（依訂單記錄的 provider_id）
	 *
	 * 折讓能力由 ISupportsAllowance 標示（目前僅 Ecpay 支援，Amego 折讓後續實作）。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return ISupportsAllowance 具備折讓能力的 provider
	 * @throws \Exception 找不到 provider 或不支援折讓
	 */
	private static function get_allowance_provider( \WC_Order $order ): ISupportsAllowance {
		$provider_id = ( new MetaKeys( $order ) )->get_provider_id();
		$provider    = ProviderUtils::get_provider( $provider_id );

		if (!$provider instanceof ISupportsAllowance) {
			throw new \Exception( "{$provider_id} 不支援發票折讓" );
		}

		return $provider;
	}

	/**
	 * 統一將 provider 回傳值映射為 REST 回應
	 *
	 * 第五階段（面 B）導入：provider 失敗從「塌縮回 []」演進為回正規化 \WP_Error。
	 * 本方法負責呼叫端映射：
	 *   - 成功（array）→ 維持既有 200 回應、原樣透傳（不變）。
	 *   - 失敗（\WP_Error）→ HTTP = {@see ErrorCode::to_http_status()}（非正規化 code 預設 500）；
	 *     body 對齊本專案 REST envelope（code / message / data）並額外於頂層帶 error_code / raw_code，
	 *     確保前端能讀 `error.response.data.error_code` + `message`。
	 *
	 * 注意：本方法**不**處理「找不到要呼叫的東西」（面 A：訂單 / provider 不存在 / 型別不符）——
	 * 那條既有 `throw \Exception` → WP ApiBase 包成 HTTP 500 的路徑完全不動。
	 *
	 * @param array<string, mixed>|\WP_Error $result provider 回傳值
	 *
	 * @return \WP_REST_Response
	 */
	private static function respond( array|\WP_Error $result ): \WP_REST_Response {
		if ( ! \is_wp_error( $result ) ) {
			return new \WP_REST_Response( $result, 200 );
		}

		$code        = NormalizedError::get_code( $result );
		$error_code  = null === $code ? (string) $result->get_error_code() : $code->value;
		$http_status = null === $code ? 500 : $code->to_http_status();
		$raw_code    = NormalizedError::get_raw_code( $result );
		$message     = $result->get_error_message();

		return new \WP_REST_Response(
			[
				'code'       => $error_code,
				'error_code' => $error_code,
				'raw_code'   => $raw_code,
				'message'    => $message,
				'data'       => null,
			],
			$http_status
		);
	}

	/**
	 * 從請求體解析出服務 & 訂單
	 *
	 * @param string|int           $order_id 訂單號
	 * @param array<string, mixed> $args API 帶進來的參數
	 *
	 * @return array{0: IInvoiceService, 1: \WC_Order} 服務, 訂單
	 * @throws \Exception 解析失敗
	 */
	private static function get_service( string|int $order_id, array $args = [] ): array {
		$order    = OrderUtils::get_order( $order_id);
		$args_dto = InvoiceParams::create($args);
		$provider = ProviderUtils::get_provider( $args_dto->provider);

		if (!$provider) {
			throw new \Exception("找不到電子發票服務 id: {$args_dto->provider}，請檢查是否啟用");
		}
		if (!$provider instanceof IInvoiceService) {
			throw new \Exception("{$args_dto->provider} 不是 Invoice Service");
		}

		return [ $provider, $order ];
	}
}
