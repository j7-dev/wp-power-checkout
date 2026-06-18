<?php
/**
 * PayNow 立吉富電子發票服務提供者（體系 3）
 *
 * 與 Amego / 綠界 / ezPay 並列，後台可切換。實作三介面：
 *   IInvoiceService     issue / cancel / get_invoice_number / get_settings
 *   ISupportsAllowance  issue_allowance / invalid_allowance（部分退款開 / 作廢折讓）
 *   ISupportsQuery      query_invoice（唯讀查詢，不改任何 meta / 狀態）
 *
 * 正規化錯誤模型（einvoice 導入第四階段-d，照 ezPay 參考模板）：
 *   所有對外動作冪等（已有對應 meta 直接回 array，不重打 API、不驗證），且 catch \Throwable →
 *   logger(error, 同步 order note) → 回經 {@see NormalizedError::from()} 建立的 \WP_Error，
 *   **絕不向 WooCommerce hook 拋例外**（never-throw 鐵律）。成功仍回 array（既有契約不變）。
 *
 *   失敗回傳分流：
 *     - issue() 第一步先跑 {@see InvoiceParamsValidator::validate_for_dispatch()}，回 WP_Error 即直接 return，不打第三方 API。
 *     - client 回 null 後讀 {@see InvoiceApiClient::get_last_error_detail()}，交 {@see self::map_error()} 做權威映射。
 *     - PayNow 發票純 Bearer JWT（無對稱簽章 / CheckCode），認證類錯誤一律 AUTH；驗章類正常不會出現。
 *     - 前置狀態（未開立 / 無折讓資料）→ NOT_FOUND；已開折讓擋作廢 → CONFLICT；catch \Throwable → UNKNOWN。
 *     - PayNow 業務失敗以「外層 type」為 raw_code（validation_error / rejected / failed），message 做關鍵字補強。
 *
 * ⚠️ R5 最關鍵裁決：const ID = 'paynow_invoice'（非金流 'paynow'），對應 WC option key
 * `woocommerce_paynow_invoice_settings`，與金流 `woocommerce_paynow_settings` 完全分離。
 *
 * ⚠️ meta 鍵名（對齊 PayNow 發票測試契約，與 ezPay / ECPay 刻意不同）：
 *   issued_data    invoice_number（snake_case）
 *   allowance_data allowance_number（PayNow snake_case，非 ezPay 的 allowance_no）
 *
 * ⚠️ 與 ezPay 實作差異（PayNow InvoiceApiClient 契約）：
 *   - issue()             由 client 內部負責寫入 issued_data + provider_id meta（成功時）。
 *   - cancel()            client 不寫 meta；本 provider 負責寫 cancelled_data。
 *   - allowance()         client 收 AllowanceParams（本 provider 以 from_issued_data 自組）。
 *   - invalid_allowance() client 收 allowance_data 陣列；本 provider 負責成功後清除 allowance_data。
 *   - query()             client 收 QueryParams（本 provider 以 from_issued_data 自組）；唯讀。
 *
 * @see .claude/skills/paynow/references/invoice-api.md
 * @see specs/features/invoice/paynow-invoice-issue.feature
 * @see specs/features/invoice/paynow-invoice-allowance.feature
 * @see specs/features/invoice/paynow-invoice-query.feature
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Services;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\PaynowInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Paynow\Http\PaynowInvoiceApiException;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams as CheckoutInvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\InvoiceParamsValidator;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/** PayNow 立吉富電子發票服務提供者 */
final class PaynowInvoiceProvider extends BaseService implements IInvoiceService, ISupportsAllowance, ISupportsQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 服務 ID
	 *
	 * R5 裁決：發票 provider id 固定為 'paynow_invoice'，與金流 gateway 'paynow' 分離，
	 * 確保 WC option key 不互撞（woocommerce_paynow_invoice_settings vs woocommerce_paynow_settings）。
	 *
	 * @var string
	 */
	public const ID = 'paynow_invoice';

	/**
	 * 記錄 log（info / error / warning 會同步記錄到 order note）
	 *
	 * @param string               $message     訊息.
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice.
	 * @param array<string, mixed> $args        附加資訊.
	 * @param int                  $trace_limit 追蹤堆疊層數.
	 * @param \WC_Order|null       $order       是否紀錄在 order note.
	 *
	 * @return void
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], int $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if ( ! $order ) {
			return;
		}

		if ( $args ) {
			$message .= "<p style='margin-bottom: 0;'>&nbsp;</p>";
		}

		$order_note = WP::array_to_html( $args, [ 'title' => $message ] );
		$order->add_order_note( $order_note );
	}

	/**
	 * 開立發票（冪等）
	 *
	 * 流程：冪等（已開立直接回 array）→ dispatch 級統一驗證（失敗回 VALIDATION，不打 API）→
	 * client 開立 → 成功回整理後陣列；client 回 null 則讀錯誤明細經 map_error 映射；catch \Throwable → UNKNOWN。
	 * 實際開立、IssueParams 驗證（載具/捐贈互斥、零稅率必填原因）與 issued_data + provider_id meta 寫入
	 * 皆由 InvoiceApiClient::issue() 負責。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回開立資料 array；失敗回正規化 \WP_Error.
	 */
	public function issue( \WC_Order|int $order_or_id ): array|\WP_Error {
		try {
			// 解析訂單在 try 內：OrderUtils::get_order 對不存在訂單 throw，須由 catch 收成 UNKNOWN（never-throw）.
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			// region 冪等：已開立過直接回傳（不驗證、不打 API）.
			$meta_keys   = new MetaKeys( $order );
			$issued_data = $meta_keys->get_issued_data();
			if ( \is_array( $issued_data ) && $issued_data ) {
				/** @var array<string, mixed> $issued_data */
				return $issued_data;
			}
			// endregion 冪等.

			// region 統一驗證層：issue 第一步，失敗即回 VALIDATION，不打第三方 API.
			$dispatch_error = InvoiceParamsValidator::validate_for_dispatch( self::build_dispatch_params( $order ) );
			if ( $dispatch_error instanceof \WP_Error ) {
				self::logger(
					"開立發票前置驗證失敗 #{$order->get_id()}：{$dispatch_error->get_error_message()}",
					'warning',
					[],
					0,
					$order
				);
				return $dispatch_error;
			}
			// endregion 統一驗證層.

			$client   = new InvoiceApiClient( $order );
			$response = $client->issue( self::ID );

			if ( ! $response instanceof IssueResponse ) {
				return self::error_from_client( $client, '開立發票失敗', $order );
			}

			// client 已寫入 issued_data + provider_id meta；回傳整理後的開立資料供呼叫端使用.
			$result = [
				'invoice_number' => $response->invoice_number,
				'invoice_date'   => $response->invoice_date,
				'order_no'       => $response->order_no,
				'total_amount'   => $response->total_amount,
			];

			self::logger(
				"✅ PayNow 開立發票成功 #{$order->get_id()}",
				'info',
				[ 'invoice_number' => $response->invoice_number ],
				0,
				$order
			);

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '開立發票失敗', $e, $order ?? null );
		}
	}

	/**
	 * 作廢發票（冪等）
	 *
	 * 已作廢過直接回傳 cancelled_data。前置：已開折讓（有 allowance_data）時拒絕作廢
	 * （須先作廢折讓），不浪費 API，且不清除 issued_data。成功後清除開立資料、寫入作廢資料。
	 *
	 * PayNow InvoiceApiClient::cancel() 不寫任何 meta（作廢請求帶 issued_data 的 invoice_number），
	 * 故 cancelled_data 與 issued_data 清理皆由本 provider 負責。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢資料 array；失敗回正規化 \WP_Error.
	 */
	public function cancel( \WC_Order|int $order_or_id ): array|\WP_Error {
		try {
			// 解析訂單在 try 內：不存在訂單由 catch 收成 UNKNOWN（never-throw）.
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			// region 冪等：已作廢過直接回傳.
			$meta_keys      = new MetaKeys( $order );
			$cancelled_data = $meta_keys->get_cancelled_data();
			if ( $cancelled_data ) {
				return $cancelled_data;
			}
			// endregion 冪等.

			// 前置：已開折讓時不可作廢發票 —— 先擋（CONFLICT），不浪費 API、不清除 issued_data.
			if ( $meta_keys->get_allowance_data() ) {
				self::logger(
					"作廢發票失敗 #{$order->get_id()}：已開立折讓，須先作廢折讓",
					'warning',
					[],
					0,
					$order
				);
				return NormalizedError::from(
					ErrorCode::CONFLICT,
					\__( '此發票已開立折讓，須先作廢折讓才能作廢發票', 'power_checkout' ),
					[
						'provider'    => self::ID,
						'raw_message' => '已開立折讓無法作廢發票',
					]
				);
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->cancel();

			if ( ! $response instanceof IssueResponse || ! $response->is_success() ) {
				// 作廢失敗一律不清除 issued_data（保留可重試）.
				return self::error_from_client( $client, '作廢發票失敗', $order );
			}

			$result = [
				'status'         => 'cancelled',
				'invoice_number' => $response->invoice_number,
				'rtn_msg'        => '作廢成功',
			];

			// 成功作廢：先清除開立資料（含 provider_id），再寫入作廢資料.
			$meta_keys->clear_data();
			$meta_keys->update_cancelled_data( $result );

			self::logger( "✅ PayNow 作廢發票成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '作廢發票失敗', $e, $order ?? null );
		}
	}

	/**
	 * 開立折讓（部分退款開折讓單，冪等）
	 *
	 * 前置：發票須已開立（有 issued_data 的 invoice_number）。折讓金額須 > 0 且 ≤ 原發票總額。
	 * 同一訂單已有折讓資料時直接回傳（冪等）。折讓即時確認，不做兩段式。
	 * client 回 null（type≠success / 驗證失敗）→ 本方法回 []，不寫 allowance_data。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @param float         $amount      折讓金額（含稅）.
	 * @param string        $notify_mail 折讓通知 Email（PayNow 折讓不使用，保留介面相容）.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回折讓資料 array；失敗回正規化 \WP_Error.
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array|\WP_Error {
		unset( $notify_mail ); // PayNow 折讓不使用此參數（保留 ISupportsAllowance 介面簽章相容）.

		try {
			// 解析訂單在 try 內：不存在訂單由 catch 收成 UNKNOWN（never-throw）.
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			$meta_keys = new MetaKeys( $order );

			// region 冪等：已開折讓直接回傳.
			$allowance_data = $meta_keys->get_allowance_data();
			if ( $allowance_data ) {
				return $allowance_data;
			}
			// endregion 冪等.

			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 前置：須已開立發票（否則 NOT_FOUND，不打 API）.
			if ( empty( $issued_data['invoice_number'] ) ) {
				self::logger( "開立折讓失敗 #{$order->get_id()}：發票尚未開立", 'warning', [], 0, $order );
				return NormalizedError::from(
					ErrorCode::NOT_FOUND,
					\__( '尚未開立發票，無法開立折讓', 'power_checkout' ),
					[ 'provider' => self::ID ]
				);
			}

			// 金額驗證：> 0 且 ≤ 原發票總額（否則 VALIDATION）.
			$allowance_amount = (int) \round( $amount );
			$order_total      = (int) \round( (float) $order->get_total() );
			if ( $allowance_amount <= 0 || $allowance_amount > $order_total ) {
				self::logger(
					"開立折讓失敗 #{$order->get_id()}：折讓金額 {$allowance_amount} 不合法（須 1 ~ {$order_total}）",
					'warning',
					[],
					0,
					$order
				);
				return NormalizedError::from(
					ErrorCode::VALIDATION,
					\sprintf(
						/* translators: 1: 折讓金額, 2: 訂單總額 */
						\__( '折讓金額 %1$d 不合法（須介於 1 ~ %2$d）', 'power_checkout' ),
						$allowance_amount,
						$order_total
					),
					[
						'provider'    => self::ID,
						'raw_message' => (string) $allowance_amount,
					]
				);
			}

			$params   = AllowanceParams::from_issued_data( $issued_data, $allowance_amount );
			$client   = new InvoiceApiClient( $order );
			$response = $client->allowance( $params );

			if ( ! $response instanceof AllowanceResponse ) {
				// 業務 / 連線失敗 → 經 map_error 映射；不寫 allowance_data.
				return self::error_from_client( $client, '開立折讓失敗', $order );
			}

			$result = [
				'allowance_number' => $response->allowance_number,
				'allowance_amount' => $allowance_amount,
				'invoice_number'   => (string) $issued_data['invoice_number'],
				'remain_amount'    => $response->remain_amount,
			];
			$meta_keys->update_allowance_data( $result );

			self::logger(
				"✅ PayNow 開立折讓成功 #{$order->get_id()}",
				'info',
				[ 'allowance_number' => $response->allowance_number ],
				0,
				$order
			);

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '開立折讓失敗', $e, $order ?? null );
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data，含 allowance_number，否則 NOT_FOUND）。成功後清除 allowance_data，
	 * 使後續可重新開立折讓。無折讓資料時不打 API。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢結果 array；失敗回正規化 \WP_Error.
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array|\WP_Error {
		try {
			// 解析訂單在 try 內：不存在訂單由 catch 收成 UNKNOWN（never-throw）.
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			$meta_keys      = new MetaKeys( $order );
			$allowance_data = $meta_keys->get_allowance_data();

			// 無折讓資料：無從作廢（NOT_FOUND）.
			if ( ! $allowance_data ) {
				return NormalizedError::from(
					ErrorCode::NOT_FOUND,
					\__( '查無已開立折讓，無法作廢折讓', 'power_checkout' ),
					[ 'provider' => self::ID ]
				);
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->invalid_allowance( $allowance_data );

			if ( ! $response instanceof AllowanceResponse || ! $response->is_success() ) {
				return self::error_from_client( $client, '作廢折讓失敗', $order );
			}

			$result = [
				'status'  => 'allowance_invalid',
				'rtn_msg' => '折讓作廢成功',
			];

			// 成功作廢折讓：清除折讓資料.
			$meta_keys->clear_allowance_data();

			self::logger( "✅ PayNow 作廢折讓成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '作廢折讓失敗', $e, $order ?? null );
		}
	}

	/**
	 * 查詢發票明細（唯讀，GET /api/invoices）
	 *
	 * 以已開立發票 meta 查詢；未開立則回 NOT_FOUND 且「不打 API」。回傳跨 provider 一致的標準化鍵
	 * （invoice_number / invoice_status / total_amount …）。本方法為純唯讀：不寫任何 meta、不改訂單狀態。
	 * client 回 null（type≠success）時經 map_error 映射。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回發票明細 array；查無或失敗回正規化 \WP_Error.
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array|\WP_Error {
		try {
			// 解析訂單在 try 內：不存在訂單由 catch 收成 UNKNOWN（never-throw）.
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			$meta_keys   = new MetaKeys( $order );
			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 未開立發票：不打 API，直接回 NOT_FOUND.
			if ( empty( $issued_data['invoice_number'] ) ) {
				return NormalizedError::from(
					ErrorCode::NOT_FOUND,
					\__( '尚未開立發票，無可查詢的發票', 'power_checkout' ),
					[ 'provider' => self::ID ]
				);
			}

			$params   = QueryParams::from_issued_data( $issued_data );
			$client   = new InvoiceApiClient( $order );
			$response = $client->query( $params );

			if ( ! $response instanceof QueryResponse ) {
				return self::error_from_client( $client, '發票查詢失敗', $order, false );
			}

			return $response->to_array();
		} catch ( \Throwable $e ) {
			return self::unknown_error( '發票查詢失敗', $e, $order ?? null, false );
		}
	}

	/**
	 * 取得發票號碼
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return string 發票號碼；未開立回空字串.
	 */
	public function get_invoice_number( \WC_Order $order ): string {
		$meta_keys   = new MetaKeys( $order );
		$issued_data = $meta_keys->get_issued_data();
		if ( \is_array( $issued_data ) && isset( $issued_data['invoice_number'] ) ) {
			return (string) $issued_data['invoice_number'];
		}
		return '';
	}

	/**
	 * 取得設定
	 *
	 * @param bool $with_default 是否帶預設值（false = 只拿 DB 值）.
	 *
	 * @return array<string, mixed> 設定.
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$option = ProviderUtils::get_option( self::ID );
			return \is_array( $option ) ? $option : [];
		}
		return PaynowInvoiceSettingsDTO::instance()->to_array();
	}

	// ========================================================================
	// 正規化錯誤模型 helper（照 ezPay 4 provider 模板）
	// ========================================================================

	/**
	 * 將 PayNow 原始錯誤映射為正規化 {@see ErrorCode}（未涵蓋 fallthrough → PROVIDER，保留 raw_code 供 debug）
	 *
	 * 與 ezPay 模板的差異：PayNow 發票 API 官方**未提供數字錯誤碼對照表**，僅以外層 `type` + `message`
	 * 表達失敗（error-codes.md §10）。故：
	 *   - $raw_code 為「外層 type」（validation_error / rejected / failed …）作主分類。
	 *   - $raw_message（外層 message）做關鍵字補強分類（認證 / 衝突 / 查無 / 字軌用罄）。
	 *   - PayNow 純 Bearer JWT、無對稱簽章 → 認證類一律 AUTH（SIGNATURE 由 client kind 分流，正常不會出現）。
	 *
	 * 驗章（SIGNATURE）/ 連線（NETWORK）由 client 的 kind 在 {@see self::error_from_client()} 先行分流，
	 * 不在本 mapper 內（本 mapper 只負責「業務 type + message」→ ErrorCode）。
	 *
	 * @param string $raw_code    PayNow 原始錯誤型別（外層 type 值）.
	 * @param string $raw_message PayNow 原始錯誤訊息（外層 message；關鍵字補強分類用）.
	 *
	 * @return ErrorCode 正規化錯誤碼.
	 */
	private static function map_error( string $raw_code, string $raw_message = '' ): ErrorCode {
		$type = \strtolower( \trim( $raw_code ) );
		$msg  = $raw_message;

		return match ( true ) {
			// 外層 type 即驗證錯誤 → VALIDATION.
			'validation_error' === $type => ErrorCode::VALIDATION,

			// 認證類（PayNow 用 Bearer JWT，token / 認證 / 授權失敗）→ AUTH.
			self::message_matches( $msg, [ 'jwt', 'token', 'unauthorized', 'unauthori', 'forbidden', '認證', '授權', '權限', '金鑰', '簽章' ] ) => ErrorCode::AUTH,

			// 狀態衝突（重複開立 / 已開立 / 已作廢 / 已折讓）→ CONFLICT.
			self::message_matches( $msg, [ 'duplicate', 'already', 'exist', '重複', '已開立', '已作廢', '已折讓', '已存在' ] ) => ErrorCode::CONFLICT,

			// 字軌號碼用罄 → NUMBER_EXHAUSTED.
			self::message_matches( $msg, [ 'exhaust', '用罄', '用完', '字軌', '號碼不足' ] ) => ErrorCode::NUMBER_EXHAUSTED,

			// 查無發票 / 資源不存在 → NOT_FOUND.
			self::message_matches( $msg, [ 'not found', 'notfound', '查無', '不存在', '找不到' ] ) => ErrorCode::NOT_FOUND,

			// 其餘未涵蓋（含 rejected / failed 無關鍵字）→ PROVIDER（保留 raw_code 供 debug）.
			default => ErrorCode::PROVIDER,
		};
	}

	/**
	 * 訊息關鍵字比對（大小寫不敏感；任一關鍵字命中即 true）
	 *
	 * @param string        $message  PayNow 原始錯誤訊息.
	 * @param array<string> $keywords 關鍵字清單（小寫 / 中文）.
	 *
	 * @return bool 命中回 true.
	 */
	private static function message_matches( string $message, array $keywords ): bool {
		if ( '' === $message ) {
			return false;
		}
		$lower = \strtolower( $message );
		foreach ( $keywords as $keyword ) {
			if ( '' !== $keyword && \str_contains( $lower, \strtolower( $keyword ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 由 client 落地的錯誤明細建立正規化 \WP_Error
	 *
	 * 分流（模板）：
	 *   kind=signature → SIGNATURE（PayNow 發票正常不會出現；保留以對齊模板）
	 *   kind=network   → NETWORK（連線 / 逾時）
	 *   kind=business  → {@see self::map_error()}(type, message)（業務錯誤權威映射）
	 *   kind=decode / 無明細 → PROVIDER（未分類 / 結構解析失敗）
	 *
	 * @param InvoiceApiClient $client       已執行過業務方法的 client（其 get_last_error_detail 攜帶失敗明細）.
	 * @param string           $action_label 動作中文標籤（log / 訊息用）.
	 * @param \WC_Order        $order        訂單（記 order note）.
	 * @param bool             $log_to_order 是否同步記 order note（查詢為唯讀，預設不寫；其餘寫）.
	 *
	 * @return \WP_Error 正規化錯誤.
	 */
	private static function error_from_client( InvoiceApiClient $client, string $action_label, \WC_Order $order, bool $log_to_order = true ): \WP_Error {
		$detail = $client->get_last_error_detail() ?? [
			'raw_code'    => '',
			'raw_message' => '',
			'raw'         => '',
			'kind'        => PaynowInvoiceApiException::KIND_DECODE,
		];

		// $detail 為 client 保證的 4 鍵字串結構，鍵恆存在.
		$raw_code    = $detail['raw_code'];
		$raw_message = $detail['raw_message'];
		$kind        = $detail['kind'];

		$code = match ( $kind ) {
			PaynowInvoiceApiException::KIND_SIGNATURE => ErrorCode::SIGNATURE,
			PaynowInvoiceApiException::KIND_NETWORK   => ErrorCode::NETWORK,
			PaynowInvoiceApiException::KIND_BUSINESS  => self::map_error( $raw_code, $raw_message ),
			default                                   => ErrorCode::PROVIDER,
		};

		$message = self::user_message( $code, $action_label );

		self::logger(
			"❌ PayNow {$action_label} #{$order->get_id()}：{$code->value}" . ( '' !== $raw_code ? "（{$raw_code}）" : '' ),
			'error',
			[ 'raw_code' => $raw_code ],
			0,
			$log_to_order ? $order : null
		);

		return NormalizedError::from(
			$code,
			$message,
			[
				'provider'    => self::ID,
				'raw_code'    => '' === $raw_code ? null : $raw_code,
				'raw_message' => '' === $raw_message ? null : $raw_message,
				'raw'         => '' === $detail['raw'] ? null : $detail['raw'],
			]
		);
	}

	/**
	 * 由攔截到的 \Throwable 建立 UNKNOWN 正規化錯誤（never-throw 鐵律落點）
	 *
	 * @param string         $action_label 動作中文標籤.
	 * @param \Throwable     $e            攔截到的例外.
	 * @param \WC_Order|null $order        訂單（記 order note）；訂單不存在時為 null，僅記 log.
	 * @param bool           $log_to_order 是否同步記 order note（查詢唯讀預設不寫）.
	 *
	 * @return \WP_Error code=UNKNOWN 的正規化錯誤.
	 */
	private static function unknown_error( string $action_label, \Throwable $e, ?\WC_Order $order, bool $log_to_order = true ): \WP_Error {
		$order_ref = $order ? "#{$order->get_id()}" : '#unknown';
		self::logger(
			"{$action_label} {$order_ref}： {$e->getMessage()}",
			'error',
			[],
			5,
			( $log_to_order && $order ) ? $order : null
		);

		return NormalizedError::from(
			ErrorCode::UNKNOWN,
			self::user_message( ErrorCode::UNKNOWN, $action_label ),
			[
				'provider'    => self::ID,
				'raw_message' => $e->getMessage(),
				'raw'         => $e->getMessage(),
			]
		);
	}

	/**
	 * 依正規化 code 產生使用者可讀訊息（text domain：power_checkout）
	 *
	 * @param ErrorCode $code         正規化錯誤碼.
	 * @param string    $action_label 動作中文標籤（如「開立發票」）.
	 *
	 * @return string 使用者可讀訊息.
	 */
	private static function user_message( ErrorCode $code, string $action_label ): string {
		$reason = match ( $code ) {
			ErrorCode::AUTH             => \__( '商店 JWT-Token 或認證錯誤', 'power_checkout' ),
			ErrorCode::VALIDATION       => \__( '發票參數驗證未通過', 'power_checkout' ),
			ErrorCode::NOT_FOUND        => \__( '查無對應的發票資料', 'power_checkout' ),
			ErrorCode::CONFLICT         => \__( '發票狀態衝突', 'power_checkout' ),
			ErrorCode::NUMBER_EXHAUSTED => \__( '發票字軌號碼已用罄，請於平台新增字軌', 'power_checkout' ),
			ErrorCode::SIGNATURE        => \__( '回應驗章失敗', 'power_checkout' ),
			ErrorCode::NETWORK          => \__( '連線發票服務逾時或失敗', 'power_checkout' ),
			ErrorCode::PROVIDER         => \__( '發票服務回傳未分類錯誤', 'power_checkout' ),
			default                     => \__( '發生未預期錯誤', 'power_checkout' ),
		};

		return \sprintf( '%1$s：%2$s', $action_label, $reason );
	}

	/**
	 * 從訂單 + 結帳發票資訊組「dispatch 級統一驗證」的參數
	 *
	 * 鏡像 {@see IssueParams::from_order()} 的金額分流，輸出
	 * {@see InvoiceParamsValidator::validate_for_dispatch()} 期望的鍵：
	 *   provider / companyId / carrier / donateCode / salesAmount / taxAmount / totalAmount。
	 * 金額一律以 `$order->get_total()`（含稅實付）為錨點：
	 *   totalAmount = grand、salesAmount = round(grand / 1.05)、taxAmount = grand − salesAmount。
	 *
	 * 第一性原理：dispatch 驗證是「防偽 / 防不一致」的最後一道，故 companyId / carrier / donateCode
	 * **原樣**取自結帳 params（不依 invoiceType 篩選）——若 params 被竄改成「同時帶載具與捐贈」，
	 * 互斥不變式才攔得到；B2C 一般情境這三者本就為空字串，不影響正常開立。
	 *
	 * @param \WC_Order $order 訂單.
	 *
	 * @return array<string, mixed> dispatch 驗證參數.
	 */
	private static function build_dispatch_params( \WC_Order $order ): array {
		$grand = (int) \round( (float) $order->get_total() );
		$sales = (int) \round( $grand / 1.05 );
		$tax   = $grand - $sales;

		$company_id  = '';
		$carrier     = '';
		$donate_code = '';

		$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
		if ( \is_array( $issue_params ) && $issue_params ) {
			$args = CheckoutInvoiceParams::create( $issue_params );

			// 原樣取值（防偽 / 防不一致）：互斥與 checksum 不變式套用在 params 實際內容上.
			$company_id  = $args->companyId;
			$carrier     = $args->carrier;
			$donate_code = $args->donateCode;
		}

		return [
			'provider'    => self::ID,
			'companyId'   => $company_id,
			'carrier'     => $carrier,
			'donateCode'  => $donate_code,
			'salesAmount' => $sales,
			'taxAmount'   => $tax,
			'totalAmount' => $grand,
		];
	}
}
