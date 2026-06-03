<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Body;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;

/**
 * WebHooks 用來接收 Shopline 的 WebHooks 通知
 * session.succeeded 將訂單轉為處理中
 *
 * @see https://docs.shoplinepayments.com/api/event/model/session/
 */
final class WebHook extends ApiBase
{
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace power-checkout/{payment_gateway} */
	protected $namespace = 'power-checkout/slp';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> $apis API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'webhook',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];


	/**
	 * 結帳交易 WebHooks 通知
	 * 回 http.statusCode 200 通知 SLP 處理成功，不再通知
	 * 非200：通知 SLP 失敗，等待下一次通知
	 * 收到通知就始終回 200 ，不用讓 SLP 重試
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_webhook_callback(\WP_REST_Request $request): \WP_REST_Response
	{
		$is_valid    = $this->is_valid($request);
		$body_params = $request->get_params();

		try {
			$webhook_dto = Body::create($body_params);

			$webhook_data_dto = $webhook_dto->data;

			// 處理退款
			if ($webhook_data_dto instanceof Webhooks\Refund) {
				$this->handle_refund($webhook_data_dto);
			}

			if ($webhook_data_dto instanceof Webhooks\Payment && $webhook_data_dto->is_successed_or_failed()) {
				$order = MetaKeys::get_order_by_identity_payment_key($webhook_data_dto->tradeOrderId);

				if (!$order) {
					throw new \Exception("找不到訂單，tradeOrderId: {$webhook_data_dto->tradeOrderId}");
				}

				$status_manager = new StatusManager($webhook_data_dto, $order);
				$status_manager->update_order_status();
			}

			// 收到通知就始終回 200 ，不用讓 SLP 重試
			return new \WP_REST_Response(null, 200);
		} catch (\Throwable $e) {
			Plugin::logger(
				'WebHook 處理失敗',
				'error',
				[
					'error'    => $e->getMessage(),
					'is_valid' => 'true',
					'params'   => $body_params,
				]
			);
			// 收到通知就始終回 200 ，不用讓 SLP 重試
			return new \WP_REST_Response(
				[
					'code'    => 'mapping_order_failed',
					'message' => $e->getMessage(),
					'data'    => null,
				],
				500
			);
		}
	}

	// region 驗證有效性


	/**
	 * 驗證簽章
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return true 是否驗證成功
	 * @throws \Exception 如果驗證失敗
	 */
	private function is_valid(\WP_REST_Request $request): bool
	{
		if ('local' === Plugin::$env) {
			// 本地環境不驗證簽章
			return true;
		}

		// 容許的時間誤差
		$diff_tolerance = 5 * 60 * 1000; // 300 seconds = 5 mins
		$timestamp      = (int) $request->get_header('timestamp');
		$current_time   = \time() * 1000;
		$diff_time      = \abs($current_time - $timestamp);
		if ($diff_time > $diff_tolerance) {
			throw new \Exception(
				"Invalid timestamp, current: {$current_time}, received: {$timestamp}, diff: {$diff_time}"
			);
		}

		return $this->verify_hmac_sha256_signature($request);
	}

	/**
	 * 驗證簽章
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return true 是否驗證成功
	 * @throws \Exception 如果簽章驗證失敗
	 */
	private function verify_hmac_sha256_signature(\WP_REST_Request $request): bool
	{
		$timestamp            = (string) $request->get_header('timestamp');
		$payload              = "{$timestamp}.{$request->get_body()}";
		$calculated_signature = $this->generate_hmac_sha256_signature($payload);
		$sign                 = (string) $request->get_header('sign');
		$is_verified          = \hash_equals($sign, $calculated_signature);
		if (!$is_verified) {
			throw new \Exception("Invalid sign, calculated: {$calculated_signature}, actual: {$sign}");
		}
		return true;
	}

	/**
	 * 使用 hash_hmac 函數生成 HMAC-SHA256 簽章
	 *
	 * @param string $payload 要簽名的字串
	 *
	 * @return string 簽章
	 */
	private function generate_hmac_sha256_signature(string $payload): string
	{
		// 確保資料是 UTF-8 編碼
		$converted = mb_convert_encoding($payload, 'UTF-8', 'auto');
		$payload   = \is_string($converted) ? $converted : $payload;
		$sign_key  = (RedirectSettingsDTO::instance())->signKey;
		return hash_hmac('sha256', $payload, $sign_key);
	}

	// endregion


	/** @return string 取得 webhook url */
	public static function get_webhook_url(): string
	{
		return \get_rest_url(null, 'power-checkout/slp/webhook');
	}

	/** 處理退款資訊 */
	private function handle_refund(Webhooks\Refund $refund_dto): void
	{
		$order = MetaKeys::get_order_by_identity_payment_key($refund_dto->tradeOrderId);
		if (!$order) {
			throw new \Exception("找不到訂單，tradeOrderId: {$refund_dto->tradeOrderId}");
		}

		// 如果 webhook 通知退款失敗
		if ($refund_dto->status === ResponseStatus::FAILED->value) {
			$refunds       = $order->get_refunds();
			$latest_refund = \reset($refunds);
			if ($latest_refund instanceof \WC_Order_Refund) {
				$latest_refund->delete(true);
			}

			return;
		}

		$reason = (string) $order->get_meta('tmp_refund_reason');
		$order->delete_meta_data('tmp_refund_reason');

		RedirectGateway::handle_refund_response($refund_dto, $order, $reason);
	}
}
