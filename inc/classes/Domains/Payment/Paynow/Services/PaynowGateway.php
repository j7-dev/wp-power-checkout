<?php
/**
 * PayNow（REST API 體系 1）付款閘道（Cycle 1 骨架）
 *
 * 資料流（PayNow Component SDK，站內 iframe，比照 ECPG / UNi Embed）：
 *   1) before_process_payment（Cycle 2 接真 client）：後端 POST /payment-intents 建立
 *      PaymentIntent，取得 id（pp_xxx）+ secret（pp_xxx_st_xxx）寫入訂單 meta，回 order-received URL。
 *   2) 前端 Component SDK（Cycle 4）：以 secret 渲染收單 iframe，顧客輸入卡片後 SDK checkout。
 *   3) PayNow Webhook（Cycle 3）：payment_result 以 server-to-server POST 回打（source of truth，
 *      HMAC-SHA256 驗簽），交 StatusManager 更新訂單狀態。
 *
 * ⚠️ 本 Cycle（Foundation）僅建立可實例化、可註冊的骨架：
 *  - const ID='paynow'、extends AbstractPaymentGateway、implements IPaymentProvider。
 *  - get_supported_payment_methods() 回 7 值（排除 ApplePayDeferred）。
 *  - before_process_payment 最小實作（寫冪等鍵 + 回 order-received URL），不接真 PayNow API。
 *  - 不覆寫 capture / void_auth（維持父類 no-op 安全預設）。
 *  - 退款 / RestClient / Callback / 前端 → Cycle 2-4。
 *
 * @see specs/open-issue/paynow-implementation-plan.md §步驟 11
 * @see .claude/rules/provider-guide.rule.md §Adding a New Payment Provider
 * @see \J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway 內嵌式骨架對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Paynow\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\CreatePaymentIntentParams;
use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\PaynowSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Paynow\DTOs\RefundParams;
use J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowCallback;
use J7\PowerCheckout\Domains\Payment\Paynow\Http\PaynowRestClient;
use J7\PowerCheckout\Domains\Payment\Paynow\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowIntentStatus;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Enums\PaynowRefundStatus;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\ItemName;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowBlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowMetaKeys;
use J7\PowerCheckout\Domains\Payment\Paynow\Shared\Helpers\PaynowTradeNo;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** PayNow 付款閘道（Cycle 2：RestClient + create_payment_intent 串接） */
final class PaynowGateway extends AbstractPaymentGateway implements IPaymentProvider {

	/**
	 * @var string PayNow Component SDK v2 來源（固定 CDN，禁下載託管）
	 *
	 * ⚠️ PayNow 安全規範：一律由 js.paynow.com.tw 載入（CSP script-src + frame-src 須白名單）。
	 *    測試 / 正式由前端 createPayment 的 env: 'sandbox'|'production' 切換，非換 SDK URL。
	 */
	public const SDK_URL = 'https://js.paynow.com.tw/sdk/v2/index.js';

	/** @var string 前端內嵌容器 id（PayNow SDK mount 目標，前端必須使用此 id） */
	public const CONTAINER_ID = 'paynow-container';

	/** @var string 付款方式 ID（已拍板，不得更名） */
	public const ID = 'paynow';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = 'PayNow 立吉富';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = 'PayNow（立吉富）Component SDK 站內收單，支援信用卡 / ATM / 超商代碼 / LINE Pay / Apple Pay';

