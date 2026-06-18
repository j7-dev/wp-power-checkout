<?php /** @noinspection PhpMissingReturnTypeInspection */

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\Shared\Abstracts;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\GatewaySupport;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\ProcessResult;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\WpUtils\Classes\DTO;
use J7\WpUtils\Classes\WP;

/**
 * 付款閘道抽象類別 單例模式，一進 WC ，很早的階段就會被實例化
 * 單例，會被儲存在容器內
 * 區塊結帳 @see https://developer.woocommerce.com/docs/block-development/cart-and-checkout-blocks/checkout-payment-methods/payment-method-integration/
 * */
abstract class AbstractPaymentGateway extends \WC_Payment_Gateway implements IPaymentProvider {

	// region 自訂屬性

	/** @var string 付款方式類型 (自訂，用來區分付款方式類型) @deprecated */
	public string $payment_type = '';

	/** @var int 付款截止日(分鐘)，通常 ATM / CVS / BARCODE 才有 */
	public int $expire_min;

	/** @var int 付款方式最小金額 */
	public int $min_amount = 0;

	// endregion

	/** @var array<string> 支援的特性預設支援退款、tokenization、區塊結帳 */
	public $supports = [
		'products',
		'refunds',
		'tokenization',
		'checkout-blocks',
		'block_checkout',
		// 'pre-orders',
		// 'subscriptions',
		// 'subscription_cancellation',
		// 'subscription_suspension',
		// 'subscription_reactivation',
		// 'subscription_amount_changes',
		// 'subscription_date_changes',
		// 'multiple_subscriptions'
	];

	/** @var \WC_Order|null 訂單 */
	public \WC_Order|null $order = null;

	/** @var array<string> 必須設定的屬性 */
	private array $require_properties = [
		'id',
		'title',
	];

