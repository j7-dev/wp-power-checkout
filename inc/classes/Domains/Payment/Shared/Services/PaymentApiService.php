<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Services;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 付款 REST API 服務 */
class PaymentApiService extends ApiBase {
	use SingletonTrait;

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/** @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> 已註冊的 API 列表 */
	protected $apis = [
		[
			'endpoint' => 'refund',
			'method'   => 'post',
		],
		[
			'endpoint' => 'refund/manual',
			'method'   => 'post',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 使用 Gateway 退款
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function post_refund_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$order_id = $request->get_param('order_id');
		if (!\is_numeric( $order_id)) {
			throw new \Exception('訂單編號必須是數字');
		}
		$order = \wc_get_order($order_id);
		if (!$order instanceof \WC_Order) {
			throw new \Exception("找不到訂單 #{$order_id}");
		}
		$remaining_refund_amount = $order->get_remaining_refund_amount();

		if (!$remaining_refund_amount) {
			throw new \Exception("訂單 #{$order_id} 已經沒有餘額可退");
		}

		$gateway = \wc_get_payment_gateway_by_order( $order );
		if (!( $gateway instanceof AbstractPaymentGateway )) {
			throw new \Exception('Gateway 不是 AbstractPaymentGateway 的實例');
		}

		$int_order_id = (int) $order_id;
		// refund_payment=true：WC 核心先以 process_refund() 做能力檢查，通過才
		// set_refunded_payment(true)，woocommerce_order_refunded hook 內的
		// process_gateway_refund 依 get_refunded_payment() 判定後發送真實退款 API。
		// 缺此旗標時 refund 會被判為「手動退款」而完全不發 API（卻回報成功）。
		// hook 已於 wc_create_refund 內觸發，不得再顯式呼叫 handle_payment_gateway_refund（會重複發送 API）。
		$refund = \wc_create_refund(
			[
				'amount'         => (float) $remaining_refund_amount,
				'reason'         => '',
				'order_id'       => $int_order_id,
				'refund_payment' => true,
			]
			);

		if (\is_wp_error($refund)) {
			// wc_refund_payment() 會把 gateway 的正規化 \WP_Error 壓平成 WP_Error('error', msg)，
			// error_code / raw_code 丟失 → 重呼叫 process_refund（純能力檢查，無副作用）取回正規化錯誤。
			$recheck = $gateway->process_refund( $int_order_id, (float) $remaining_refund_amount );
			return self::error_response( \is_wp_error( $recheck ) ? $recheck : $refund );
		}

		// 面 B（豐富化）：process_gateway_refund 於 hook 內失敗時吞例外（note + 刪 refund + 狀態回滾），
		// 呼叫端無從得知 → 由 gateway 記錄的失敗 meta 取回（read-once），映射為
		// error_code + raw_code + message + 依 code 的 HTTP 狀態碼（對齊 InvoiceApiService::respond()）。
		// 讓前端 RefundDialog 能讀 error.response.data.error_code（UNSUPPORTED → 提示手動退款）。never-throw。
		$gateway_error = $gateway->consume_refund_error( $int_order_id );
		if ($gateway_error instanceof \WP_Error) {
			return self::error_response( $gateway_error );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "訂單 #{$order_id} 透過 {$gateway->method_title} 退款成功",
				'data'    => null,
			],
			200
		);
	}

	/**
	 * 將退款 \WP_Error 映射為豐富化的 REST 錯誤回應
	 *
	 * 對齊 Invoice 領域 InvoiceApiService::respond() 的 envelope：
	 *   - HTTP status = {@see \J7\PowerCheckout\Shared\Errors\ErrorCode::to_http_status()}；
	 *     非正規化 code（裸 \WP_Error）預設 500。
	 *   - body：頂層帶 code / error_code（正規化 code）/ raw_code（provider 原始碼）/ message / data。
	 *
	 * 僅用於「呼叫了退款但失敗」（面 B）；「找不到訂單 / gateway」（面 A）維持既有 throw → HTTP 500。
	 *
	 * @param \WP_Error $error process_refund 回傳的 \WP_Error
	 *
	 * @return \WP_REST_Response
	 */
	private static function error_response( \WP_Error $error ): \WP_REST_Response {
		$code        = NormalizedError::get_code( $error );
		$error_code  = null === $code ? (string) $error->get_error_code() : $code->value;
		$http_status = null === $code ? 500 : $code->to_http_status();
		$raw_code    = NormalizedError::get_raw_code( $error );
		$message     = $error->get_error_message();

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
	 * 手動退款
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function post_refund_manual_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = $request->get_param('order_id');
		if (!\is_numeric( $order_id)) {
			throw new \Exception('order_id must be numeric');
		}
		$order = \wc_get_order($order_id);
		if (!$order instanceof \WC_Order) {
			throw new \Exception("#{$order_id} order not found");
		}

		$order->update_status( OrderStatus::REFUNDED->value);
		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "訂單 #{$order_id} 手動退款成功",
				'data'    => null,
			],
			200
		);
	}
}
