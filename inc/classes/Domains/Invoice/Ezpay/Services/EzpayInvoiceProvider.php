<?php
/**
 * 藍新 ezPay 電子發票服務提供者
 *
 * 與 Amego / 綠界並列，後台可切換。實作三介面：
 *   IInvoiceService     issue / cancel / get_invoice_number / get_settings
 *   ISupportsAllowance  issue_allowance / invalid_allowance（部分退款開 / 作廢折讓）
 *   ISupportsQuery      query_invoice（唯讀查詢財政部上傳狀態）
 *
 * 正規化錯誤模型（einvoice 導入第四階段-a，本 provider 為 4 家的「參考模板」）：
 *   所有對外動作冪等（已有對應 meta 直接回 array，不重打 API、不驗證），且 catch \Throwable →
 *   logger(error, 同步 order note) → 回經 {@see NormalizedError::from()} 建立的 \WP_Error，
 *   **絕不向 WooCommerce hook 拋例外**（never-throw 鐵律）。成功仍回 array（既有契約不變）。
 *
 *   失敗回傳改寫指引（給 Ecpay / Amego / PayNow 照抄）：
 *     - issue() 第一步先跑 {@see InvoiceParamsValidator::validate_for_dispatch()}，回 WP_Error 即直接 return，不打第三方 API。
 *     - client 回 null 後讀 {@see InvoiceApiClient::get_last_error_detail()}，交 {@see self::map_error()} 做權威映射。
 *     - 前置狀態（未開立 / 無折讓資料）→ NOT_FOUND；已開折讓擋作廢（LIB10007）→ CONFLICT；catch \Throwable → UNKNOWN。
 *
 * ⚠️ meta 鍵名與綠界 Ecpay 刻意不同（對齊 ezPay 測試契約）：
 *   issued_data    invoice_number / invoice_trans_no / random_num（非 random_number）
 *   allowance_data allowance_no（非 allowance_number） / allowance_amount
 * 實際寫入 issued_data 由 InvoiceApiClient::issue() 負責（含 invoice_trans_no / random_num）。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md
 * @see .claude/skills/ezpay-invoice/references/concepts.md
 * @see .claude/skills/ezpay-invoice/references/error-codes.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Services;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\QueryResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\EzpayApiException;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\InvoiceApiClient;
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

/** 藍新 ezPay 電子發票服務提供者 */
final class EzpayInvoiceProvider extends BaseService implements IInvoiceService, ISupportsAllowance, ISupportsQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 服務 ID */
	public const ID = 'ezpay';

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
	 * 實際開立與 meta 寫入由 InvoiceApiClient::issue() 負責。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回開立資料 array；失敗回正規化 \WP_Error.
	 */
	public function issue( \WC_Order|int $order_or_id ): array|\WP_Error {
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

		try {
			$client   = new InvoiceApiClient( $order );
			$response = $client->issue( self::ID );

			if ( ! $response instanceof IssueResponse ) {
				return self::error_from_client( $client, '開立發票失敗', $order );
			}

			// client 已寫入 issued_data + provider_id meta；回傳整理後的開立資料供呼叫端使用.
			$result = [
				'invoice_number'   => $response->invoice_number,
				'invoice_trans_no' => $response->invoice_trans_no,
				'random_num'       => $response->random_num,
				'invoice_date'     => $response->create_time,
				'total_amt'        => $response->total_amt,
			];

			self::logger(
				"✅ ezPay 開立發票成功 #{$order->get_id()}",
				'info',
				[ 'invoice_number' => $response->invoice_number ],
				0,
				$order
			);

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '開立發票失敗', $e, $order );
		}
	}

	/**
	 * 作廢發票（冪等）
	 *
	 * 已作廢過直接回傳 cancelled_data。前置：已開折讓（有 allowance_data）時拒絕作廢
	 * （ezPay LIB10007：已開折讓無法作廢發票）→ CONFLICT，不浪費 API，且不清除 issued_data。
	 * 成功後清除開立資料，再寫入作廢資料。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢資料 array；失敗回正規化 \WP_Error.
	 */
	public function cancel( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已作廢過直接回傳.
		$meta_keys      = new MetaKeys( $order );
		$cancelled_data = $meta_keys->get_cancelled_data();
		if ( $cancelled_data ) {
			return $cancelled_data;
		}
		// endregion 冪等.

		try {
			// 前置：已開折讓時不可作廢發票（LIB10007）—— 先擋，不浪費 API、不清除 issued_data.
			if ( $meta_keys->get_allowance_data() ) {
				self::logger(
					"作廢發票失敗 #{$order->get_id()}：已開立折讓，須先作廢折讓（ezPay LIB10007）",
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
						'raw_code'    => 'LIB10007',
						'raw_message' => '已開立折讓無法作廢發票',
					]
				);
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->cancel();

			if ( ! $response instanceof IssueResponse ) {
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

			self::logger( "✅ ezPay 作廢發票成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '作廢發票失敗', $e, $order );
		}
	}

	/**
	 * 開立折讓（部分退款開折讓單，冪等）
	 *
	 * 前置：發票須已開立（有 issued_data，否則 NOT_FOUND）。折讓金額須 > 0 且 ≤ 原發票金額（否則 VALIDATION）。
	 * 同一訂單已有折讓資料時直接回傳（冪等）。折讓即時確認（Status=1），不做兩段式。
	 * CheckCode 驗證失敗（金鑰不符 / 回應偽造）時 client 回 null → 經 map_error 映射 SIGNATURE，不寫 allowance_data。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @param float         $amount      折讓金額（含稅）.
	 * @param string        $notify_mail 折讓通知 Email（空字串不通知）.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回折讓資料 array；失敗回正規化 \WP_Error.
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys = new MetaKeys( $order );

		// region 冪等：已開折讓直接回傳.
		$allowance_data = $meta_keys->get_allowance_data();
		if ( $allowance_data ) {
			return $allowance_data;
		}
		// endregion 冪等.

		try {
			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 前置：須已開立發票（否則 NOT_FOUND）.
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

			$merchant_order_no = IssueParams::build_merchant_order_no( $order );

			$client   = new InvoiceApiClient( $order );
			$response = $client->issue_allowance( $allowance_amount, $merchant_order_no, $notify_mail );

			if ( ! $response instanceof AllowanceResponse ) {
				// 驗章 / 業務失敗 → 經 map_error 映射；不寫 allowance_data.
				return self::error_from_client( $client, '開立折讓失敗', $order );
			}

			$result = [
				'allowance_no'     => $response->allowance_no,
				'allowance_amount' => $allowance_amount,
				'invoice_number'   => (string) $issued_data['invoice_number'],
				'remain_amt'       => $response->remain_amt,
			];
			$meta_keys->update_allowance_data( $result );

			self::logger(
				"✅ ezPay 開立折讓成功 #{$order->get_id()}",
				'info',
				[ 'allowance_no' => $response->allowance_no ],
				0,
				$order
			);

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '開立折讓失敗', $e, $order );
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data，否則 NOT_FOUND）。成功後清除 allowance_data，使後續可重新開立折讓。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢結果 array；失敗回正規化 \WP_Error.
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array|\WP_Error {
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

		try {
			$client   = new InvoiceApiClient( $order );
			$response = $client->invalid_allowance( $allowance_data );

			if ( ! $response instanceof AllowanceResponse ) {
				return self::error_from_client( $client, '作廢折讓失敗', $order );
			}

			$result = [
				'status'  => 'allowance_invalid',
				'rtn_msg' => '折讓作廢成功',
			];

			// 成功作廢折讓：清除折讓資料.
			$meta_keys->clear_allowance_data();

			self::logger( "✅ ezPay 作廢折讓成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result;
		} catch ( \Throwable $e ) {
			return self::unknown_error( '作廢折讓失敗', $e, $order );
		}
	}

	/**
	 * 查詢發票明細（唯讀，invoice_search）
	 *
	 * 以已開立發票 meta 查詢；未開立則回 NOT_FOUND 且「不打 API」。回傳跨 provider 一致的標準化鍵
	 * （invoice_number / invoice_status / upload_status / total_amt …）。
	 * 本方法為純唯讀：不寫任何 meta、不改訂單狀態。CheckCode 驗證失敗時經 map_error 映射 SIGNATURE。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed>|\WP_Error 成功回發票明細 array；查無或失敗回正規化 \WP_Error.
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		try {
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

			$client   = new InvoiceApiClient( $order );
			$response = $client->query();

			if ( ! $response instanceof QueryResponse ) {
				return self::error_from_client( $client, '發票查詢失敗', $order, false );
			}

			return $response->to_array();
		} catch ( \Throwable $e ) {
			return self::unknown_error( '發票查詢失敗', $e, $order, false );
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
		return EzpaySettingsDTO::instance()->to_array();
	}

	// ========================================================================
	// 正規化錯誤模型 helper（4 provider 模板）
	// ========================================================================

	/**
	 * 將 ezPay 原始錯誤碼映射為正規化 {@see ErrorCode}（模板：未涵蓋 fallthrough → PROVIDER，保留 raw_code 供 debug）
	 *
	 * 這個 mapper 的「形狀」就是給 Ecpay / Amego / PayNow 照抄的範式：
	 *   - 純函式、static、`(string $raw_code, string $raw_message = ''): ErrorCode`。
	 *   - match(true) 依錯誤碼前綴 / 區間分類；fallthrough 一律 PROVIDER（絕不漏映射成誤判）。
	 *   - 驗章（SIGNATURE）/ 連線（NETWORK）由 client 的 kind 提示在 {@see self::error_from_client()} 先行分流，
	 *     不在本 mapper 內（本 mapper 只負責「業務錯誤碼」→ ErrorCode）。
	 *
	 * @param string $raw_code    ezPay 原始錯誤碼（外層 Status 值，如 LIB10007 / KEY10002）.
	 * @param string $raw_message ezPay 原始錯誤訊息（保留參數以對齊模板；ezPay 以碼為主，訊息暫不參與分類）.
	 *
	 * @return ErrorCode 正規化錯誤碼.
	 */
	private static function map_error( string $raw_code, string $raw_message = '' ): ErrorCode {
		unset( $raw_message ); // ezPay 以錯誤碼為權威來源；保留參數對齊跨 provider 模板簽名.

		return match ( true ) {
			// 金鑰 / 認證 / 解密 / 商店未啟用（KEY 系列）→ AUTH.
			\in_array( $raw_code, [ 'KEY10002', 'KEY10004', 'KEY10006', 'KEY10010', 'KEY10011', 'KEY10012', 'KEY10013' ], true ) => ErrorCode::AUTH,

			// 狀態衝突：自訂編號重覆 / 已作廢過 / 已開折讓無法作廢（LIB 系列）→ CONFLICT.
			\in_array( $raw_code, [ 'LIB10003', 'LIB10005', 'LIB10007', 'LIB10008', 'LIB10009' ], true ) => ErrorCode::CONFLICT,

			// 查無發票 → NOT_FOUND.
			'INV20006' === $raw_code => ErrorCode::NOT_FOUND,

			// 可開立張數已用罄（字軌號碼用完）→ NUMBER_EXHAUSTED.
			'INV90006' === $raw_code => ErrorCode::NUMBER_EXHAUSTED,

			// 商品 / 金額 / 欄位 / 格式驗證錯誤（INV 業務驗證系列）→ VALIDATION.
			\in_array( $raw_code, [ 'INV10003', 'INV10004', 'INV10006', 'INV10012', 'INV10013', 'INV10014', 'INV10015', 'INV10016', 'INV10017', 'INV10019', 'INV70001' ], true ) => ErrorCode::VALIDATION,

			// 網路連線 / TimeOut（NOR / KEY TimeOut）→ NETWORK.
			\in_array( $raw_code, [ 'NOR10001', 'KEY10007', 'KEY10014' ], true ) => ErrorCode::NETWORK,

			// 其餘未涵蓋業務碼（如 LIB99999 / INV90005 / IAI 系列等）→ PROVIDER（保留 raw_code 供 debug）.
			default => ErrorCode::PROVIDER,
		};
	}

	/**
	 * 由 client 落地的錯誤明細建立正規化 \WP_Error
	 *
	 * 分流（模板）：
	 *   kind=signature → SIGNATURE（驗章失敗）
	 *   kind=network   → NETWORK（連線 / 逾時）
	 *   kind=business  → {@see self::map_error()}(raw_code)（業務錯誤碼權威映射）
	 *   kind=decode / 無明細 → PROVIDER（未分類）
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
			'kind'        => EzpayApiException::KIND_DECODE,
		];

		// $detail 為 client 保證的 4 鍵字串結構，鍵恆存在.
		$raw_code    = $detail['raw_code'];
		$raw_message = $detail['raw_message'];
		$kind        = $detail['kind'];

		$code = match ( $kind ) {
			EzpayApiException::KIND_SIGNATURE => ErrorCode::SIGNATURE,
			EzpayApiException::KIND_NETWORK   => ErrorCode::NETWORK,
			EzpayApiException::KIND_BUSINESS  => self::map_error( $raw_code, $raw_message ),
			default                           => ErrorCode::PROVIDER,
		};

		$message = self::user_message( $code, $action_label );

		self::logger(
			"❌ ezPay {$action_label} #{$order->get_id()}：{$code->value}" . ( '' !== $raw_code ? "（{$raw_code}）" : '' ),
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