	/** Constructor
	 *
	 * @noinspection PhpPossiblePolymorphicInvocationInspection
	 * */
	public function __construct() {
		$settings = $this->get_settings();

		$this->init_settings();
		$this->icon              = (string) ( $settings['icon'] ?? '' );
		$this->title             = (string) ( $settings['title'] ?? '' );
		$this->description       = (string) ( $settings['description'] ?? '' );
		$this->order_button_text = (string) ( $settings['order_button_text'] ?? '' ) ?: \sprintf(
			\__( '使用 %s 付款', 'power_checkout' ),
			(string) ( $settings['title'] ?? '' )
		);

		if (isset($settings['expire_min'])) {
			$this->expire_min = (int) $settings['expire_min'];
		}

		if (isset($settings['min_amount'])) {
			$this->min_amount = (int) $settings['min_amount'];
		}

		if (isset($settings['max_amount'])) {
			$this->max_amount = (int) $settings['max_amount'];
		}

		// 儲存欄位
		\add_action( "woocommerce_update_options_payment_gateways_{$this->id}", [ $this, 'process_admin_options' ] );

		// [Admin] 在後台 order detail 頁地址下方顯示資訊
		\add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'render_after_billing_address' ] );

		// 在 checkout/order-pay checkout/order-received 前執行
		\add_action( 'wp', [ $this, 'before_page_render' ] );

		$this->validate_properties();

		// 註冊 power checkout 的 payment gateway
		\add_filter( 'power_checkout_payment_gateway_ids', fn( array $gateway_ids ) => [ ...$gateway_ids, $this->id ] );

		\add_action('woocommerce_order_refunded', [ static::class, 'handle_payment_gateway_refund' ], 10, 2);

		// 訂閱收款
		// \add_action( 'woocommerce_scheduled_subscription_payment_dummy', array( $this, 'process_subscription_payment' ), 10, 2 );

		// add_action ( 'wc_pre_orders_process_pre_order_completion_payment_' . $this->id, array( $this, 'process_pre_order_release_payment' ), 10 );
	}

	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 *
	 * @return array<string, mixed> 取得設定
	 */
	abstract public static function get_settings( bool $with_default = true ): array;

	/**
	 * 驗證必須設定的屬性
	 *
	 * @throws \Exception 如果屬性未設定
	 *  */
	private function validate_properties(): void {
		foreach ( $this->require_properties as $property ) {
			if ( !isset( $this->$property ) ) {
				throw new \Exception( static::class . "必須設定 {$property} 屬性" );
			}
		}

		// \array_map( static fn( $support ) => GatewaySupport::from( $support ), $this->supports );
	}

	/**
	 * 是否可用
	 * 基於原本 WC_Payment_Gateway 的 is_available 方法，增加最小金額限制
	 *
	 * @return bool
	 */
	public function is_available(): bool {

		$is_available = ( 'yes' === $this->enabled );

		if (\is_admin()) {
			return $is_available;
		}

		if ( !$is_available ) {
			return false;
		}

		$total = $this->get_order_total();
		if ( $total <= 0 ) {
			return false;
		}

		if ( $this->min_amount > 0 && $total < $this->min_amount ) {
			return false;
		}

		if ( $this->max_amount > 0 && $total > $this->max_amount ) {
			return false;
		}

		return true;
	}

	/**
	 * 處理付款，定義為 final method，不可被覆寫
	 * 支付邏輯請寫在 before_process_payment 裡面
	 *
	 * 共用邏輯：
	 * 1. order note 紀錄 gateway
	 * 2. 支付順利的話就扣庫存，不順利就 throw Exception
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return array{result: string, redirect?: string}
	 * @throws \Exception 如果訂單不存在
	 * @see WC_Payment_Gateway::process_payment
	 */
	final public function process_payment( $order_id ): array {
		try {
			$order = \wc_get_order( $order_id );
			if ( !$order instanceof \WC_Order ) {
				throw new \Exception( \__( 'Order not found.', 'power_checkout' ) );
			}
			$this->record_exception_to_order_note( $order );

			$order->add_order_note( \sprintf( \__( 'Pay via %s', 'power_checkout' ), $this->method_title ) );

			$redirect = $this->before_process_payment( $order );

			// 順利通過就扣庫存，不順利就 throw 出去
			\wc_maybe_reduce_stock_levels( $order_id );
			\wc_release_stock_for_order( $order );
			return ProcessResult::SUCCESS->to_array( $redirect );
		} catch (\Throwable $e) {
			$this->logger( "❌ {$this->title} 處理結帳時發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
			// 避免將錯誤資訊 print 到前端
			\wc_add_notice( "處理結帳時發生錯誤，請查閱 {$this->title} 的 log 紀錄了解詳情", 'error' );
			return ProcessResult::FAILED->to_array();
		}
	}

	/**
	 * 核心支付邏輯請寫在這裡，直接覆寫
	 *
	 * @param \WC_Order $order 訂單 在 process_payment 之前執行
	 * @return string 返回結帳付款頁面的 URL
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		return $order->get_checkout_payment_url( true );
	}

	/**
	 * 處理退款（安全預設）
	 * 這不是訂單狀態轉換時觸發，而是 admin 點按部分退款時觸發。
	 *
	 * 預設行為：不支援 API 退款的金流回正規化 `\WP_Error`（{@see ErrorCode::UNSUPPORTED}），
	 * 提示改至金流後台手動處理；支援退款的金流自行覆寫此方法。
	 *
	 * 設計：失敗一律經 {@see NormalizedError::from()} 建構（$data 帶 provider），
	 * 取代舊有回裸 `false`，使呼叫端（REST /refund + 前端 RefundDialog）能讀
	 * `error_code` 精確顯示「不支援 API 退款」並導引手動退款流程。
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 *
	 * @return bool|\WP_Error 成功為 true，失敗為正規化 \WP_Error（預設 UNSUPPORTED）。
	 * @noinspection PhpMissingReturnTypeInspection
	 * @see          WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		return NormalizedError::from(
			ErrorCode::UNSUPPORTED,
			\__( '此付款方式不支援 API 退款，請至金流後台手動處理', 'power_checkout' ),
			[ 'provider' => $this->id ]
		);
	}

	/**
	 * 查詢交易 / 補單（安全預設）
	 * 不支援查詢的金流回傳空陣列；支援的金流自行覆寫
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 交易查詢結果，預設為空陣列
	 */
	public function query_trade( \WC_Order $order ): array {
		return [];
	}

	/**
	 * 請款（capture，安全預設 no-op）
	 * 不支援線上請款的金流為 no-op，不 throw；支援的金流自行覆寫
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function capture( \WC_Order $order ): void {}

	/**
	 * 取消授權（void authorization，安全預設 no-op）
	 * 不支援取消授權的金流為 no-op，不 throw；支援的金流自行覆寫
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function void_auth( \WC_Order $order ): void {}

	/**
	 * 取得支援的付款方式清單（安全預設）
	 * 預設回傳空陣列；支援多付款方式的金流自行覆寫
	 *
	 * @return array<int|string, mixed> 支援的付款方式清單，預設為空陣列
	 */
	public function get_supported_payment_methods(): array {
		return [];
	}

	/**
	 * [後台] 欄位儲存 field schema 的值存入 option，可以自訂驗證邏輯
	 * 可以用
	 * \WC_Admin_Settings::add_error
	 * 或
	 * $this->errors[] = 'error_message';
	 * $this->display_errors();
	 * 來顯示錯誤訊息
	 *
	 * @return bool was anything saved?
	 * @see WC_Settings_API::process_admin_options
	 */
	public function process_admin_options(): bool { // phpcs:ignore
		return parent::process_admin_options();
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_detail_array = ( new MetaKeys($order) )->get_payment_detail();
		if ($payment_detail_array) {
			echo WP::array_to_html( $payment_detail_array);
		}

		$refund_detail_array = ( new MetaKeys($order) )->get_refund_detail();
		if ($refund_detail_array) {
			echo WP::array_to_html( $refund_detail_array);
		}
	}

	/**
	 * 在 /checkout/order-received/{$order_id}/?key=wc_order_{$order_key}，定義為 final method，不可被覆寫
	 * 前執行
	 *
	 * @see WC_Shortcode_Checkout::output
	 * */
	final public function before_page_render(): void {
		global $wp;
		$order_id = $wp->query_vars['order-received'] ?? null;
		if ( !$order_id ) {
			return;
		}
		$order = \wc_get_order( $order_id );
		if ( !$order instanceof \WC_Order) {
			return;
		}

		if ($this->id === $order->get_payment_method() ) {
			$this->record_exception_to_order_note($order);
			$this->before_order_received( $order );
			\WC()->cart->empty_cart();
		}
	}

	/**
	 * 第三方金流 callback 回來之後，頁面 render 前
	 * 在 /checkout/order-received/{$order_id}/?key=wc_order_{$order_key}
	 * 前執行
	 *
	 * 例如綠界需透過前端網頁導轉(Submit)到綠界付款API網址
	 *
	 * @param \WC_Order $order 訂單
	 */
	protected function before_order_received( \WC_Order $order ): void {}

	/**
	 * 不同的 gateway 會有不同的自訂 request params
	 *
	 * @return array<string, mixed>
	 */
	public function extra_request_params(): array {
		return [];
	}

	/** [後台]顯示錯誤訊息，改用 WC_Admin_Settings */
	final public function display_errors(): void {
		if ( $this->errors ) {
			foreach ( $this->errors as $error ) {
				\WC_Admin_Settings::add_error( $error );
			}
		}
	}


	/**
	 * 記錄 log
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param bool|null            $allow_order_note 是否紀錄在 order note
	 */
	final public function logger( string $message, string $level = 'debug', array $args = [], $trace_limit = 0, bool|null $allow_order_note = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, "power_checkout_{$this->id}", $trace_limit );
		if (!$this->order) {
			return;
		}

		$allow_order_note = $allow_order_note ?? \in_array( $level, [ 'error', 'warning' ], true );
		if ( $allow_order_note ) {
			$order_note = WP::array_to_html( $args, [ 'title' => "{$message} <p style='margin-bottom: 0;'>&nbsp;</p>" ] );
			$this->order->add_order_note( $order_note );
		}
	}

	/**
	 * 驗證訂單是不是使用此付款方式
	 * 通常用在 hook callback 中
	 *
	 * @param \WC_Order|int|string $order_or_id 訂單或訂單 ID
	 *
	 * @return bool
	 * @throws \Exception 如果訂單不是實例或不是實例的訂單
	 */
	public function is_this_gateway( \WC_Order|int|string $order_or_id ): bool {
		if ( \is_numeric( $order_or_id ) ) {
			/** @noinspection CallableParameterUseCaseInTypeContextInspection */
			$order_or_id = \wc_get_order( $order_or_id );
		}

		if ( !$order_or_id instanceof \WC_Order ) {
			throw new \Exception( "#{$order_or_id} is not instance of WC_Order" );
		}

		return $order_or_id->get_payment_method() === $this->id;
	}

	/** 設定 order 屬性，就能在呼叫 logger 時同時記錄在 order note 上 */
	final protected function record_exception_to_order_note( \WC_Order $order ): void {
		$this->order = $order;
	}

	/**
	 * 退款邏輯，API 發送（退款創建 woocommerce_order_refunded 時觸發）
	 *
	 * ⚠️ 設計為 static：因 PAYUNi UNi Embed 等子類需以靜態形式覆寫此 handler
	 *    （PHP 限制 static / instance 不可同名混用）。本 static 方法以 late static binding
	 *    建立「被呼叫的 gateway 類別」實例（new static()），再委派至 instance method
	 *    process_gateway_refund，使既有 5 個 gateway（SLP / AIO / ECPG / MPG / UPP）的 instance
	 *    退款邏輯（依賴 $this，含 is_this_gateway 守衛）完全不需改動內部，僅由
	 *    handle_payment_gateway_refund 改名為 process_gateway_refund。
	 *
	 * ⚠️ 必須用 new static()（被呼叫類別）而非 wc_get_payment_gateway_by_order（訂單實際 gateway）：
	 *    後者會在「以 A gateway 處理 B gateway 訂單」時誤路由到 B gateway 並執行退款，
	 *    破壞各 gateway「非本 gateway 訂單不處理」的 is_this_gateway 守衛（見 *RefundTest 之
	 *    「非本 gateway 訂單 handle 不處理退款」場景）。new static() 保留呼叫端多型，
	 *    由子類 process_gateway_refund 內的 is_this_gateway 自行過濾。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public static function handle_payment_gateway_refund( int $order_id, int $refund_id ): void {
		// late static binding：被呼叫的 gateway 類別（hook 以 [static::class] 註冊；
		// 測試 / API 以 $gateway->handle_payment_gateway_refund 實例呼叫，static 皆為該 gateway 類別）。
		// new static() 安全：所有具體 gateway 子類皆為 final 且建構子無必填參數（與父類一致）。
		( new static() )->process_gateway_refund( $order_id, $refund_id ); // @phpstan-ignore new.static
	}

	/**
	 * 退款邏輯實作（instance，子類覆寫；由 static handle_payment_gateway_refund 委派）
	 *
	 * 預設 no-op；各 gateway 覆寫此方法實作實際退款 API 發送（可安全使用 $this）。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
	}

	/** 初始化 */
	public static function init(): void {}
}