	/**
	 * 核心支付邏輯（Cycle 2：接真 RestClient + create_payment_intent）
	 *
	 * 資料流（資料流分析 §流程 1）：
	 *   下單 → 幣別守衛（≠TWD throw）→ 冪等（已有 intent_id 直接回 URL）
	 *        → create_payment_intent → 寫 intent_id / secret / trade_no → 回 order-received URL。
	 *
	 * 流程：
	 *  1. 幣別守衛：currency≠TWD → throw（父類 process_payment catch → failure + notice「僅支援 TWD」，
	 *     此時 PayNow API 尚未被呼叫，符合「非 TWD 不呼叫 API」）。
	 *  2. 冪等：已有 PaymentIntentId → 不重複 create_payment_intent，直接回 order-received URL，
	 *     不覆寫既有 intent_id / secret（比照 UNi Embed SDK_TOKEN 冪等）。
	 *  3. 預寫冪等鍵 MerTradeNo（PCN{order_id}，Webhook / 對帳輔助；Webhook 反查主鍵為 intent_id）。
	 *  4. create_payment_intent（amount 後端依訂單計算，防金額竄改）→ 取 result.id（pp_xxx）+
	 *     result.secret（pp_xxx_st_xxx）寫入 meta。
	 *  5. 回傳 order-received URL，前端 Component SDK 以 secret 渲染收單。
	 *
	 * create_payment_intent 失敗（RuntimeException）由父類 process_payment 攔截 → 記 log + order note，
	 * 回 failure，不寫 intent_id / secret、不轉訂單狀態（維持 pending）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 * @throws \Exception 幣別非 TWD
	 * @throws \RuntimeException 建立付款意圖（create_payment_intent）失敗
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		// 1. 幣別守衛（PayNow 體系 1 僅支援 TWD）；在呼叫 API 之前 throw，確保非 TWD 不打 PayNow
		$currency = $order->get_currency();
		if ( 'TWD' !== $currency ) {
			throw new \Exception(
				\sprintf(
					/* translators: %s: order currency */
					\__( 'PayNow 僅支援新台幣（TWD），目前訂單幣別為 %s', 'power_checkout' ),
					$currency
				)
			);
		}

		$meta_keys = new PaynowMetaKeys( $order );

		// 2. 冪等：已有 PaymentIntentId → 不重建，直接回 order-received URL（不覆寫既有 intent_id / secret）
		if ( '' !== $meta_keys->get_payment_intent_id() ) {
			return $this->get_return_url( $order );
		}

		// 3. 預寫冪等鍵 MerTradeNo（PCN{order_id}）
		if ( '' === $meta_keys->get_trade_no() ) {
			$meta_keys->update_trade_no( PaynowTradeNo::generate( $order->get_id() ) );
		}

		// 4. create_payment_intent（amount 後端依訂單計算，禁前端傳入 → 防金額竄改）
		$settings = PaynowSettingsDTO::instance();
		$params   = CreatePaymentIntentParams::create(
			[
				'paymentNo'             => $meta_keys->get_trade_no(),
				'amount'                => (int) \ceil( (float) $order->get_total() ),
				'currency'              => 'TWD',
				'description'           => ItemName::get( $order ),
				'resultUrl'             => $this->get_return_url( $order ),
				'webhookUrl'            => self::get_webhook_url(),
				'allowedPaymentMethods' => $settings->allowed_payment_methods,
				'allowInstallments'     => $settings->allow_installments ? self::default_installments() : [],
				'expireDays'            => $settings->expire_days,
			]
		);

		$client = new PaynowRestClient(
			private_key: $settings->private_key,
			is_sandbox: Mode::PROD->value !== $settings->mode,
		);
		$result = $client->create_payment_intent( $params->to_array() );

		// 5. 寫入 PaymentIntentId（pp_xxx，Webhook 反查主鍵）+ secret（pp_xxx_st_xxx，前端 SDK）
		$intent_id = (string) ( $result['id'] ?? '' );
		$secret    = (string) ( $result['secret'] ?? '' );
		if ( '' === $intent_id || '' === $secret ) {
			throw new \RuntimeException( 'PayNow create_payment_intent 回應缺少 id / secret' );
		}

		$meta_keys->update_payment_intent_id( $intent_id );
		$meta_keys->update_secret( $secret );

		return $this->get_return_url( $order );
	}

	/**
	 * 於 order-received 頁曝露前端 Component SDK 渲染所需的「逐訂單」+ 靜態設定
	 *
	 * 比照 UNi Embed before_order_received：把 secret（result.secret）/ public_key / env /
	 * order_received_url / container_id 透過 wp_localize_script 掛到既有前端 bundle handle，
	 * 供前端 MountPaynowPayment 讀取渲染 SDK。
	 *
	 * ⚠️ 與 UNi Embed 差異（減一支）：**不** localize create-payment REST URL —— PayNow SDK
	 *    checkout() 直接與 PayNow 完成授權，無「前端送回後端」中間步驟，故無此端點。
	 *
	 * 無 secret 守衛：secret 空（before_process_payment 未成功 / 失敗）→ 不 localize、不渲染 SDK，
	 * 避免前端 throw（資料流分析 §流程 2：[secret 空?→不渲染]）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$secret = ( new PaynowMetaKeys( $order ) )->get_secret();
		if ( '' === $secret ) {
			// 無 secret 代表未成功建立 PaymentIntent，不渲染 SDK（避免前端 throw）
			return;
		}

		// 確保前端 bundle 已註冊 / enqueue（與退款、發票表單、ECPG、UNi Embed 共用同一 handle）
		SettingTabService::enqueue_vue_app();

		\wp_localize_script(
			SettingTabService::$handle,
			Plugin::$snake . '_paynow_data', // power_checkout_paynow_data
			\array_merge(
				self::build_sdk_config(),
				[
					'secret'    => $secret,
					'order_id'  => (string) $order->get_id(),
					'order_key' => $order->get_order_key(),
				]
			)
		);
	}

	/**
	 * 組裝前端 Component SDK 靜態設定（不含逐訂單機密，可安全曝露前端）
	 *
	 * 其中 public_key 為前端 SDK 初始化用（非機密；private_key 絕不曝露前端）。
	 * env 供前端 createPayment 決定 'sandbox'（測試）/ 'production'（正式）。
	 * ⚠️ 不含 create_payment_url（PayNow SDK checkout 直接授權，無此中間端點）。
	 *
	 * @return array{sdk_url: string, public_key: string, env: string, container_id: string}
	 */
	public static function build_sdk_config(): array {
		$settings = PaynowSettingsDTO::instance();
		return [
			'sdk_url'      => self::SDK_URL,
			'public_key'   => $settings->public_key,
			'env'          => Mode::PROD->value === $settings->mode ? 'production' : 'sandbox',
			'container_id' => self::CONTAINER_ID,
		];
	}

	/**
	 * Webhook NotifyURL（PayNow payment_result server-to-server POST 至此；Cycle 3 callback 處理）
	 *
	 * 資安 F-5（single source of truth）：URL 字串集中於 PaynowCallback::get_notify_url()，
	 * 本方法委派之，避免 gateway / callback 兩處各自硬編同一 URL 而日後失同步
	 * （create_payment_intent 帶入的 webhookUrl 必須與 callback 註冊端點完全一致）。
	 *
	 * @return string Webhook URL（https://{host}/wp-json/power-checkout/paynow/notify）
	 */
	public static function get_webhook_url(): string {
		return PaynowCallback::get_notify_url();
	}

	/**
	 * 初始化（gateway 啟用時由 ProviderRegister 呼叫）
	 *
	 * Cycle 3（Phase 07）：註冊 Webhook callback（NotifyURL）+ block checkout 整合。
	 *  - **不**註冊 FrontendApi / create-payment 端點：PayNow 體系 1 由前端 Component SDK
	 *    checkout() 直接與 PayNow 完成授權，無「前端送回後端」中間步驟（對比 ECPG / UNi Embed）。
	 *  - Webhook payment_result 以 server-to-server POST 回打 /paynow/notify（source of truth，
	 *    HMAC-SHA256 驗簽在 callback 內完成，permission_callback __return_true）。
	 *
	 * ⚠️ 註冊冪等：callback REST 路由與 block hook 亦於 ProviderRegister::register_hooks() 無條件
	 *    註冊（確保未啟用時 NotifyURL 仍可回 200、block 整合可被探測）；本 init 在啟用路徑重複呼叫
	 *    亦安全（PaynowCallback 為 SingletonTrait、blocks hook 由 GatewayUtils 守衛）。
	 *
	 * @return void
	 */
	public static function init(): void {
		// NotifyURL 幕後通知（source of truth；HMAC 驗簽在 callback 內完成）
		PaynowCallback::register_hooks();

		// 整合區塊結帳（比照 5 個既有 gateway）
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );

		// 後台訂單操作：補查付款意圖（GET /payment-intents/:id）/ 退款查詢（GET /refunds/:uuid）。
		// ⚠️ 不註冊 capture / void_auth（PayNow 體系 1 無此端點，維持父類 no-op）。
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_QUERY, [ __CLASS__, 'handle_query_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_REFUND_QUERY, [ __CLASS__, 'handle_refund_query_action' ] );
	}

	/**
	 * 註冊區塊結帳支援（仿 ECPG / AIO / SLP）
	 *
	 * 透過 woocommerce_blocks_payment_method_type_registration 注入 PaynowBlocksIntegration；
	 * gateway 未啟用 / 未取得時 no-op（GatewayUtils::get_gateway 回 null）。
	 *
	 * @return void
	 */
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
					// 用 PayNow 專屬 integration，block data 額外帶 Component SDK 靜態設定
					$payment_method_registry->register( new PaynowBlocksIntegration( $gateway ) );
				}
			}
		);
	}

	/**
	 * 預設分期期數（allow_installments=true 時帶入）
	 *
	 * @return array<int> 合法分期期數（白名單子集）
	 */
	private static function default_installments(): array {
		return [ 3, 6, 12 ];
	}

	/**
	 * 取得支援的付款方式清單（7 值，排除 ApplePayDeferred）
	 *
	 * 依 Q1 裁決：CreditCard / CreditCardInstallment / ATM / ConvenienceStore /
	 *            LINEPayOnline / LINEPayOffline / ApplePay。
	 *
	 * @return array<int|string, mixed>
	 */
	public function get_supported_payment_methods(): array {
		return \array_map(
			static fn( PaynowPaymentMethod $method ): string => $method->value,
			PaynowPaymentMethod::cases()
		);
	}

	// region 退款（信用卡 / ATM 走 REST POST /payment-intents/:id/refunds）

	/** @var string 後台訂單操作 — 補查付款意圖（GET /payment-intents/:id） */
	public const ACTION_QUERY = 'pc_paynow_query_trade';

	/** @var string 後台訂單操作 — 退款查詢（GET /refunds/:uuid） */
	public const ACTION_REFUND_QUERY = 'pc_paynow_refund_query';

	/**
	 * 訂單退款 bank 三欄 meta（ATM 退款必填，由後台 / REST 寫入）
	 *
	 * ⚠️ PayNow ATM 退款需 bankCode / bankBranchCode / bankAccount（payment-rest-api.md §5.1）。
	 *    缺任一欄 → handle_payment_gateway_refund 拒絕送出並記 order note，絕不打 API。
	 */
	public const META_REFUND_BANK_CODE   = '_pc_paynow_refund_bank_code';
	public const META_REFUND_BANK_BRANCH = '_pc_paynow_refund_bank_branch';
	public const META_REFUND_BANK_ACCT   = '_pc_paynow_refund_bank_account';

	/**
	 * 能否處理退款（admin 點按退款時觸發的前置檢查）
	 *
	 * 判定依據為 PayNow 回傳並存於 _pc_paynow_payment_detail 的 PaymentType（後端 source of truth，
	 * 非前端傳入）：
	 *  - 信用卡（CreditCard / CreditCardInstallment）/ ATM → 回 true（實際退款於 handle_payment_gateway_refund）。
	 *  - 超商代碼 / LINE Pay / Apple Pay 等 → 回 WP_Error('refund_unsupported')，不呼叫任何退款 API。
	 *
	 * 邊緣防禦：金額 null / ≤0 / 超過訂單總額 → 回 false（不允許、不呼叫 API）。
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額（WC 計算，僅作非空 / 範圍檢查）
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error
	 * @see WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		// 金額防禦：null / ≤0 / 超過訂單總額 → 不允許（不呼叫 API）
		$amount = (float) $amount;
		if ( $amount <= 0 || $amount > (float) $order->get_total() ) {
			return false;
		}

		// 信用卡 / ATM 才允許 API 退款（依後端 PaymentType，非前端）；其餘擋下
		if ( self::is_refundable_order( $order ) ) {
			return true;
		}

		return new \WP_Error(
			'refund_unsupported',
			\__( '此付款方式不支援 API 退款，請至 PayNow 商家後台人工處理', 'power_checkout' )
		);
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發，由父類 static 委派而來）
	 *
	 * ⚠️ 覆寫父類 AbstractPaymentGateway::process_gateway_refund（instance）；
	 *    父類 static handle_payment_gateway_refund 以 new static() 建立本 gateway 後委派至此，
	 *    保留呼叫端多型，並由本方法內 is_this_gateway 守衛過濾「非本 gateway 訂單」。
	 *
	 * 流程（資料流分析 §流程 5）：
	 *  1. is_this_gateway 守衛：非 paynow 訂單 → 靜默略過（不刪 refund、不發 API）。
	 *  2. refund 物件守衛：缺 / 非 gateway 退款（手動退款）→ 不發 API。
	 *  3. 能力守衛：非信用卡 / ATM → note 提示 + 不發 API（process_refund 理應已擋，雙重防禦）。
	 *  4. PaymentIntentId 守衛：缺 intent_id → 無法組退款端點 → 失敗（ROLLBACK + 刪 refund）。
	 *  5. ATM 守衛：缺 bank 三欄 → note 提示「銀行」必填 + 不發 API（不視為失敗，不刪 refund）。
	 *  6. wpdb TRANSACTION → RestClient::refund：
	 *     - result.type=success → 寫 _pc_paynow_refund_detail + note '退款成功' → COMMIT。
	 *     - result.type=rejected / failed / 其餘非 success → 視同失敗 → ROLLBACK + 刪 refund + note（含拒絕原因）。
	 *  7. 任何 \Throwable（含 API 失敗）→ ROLLBACK + note '退款失敗' + 刪 refund。
	 *
	 * 金額一律來自 WC refund 物件（非前端），防止前端竄改金額。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== self::ID ) {
			return; // 非本 gateway 訂單不處理（靜默略過）
		}

		$refund = \wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund || ! $refund->get_refunded_payment() ) {
			return; // 手動退款（未經 gateway）不發 API
		}

		$meta_keys    = new PaynowMetaKeys( $order );
		$payment_type = (string) ( $meta_keys->get_payment_detail()['PaymentType'] ?? '' );

		// 能力守衛：非信用卡 / ATM 不發 API（process_refund 理應已擋；此處雙重防禦，不刪 refund）
		if ( ! self::is_refundable_order( $order ) ) {
			$order->add_order_note( '⚠️ 此付款方式不支援 API 退款，請至 PayNow 商家後台人工處理' );
			return;
		}

		$amount = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
		$reason = (string) $refund->get_reason();

		// ATM 退款守衛：缺 bank 三欄 → 拒絕送出 + note 提示「銀行」必填（不視為失敗、不刪 refund，待補填重試）
		$is_atm    = PaynowPaymentMethod::ATM->value === $payment_type;
		$bank_data = self::get_refund_bank_data( $order );
		if ( $is_atm && ! self::has_complete_bank_data( $bank_data ) ) {
			$order->add_order_note(
				\__( '⚠️ ATM 退款必填銀行代碼 / 分行代碼 / 帳號（bankCode / bankBranchCode / bankAccount），請補填後重試', 'power_checkout' )
			);
			return;
		}

		// PaymentIntentId 守衛：缺 intent_id 無法組退款端點 → 視同失敗（ROLLBACK + 刪 refund）
		$payment_intent_id = $meta_keys->get_payment_intent_id();

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

			if ( '' === $payment_intent_id ) {
				throw new \RuntimeException( '缺少 PaymentIntentId，無法發起退款' );
			}

			$params = $is_atm
			? RefundParams::create_for_atm(
					[
						'amount'         => (int) \ceil( $amount ),
						'reason'         => $reason,
						'bankCode'       => $bank_data['bankCode'],
						'bankBranchCode' => $bank_data['bankBranchCode'],
						'bankAccount'    => $bank_data['bankAccount'],
					]
				)
			: RefundParams::create(
					[
						'amount' => (int) \ceil( $amount ),
						'reason' => $reason,
					]
				);

			$client   = self::make_rest_client();
			$response = $client->refund( $payment_intent_id, $params->to_array() );

			$refund_type = (string) ( $response['type'] ?? '' );
			$status      = PaynowRefundStatus::tryFrom( $refund_type );

			// 退款成功（success）→ 寫明細 + note → COMMIT
			if ( null !== $status && $status->is_success() ) {
				$meta_keys->update_refund_detail( $response );
				$order->add_order_note(
					\sprintf(
						'✅ PayNow 退款成功，金額 %1$d 元，退款原因：%2$s',
						(int) \ceil( $amount ),
						$reason
					)
				);
				$wpdb->query( 'COMMIT' ); // phpcs:ignore
				return;
			}

			// rejected / failed / processing / 其餘非 success → 視同失敗（含 RejectReason）
			$reject_reason = (string) ( $response['RejectReason'] ?? $response['message'] ?? '' );
			$is_rejected   = null !== $status && $status->is_rejected();
			throw new \RuntimeException(
				$is_rejected
					? \sprintf( '退款遭拒絕（RejectReason：%s）', '' !== $reject_reason ? $reject_reason : '未提供' )
					: \sprintf( '退款未成功（type：%s）%s', $refund_type, '' !== $reject_reason ? '：' . $reject_reason : '' )
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$order->add_order_note( "❌ PayNow 退款失敗：{$e->getMessage()}" );
			\wc_delete_shop_order_transients( $order );
			$refund->delete( true );
			Plugin::logger(
				"PayNow 退款失敗 #{$order_id}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// endregion

	// region 後台訂單操作（補查付款意圖 / 退款查詢）—— 不含 capture / void_auth（PayNow 體系 1 無此端點）

	/**
	 * 查詢交易（override Abstract 安全預設）
	 *
	 * IPaymentProvider 契約：一律回傳陣列、不 throw（連線 / 解析失敗回空陣列）。
	 * 補單流程改用 handle_query_action 直接呼叫並於該處統一捕捉例外。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 查詢結果內層（失敗回空陣列）
	 */
	public function query_trade( \WC_Order $order ): array {
		try {
			$intent_id = ( new PaynowMetaKeys( $order ) )->get_payment_intent_id();
			if ( '' === $intent_id ) {
				return [];
			}
			return self::make_rest_client()->retrieve_payment_intent( $intent_id );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"PayNow query_trade 失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
			return [];
		}
	}

	/**
	 * 後台訂單操作清單注入補查付款意圖 / 退款查詢（woocommerce_order_actions filter）
	 *
	 * PayNow 專屬 action key 一律前綴 pc_paynow_（與其他 gateway 區隔，避免衝突）。
	 * ⚠️ 不含 capture / void_auth（PayNow 體系 1 無請款 / 取消授權端點，維持父類 no-op）。
	 *
	 * @param array<string, string> $actions 既有訂單操作
	 * @param \WC_Order|null        $order   訂單（WC 於 order detail 頁傳入）
	 * @return array<string, string>
	 */
	public static function add_order_actions( array $actions, ?\WC_Order $order = null ): array {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		if ( $order->get_payment_method() !== self::ID ) {
			return $actions;
		}

		$actions[ self::ACTION_QUERY ]        = \__( 'PayNow 補查付款意圖（重新查詢付款狀態 / 補單）', 'power_checkout' );
		$actions[ self::ACTION_REFUND_QUERY ] = \__( 'PayNow 退款查詢（更新退款明細）', 'power_checkout' );

		return $actions;
	}

	/**
	 * 後台「補查付款意圖」訂單操作 handler（GET /payment-intents/:id）
	 *
	 * 呼叫 retrieve_payment_intent → 若 status=success 且訂單尚未 processing →
	 * 將回應映射為 StatusManager 可吃的 payload（Status=Success + Amount + PaymentType），
	 * 交 StatusManager 補單（含金額防竄改 + 冪等 + 幣別守衛）。
	 * status≠success（如 draft）→ 不補單，記 order note 說明目前狀態。
	 * 任何 \Throwable 一律捕捉並記錄，不外露內部錯誤。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_query_action( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		try {
			$intent_id = ( new PaynowMetaKeys( $order ) )->get_payment_intent_id();
			if ( '' === $intent_id ) {
				$order->add_order_note( '⚠️ PayNow 補查付款意圖失敗：訂單缺少 PaymentIntentId' );
				return;
			}

			$result = self::make_rest_client()->retrieve_payment_intent( $intent_id );
			$status = (string) ( $result['status'] ?? '' );

			$order->add_order_note(
				\sprintf(
					'PayNow 補查付款意圖查詢結果：status=%s，amount=%s，PaymentIntentId=%s',
					$status,
					(string) ( $result['amount'] ?? '' ),
					$intent_id
				)
			);

			$intent_status = PaynowIntentStatus::tryFrom( $status );

			// 已付款且訂單尚未 processing → 映射為 StatusManager payload 補單
			if ( null !== $intent_status && $intent_status->is_success()
				&& ! $order->has_status( OrderStatus::PROCESSING->value ) ) {
				$payload = [
					'Status'          => 'Success',
					'Amount'          => $result['amount'] ?? '',
					'PaymentIntentId' => $intent_id,
					'PaymentType'     => (string) ( $result['paymentType'] ?? ( new PaynowMetaKeys( $order ) )->get_payment_detail()['PaymentType'] ?? PaynowPaymentMethod::CreditCard->value ),
					'TransactionNo'   => (string) ( $result['transactionNo'] ?? '' ),
				];
				( new StatusManager( $payload, $order ) )->update_order_status();
			}
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ PayNow 補查付款意圖失敗：{$e->getMessage()}" );
			Plugin::logger(
				"PayNow 補查付款意圖失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 後台「退款查詢」訂單操作 handler（GET /refunds/:uuid）
	 *
	 * 取 _pc_paynow_refund_detail 的退款 uuid（id）→ retrieve_refund → 寫回明細 + note。
	 * 無 uuid（尚無退款 / 退款明細缺 id）→ 記 note 提示，不查詢。
	 * 任何 \Throwable 一律捕捉並記錄。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_refund_query_action( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		try {
			$meta_keys = new PaynowMetaKeys( $order );
			$uuid      = (string) ( $meta_keys->get_refund_detail()['id'] ?? '' );

			if ( '' === $uuid ) {
				$order->add_order_note( '⚠️ PayNow 退款查詢失敗：訂單尚無退款 uuid（須先發起退款）' );
				return;
			}

			$result = self::make_rest_client()->retrieve_refund( $uuid );

			$meta_keys->update_refund_detail( $result );
			$order->add_order_note(
				\sprintf(
					'PayNow 退款查詢結果：type=%s，amount=%s，uuid=%s',
					(string) ( $result['type'] ?? '' ),
					(string) ( $result['amount'] ?? '' ),
					$uuid
				)
			);
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ PayNow 退款查詢失敗：{$e->getMessage()}" );
			Plugin::logger(
				"PayNow 退款查詢失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// endregion

	// region 退款 / 退款查詢 共用工具

	/**
	 * 建立 RestClient（依商店設定 mode 切換 sandbox / prod）
	 *
	 * @return PaynowRestClient
	 */
	private static function make_rest_client(): PaynowRestClient {
		$settings = PaynowSettingsDTO::instance();
		return new PaynowRestClient(
			private_key: $settings->private_key,
			is_sandbox: Mode::PROD->value !== $settings->mode,
		);
	}

	/**
	 * 判定訂單是否可走 API 退款（信用卡 / ATM）
	 *
	 * 資安：判定依據為 PayNow 回傳並存於 _pc_paynow_payment_detail 的 PaymentType（後端 source of truth），
	 * 非前端輸入。無付款明細（callback 漏接）→ 視為不可退款（保守）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_refundable_order( \WC_Order $order ): bool {
		$detail = ( new PaynowMetaKeys( $order ) )->get_payment_detail();
		if ( ! $detail ) {
			return false;
		}

		$payment_type = (string) ( $detail['PaymentType'] ?? '' );
		return \in_array(
			$payment_type,
			[
				PaynowPaymentMethod::CreditCard->value,
				PaynowPaymentMethod::CreditCardInstallment->value,
				PaynowPaymentMethod::ATM->value,
			],
			true
		);
	}

	/**
	 * 取得訂單退款 bank 三欄（ATM 退款用，由後台 / REST 預先寫入 order meta）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array{bankCode: string, bankBranchCode: string, bankAccount: string}
	 */
	private static function get_refund_bank_data( \WC_Order $order ): array {
		return [
			'bankCode'       => (string) ( $order->get_meta( self::META_REFUND_BANK_CODE ) ?: '' ),
			'bankBranchCode' => (string) ( $order->get_meta( self::META_REFUND_BANK_BRANCH ) ?: '' ),
			'bankAccount'    => (string) ( $order->get_meta( self::META_REFUND_BANK_ACCT ) ?: '' ),
		];
	}

	/**
	 * Bank 三欄是否齊全（ATM 退款必填守衛）
	 *
	 * @param array{bankCode: string, bankBranchCode: string, bankAccount: string} $bank_data bank 資料
	 * @return bool
	 */
	private static function has_complete_bank_data( array $bank_data ): bool {
		return '' !== $bank_data['bankCode']
		&& '' !== $bank_data['bankBranchCode']
		&& '' !== $bank_data['bankAccount'];
	}

	// endregion

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new PaynowSettingsDTO() )->to_array();
			$unset_keys    = [ 'public_key', 'private_key', 'base_url' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return PaynowSettingsDTO::instance()->to_array();
	}
}
