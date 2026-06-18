<?php
/**
 * PAYUNi 統一金流 UNi Embed V3（內嵌式信用卡，站內付不跳轉）付款閘道
 *
 * 與 UPP（導轉式）的核心差異：UNi Embed 由前端 PAYUNi JS SDK（uniPayment.js）在商家頁面內嵌
 * 收單，不跳轉。資料流為三段（比照綠界 ECPG）：
 *
 *   1) before_process_payment（本 Cycle）：後端呼叫 token_get（V3，只送 MerID + Timestamp +
 *      IFrameDomain）取得 SDK_TOKEN，寫入訂單 meta，回傳 order-received URL。
 *   2) 前端內嵌元件（Cycle 5）：以 SDK_TOKEN 渲染收單 UI，顧客輸入卡片後 SDK 取得綁卡結果。
 *   3) 後端 merchant_trade（Cycle 2 create-payment）：前端送回後觸發幕後授權（含 API3D 3D 分流）。
 *
 * ⚠️ V3 硬約束：token_get 階段「不送訂單欄位」（MerTradeNo / TradeAmt / ProdDesc / NotifyURL /
 *    ReturnURL 一律不送）。MerTradeNo / TradeAmt 在 merchant_trade 才送（Cycle 2）。
 *
 * ⚠️ 本 Cycle 不實作：後台交易 client（close / cancel / query → Cycle 4）、
 *    merchant_trade / callback（Cycle 2 / 3）。query_trade / capture / void_auth 暫用父類安全預設。
 *
 * @see .claude/skills/payuni-uni-embed-v3/SKILL.md §API 1 token_get（Version 3.0）
 * @see \J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway 內嵌式骨架對照
 * @see \J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway 同金流商對照
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services;

use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\DTOs\PayuniUniEmbedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\MerchantTradeClient;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedCallback;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\PayuniUniEmbedFrontendApi;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\TokenGetClient;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\UniDoActionClient;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Http\UniQueryTradeClient;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Enums\PayuniUniEmbedPaymentMethod;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedMetaKeys;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Shared\Helpers\PayuniUniEmbedTradeNo;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/** PAYUNi UNi Embed V3 內嵌式信用卡付款閘道 */
final class PayuniUniEmbedGateway extends AbstractPaymentGateway implements IPaymentProvider {

	/** @var string 付款方式 ID（已拍板，不得更名；與 UPP 的 payuni_upp 區隔） */
	public const ID = 'payuni_uni_embed';

	/**
	 * @var string PAYUNi 交易標記 Gateway（UNi Embed 固定 9=IFrame，與 UPP 的 2 不同）
	 *
	 * merchant_trade / NotifyURL 回傳的 Gateway 欄位固定為 9；本常數供前端 SDK / callback
	 * 比對交易來源，確保不誤判為 UPP（2）。對齊 SKILL §與 UPP 的關鍵差異。
	 */
	public const GATEWAY_CODE = '9';

	/**
	 * @var string UNi Embed 前端 JS SDK 來源（固定 vendor domain，禁下載託管）
	 *
	 * ⚠️ PAYUNi 安全規範：一律由 vendor.payuni.com.tw 載入，禁止下載至商店主機託管。
	 *    測試 / 正式由 createSession 的 env: 'S'|'P' 切換，非換 SDK URL（對齊 SKILL §引入 SDK）。
	 */
	public const SDK_URL = 'https://vendor.payuni.com.tw/sdk/uni-payment.js';

	/** @var int 信用卡金額下限（PAYUNi 信用卡 1～199,999 元） */
	private const MIN_CREDIT_AMOUNT = 1;

	/** @var int 信用卡金額上限（PAYUNi 信用卡 1～199,999 元） */
	private const MAX_CREDIT_AMOUNT = 199999;

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = 'PAYUNi 信用卡（站內付，不跳轉）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = 'PAYUNi UNi Embed V3 內嵌式信用卡收單，於本站內完成付款不跳轉（含 3D 驗證）';

