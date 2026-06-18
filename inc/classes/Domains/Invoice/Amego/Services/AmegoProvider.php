<?php
/**
 * 光貿 Amego 電子發票服務提供者
 *
 * 與 ezPay / 綠界 / PayNow 並列，後台可切換。實作三介面：
 *   IInvoiceService     issue / cancel / get_invoice_number / get_settings
 *   ISupportsAllowance  issue_allowance / invalid_allowance（部分退款開 / 作廢折讓）
 *   ISupportsQuery      query_invoice（唯讀查詢財政部上傳狀態）
 *
 * 正規化錯誤模型（einvoice 導入第四階段-c，照 ezPay 參考模板套用）：
 *   Amego 原本是 4 家中失敗處理最弱的（issue/cancel 用 `?? []` null 合併、無顯式 try/catch、
 *   無顯式冪等檢查）。本次比照 ezPay：所有對外動作冪等（已有對應 meta 直接回 array，不重打 API、
 *   不驗證），且 catch \Throwable → logger（同步 order note）→ 回經 {@see NormalizedError::from()}
 *   建立的 \WP_Error，**絕不向 WooCommerce hook 拋例外**（never-throw 鐵律）。成功仍回 array（既有契約不變）。
 *
 *   失敗回傳改寫指引（沿用 ezPay 形狀）：
 *     - issue() 第一步先跑 {@see InvoiceParamsValidator::validate_for_dispatch()}，回 WP_Error 即直接 return，不打第三方 API。
 *     - client（{@see ApiClient}）回 null 後讀 {@see ApiClient::get_last_error_detail()}（委派給注入的 Requester），
 *       交 {@see self::map_error()} 做權威映射。
 *     - 前置狀態（未開立 / 無折讓資料）→ NOT_FOUND；catch \Throwable → UNKNOWN。
 *
 * ⚠️ meta 鍵名與 ezPay 刻意不同（對齊 Amego 既有契約）：
 *   issued_data    invoice_number / invoice_time / random_number（非 ezPay 的 random_num / invoice_date）
 *   allowance_data allowance_number（非 ezPay 的 allowance_no） / allowance_amount
 *
 * @see .claude/skills/amego-invoice/references/error-codes.md
 * @see https://invoice.amego.tw/api_doc/
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Services;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AllowanceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\CancelAllowanceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\InvoiceQueryParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Http\AmegoApiException;
use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
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

/** 光貿電子發票服務提供者 */
final class AmegoProvider extends BaseService implements IInvoiceService, ISupportsAllowance, ISupportsQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const ID = 'amego';

	/**
	 * 記錄 log
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param \WC_Order|null       $order       是否紀錄在 order note
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], int $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if (!$order) {
			return;
		}

		if ($args) {
			$message .= "<p style='margin-bottom: 0;'>&nbsp;</p>";
		}

		$order_note = WP::array_to_html( $args, [ 'title' => $message ] );
		$order->add_order_note( $order_note );
	}

	/**
	 * 開立發票（冪等）
	 *
	 * 流程：冪等（已開立直接回 array）→ dispatch 級統一驗證（失敗回 VALIDATION，不打 API）→
	 * client 開立 → 成功回 DTO 整理後陣列；client 回 null 則讀錯誤明細經 map_error 映射；catch \Throwable → UNKNOWN。
	 * 實際開立與 meta 寫入由 {@see ApiClient::issue()} 負責。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回開立資料 array；失敗回正規化 \WP_Error
	 */
	public function issue( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id);

		// region 冪等：已開立過直接回傳（不驗證、不打 API）.
		$meta_keys   = new MetaKeys( $order);
		$issued_data = $meta_keys->get_issued_data();
		if (\is_array($issued_data) && $issued_data) {
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

		try {
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$result    = $client->issue( self::ID );

			if ( ! $result instanceof IssueInvoiceResponseDTO ) {
				return self::error_from_client( $client, '開立發票失敗', $order );
			}

			// client 已寫入 issued_data + provider_id meta；回傳 DTO 陣列供呼叫端使用.
			$result_array = $result->to_array();

			self::logger(
				"✅ Amego 開立發票成功 #{$order->get_id()}",
				'info',
				[ 'invoice_number' => $result->invoice_number ],
				0,
				$order
			);

			return $result_array;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '開立發票失敗', $e, $order );
		}
	}

	/**
	 * 作廢發票（冪等）
	 *
	 * 已作廢過直接回傳 cancelled_data。成功後由 {@see ApiClient::cancel()} 清除開立資料並寫入作廢資料；
	 * 作廢失敗一律不清除 issued_data（保留可重試）。client 回 null 經 map_error 映射；catch \Throwable → UNKNOWN。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢資料 array；失敗回正規化 \WP_Error
	 */
	public function cancel( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id);

		// region 冪等：已作廢過直接回傳.
		$meta_keys      = new MetaKeys( $order);
		$cancelled_data = $meta_keys->get_cancelled_data();
		if ($cancelled_data) {
			return $cancelled_data;
		}
		// endregion 冪等.

		try {
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$result    = $client->cancel();

			if ( ! $result instanceof IssueInvoiceResponseDTO ) {
				// 作廢失敗一律不清除 issued_data（保留可重試）.
				return self::error_from_client( $client, '作廢發票失敗', $order );
			}

			self::logger( "✅ Amego 作廢發票成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result->to_array();
		} catch ( \Throwable $e ) {
			return self::unknown_error( '作廢發票失敗', $e, $order );
		}
	}

	/**
	 * 開立折讓（部分退款開折讓單，冪等）
	 *
	 * 前置：發票須已開立（有 issued_data.invoice_number，否則 NOT_FOUND）。折讓金額須 > 0 且 ≤ 原發票金額（否則 VALIDATION）。
	 * 同一訂單已有折讓資料時直接回傳（冪等）。client 回 null 經 map_error 映射；catch \Throwable → UNKNOWN。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 * @param float         $amount      折讓金額（含稅）
	 * @param string        $notify_mail 折讓通知 Email（空字串不通知）
	 *
	 * @return array<string, mixed>|\WP_Error 成功回折讓資料 array；失敗回正規化 \WP_Error
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys = new MetaKeys( $order );

		// region 冪等：已開折讓直接回傳.
		$allowance_data = $meta_keys->get_allowance_data();
		if ($allowance_data) {
			return $allowance_data;
		}
		// endregion 冪等.

		try {
			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 前置：須已開立發票（否則 NOT_FOUND）.
			if (empty( $issued_data['invoice_number'] )) {
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
			if ($allowance_amount <= 0 || $allowance_amount > $order_total) {
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

			$params    = AllowanceParamsDTO::from_order( $order, $issued_data, $allowance_amount, $notify_mail );
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$response  = $client->issue_allowance( $params );

			if (!$response instanceof IssueInvoiceResponseDTO) {
				// 驗章 / 業務失敗 → 經 map_error 映射；不寫 allowance_data.
				return self::error_from_client( $client, '開立折讓失敗', $order );
			}

			$result = [
				'allowance_number' => $params->AllowanceNumber,
				'allowance_amount' => $allowance_amount,
				'invoice_number'   => (string) $issued_data['invoice_number'],
				'code'             => $response->code,
				'msg'              => $response->msg,
			];
			$meta_keys->update_allowance_data( $result );

			self::logger(
				"✅ Amego 開立折讓成功 #{$order->get_id()}",
				'info',
				[ 'allowance_number' => $params->AllowanceNumber ],
				0,
				$order
			);

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '開立折讓失敗', $e, $order );
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data，否則 NOT_FOUND）。成功後清除 allowance_data，使後續可重新開立。
	 * client 回 null 經 map_error 映射；catch \Throwable → UNKNOWN。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢結果 array；失敗回正規化 \WP_Error
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();

		// 無折讓資料：無從作廢（NOT_FOUND）.
		if (!$allowance_data) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				\__( '查無已開立折讓，無法作廢折讓', 'power_checkout' ),
				[ 'provider' => self::ID ]
			);
		}

		try {
			$params    = CancelAllowanceParamsDTO::from_allowance_data( $allowance_data );
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$response  = $client->invalid_allowance( $params );

			if (!$response instanceof IssueInvoiceResponseDTO) {
				return self::error_from_client( $client, '作廢折讓失敗', $order );
			}

			$result = [
				'code'   => $response->code,
				'msg'    => $response->msg,
				'status' => 'allowance_invalid',
			];

			// 成功作廢折讓：清除折讓資料.
			$meta_keys->clear_allowance_data();

			self::logger( "✅ Amego 作廢折讓成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '作廢折讓失敗', $e, $order );
		}
	}

	/**
	 * 查詢發票明細（唯讀，invoice_query）
	 *
	 * 以已開立發票號碼查詢；未開立則回 NOT_FOUND 且「不打 API」。
	 * 本方法為純唯讀：不寫任何 meta、不改訂單狀態。client 回 null 經 map_error 映射；catch \Throwable → UNKNOWN。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回發票明細 array；查無或失敗回正規化 \WP_Error
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		try {
			$invoice_number = $this->get_invoice_number( $order );

			// 未開立發票：不打 API，直接回 NOT_FOUND.
			if ('' === $invoice_number) {
				return NormalizedError::from(
					ErrorCode::NOT_FOUND,
					\__( '尚未開立發票，無可查詢的發票', 'power_checkout' ),
					[ 'provider' => self::ID ]
				);
			}

			$params    = InvoiceQueryParamsDTO::by_invoice_number( $invoice_number );
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$result    = $client->query_invoice( $params );

			if (!\is_array( $result )) {
				return self::error_from_client( $client, '發票查詢失敗', $order, false );
			}

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '發票查詢失敗', $e, $order, false );
		}
	}

	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 * false = 只拿 db, true = 會給預設值
	 *
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID);
			return \is_array($option) ? $option : [];
		}
		return AmegoSettingsDTO::instance()->to_array();
	}

	/**
	 * 取得發票號碼
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	public function get_invoice_number( \WC_Order $order ): string {
		$meta_keys   = new MetaKeys( $order);
		$issued_data = $meta_keys->get_issued_data();
		if (\is_array($issued_data) && isset($issued_data['invoice_number'])) {
			return (string) $issued_data['invoice_number'];
		}
		return '';
	}

	// ========================================================================
	// 正規化錯誤模型 helper（照 ezPay 參考模板套用）
	// ========================================================================

	/**
	 * 將 Amego 原始錯誤碼映射為正規化 {@see ErrorCode}（未涵蓋 fallthrough → PROVIDER，保留 raw_code 供 debug）
	 *
	 * 與 ezPay 模板同形狀：純函式、static、`(string $raw_code, string $raw_message = ''): ErrorCode`，
	 * match(true) 依錯誤碼值 / 區間分類；fallthrough 一律 PROVIDER。
	 * 驗章 / 連線由 client 的 kind 在 {@see self::error_from_client()} 先行分流（Amego 簽章失敗為 code=16，
	 * 屬 business kind 帶 raw_code='16'，故在本 mapper 內以 SIGNATURE 處理；連線為 kind=network）。
	 *
	 * Amego 錯誤碼形態（數字字串，見 amego-invoice skill error-codes.md）：
	 *   通用：11/12（統編）/13/14/19/22 → AUTH（認證 / 統編 / 停權 / 未申請 API）；15 Time/16 簽章 → SIGNATURE；
	 *         10/18/21 → NETWORK（停機 / DB 連線 / 人數過多）。
	 *   開立 f0401：3040171 OrderId 重複 → CONFLICT；3040111 字軌不足 / 3040191 無法取得下一張 → NUMBER_EXHAUSTED；
	 *         其餘 30401xx（欄位 / 金額 / 載具 / 課稅別驗證）→ VALIDATION。
	 *   作廢 f0501：3050121/22/23/31 開立中 / 已作廢 / 已註銷 / 等待 → CONFLICT；3050141 已開折讓 → CONFLICT；
	 *         3050125 發票不存在 → NOT_FOUND；3050111/12/24/26 → VALIDATION。
	 *   折讓 g0401 / g0501：4040161/62 / 4050131/32/41 折讓狀態衝突 → CONFLICT；4040156 / 4050134 不存在 → NOT_FOUND；
	 *         4040171/73 折讓金額超原發票 → VALIDATION；其餘 40401xx / 40501xx 欄位驗證 → VALIDATION。
	 *   查詢類短碼：71 查無 → NOT_FOUND；51 超過查詢期限 → VALIDATION；99 視 msg（保守 → PROVIDER）。
	 *
	 * @param string $raw_code    Amego 原始錯誤碼（外層 code 字串，如 '16' / '22' / '3050141'）.
	 * @param string $raw_message Amego 原始錯誤訊息（保留參數以對齊模板；Amego 以碼為主，訊息暫不參與分類）.
	 *
	 * @return ErrorCode 正規化錯誤碼.
	 */
	private static function map_error( string $raw_code, string $raw_message = '' ): ErrorCode {
		unset( $raw_message ); // Amego 以錯誤碼為權威來源；保留參數對齊跨 provider 模板簽名.

		return match ( true ) {
			// 簽章驗證錯誤（Time 誤差 / sign 不符）→ SIGNATURE.
			\in_array( $raw_code, [ '15', '16' ], true ) => ErrorCode::SIGNATURE,

			// 認證 / 統編 / 停權 / 未申請 API / IP / 未啟用 → AUTH.
			\in_array( $raw_code, [ '11', '12', '13', '14', '19', '22' ], true ) => ErrorCode::AUTH,

			// 系統停機 / DB 連線 / 人數過多 → NETWORK（暫時性，可重試）.
			\in_array( $raw_code, [ '10', '18', '21' ], true ) => ErrorCode::NETWORK,

			// 字軌不足 / 無法取得下一張發票 → NUMBER_EXHAUSTED.
			\in_array( $raw_code, [ '3040111', '3040191' ], true ) => ErrorCode::NUMBER_EXHAUSTED,

			// OrderId 重複（或註銷重開中）→ CONFLICT.
			'3040171' === $raw_code => ErrorCode::CONFLICT,

			// 作廢狀態衝突：開立中 / 已作廢 / 已註銷 / 等待 / 已開折讓 → CONFLICT.
			\in_array( $raw_code, [ '3050121', '3050122', '3050123', '3050131', '3050141' ], true ) => ErrorCode::CONFLICT,

			// 作廢：指定發票不存在 → NOT_FOUND.
			'3050125' === $raw_code => ErrorCode::NOT_FOUND,

			// 折讓狀態衝突：已存在折讓開立 / 作廢折讓 / 折讓開立中 / 等待 → CONFLICT.
			\in_array( $raw_code, [ '4040161', '4040162', '4050131', '4050132', '4050141' ], true ) => ErrorCode::CONFLICT,

			// 折讓：原發票 / 折讓單不存在 → NOT_FOUND.
			\in_array( $raw_code, [ '4040156', '4050134' ], true ) => ErrorCode::NOT_FOUND,

			// 查詢類短碼：查無資料 → NOT_FOUND.
			'71' === $raw_code => ErrorCode::NOT_FOUND,

			// 開立 / 作廢 / 折讓欄位 / 金額 / 載具 / 課稅別 / 通關 驗證錯誤（30401xx / 30501xx / 40401xx / 40501xx）+ 17 資料為空 + 20 非 JSON + 23 陣列格式 + 51 查詢期限 → VALIDATION.
			'17' === $raw_code
			|| '20' === $raw_code
			|| '23' === $raw_code
			|| '51' === $raw_code
			|| self::starts_with( $raw_code, '30401' )
			|| self::starts_with( $raw_code, '30501' )
			|| self::starts_with( $raw_code, '40401' )
			|| self::starts_with( $raw_code, '40501' ) => ErrorCode::VALIDATION,

			// 其餘未涵蓋業務碼（含查詢類 99 等）→ PROVIDER（保留 raw_code 供 debug）.
			default => ErrorCode::PROVIDER,
		};
	}

	/**
	 * 判斷字串是否以指定前綴開頭（map_error 區間分類用）
	 *
	 * @param string $haystack 待檢字串
	 * @param string $prefix   前綴
	 *
	 * @return bool
	 */
	private static function starts_with( string $haystack, string $prefix ): bool {
		return '' !== $prefix && \str_starts_with( $haystack, $prefix );
	}

	/**
	 * 由 client 落地的錯誤明細建立正規化 \WP_Error
	 *
	 * 分流（照 ezPay 模板）：
	 *   kind=network → NETWORK（連線 / 逾時）
	 *   kind=business → {@see self::map_error()}(raw_code)（業務錯誤碼權威映射；Amego 簽章 code=16 在此映 SIGNATURE）
	 *   kind=decode / 無明細 → PROVIDER（未分類 / JSON 解析失敗）
	 *
	 * @param ApiClient $client       已執行過業務方法的 client（其 get_last_error_detail 攜帶失敗明細）.
	 * @param string    $action_label 動作中文標籤（log / 訊息用）.
	 * @param \WC_Order $order        訂單（記 order note）.
	 * @param bool      $log_to_order 是否同步記 order note（查詢為唯讀，預設不寫；其餘寫）.
	 *
	 * @return \WP_Error 正規化錯誤.
	 */
	private static function error_from_client( ApiClient $client, string $action_label, \WC_Order $order, bool $log_to_order = true ): \WP_Error {
		$detail = $client->get_last_error_detail() ?? [
			'raw_code'    => '',
			'raw_message' => '',
			'raw'         => '',
			'kind'        => AmegoApiException::KIND_DECODE,
		];

		// $detail 為 client 保證的 4 鍵字串結構，鍵恆存在.
		$raw_code    = $detail['raw_code'];
		$raw_message = $detail['raw_message'];
		$kind        = $detail['kind'];

		$code = match ( $kind ) {
			AmegoApiException::KIND_NETWORK  => ErrorCode::NETWORK,
			AmegoApiException::KIND_BUSINESS => self::map_error( $raw_code, $raw_message ),
			default                          => ErrorCode::PROVIDER,
		};

		$message = self::user_message( $code, $action_label );

		self::logger(
			"❌ Amego {$action_label} #{$order->get_id()}：{$code->value}" . ( '' !== $raw_code ? "（{$raw_code}）" : '' ),
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
	 * @param string     $action_label 動作中文標籤.
	 * @param \Throwable $e            攔截到的例外.
	 * @param \WC_Order  $order        訂單（記 order note）.
	 * @param bool       $log_to_order 是否同步記 order note（查詢唯讀預設不寫）.
	 *
	 * @return \WP_Error code=UNKNOWN 的正規化錯誤.
	 */
	private static function unknown_error( string $action_label, \Throwable $e, \WC_Order $order, bool $log_to_order = true ): \WP_Error {
		self::logger(
			"{$action_label} #{$order->get_id()}： {$e->getMessage()}",
			'error',
			[],
			5,
			$log_to_order ? $order : null
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
			ErrorCode::AUTH             => \__( '商店金鑰或認證錯誤', 'power_checkout' ),
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
	 * 鏡像 ezPay 模板：金額一律以 `$order->get_total()`（含稅實付）為錨點：
	 *   totalAmount = grand、salesAmount = round(grand / 1.05)、taxAmount = grand − salesAmount。
	 * companyId / carrier / donateCode **原樣**取自結帳 params（不依 invoiceType 篩選）——若 params 被竄改成
	 * 「同時帶載具與捐贈」，互斥不變式才攔得到；B2C 一般情境這三者本就為空字串，不影響正常開立。
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
