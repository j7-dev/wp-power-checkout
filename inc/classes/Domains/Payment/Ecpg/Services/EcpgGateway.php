<?php
/**
 * 綠界 ECPay 站內付 2.0（ECPG，內嵌式）付款閘道
 *
 * 與 AIO（導轉式）/ SLP（跳轉式）的核心差異：站內付 2.0 由前端綠界 JS SDK 在商家頁面
 * 內嵌收單，不跳轉。資料流為三段：
 *
 *   1) before_process_payment（本階段）：後端呼叫 GetTokenbyTrade（ecpg domain，AES）取得交易
 *      Token，寫入訂單 meta，回傳 order-received URL。
 *   2) 前端站內付元件（階段六 block tsx，容器 id ECPayPayment）：以 Token 渲染收單 UI，顧客輸入
 *      卡片後 SDK 取得 PayToken。
 *   3) 後端 CreatePayment（EcpgApiClient::create_payment，前端 PayToken 送回後觸發）：解析
 *      ThreeDInfo.ThreeDURL，非空則前端導向 3DS；空則等 ReturnURL（EcpgCallback）幕後通知。
 *
 * 資料流決策（本階段）：採「GetToken 在 before_process_payment 完成、回 order-received」的設計，
 * 比照 SLP 的 process_payment 取資料、AIO 的 before_order_received 接手 render。CreatePayment 因
 * 需前端 PayToken，故 EcpgApiClient::create_payment 在本階段建好方法但不在 before_process_payment
 * 內呼叫——實際觸發點（前端送回 PayToken 的 REST endpoint）留待階段六與 block tsx 一併接上。
 * 本階段把 Token 透過訂單 meta 暫存，供階段六前端讀取。
 *
 * 仿 EcpayAIO\Services\AioRedirectGateway。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Ecpg\DTOs\EcpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgApiClient;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgCallback;
use J7\PowerCheckout\Domains\Payment\Ecpg\Http\EcpgFrontendApi;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\EcpgBlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Ecpg\Shared\Helpers\EcpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Enums\EcpayPaymentMethod;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayPaymentType;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/** 綠界 ECPay 站內付 2.0 付款閘道 */
final class EcpgGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'ecpay_ecpg';

	/** @var string 站內付 2.0 交易 Token meta key（供前端站內付元件讀取） */
	private const TOKEN_META_KEY = '_pc_ecpay_ecpg_token';

	/**
	 * @var string 站內付 2.0 前端 JS SDK 來源（固定正式 domain，環境由 ECPay.initialize 切換）
	 *
	 * ⚠️ 一律用正式 domain 的 SDK（含 Stage 測試）；stage 版 SDK 是不同檔案（功能不完整）。
	 * 測試 / 正式由 ECPay.initialize('Stage'|'Prod', ...) 控制，非換 SDK URL。路徑大寫 S。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md §JS SDK 三依賴
	 */
	public const SDK_URL = 'https://ecpg.ecpay.com.tw/Scripts/sdk-1.0.0.js';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = '綠界 ECPay 站內付（不跳轉）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '綠界 ECPay 站內付 2.0，內嵌式信用卡收單，不跳轉即完成付款（含 3D 驗證）';

	/**
	 * 站內付 2.0 核心支付邏輯（依顧客選擇的付款方式分流）
	 *
	 * 兩種資料流：
	 *  - 信用卡（Credit）：GetTokenbyTrade 取交易 Token → 寫 meta 供前端 JS SDK 收單（3DS）。
	 *  - 非信用卡（ATM / CVS / BARCODE）：後端幕後取號（GetToken → CreatePayment 直接用 Token），
	 *    取得虛擬帳號 / 超商代碼 / 條碼 + 繳費期限，寫入 _pc_ecpay_payment_info + order note。
	 *    取號 ≠ 付款：訂單維持待付款（pending），消費者實際繳費後 ReturnURL（RtnCode=1）才轉付款完成。
	 *
	 * 兩者皆回傳 order-received URL。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 * @throws \Exception 取 token / 取號失敗（ConsumerInfo 缺漏 / 傳輸層 / 業務層）由父類 process_payment 攔截
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$payment = ( new EcpgMetaKeys( $order ) )->get_payment();

		if ( $payment->is_get_code_payment() ) {
			$this->get_code( $order, $payment );
		} else {
			$this->get_credit_token( $order );
		}

		return $this->get_return_url( $order );
	}

	/**
	 * 信用卡流程：GetTokenbyTrade 取交易 Token，寫入訂單 meta 供前端站內付元件讀取
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 * @throws \Exception 取 token 失敗
	 */
	private function get_credit_token( \WC_Order $order ): void {
		$decrypted = ( new EcpgApiClient( $order ) )->get_token();

		// 暫存交易 Token，供前端站內付元件（block tsx）讀取渲染收單 UI
		$order->update_meta_data( self::TOKEN_META_KEY, (string) ( $decrypted['Token'] ?? '' ) );
		$order->save_meta_data();
	}

	/**
	 * 非信用卡幕後取號流程：取得取號資訊，寫入訂單 meta + order note，訂單維持待付款
	 *
	 * 取號資訊（ATMInfo / CVSInfo / BarcodeInfo + OrderInfo）寫入 _pc_ecpay_payment_info（沿用 AIO 的
	 * EcpayMetaKeys::update_payment_info），並以人類可讀的 order note 呈現繳費指示供顧客 / 客服查閱。
	 *
	 * @param \WC_Order          $order   訂單
	 * @param EcpayPaymentMethod $payment 付款方式（ATM / CVS / BARCODE）
	 * @return void
	 * @throws \Exception 取號失敗
	 */
	private function get_code( \WC_Order $order, EcpayPaymentMethod $payment ): void {
		$info = ( new EcpgApiClient( $order ) )->get_code( $payment );

		// 取號資訊寫入 _pc_ecpay_payment_info（admin / 顧客顯示用）
		( new EcpayMetaKeys( $order ) )->update_payment_info( $info );

		// order note 記錄取號繳費指示（人類可讀）
		$note = WP::array_to_html(
			$info,
			[ 'title' => \sprintf( '綠界站內付 2.0 %s 取號成功（待繳費）', $payment->label() ) ]
		);
		$order->add_order_note( $note );
	}

	/**
	 * 取得訂單暫存的站內付 2.0 交易 Token（供前端站內付元件讀取）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string Token（無則空字串）
	 */
	public static function get_ecpg_token( \WC_Order $order ): string {
		return (string) ( $order->get_meta( self::TOKEN_META_KEY ) ?: '' );
	}

	/**
	 * 於 order-received 頁曝露前端站內付 SDK 渲染所需的「逐訂單」資料
	 *
	 * 站內付 2.0 的 token 在 before_process_payment（下單時）才產生，block checkout「下單前」尚無
	 * 訂單與 token，故 SDK 渲染落在 order-received 頁（CardInfo.OrderResultURL 亦指向此頁）。
	 * 本方法把 token / MerchantTradeNo / order_id / order_key（前端呼叫 create-payment 的擁有權憑證）
	 * 透過 wp_localize_script 掛到既有前端 bundle handle，供階段六-C 的 block tsx 讀取渲染 SDK。
	 *
	 * 靜態 SDK 設定（sdk_url / merchant_id / create_payment_url / is_test）由 build_sdk_config()
	 * 一併提供，與 block data（EcpgBlocksIntegration）同源，避免兩處不一致。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$token = self::get_ecpg_token( $order );
		if ( '' === $token ) {
			// 無 token 代表未成功取號，不渲染 SDK（避免前端 throw）
			return;
		}

		// 確保前端 bundle 已註冊 / enqueue（與退款、發票表單共用同一 handle）
		SettingTabService::enqueue_vue_app();

		\wp_localize_script(
			SettingTabService::$handle,
			Plugin::$snake . '_ecpg_data', // power_checkout_ecpg_data
			\array_merge(
				self::build_sdk_config(),
				[
					'token'             => $token,
					'merchant_trade_no' => ( new EcpayMetaKeys( $order ) )->get_trade_no(),
					'order_id'          => (string) $order->get_id(),
					'order_key'         => $order->get_order_key(),
				]
			)
		);
	}

	/**
	 * 組裝前端站內付 SDK 靜態設定（不含逐訂單機密，可安全曝露前端）
	 *
	 * MerchantID 為公開特店編號（非機密；HashKey/HashIV 絕不曝露前端）。
	 * is_test 供前端決定 ECPay.initialize('Stage'|'Prod')。
	 *
	 * @return array{sdk_url: string, merchant_id: string, create_payment_url: string, is_test: bool, container_id: string}
	 */
	public static function build_sdk_config(): array {
		$settings = EcpgSettingsDTO::instance();
		return [
			'sdk_url'            => self::SDK_URL,
			'merchant_id'        => $settings->merchantId,
			'create_payment_url' => EcpgFrontendApi::get_create_payment_url(),
			'is_test'            => Mode::TEST === $settings->mode,
			'container_id'       => 'ECPayPayment', // 綠界 SDK 硬編碼容器 id，前端必須使用此 id
		];
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_detail = ( new EcpayMetaKeys( $order ) )->get_payment_detail();
		if ( $payment_detail ) {
			echo WP::array_to_html( $payment_detail, [ 'title' => '綠界站內付 2.0 付款明細' ] );
		}
	}

	// region 設定

	/**
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new EcpgSettingsDTO() )->to_array();
			$unset_keys    = [ 'merchantId', 'hashKey', 'hashIv', 'tokenEndpoint', 'paymentEndpoint' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return EcpgSettingsDTO::instance()->to_array();
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
	 *  - 信用卡類（站內付 2.0 本階段僅信用卡）→ 回 true，實際 API 退款於 handle_payment_gateway_refund。
	 *  - 非信用卡（ATM/CVS/BARCODE）→ 回 WP_Error('refund_unsupported')，不呼叫任何退款 API。
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

		// 非信用卡一律擋下，不呼叫退款 API（ATM/CVS/BARCODE 須人工）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			return new \WP_Error(
				'refund_unsupported',
				\__( '此付款方式不支援 API 退款，請至綠界商家後台人工處理', 'power_checkout' )
			);
		}

		// 信用卡類：允許退款，實際 DoAction API（ecpayment domain）於 handle_payment_gateway_refund 發送
		return true;
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發）
	 *
	 * 信用卡類訂單呼叫綠界 ecpayment domain DoAction(Action=R)；非信用卡 process_refund 已擋下故不會走 API。
	 * 仿 SLP RedirectGateway：wpdb 交易 + 失敗 order note + 刪 refund。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
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

			$merchant_trade_no = ( new EcpayMetaKeys( $order ) )->get_trade_no();
			$trade_no          = EcpayPaymentType::get_trade_no( $order );
			$amount            = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
			$reason            = (string) $refund->get_reason();

			$response = ( new EcpgApiClient( $order ) )->refund( $merchant_trade_no, $trade_no, $amount );

			$note = \sprintf(
				'✅ 綠界 ECPay 站內付信用卡退款成功，金額 %1$s 元，TradeNo：%2$s，RtnMsg：%3$s。退款原因：%4$s',
				(int) \ceil( $amount ),
				$trade_no,
				(string) ( $response['RtnMsg'] ?? '' ),
				$reason
			);
			$order->add_order_note( $note );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$order->add_order_note( "❌ 綠界 ECPay 站內付退款失敗：{$e->getMessage()}" );
			$refund->delete( true );
		}
	}

	// endregion

	/** 初始化：註冊 callback（ReturnURL）+ 前端 SDK REST 端點（create-payment）+ blocks */
	public static function init(): void {
		EcpgCallback::instance();

		// 前端站內付元件取得 PayToken 後 POST 回此端點觸發 CreatePayment（order_key 驗證）
		EcpgFrontendApi::register_hooks();

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );
	}

	/** 註冊區塊結帳支援（仿 AIO / SLP） */
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
					// 用 ECPG 專屬 integration，block data 額外帶站內付 SDK 靜態設定
					$payment_method_registry->register( new EcpgBlocksIntegration( $gateway ) );
				}
			}
		);
	}
}