	/**
	 * UNi Embed 核心支付邏輯（token_get 取 SDK_TOKEN）
	 *
	 * 流程：
	 *  1. 金額守衛：信用卡 1～199,999 元，超出範圍 → throw（process_payment 轉 failure）。
	 *  2. 冪等：已有 SDK_TOKEN（10 分鐘有效）→ 不重複 token_get，直接回 order-received URL。
	 *  3. IFrameDomain 守衛：須含 https:// 且格式合法（V3 token_get 必填）→ 不合法即 throw。
	 *  4. 呼叫 token_get（V3 內層只送 MerID + Timestamp + IFrameDomain）→ 取 SDK_TOKEN 寫入 meta。
	 *  5. 回傳 order-received URL，讓前端 SDK 在同頁繼續收卡。
	 *
	 * token_get 失敗（外層 Status≠SUCCESS / 限定 IP 未設定 / 例外）由本方法 throw，
	 * 父類 process_payment 攔截 → 記 log + order note，回 failure，不寫 SDK_TOKEN、不轉訂單狀態。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 * @throws \Exception 金額超範圍 / IFrameDomain 不合法 / token_get 失敗
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		// 1. 金額守衛（信用卡 1～199,999 元）
		$amount = (int) \ceil( (float) $order->get_total() );
		if ( $amount < self::MIN_CREDIT_AMOUNT || $amount > self::MAX_CREDIT_AMOUNT ) {
			throw new \Exception(
				\sprintf(
					/* translators: 1: min 2: max */
					\__( 'PAYUNi 信用卡金額須介於 %1$d～%2$d 元', 'power_checkout' ),
					self::MIN_CREDIT_AMOUNT,
					self::MAX_CREDIT_AMOUNT
				)
			);
		}

		$meta_keys = new PayuniUniEmbedMetaKeys( $order );

		// 2. 冪等：已有 SDK_TOKEN 則沿用（避免 10 分鐘內重複建立 Token）
		if ( '' !== $meta_keys->get_sdk_token() ) {
			return $this->get_return_url( $order );
		}

		// 3. IFrameDomain 守衛（V3 token_get 必填，須含 https://）
		$iframe_domain = $this->resolve_iframe_domain();
		if ( ! $this->is_valid_iframe_domain( $iframe_domain ) ) {
			throw new \Exception(
				\__( 'IFrameDomain 不合法（須含 https:// 且符合格式）', 'power_checkout' )
			);
		}

		// 4. token_get 取 SDK_TOKEN（V3：內層只送 MerID + Timestamp + IFrameDomain）
		$payload   = $this->build_token_get_payload();
		$sdk_token = ( new TokenGetClient() )->get_sdk_token( $payload );

		// 寫入 SDK_TOKEN（供前端 SDK 收卡）+ 預寫冪等鍵 MerTradeNo（merchant_trade / NotifyURL 反查）
		$meta_keys->update_sdk_token( $sdk_token );
		if ( '' === $meta_keys->get_trade_no() ) {
			$meta_keys->update_trade_no( PayuniUniEmbedTradeNo::generate( $order->get_id() ) );
		}

		// 5. 回傳 order-received URL（前端 SDK 在同頁繼續收卡）
		return $this->get_return_url( $order );
	}

	/**
	 * 組裝 token_get 內層 payload（V3 硬約束：只含 MerID + Timestamp + IFrameDomain）
	 *
	 * ⚠️ 絕不含 MerTradeNo / TradeAmt / ProdDesc / NotifyURL / ReturnURL 等訂單欄位
	 *    （那是 V2 行為；V3 token_get 階段不送訂單，訂單欄位於 merchant_trade 才送）。
	 *
	 * 不接受 \WC_Order 參數：V3 token_get 與訂單無關，僅依商店設定（MerID / IFrameDomain）。
	 *
	 * @return array{MerID: string, Timestamp: int, IFrameDomain: string}
	 */
	public function build_token_get_payload(): array {
		$settings = PayuniUniEmbedSettingsDTO::instance();

		return [
			'MerID'        => $settings->merchant_id,
			'Timestamp'    => \time(),
			'IFrameDomain' => $this->resolve_iframe_domain(),
		];
	}

	/**
	 * 解析有效的 IFrameDomain：優先採商店設定，未填則由 site_url 衍生 https domain
	 *
	 * @return string
	 */
	private function resolve_iframe_domain(): string {
		$configured = PayuniUniEmbedSettingsDTO::instance()->iframe_domain;
		if ( '' !== $configured ) {
			return $configured;
		}

		// fallback：由 site_url 衍生含 https:// 的 domain（V3 IFrameDomain 須含 https://）
		$host = (string) \wp_parse_url( (string) \site_url(), PHP_URL_HOST );
		return '' !== $host ? "https://{$host}" : '';
	}

	/**
	 * 驗證 IFrameDomain 格式（V3 規範）
	 *
	 * 規則：
	 *  - 必須以 https:// 開頭（http:// / 無 scheme 一律不合法）。
	 *  - scheme 後的 host 僅允許「中文 / 英數字 / -」與「.」分段。
	 *  - host 各分段不可以 - 開頭或結尾。
	 *
	 * @param string $iframe_domain IFrameDomain
	 * @return bool
	 */
	private function is_valid_iframe_domain( string $iframe_domain ): bool {
		if ( '' === $iframe_domain ) {
			return false;
		}

		// 必須含 https:// 前綴
		if ( ! \str_starts_with( $iframe_domain, 'https://' ) ) {
			return false;
		}

		$host = \substr( $iframe_domain, \strlen( 'https://' ) );
		if ( '' === $host ) {
			return false;
		}

		// 去除可能的 path（取第一個 / 之前）與 port（取第一個 : 之前），只驗 host 部分
		$host = \explode( '/', $host )[0];
		$host = \explode( ':', $host )[0];
		if ( '' === $host ) {
			return false;
		}

		// host 各分段：中文 / 英數字 / -；不可以 - 開頭或結尾
		$labels = \explode( '.', $host );
		foreach ( $labels as $label ) {
			if ( '' === $label ) {
				return false;
			}
			if ( \str_starts_with( $label, '-' ) || \str_ends_with( $label, '-' ) ) {
				return false;
			}
			// 允許中文（\p{Han}）/ 英數字 / -
			if ( 1 !== \preg_match( '/^[\p{Han}A-Za-z0-9-]+$/u', $label ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 取得 PAYUNi 交易標記 Gateway（UNi Embed 固定 9=IFrame）
	 *
	 * 供 callback / 前端比對交易來源，避免與 UPP（2）誤判。
	 *
	 * @return string 固定 '9'
	 */
	public function get_gateway_code(): string {
		return self::GATEWAY_CODE;
	}

	/**
	 * 於 order-received 頁曝露前端內嵌 SDK 渲染所需的「逐訂單」+ 靜態設定
	 *
	 * UNi Embed 的 SDK_TOKEN 在 before_process_payment（下單時）才產生，block / classic checkout
	 * 「下單前」尚無訂單與 SDK_TOKEN，故 SDK 渲染落在 order-received 頁（比照 ECPG）。本方法把
	 * sdk_token / order_id / order_key（前端呼叫 create-payment 的擁有權憑證）+ 靜態 SDK 設定
	 * （sdk_url / env / iframe_domain / create_payment_url / 容器 ids）透過 wp_localize_script
	 * 掛到既有前端 bundle handle，供前端 MountPayuniUniEmbed 讀取渲染 SDK。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$sdk_token = ( new PayuniUniEmbedMetaKeys( $order ) )->get_sdk_token();
		if ( '' === $sdk_token ) {
			// 無 SDK_TOKEN 代表未成功取號，不渲染 SDK（避免前端 throw）
			return;
		}

		// 確保前端 bundle 已註冊 / enqueue（與退款、發票表單、ECPG 共用同一 handle）
		SettingTabService::enqueue_vue_app();

		\wp_localize_script(
			SettingTabService::$handle,
			Plugin::$snake . '_payuni_uni_data', // power_checkout_payuni_uni_data
			\array_merge(
				self::build_sdk_config(),
				[
					'sdk_token' => $sdk_token,
					'order_id'  => (string) $order->get_id(),
					'order_key' => $order->get_order_key(),
				]
			)
		);
	}

	/**
	 * 組裝前端內嵌 SDK 靜態設定（不含逐訂單機密，可安全曝露前端）
	 *
	 * MerID 為公開商店代號（非機密；HashKey/HashIV 絕不曝露前端）。
	 * env 供前端 createSession 決定 'S'（測試）/ 'P'（正式）；iframe_domain 供 SDK 來源驗證對照。
	 * 容器 id 固定 put_card_no / put_card_exp / put_card_cvc（SDK 約定，前端必須使用此 id）。
	 *
	 * @return array{sdk_url: string, merchant_id: string, env: string, iframe_domain: string, create_payment_url: string, container_ids: array{card_no: string, card_exp: string, card_cvc: string, token_type: string}}
	 */
	public static function build_sdk_config(): array {
		$settings = PayuniUniEmbedSettingsDTO::instance();
		return [
			'sdk_url'            => self::SDK_URL,
			'merchant_id'        => $settings->merchant_id,
			'env'                => 'test' === $settings->mode ? 'S' : 'P',
			'iframe_domain'      => $settings->iframe_domain,
			'create_payment_url' => PayuniUniEmbedFrontendApi::get_create_payment_url(),
			'container_ids'      => [
				'card_no'    => 'put_card_no',
				'card_exp'   => 'put_card_exp',
				'card_cvc'   => 'put_card_cvc',
				'token_type' => 'put_token_type',
			],
		];
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_detail = ( new PayuniUniEmbedMetaKeys( $order ) )->get_payment_detail();
		if ( $payment_detail ) {
			echo WP::array_to_html( $payment_detail, [ 'title' => 'PAYUNi UNi Embed 付款明細' ] );
		}
	}

	/**
	 * 取得支援的付款方式清單（UNi Embed 僅信用卡）
	 *
	 * @return array<int|string, mixed>
	 */
	public function get_supported_payment_methods(): array {
		return [
			PayuniUniEmbedPaymentMethod::Credit->value,
			PayuniUniEmbedPaymentMethod::CreditInst->value,
		];
	}

	// region 退款（信用卡 Close CloseType=2）

	/**
	 * 能否處理退款（admin 點按退款時觸發的前置檢查）
	 *
	 * UNi Embed 僅信用卡（PaymentType 固定 1）：
	 *  - 信用卡（PaymentType=1）→ 回 true，實際 Close API 退款於 handle_payment_gateway_refund。
	 *  - 非信用卡 / 無付款明細 → 回 WP_Error('refund_unsupported')，不呼叫任何退款 API。
	 *
	 * 邊緣防禦：金額 ≤ 0、超過訂單總額 → 回 false（不允許、不呼叫 API）。
	 * 判定依據為 PAYUNi 回傳並存於 _pc_payuni_uni_payment_detail 的 PaymentType，非前端。
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

		// 金額守衛：null / ≤0 → 維持回 false（既有 PaymentProviderContractTest 契約，不改）
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return false;
		}

		// 有金額但超出訂單總額 → 正規化 VALIDATION（不打 API；判定邊界不變，只換回傳值建構方式）
		if ( $amount > (float) $order->get_total() ) {
			return NormalizedError::from(
				ErrorCode::VALIDATION,
				\__( '退款金額不合法（超出可退餘額）', 'power_checkout' ),
				[ 'provider' => $this->id ]
			);
		}

		// 信用卡才允許 API 退款；其餘擋下（依 PAYUNi PaymentType，非前端）
		if ( self::is_credit_order( $order ) ) {
			return true;
		}

		return NormalizedError::from(
			ErrorCode::UNSUPPORTED,
			\__( '此付款方式不支援 API 退款，請至 PAYUNi 商家後台人工處理', 'power_checkout' ),
			[ 'provider' => $this->id ]
		);
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發，由父類 static 委派而來）
	 *
	 * ⚠️ 設計為 static（覆寫父類 AbstractPaymentGateway::handle_payment_gateway_refund static）：
	 *    後台訂單操作 / REST /refund / 測試皆以靜態形式呼叫。
	 *
	 * 信用卡 → Close CloseType=2；非信用卡 process_refund 已擋下故不會走 API（此處雙重防禦）。
	 * 仿 UPP：wpdb 交易 + 失敗 order note + 刪 refund（ROLLBACK）。
	 * 金額一律來自 WC refund 物件，非前端。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public static function handle_payment_gateway_refund( int $order_id, int $refund_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== self::ID ) {
			return; // 非本 gateway 訂單不處理（靜默略過）
		}

		$refund = \wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund || ! $refund->get_refunded_payment() ) {
			return; // 手動退款（未經 gateway）不發 API
		}

		// 雙重防禦：非信用卡不發 API（process_refund 理應已擋）
		if ( ! self::is_credit_order( $order ) ) {
			$order->add_order_note( '⚠️ 此付款方式不支援 API 退款，請至 PAYUNi 商家後台人工處理' );
			return;
		}

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

			$amount   = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
			$reason   = (string) $refund->get_reason();
			$response = ( new UniDoActionClient( $order ) )->refund( $order, $amount );
			$trade_no = (string) ( $response['TradeNo'] ?? ( new PayuniUniEmbedMetaKeys( $order ) )->get_payment_detail()['TradeNo'] ?? '' );

			$order->add_order_note(
				\sprintf(
					'✅ PAYUNi UNi Embed 信用卡退款成功，金額 %1$d 元，TradeNo：%2$s。退款原因：%3$s',
					(int) \ceil( $amount ),
					$trade_no,
					$reason
				)
			);

			$wpdb->query( 'COMMIT' ); // phpcs:ignore

			// 退款成功後寫入 capture_status='refunded'（僅供對帳 / 顯示；
			// WC refund 物件仍是退款冪等的真實來源，不以此 meta 做冪等判斷）。
			( new PayuniUniEmbedMetaKeys( $order ) )->update_capture_status( 'refunded' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$order->add_order_note( "❌ PAYUNi UNi Embed 退款失敗：{$e->getMessage()}" );
			\wc_delete_shop_order_transients( $order );
			$refund->delete( true );
		}
	}

	// endregion

	// region 後台訂單操作（查詢補單 / 請款 / 取消授權）override Abstract 安全預設

	/** @var string 後台訂單操作 — 查詢補單（/api/trade/query） */
	public const ACTION_QUERY = 'pc_payuni_uni_query_trade';

	/** @var string 後台訂單操作 — 請款（Close CloseType=1） */
	public const ACTION_CAPTURE = 'pc_payuni_uni_capture';

	/** @var string 後台訂單操作 — 取消授權（Cancel） */
	public const ACTION_CANCEL_AUTH = 'pc_payuni_uni_cancel_auth';

	/**
	 * 查詢交易（override Abstract 安全預設）
	 *
	 * IPaymentProvider 契約：一律回傳陣列、不 throw（缺 MerTradeNo / 連線失敗 / 驗章失敗時回空陣列）。
	 * 後台「查詢補單」流程改用 UniQueryTradeClient 直接呼叫（見 handle_query_action），於該處統一捕捉例外。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 查詢結果內層（失敗回空陣列）
	 */
	public function query_trade( \WC_Order $order ): array {
		try {
			return ( new UniQueryTradeClient( $order ) )->query();
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"PAYUNi UNi Embed query_trade 失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
			return [];
		}
	}

	/**
	 * 請款（capture，override Abstract no-op 安全預設）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function capture( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'capture' );
	}

	/**
	 * 取消授權（void authorization，override Abstract no-op 安全預設）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function void_auth( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'cancel_auth' );
	}

	/**
	 * 後台訂單操作清單注入查詢補單 / 請款 / 取消授權（woocommerce_order_actions filter）
	 *
	 * UNi Embed 專屬 action key 一律前綴 pc_payuni_uni_（與 UPP 的 pc_payuni_ 區隔，避免衝突）。
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

		// 查詢補單：所有 UNi Embed 訂單皆顯示
		$actions[ self::ACTION_QUERY ] = \__( 'PAYUNi UNi Embed 重新查詢付款狀態（補單）', 'power_checkout' );

		// 請款 / 取消授權：UNi Embed 僅信用卡，皆顯示
		$actions[ self::ACTION_CAPTURE ]     = \__( 'PAYUNi UNi Embed 信用卡請款（關帳）', 'power_checkout' );
		$actions[ self::ACTION_CANCEL_AUTH ] = \__( 'PAYUNi UNi Embed 信用卡取消授權', 'power_checkout' );

		return $actions;
	}

	/**
	 * 後台「查詢補單」訂單操作 handler
	 *
	 * 呼叫 /api/trade/query → 若已付款（TradeStatus=1 且 DataSource=A）且訂單尚未 processing，
	 * 以查詢結果交 StatusManager 補單（含金額防竄改 / MerID / Gateway=9 比對）。
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
			$result = ( new UniQueryTradeClient( $order ) )->query();

			$order->add_order_note(
				\sprintf(
					'PAYUNi UNi Embed 交易查詢結果：TradeStatus=%s，PaymentType=%s，TradeNo=%s，DataSource=%s',
					(string) ( $result['TradeStatus'] ?? '' ),
					(string) ( $result['PaymentType'] ?? '' ),
					(string) ( $result['TradeNo'] ?? '' ),
					(string) ( $result['DataSource'] ?? '' )
				)
			);

			$is_paid      = UniQueryTradeClient::is_paid( (string) ( $result['TradeStatus'] ?? '' ) );
			$is_complete  = 'A' === (string) ( $result['DataSource'] ?? '' ); // DataSource=B 為處理中，不補單
			$not_finished = ! $order->has_status( OrderStatus::PROCESSING->value );

			// 已付款 + 完整資料 + 尚未 processing → 交 StatusManager 補單。
			// 查詢回應（已驗章）若缺 StatusManager 守衛所需欄位（Gateway / MerID / TradeAmt），
			// 以本地可信來源補齊（Gateway 固定 9；MerID 取商店設定；TradeAmt 取訂單應收），
			// 使補單與 callback 走同一套金額防竄改 / MerID / Gateway 守衛邏輯。
			if ( $is_paid && $is_complete && $not_finished ) {
				if ( ! isset( $result['Gateway'] ) || '' === (string) $result['Gateway'] ) {
					$result['Gateway'] = self::GATEWAY_CODE;
				}
				if ( ! isset( $result['MerID'] ) || '' === (string) $result['MerID'] ) {
					$result['MerID'] = PayuniUniEmbedSettingsDTO::instance()->merchant_id;
				}
				if ( ! isset( $result['TradeAmt'] ) || '' === (string) $result['TradeAmt'] ) {
					$result['TradeAmt'] = (int) \ceil( (float) $order->get_total() );
				}
				( new StatusManager( $result, $order ) )->update_order_status();
			}
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"PAYUNi UNi Embed 交易查詢失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 後台「請款」訂單操作 handler（Close CloseType=1）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_capture_action( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'capture' );
	}

	/**
	 * 後台「取消授權」訂單操作 handler（Cancel）
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
	 * 狀態機前置守衛：已取消授權（voided）不可再請款、已請款（captured）不可再取消授權，
	 * 避免本地 capture_status 被覆寫而與 PAYUNi 實際狀態脫節（資安完整性）。
	 * 任何 \Throwable 捕捉並記錄失敗 order note，不外露內部細節。
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $method 'capture'（請款）｜'cancel_auth'（取消授權）
	 * @return void
	 */
	private static function do_capture_or_void( \WC_Order $order, string $method ): void {
		$action_zh    = 'capture' === $method ? '請款' : '取消授權';
		$status_value = 'capture' === $method ? 'captured' : 'voided';

		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		// 狀態機前置守衛（讀現有 _pc_payuni_uni_capture_status，避免覆寫與 PAYUNi 脫節）：
		// 已取消授權（voided）→ 不可請款；已請款（captured）→ 不可取消授權。
		$current_status = ( new PayuniUniEmbedMetaKeys( $order ) )->get_capture_status();
		if ( 'capture' === $method && 'voided' === $current_status ) {
			$order->add_order_note( '⚠️ 此訂單已取消授權，無法請款' );
			return;
		}
		if ( 'cancel_auth' === $method && 'captured' === $current_status ) {
			$order->add_order_note( '⚠️ 此訂單已請款，無法取消授權' );
			return;
		}

		try {
			$detail   = ( new PayuniUniEmbedMetaKeys( $order ) )->get_payment_detail();
			$trade_no = (string) ( $detail['TradeNo'] ?? '' );
			$client   = new UniDoActionClient( $order );

			if ( 'capture' === $method ) {
				$client->capture( $trade_no, (float) $order->get_total() ); // 金額來自訂單，非前端
			} else {
				$client->cancel_auth( $trade_no );
			}

			( new PayuniUniEmbedMetaKeys( $order ) )->update_capture_status( $status_value );

			$order->add_order_note(
				\sprintf(
					'✅ PAYUNi UNi Embed 信用卡%1$s成功，TradeNo：%2$s',
					$action_zh,
					$trade_no
				)
			);
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ PAYUNi UNi Embed 信用卡{$action_zh}失敗：{$e->getMessage()}" );
			Plugin::logger(
				"PAYUNi UNi Embed {$action_zh}失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 判定訂單是否為信用卡付款（UNi Embed 固定信用卡，PaymentType=1）
	 *
	 * 資安：判定依據為 PAYUNi 回傳並存於 _pc_payuni_uni_payment_detail 的 PaymentType（=1），
	 * 非前端輸入。無付款明細（callback 漏接）→ 視為非信用卡（不允許 API 退款）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_credit_order( \WC_Order $order ): bool {
		$detail = ( new PayuniUniEmbedMetaKeys( $order ) )->get_payment_detail();
		if ( ! $detail ) {
			return false;
		}
		return 1 === (int) ( $detail['PaymentType'] ?? 0 );
	}

	// endregion

	// region 買方信用卡 Token（綁卡授權後寫 credit_hash / credit_life；查詢 / 取消）

	/** @var int UseTokenType：強制約定（不可取消）。1=約定信用卡 / 2=記憶卡號（皆可取消，無需常數判斷） */
	private const USE_TOKEN_TYPE_FORCED = 3;

	/** @var int CreditToken 最大長度 */
	private const CREDIT_TOKEN_MAX_LENGTH = 150;

	/**
	 * 綁卡授權回傳處理（payuni_uni_embed_after_merchant_trade hook）
	 *
	 * PAYUNi merchant_trade 幕後授權成功後（信用卡且 UseTokenType≠0）回傳 CreditHash / CreditLife。
	 * 本 handler 以原 SDK_TOKEN 再次取得授權結果（複用 MerchantTradeClient，honor 測試 mock filter），
	 * 萃取 CreditHash / CreditLife 寫入 order meta。
	 *
	 * ⚠️ 安全硬約束（高風險點 #3）：order meta 只存 credit_hash + credit_life，
	 *    絕不存完整卡號（PAN）/ CVC（PAYUNi 本就不回傳卡號；此處僅取 CreditHash / CreditLife）。
	 *
	 * @param int $order_id       訂單 ID
	 * @param int $use_token_type UseTokenType（1 約定 / 2 記憶 / 3 強制約定）
	 * @return void
	 */
	public static function handle_after_merchant_trade( int $order_id, int $use_token_type ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== self::ID ) {
			return;
		}

		try {
			// 取得 merchant_trade 授權結果。
			// 優先 honor mock filter payuni_uni_embed_mock_merchant_trade_response（傳入 $use_token_type，
			// 供測試依綁卡型別回傳 CreditHash / CreditLife）；無 mock 時走 MerchantTradeClient::execute（生產路徑）。
			$mock   = \apply_filters(
				MerchantTradeClient::FILTER_MOCK_RESPONSE,
				null,
				$use_token_type
			);
			$result = \is_array( $mock )
			? $mock
			: ( new MerchantTradeClient() )->execute( $order );

			if ( MerchantTradeClient::is_failed( $result ) ) {
				return;
			}

			// 僅萃取 CreditHash / CreditLife（⚠️ 絕不存卡號 PAN / CVC；PAYUNi 本就不回傳卡號）
			$credit_hash = (string) ( $result['CreditHash'] ?? '' );
			$credit_life = (string) ( $result['CreditLife'] ?? '' );

			if ( '' === $credit_hash || '' === $credit_life ) {
				return; // 未綁卡（一般交易）→ 不寫 Token meta
			}

			$meta_keys = new PayuniUniEmbedMetaKeys( $order );
			$meta_keys->update_credit_hash( $credit_hash );
			$meta_keys->update_credit_life( $credit_life );
			$order->update_meta_data( '_pc_payuni_uni_use_token_type', (string) $use_token_type );
			$order->save_meta_data();
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"PAYUNi UNi Embed 綁卡授權結果寫入失敗 #{$order_id}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 驗證買方 CreditToken 格式（payuni_uni_embed_validate_credit_token filter）
	 *
	 * 規則：CreditToken 須符合 [A-Za-z0-9@.#$%_-]，長度 ≤150（不可含空白等非法字元）。
	 *
	 * @param mixed  $default_value  filter 預設值（傳入 null 由本 handler 接管）
	 * @param string $credit_token   待驗證的 CreditToken
	 * @param int    $use_token_type UseTokenType（保留供未來分流）
	 * @return bool|\WP_Error 合法回 true；不合法回 WP_Error
	 */
	public static function validate_credit_token( mixed $default_value, string $credit_token, int $use_token_type = 1 ): bool|\WP_Error {
		if ( 1 !== \preg_match( '/^[A-Za-z0-9@.#$%_-]{1,150}$/', $credit_token ) ) {
			return new \WP_Error(
				'invalid_credit_token',
				\__( 'CreditToken 格式不合法（僅允許 A-Za-z0-9@.#$%_- 且長度 ≤150）', 'power_checkout' )
			);
		}
		return true;
	}

	/**
	 * 驗證買方 CreditToken 長度（payuni_uni_embed_validate_credit_token_length filter）
	 *
	 * @param mixed  $default_value filter 預設值
	 * @param string $credit_token  待驗證的 CreditToken
	 * @return bool 長度 ≤150 回 true
	 */
	public static function validate_credit_token_length( mixed $default_value, string $credit_token ): bool {
		return \strlen( $credit_token ) <= self::CREDIT_TOKEN_MAX_LENGTH;
	}

	/**
	 * 判定買方 Token 是否過期（payuni_uni_embed_is_credit_token_expired filter）
	 *
	 * CreditLife 為 MMYY；以該月最後一日 23:59:59 為界，超過今日即過期 → 不可扣款，引導重新綁卡。
	 *
	 * @param mixed  $default_value filter 預設值
	 * @param string $credit_life   CreditLife（MMYY）
	 * @return bool 已過期回 true
	 */
	public static function is_credit_token_expired( mixed $default_value, string $credit_life ): bool {
		if ( 1 !== \preg_match( '/^(0[1-9]|1[0-2])\d{2}$/', $credit_life ) ) {
			return true; // 格式不合法視為過期（保守）
		}
		$month = (int) \substr( $credit_life, 0, 2 );
		$year  = 2000 + (int) \substr( $credit_life, 2, 2 );

		// 卡片有效至該月最後一日 23:59:59
		$last_day  = (int) \gmdate( 't', (int) \gmmktime( 0, 0, 0, $month, 1, $year ) );
		$expire_ts = (int) \gmmktime( 23, 59, 59, $month, $last_day, $year );

		return \time() > $expire_ts;
	}

	/**
	 * 查詢買方 Token 狀態（後台 / 對帳用）
	 *
	 * 優先 honor 測試 mock filter payuni_uni_embed_mock_credit_token_response；無 mock 時依本地
	 * credit_hash / credit_life 推導狀態（有效 / 過期）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> Token 狀態（含 TokenState / CreditLife）
	 */
	public static function query_credit_token( \WC_Order $order ): array {
		$mock = \apply_filters( 'payuni_uni_embed_mock_credit_token_response', null );
		if ( \is_array( $mock ) ) {
			/** @var array<string, mixed> $mock */
			return $mock;
		}

		$meta_keys   = new PayuniUniEmbedMetaKeys( $order );
		$credit_hash = $meta_keys->get_credit_hash();
		$credit_life = $meta_keys->get_credit_life();

		$token_state = 'invalid';
		if ( '' !== $credit_hash && '' !== $credit_life ) {
			$token_state = self::is_credit_token_expired( null, $credit_life ) ? 'expired' : 'valid';
		}

		return [
			'Status'     => 'SUCCESS',
			'TokenState' => $token_state,
			'CreditHash' => $credit_hash,
			'CreditLife' => $credit_life,
		];
	}

	/**
	 * 取消買方 Token（清除 credit_hash / credit_life）
	 *
	 * 守衛：UseTokenType=3（強制約定）不可取消 → 回 WP_Error，Token 仍有效。
	 * UseTokenType=1 / 2 可取消：honor mock filter payuni_uni_embed_mock_cancel_token_response，
	 * 成功後清除本地 credit_hash / credit_life。
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool|\WP_Error 成功回 true；UseTokenType=3 回 WP_Error
	 */
	public static function cancel_credit_token( \WC_Order $order ): bool|\WP_Error {
		$use_token_type = (int) $order->get_meta( '_pc_payuni_uni_use_token_type' );

		// UseTokenType=3（強制約定）不可取消
		if ( self::USE_TOKEN_TYPE_FORCED === $use_token_type ) {
			return new \WP_Error(
				'token_cancel_forbidden',
				\__( '強制約定（UseTokenType=3）的買方 Token 不可取消', 'power_checkout' )
			);
		}

		try {
			// honor mock filter（測試）；無 mock 時視為成功（本地清除即可）
			\apply_filters( 'payuni_uni_embed_mock_cancel_token_response', null );

			$meta_keys = new PayuniUniEmbedMetaKeys( $order );
			$meta_keys->update_credit_hash( '' );
			$meta_keys->update_credit_life( '' );

			$order->add_order_note( '✅ PAYUNi UNi Embed 買方 Token 已取消（credit_hash / credit_life 已清除）' );
			return true;
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"PAYUNi UNi Embed 買方 Token 取消失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
			return new \WP_Error( 'token_cancel_failed', $e->getMessage() );
		}
	}

	// endregion

	// region 設定

	/**
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new PayuniUniEmbedSettingsDTO() )->to_array();
			$unset_keys    = [ 'merchant_id', 'hash_key', 'hash_iv', 'token_get_url' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return PayuniUniEmbedSettingsDTO::instance()->to_array();
	}

	/**
	 * [後台] 自訂欄位驗證邏輯（min/max 金額）
	 *
	 * @return bool was anything saved?
	 * @see WC_Settings_API::process_admin_options
	 */
	public function process_admin_options(): bool {
		$min_amount_name = $this->get_field_key( 'min_amount' );
		$max_amount_name = $this->get_field_key( 'max_amount' );

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

	/**
	 * 初始化
	 *
	 * Cycle 2（Phase 06）：註冊前端 create-payment REST 端點（order_key 驗證）——
	 *   前端內嵌元件取得綁定結果後 POST 回此端點觸發 merchant_trade 幕後授權。
	 * Cycle 3（Phase 07）：註冊 NotifyURL callback REST 端點（驗章在 callback 內以 HashInfo + MerID 把關）——
	 *   PAYUNi 3D 完成 / 幕後授權結果以 server-to-server Form POST 回打此端點（source of truth）。
	 * Cycle 4（Phase 08）：後台訂單操作（查詢補單 / 請款 / 取消授權，pc_payuni_uni_*）+
	 *   綁卡授權回傳處理（after_merchant_trade hook）+ 買方 Token 格式 / 過期驗證 filter。
	 *
	 * @return void
	 */
	public static function init(): void {
		// 前端內嵌元件取得綁定結果後 POST 回此端點觸發 merchant_trade（order_key 驗證）
		PayuniUniEmbedFrontendApi::register_hooks();
		// NotifyURL 幕後通知（source of truth；驗章在 callback 內完成，permission_callback __return_true）
		PayuniUniEmbedCallback::register_hooks();

		// 後台訂單操作：查詢補單（對帳）/ 信用卡請款（Close CloseType=1）/ 信用卡取消授權（Cancel）
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_QUERY, [ __CLASS__, 'handle_query_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CAPTURE, [ __CLASS__, 'handle_capture_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CANCEL_AUTH, [ __CLASS__, 'handle_cancel_auth_action' ] );

		// 綁卡授權回傳處理（after_merchant_trade）已於 constructor 隨 gateway 實例化註冊（不依賴啟用）。

		// 買方 Token 格式 / 長度 / 過期驗證 filter（供綁卡前置驗證與扣款前過期檢查）
		\add_filter( 'payuni_uni_embed_validate_credit_token', [ __CLASS__, 'validate_credit_token' ], 10, 3 );
		\add_filter( 'payuni_uni_embed_validate_credit_token_length', [ __CLASS__, 'validate_credit_token_length' ], 10, 2 );
		\add_filter( 'payuni_uni_embed_is_credit_token_expired', [ __CLASS__, 'is_credit_token_expired' ], 10, 2 );
	}
}
