<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund\RefundDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Refund as WebhooksRefundDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\ApiClient;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Abstracts\PaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/**
 * RedirectGateway 跳轉支付
 * */
final class RedirectGateway extends PaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'shopline_payment_redirect';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = 'Shopline Payment 線上付款';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '提供信用卡一次付清、分期付款、虛擬帳號轉帳，以及 LINE Pay、街口支付、APPLE PAY 等付款方式';

	/**
	 * Shopline 跳轉式支付核心支付邏輯
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 * @throws \Exception 如果訂單不存在
	 * @see \WC_Payment_Gateway::process_payment
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$response_dto = ( new ApiClient( $this, $order ) )->create_session();
		// 取得要跳轉的 url

		if (ResponseStatus::tryFrom( $response_dto->status) === ResponseStatus::EXPIRED) {
			// 訂單過期
			$msg     = '已超過 Shopline Payment 付款期限，請重新下單';
			$sys_msg = "session_id: {$response_dto->sessionId}";
			$order->add_order_note( "{$msg}<br>{$sys_msg}");
			$order->update_status( OrderStatus::CANCELLED->value);
			$order_url = $order->get_view_order_url();
			\wc_add_notice( $msg, 'error' );
			\wp_safe_redirect( $order_url );
			exit;
		}

		return $response_dto->sessionUrl;
	}

	/**
	 * [後台] 自訂欄位驗證邏輯
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 * 驗證 min_amount（≥ 5）與 max_amount（≤ 50000）範圍，超出則拒絕儲存
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {

		// 取得 $_POST 的指定欄位 name
		$expire_date_name = $this->get_field_key( 'expire_date' );
		$min_amount_name  = $this->get_field_key( 'min_amount' );
		$max_amount_name  = $this->get_field_key( 'max_amount' );

		// 解構，不存在就會是 null
		@[
			$expire_date_name => $expire_date,
			$min_amount_name  => $min_amount,
			$max_amount_name  => $max_amount,
		] = $this->get_post_data();

		$min_amount = (float) $min_amount;
		$max_amount = (float) $max_amount;

		if ( $min_amount < 5 ) {
			$this->errors[] = sprintf(
				\__( 'Save failed. %s minimum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $max_amount > 50000 ) {
			$this->errors[] = sprintf(
				\__( 'Save failed. %s maximum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}


	/**
	 * 過濾預設的表單欄位
	 *
	 * @param array<string, mixed> $fields 表單欄位
	 *
	 * @return array<string, mixed> 過濾後的表單欄位
	 * */
	// public function filter_fields( array $fields ): array {
	// return [];
	// }


	/**
	 * 第三方金流 callback 回來之後，頁面 render 前
	 * 在 /checkout/order-received/{$order_id}/?key=wc_order_{$order_key}
	 * 前執行
	 *
	 * 例如綠界需透過前端網頁導轉(Submit)到綠界付款API網址
	 *
	 * @param \WC_Order $order 訂單
	 */
	protected function before_order_received( \WC_Order $order ): void {
		try {
            $trade_order_id = $_GET['tradeOrderId'] ?? '';//phpcs:ignore
			if (!$trade_order_id) {
				return;
			}
			$order_params = new MetaKeys( $order);
			// 檢查 payment_identity (tradeOrderId) 是否重複，重複代表發過，就不用再發 API
			$payment_identity = $order_params->get_payment_identity();
			if ($payment_identity === $trade_order_id) {
				return;
			}

			// 儲存 payment_identity
			$order_params->update_payment_identity( (string) $trade_order_id);
		} catch (\Throwable $e) {
			$this->logger( "❌ {$this->title} 發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_detail_array = ( new MetaKeys( $order) )->get_payment_detail();
		if ( $payment_detail_array ) {
			try {
				echo PaymentDTO::create( $payment_detail_array)->to_human_html();
			} catch (\Throwable $e) {
				echo WP::array_to_html( $payment_detail_array );
			}
		}

		/** @var \WC_Order_Refund[] $refunds */
		$refunds = $order->get_refunds();

		foreach ($refunds as $refund) {
			echo '<div style="width: 100%;height: 24px;"></div>';
			$output_array = [
				'金額' => \wc_price( $refund->get_amount()),
			];

			$user_id = $refund->get_refunded_by();
			$user    = \get_user_by( 'id', $user_id );
			if ($user instanceof \WP_User) {
				$display_name        = $user->display_name;
				$output_array['操作者'] = "{$display_name} #{$user_id}";
			}

			$output_array['退款時間'] = $refund->get_date_created()?->date_i18n('Y-m-d H:i');

			echo WP::array_to_html(
				$output_array,
				[
					'title' => "退款 #{$refund->get_id()}：{$refund->get_reason()}",
				]
				);

		}

		// $refund_detail_array = ( new MetaKeys($order) )->get_refund_detail();
		// if ($refund_detail_array) {
		// echo '<div style="width: 100%;height: 24px;"></div>';
		// try {
		// echo WebhooksRefundDTO::create( $refund_detail_array)->to_human_html();
		// } catch (\Throwable $e) {
		// echo WP::array_to_html( $refund_detail_array );
		// }
		// }
	}

	// region 取得設定

	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 *
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$default_array = ( new RedirectSettingsDTO() )->to_array();
			$unset_keys    = [ 'platformId', 'merchantId', 'apiKey', 'clientKey', 'signKey' ];
			foreach ($unset_keys as $key) {
				unset($default_array[ $key ]);
			}

			$option = ProviderUtils::get_option( self::ID);
			return \wp_parse_args( \is_array($option) ? $option : [], $default_array);
		}
		return RedirectSettingsDTO::instance()->to_array();
	}


	// endregion

	// region 退款

	/**
	 * 能否處理退款
	 * 這不是訂單狀態轉換時觸發，而是 admin 點按部分退款時觸發
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 *
	 * @return bool|\WP_Error True or false based on success, or a WP_Error object.
	 * @see          WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order || !$amount) {
			return false;
		}

		try {
			$payment_dto = PaymentDTO::from_order($order);
			return $payment_dto->get_payment_method()->can_refund( $order, (float) $amount);
		} catch (\Throwable $e) {
			/** @var array<string, mixed> $trace */
			$trace = $e->getTrace();
			$this->logger("❌ #{$order_id} 退款失敗： {$e->getMessage()}", 'error', $trace, 5, false );
			// never-throw：未預期 \Throwable 一律正規化為 UNKNOWN，raw_message 保留原始例外訊息供 debug，
			// 不向 WC 退款流程拋。取代舊散裝 WP_Error('refund_failed')。
			return NormalizedError::from(
				ErrorCode::UNKNOWN,
				\__( '退款失敗，詳情請查閱 log 紀錄', 'power_checkout' ),
				[
					'provider'    => $this->id,
					'raw_message' => $e->getMessage(),
				]
			);
		}
	}


	/**
	 * 退款邏輯，API 發送
	 * 退款創建時觸發
	 *
	 * @param int $order_id 訂單 id
	 * @param int $refund_id 退款 id
	 *
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
		if (!$this->is_this_gateway( $order_id)) {
			return;
		}

		/** @var \WC_Order_Refund $refund */
		$refund = \wc_get_order( $refund_id );
		if (!$refund->get_refunded_payment()) { // 如果是手動退款，就不做
			return;
		}

		global $wpdb;
		/** @var \WC_Order $order */
		$order = \wc_get_order( $order_id );

		try {
			$wpdb->query('START TRANSACTION'); // phpcs:ignore
			$reason       = $refund->get_reason();
			$response_dto = ( new ApiClient( $this, $order ) )->create_refund( (float) $refund->get_amount(), $reason );
			self::handle_refund_response( $response_dto, $order, $reason);
			$wpdb->query('COMMIT'); // phpcs:ignore
		} catch (\Throwable $e) {
			$wpdb->query('ROLLBACK'); // phpcs:ignore
			$order->add_order_note( "❌ 退款失敗：{$e->getMessage()}" );
			$refund->delete(true);
		}
	}



	/**
	 * 處理退款 API 回傳結果
	 *
	 * @param RefundDTO|WebhooksRefundDTO $response_dto 退款回應資料
	 * @param \WC_Order                   $order        WooCommerce 訂單物件
	 * @param string                      $reason 原因
	 * @return void
	 * @throws \Exception 如果退款失敗
	 */
	public static function handle_refund_response( RefundDTO|WebhooksRefundDTO $response_dto, \WC_Order $order, string $reason ): void {
		$html         = $response_dto->to_human_html($reason);
		$from_webhook = $response_dto instanceof WebhooksRefundDTO;
		$order->add_order_note( $html );

		if ($from_webhook) {
			( new MetaKeys( $order) )->update_refund_detail( $response_dto->to_array() );
		}

		if ($response_dto->refundMsg?->code) {
			throw new \Exception( $response_dto->to_human_title($reason));
		}

		if (!$from_webhook) {
			$order->update_meta_data( 'tmp_refund_reason', $reason);
			$order->save_meta_data();
		}
	}

	// endregion

	/** 初始化 */
	public static function init(): void {
		WebHook::instance();
		// 添加付款方式

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );
	}


	/** 註冊區塊結帳支援 */
	public static function register_checkout_blocks(): void {
		\add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ): void {
				if (!\class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
					return;
				}

				if (!\class_exists(GatewayUtils::class)) {
					require_once Plugin::$dir . '/inc/classes/Domains/Payment/Shared/Utils/GatewayUtils.php';
				}

				$gateway = GatewayUtils::get_gateway( self::ID);

				if (!$gateway) {
					return;
				}

				if ($gateway instanceof \J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway) {
					$payment_method_registry->register(new BlocksIntegration($gateway));
				}
			}
		);
	}
}
