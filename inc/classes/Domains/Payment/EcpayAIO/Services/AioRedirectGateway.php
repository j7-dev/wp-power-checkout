<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\AioCallback;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayPaymentType;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/**
 * 綠界 ECPay 全方位金流（AIO，導轉式）付款閘道
 *
 * 與 SLP RedirectGateway 不同處：SLP 在 process_payment 階段呼叫 API 取得 sessionUrl 再跳轉；
 * AIO 不在 process_payment 發 API，而是在 order-received 頁以 auto-form 自動 submit 建單參數
 * （含 CheckMacValue SHA256）POST 至綠界 Cashier 託管頁，故：
 *  - before_process_payment 僅回傳 order-received URL
 *  - before_order_received 負責組裝 RequestParams 並 render auto-form
 *
 * 仿 ShoplinePayment\Services\RedirectGateway。
 */
final class AioRedirectGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'ecpay_aio';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = '綠界 ECPay 全方位金流（導轉）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '綠界 ECPay AIO 全方位金流，支援信用卡、ATM、超商代碼、超商條碼、WebATM、Apple Pay 等付款方式';

	/**
	 * 綠界 AIO 核心支付邏輯
	 *
	 * AIO 不在此階段發 API，僅回傳 order-received URL；實際跳轉在 before_order_received
	 * 以 auto-form submit 至綠界 Cashier。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		return $this->get_return_url( $order );
	}

	/**
	 * 第三方金流頁面 render 前（order-received）
	 *
	 * 冪等檢查（已寫入 MerchantTradeNo 則 skip）→ 組裝 RequestParams →
	 * render auto-form template POST 到綠界 Cashier。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		try {
			$meta_keys = new EcpayMetaKeys( $order );

			// 冪等：已建單（已有 MerchantTradeNo）則不重複送
			if ( $meta_keys->get_trade_no() ) {
				return;
			}

			$request_params = RequestParams::instance( $order, $this );

			// 寫入冪等鍵 MerchantTradeNo
			$meta_keys->update_trade_no( $request_params->MerchantTradeNo );

			$settings = AioSettingsDTO::instance();

			// render auto-form（隱藏表單自動 submit 至綠界 Cashier）
			Plugin::load_template(
				'auto-form',
				[
					'params' => $request_params->to_form_params(),
					'url'    => $settings->endpoint,
				]
			);
		} catch ( \Throwable $e ) {
			$this->logger( "❌ {$this->title} 建單時發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$meta_keys = new EcpayMetaKeys( $order );

		$payment_info = $meta_keys->get_payment_info();
		if ( $payment_info ) {
			echo WP::array_to_html( $payment_info, [ 'title' => '綠界繳費資訊' ] );
		}

		$payment_detail = $meta_keys->get_payment_detail();
		if ( $payment_detail ) {
			echo WP::array_to_html( $payment_detail, [ 'title' => '綠界付款明細' ] );
		}
	}

	// region 設定

	/**
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new AioSettingsDTO() )->to_array();
			$unset_keys    = [ 'merchantId', 'hashKey', 'hashIv', 'endpoint' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return AioSettingsDTO::instance()->to_array();
	}

	/**
	 * [後台] 自訂欄位驗證邏輯（min/max 金額）
	 *
	 * @return bool was anything saved?
	 * @see WC_Settings_API::process_admin_options
	 */
	public function process_admin_options(): bool {
		$min_amount_name = $this->get_field_key( 'minAmount' );
		$max_amount_name = $this->get_field_key( 'maxAmount' );

		@[
			$min_amount_name => $min_amount,
			$max_amount_name => $max_amount,
		] = $this->get_post_data();

		$min_amount = (float) $min_amount;
		$max_amount = (float) $max_amount;

		if ( $min_amount < 0 ) {
			$this->errors[] = \sprintf(
				/* translators: %s: gateway title */
				\__( 'Save failed. %s minimum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $max_amount > 0 && $max_amount < $min_amount ) {
			$this->errors[] = \sprintf(
				/* translators: %s: gateway title */
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

	// endregion

	// region 退款

	/**
	 * 能否處理退款（admin 點按退款時觸發的前置檢查）
	 *
	 * 退款分流（資安：判定依據為綠界回傳並存於 _pc_ecpay_payment_detail 的 PaymentType，非前端）：
	 *  - 信用卡類（Credit_* / Flexible_*）→ 回 true，實際 API 退款於 handle_payment_gateway_refund。
	 *  - 非信用卡（ATM/WebATM/CVS/BARCODE/ApplePay）→ 回 WP_Error('refund_unsupported')，不呼叫任何退款 API。
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額（WC 計算，僅作非空檢查）
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error
	 * @see WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $amount ) {
			return false;
		}

		// 非信用卡一律擋下，不呼叫退款 API（D5：ATM/CVS/BARCODE/WebATM/ApplePay 須人工）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			return new \WP_Error(
				'refund_unsupported',
				\__( '此付款方式不支援 API 退款，請至綠界商家後台人工處理', 'power_checkout' )
			);
		}

		// 信用卡類：允許退款，實際 DoAction API 於 handle_payment_gateway_refund 發送
		return true;
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發）
	 *
	 * 信用卡類訂單呼叫綠界 DoAction(Action=R)；非信用卡 process_refund 已擋下故不會走 API。
	 * 仿 SLP RedirectGateway：wpdb 交易 + 失敗 order note + 刪 refund。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public function handle_payment_gateway_refund( int $order_id, int $refund_id ): void {
		if ( ! $this->is_this_gateway( $order_id ) ) {
			return;
		}

		/** @var \WC_Order_Refund $refund */
		$refund = \wc_get_order( $refund_id );
		if ( ! $refund->get_refunded_payment() ) { // 手動退款（未經 gateway）不發 API
			return;
		}

		/** @var \WC_Order $order */
		$order = \wc_get_order( $order_id );

		// 防禦：非信用卡不發 API（process_refund 理應已擋，雙重保險）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			$order->add_order_note( '⚠️ 非信用卡付款方式不支援 API 退款，請至綠界商家後台人工處理' );
			return;
		}

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

			$trade_no = EcpayPaymentType::get_trade_no( $order );
			$amount   = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
			$reason   = (string) $refund->get_reason();

			$response = ( new DoActionClient( $order ) )->refund( $trade_no, $amount );

			$note = \sprintf(
				'✅ 綠界 ECPay 信用卡退款成功，金額 %1$s 元，TradeNo：%2$s，RtnMsg：%3$s。退款原因：%4$s',
				(int) \ceil( $amount ),
				$trade_no,
				$response['RtnMsg'],
				$reason
			);
			$order->add_order_note( $note );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$order->add_order_note( "❌ 綠界 ECPay 退款失敗：{$e->getMessage()}" );
			$refund->delete( true );
		}
	}

	// endregion

	// region 後台訂單操作（請款 Capture / 取消授權 Cancel-Auth）

	/** @var string 後台訂單操作 — 請款（Action=C） */
	public const ACTION_CAPTURE = 'pc_ecpay_aio_capture';

	/** @var string 後台訂單操作 — 取消授權（Action=N） */
	public const ACTION_CANCEL_AUTH = 'pc_ecpay_aio_cancel_auth';

	/**
	 * 後台訂單操作清單注入請款 / 取消授權（woocommerce_order_actions filter）
	 *
	 * 僅信用卡類 AIO 訂單顯示（依綠界回傳並存於 _pc_ecpay_payment_detail 的 PaymentType 判定，非前端）。
	 * ATM/CVS/BARCODE/WebATM/ApplePay 與非綠界訂單不顯示。
	 *
	 * @param array<string, string> $actions 既有訂單操作
	 * @param \WC_Order|null         $order   訂單（WC 於 order detail 頁傳入）
	 * @return array<string, string>
	 */
	public static function add_order_actions( array $actions, ?\WC_Order $order = null ): array {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		if ( $order->get_payment_method() !== self::ID ) {
			return $actions;
		}
		// 僅信用卡類可請款 / 取消授權（DoAction C/N 僅信用卡）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			return $actions;
		}

		$actions[ self::ACTION_CAPTURE ]     = \__( '綠界 ECPay 信用卡請款（關帳）', 'power_checkout' );
		$actions[ self::ACTION_CANCEL_AUTH ] = \__( '綠界 ECPay 信用卡取消授權', 'power_checkout' );
		return $actions;
	}

	/**
	 * 後台「請款」訂單操作 handler（Action=C）
	 *
	 * 信用卡類 → 呼叫綠界 DoAction(C) 請款，寫 capture meta + order note；
	 * 非信用卡 → 僅記錄不支援提示（雙重防禦，理應已被 add_order_actions 隱藏）。
	 * 任何 \Throwable 一律捕捉並記錄 order note，不外露內部錯誤。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_capture_action( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'capture' );
	}

	/**
	 * 後台「取消授權」訂單操作 handler（Action=N）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_cancel_auth_action( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'cancel_auth' );
	}

	/**
	 * 請款 / 取消授權共用流程
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $method 'capture'（請款 C）｜'cancel_auth'（取消授權 N）
	 * @return void
	 */
	private static function do_capture_or_void( \WC_Order $order, string $method ): void {
		$action_zh    = 'capture' === $method ? '請款' : '取消授權';
		$status_value = 'capture' === $method ? 'captured' : 'voided';

		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		// 非信用卡一律擋下，不呼叫任何 API（依綠界 PaymentType，非前端）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			$order->add_order_note( "⚠️ 非信用卡付款方式不支援綠界 API {$action_zh}，請至綠界商家後台人工處理" );
			return;
		}

		try {
			$trade_no = EcpayPaymentType::get_trade_no( $order );
			$amount   = (float) $order->get_total(); // 金額來自訂單，非前端
			$client   = new DoActionClient( $order );

			$response = 'capture' === $method
				? $client->capture( $trade_no, $amount )
				: $client->cancel_auth( $trade_no, $amount );

			( new EcpayMetaKeys( $order ) )->update_capture_status( $status_value );

			$order->add_order_note(
				\sprintf(
					'✅ 綠界 ECPay 信用卡%1$s成功，金額 %2$s 元，TradeNo：%3$s，RtnMsg：%4$s',
					$action_zh,
					(int) \ceil( $amount ),
					$trade_no,
					$response['RtnMsg']
				)
			);
		} catch ( \Throwable $e ) {
			// do_action 內已記錄失敗 order note；此處僅記錄至 log，不外露細節
			Plugin::logger(
				"綠界 AIO {$action_zh}失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// endregion

	/** 初始化：註冊 callback + blocks + 後台訂單操作 */
	public static function init(): void {
		AioCallback::instance();

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );

		// 後台訂單操作：請款 / 取消授權（woocommerce_order_actions filter + 對應 handler action）
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CAPTURE, [ __CLASS__, 'handle_capture_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CANCEL_AUTH, [ __CLASS__, 'handle_cancel_auth_action' ] );
	}

	/** 註冊區塊結帳支援（仿 SLP） */
	public static function register_checkout_blocks(): void {
		\add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ): void {
				if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}

				if ( ! \class_exists( GatewayUtils::class ) ) {
					require_once Plugin::$dir . '/inc/classes/Domains/Payment/Shared/Utils/GatewayUtils.php';
				}

				$gateway = GatewayUtils::get_gateway( self::ID );

				if ( ! $gateway ) {
					return;
				}

				if ( $gateway instanceof AbstractPaymentGateway ) {
					$payment_method_registry->register( new BlocksIntegration( $gateway ) );
				}
			}
		);
	}
}
