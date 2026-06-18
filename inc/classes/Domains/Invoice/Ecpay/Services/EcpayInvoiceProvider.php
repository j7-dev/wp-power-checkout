<?php
/**
 * 綠界電子發票服務提供者
 *
 * 與 Amego / ezPay / PayNow 並列，後台可切換。實作三介面：
 *   IInvoiceService     issue / cancel / get_invoice_number / get_settings
 *   ISupportsAllowance  issue_allowance / invalid_allowance（部分退款開 / 作廢折讓）
 *   ISupportsQuery      query_invoice（唯讀查詢發票明細）
 *
 * 正規化錯誤模型（einvoice 導入第四階段-b，照抄 ezPay 第四階段-a 模板）：
 *   所有對外動作冪等（已有對應 meta 直接回 array，不重打 API、不驗證），且 catch \Throwable →
 *   logger(error, 同步 order note) → 回經 {@see NormalizedError::from()} 建立的 \WP_Error，
 *   **絕不向 WooCommerce hook 拋例外**（never-throw 鐵律）。成功仍回 array（既有契約不變）。
 *
 *   失敗回傳改寫指引：
 *     - issue() 第一步先跑 {@see InvoiceParamsValidator::validate_for_dispatch()}，回 WP_Error 即直接 return，不打第三方 API。
 *     - client 回 null 後讀 {@see InvoiceApiClient::get_last_error_detail()}，交 {@see self::map_error()} 做權威映射。
 *     - 前置狀態（未開立 / 無折讓資料）→ NOT_FOUND；金額不合法 → VALIDATION；catch \Throwable → UNKNOWN。
 *
 *   綠界專屬映射（AES-JSON 雙層 TransCode → RtnCode）：
 *     - 內層 RtnCode≠1（業務失敗）→ 依 RtnCode + 中文 RtnMsg regex 補強（字軌用罄→NUMBER_EXHAUSTED、
 *       已作廢/已開立/重複→CONFLICT、財政部失敗→NETWORK、統編/稅額/載具/捐贈碼格式→VALIDATION、
 *       查無→NOT_FOUND、金鑰/憑證→AUTH、未涵蓋→PROVIDER 保留 raw_code）。
 *     - 外層 TransCode≠1 之 CheckMacValue / AES 驗章失敗 → SIGNATURE（由 client 的 kind 在 error_from_client 先行分流）。
 *
 * issue / cancel 皆為冪等（已有對應 meta 直接回傳，不重打 API）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/20-error-codes-reference.md
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Services;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\AllowanceInvalidParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\AllowanceParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\CancelParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\QueryParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Http\EcpayInvoiceApiException;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
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

/** 綠界電子發票服務提供者 */
final class EcpayInvoiceProvider extends BaseService implements IInvoiceService, ISupportsAllowance, ISupportsQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const ID = 'ecpay';

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
	 * client 開立 → 成功回整理後陣列；client 回 null 則讀錯誤明細經 map_error 映射；catch \Throwable → UNKNOWN。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回開立資料 array；失敗回正規化 \WP_Error.
	 */
	public function issue( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已開立過直接回傳（不驗證、不打 API）
		$meta_keys   = new MetaKeys( $order );
		$issued_data = $meta_keys->get_issued_data();
		if (\is_array( $issued_data ) && $issued_data) {
			/** @var array<string, mixed> $issued_data */
			return $issued_data;
		}
		// endregion 冪等

		// region 統一驗證層：issue 第一步，失敗即回 VALIDATION，不打第三方 API
		$dispatch_error = InvoiceParamsValidator::validate_for_dispatch( self::build_dispatch_params( $order ) );
		if ($dispatch_error instanceof \WP_Error) {
			self::logger(
				"開立發票前置驗證失敗 #{$order->get_id()}：{$dispatch_error->get_error_message()}",
				'warning',
				[],
				0,
				$order
			);
			return $dispatch_error;
		}
		// endregion 統一驗證層

		try {
			$settings = EcpayInvoiceSettingsDTO::instance();
			$is_b2b   = self::is_b2b( $order );

			$params   = IssueParams::from_order( $order, $settings->merchant_id );
			$client   = new InvoiceApiClient( $order );
			$response = $client->issue( $params, $is_b2b );

			if (!$response instanceof IssueResponse) {
				// 開立失敗一律不寫入 issued_data；讀錯誤明細經 map_error 映射.
				return self::error_from_client( $client, '開立發票失敗', $order );
			}

			$result = self::build_issued_data( $response );
			$meta_keys->update_issued_data( $result );
			$meta_keys->update_provider_id( self::ID );

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '開立發票失敗', $e, $order );
		}
	}

	/**
	 * 作廢發票（冪等）
	 *
	 * 已作廢過直接回傳 cancelled_data。失敗一律不清除 issued_data（保留可重試）。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢資料 array；失敗回正規化 \WP_Error.
	 */
	public function cancel( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已作廢過直接回傳
		$meta_keys      = new MetaKeys( $order );
		$cancelled_data = $meta_keys->get_cancelled_data();
		if ($cancelled_data) {
			return $cancelled_data;
		}
		// endregion 冪等

		try {
			$settings = EcpayInvoiceSettingsDTO::instance();
			$is_b2b   = self::is_b2b( $order );

			$raw_issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $raw_issued_data ) ? $raw_issued_data : [];

			$params   = CancelParams::from_issued_data( $settings->merchant_id, $issued_data, $is_b2b );
			$client   = new InvoiceApiClient( $order );
			$response = $client->cancel( $params, $is_b2b );

			if (!$response instanceof IssueResponse) {
				// 作廢失敗一律不清除 issued_data（保留可重試）；讀錯誤明細經 map_error 映射.
				return self::error_from_client( $client, '作廢發票失敗', $order );
			}

			$result = [
				'rtn_code' => $response->RtnCode,
				'rtn_msg'  => $response->RtnMsg,
				'status'   => 'cancelled',
			];

			// 成功作廢：先清除開立資料，再寫入作廢資料
			$meta_keys->clear_data();
			$meta_keys->update_cancelled_data( $result );

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '作廢發票失敗', $e, $order );
		}
	}

	/**
	 * 開立折讓（部分退款開折讓單，冪等）
	 *
	 * 前置：發票須已開立（有 issued_data，否則 NOT_FOUND）。折讓金額須 > 0 且 ≤ 原發票金額（否則 VALIDATION）。
	 * 同一訂單已有折讓資料時直接回傳（冪等）。
	 *
	 * @param \WC_Order|int $order_or_id      訂單
	 * @param float         $amount           折讓金額（含稅）
	 * @param string        $notify_mail      B2C 折讓通知 Email（空字串不通知）
	 *
	 * @return array<string, mixed>|\WP_Error 成功回折讓資料 array；失敗回正規化 \WP_Error.
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys = new MetaKeys( $order );

		// region 冪等：已開折讓直接回傳
		$allowance_data = $meta_keys->get_allowance_data();
		if ($allowance_data) {
			return $allowance_data;
		}
		// endregion 冪等

		try {
			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 前置：須已開立發票（否則 NOT_FOUND）
			if (empty( $issued_data['invoice_number'] )) {
				self::logger( "開立折讓失敗 #{$order->get_id()}：發票尚未開立", 'warning', [], 0, $order );
				return NormalizedError::from(
					ErrorCode::NOT_FOUND,
					\__( '尚未開立發票，無法開立折讓', 'power_checkout' ),
					[ 'provider' => self::ID ]
				);
			}

			// 金額驗證：> 0 且 ≤ 原發票總額（否則 VALIDATION）
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

			$settings = EcpayInvoiceSettingsDTO::instance();
			$is_b2b   = self::is_b2b( $order );

			$params = $is_b2b
			? AllowanceParams::for_b2b( $settings->merchant_id, $issued_data, $allowance_amount )
			: AllowanceParams::for_b2c( $settings->merchant_id, $issued_data, $allowance_amount, $notify_mail );

			$client   = new InvoiceApiClient( $order );
			$response = $client->issue_allowance( $params, $is_b2b );

			if (!$response instanceof AllowanceResponse) {
				// 折讓失敗不寫 allowance_data；讀錯誤明細經 map_error 映射.
				return self::error_from_client( $client, '開立折讓失敗', $order );
			}

			$result = [
				'allowance_number' => $response->get_allowance_number(),
				'allowance_amount' => $allowance_amount,
				'invoice_number'   => (string) $issued_data['invoice_number'],
				'remain_amount'    => $response->IIS_Remain_Allowance_Amt,
				'rtn_code'         => $response->RtnCode,
				'rtn_msg'          => $response->RtnMsg,
			];
			$meta_keys->update_allowance_data( $result );

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '開立折讓失敗', $e, $order );
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data，否則 NOT_FOUND）。成功後清除 allowance_data。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>|\WP_Error 成功回作廢結果 array；失敗回正規化 \WP_Error.
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array|\WP_Error {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();

		// 無折讓資料：無從作廢（NOT_FOUND）
		if (!$allowance_data) {
			return NormalizedError::from(
				ErrorCode::NOT_FOUND,
				\__( '查無已開立折讓，無法作廢折讓', 'power_checkout' ),
				[ 'provider' => self::ID ]
			);
		}

		try {
			$settings = EcpayInvoiceSettingsDTO::instance();
			$is_b2b   = self::is_b2b( $order );

			$params   = AllowanceInvalidParams::from_allowance_data( $settings->merchant_id, $allowance_data, $is_b2b );
			$client   = new InvoiceApiClient( $order );
			$response = $client->invalid_allowance( $params, $is_b2b );

			if (!$response instanceof AllowanceResponse) {
				return self::error_from_client( $client, '作廢折讓失敗', $order );
			}

			$result = [
				'rtn_code' => $response->RtnCode,
				'rtn_msg'  => $response->RtnMsg,
				'status'   => 'allowance_invalid',
			];

			// 成功作廢折讓：清除折讓資料，使後續可重新開立
			$meta_keys->clear_allowance_data();

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '作廢折讓失敗', $e, $order );
		}
	}

	/**
	 * 查詢發票明細（唯讀，GetIssue）
	 *
	 * 以已開立發票 meta（發票號碼 + 開立日期）查詢；未開立則回 NOT_FOUND 且「不打 API」。
	 * 回傳統一鍵 invoice_number（對應綠界 IIS_Number），其餘原始欄位保留。
	 * 本方法為純唯讀：不寫任何 meta、不改訂單狀態。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
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

			// 未開立發票：不打 API，直接回 NOT_FOUND
			if (empty( $issued_data['invoice_number'] )) {
				return NormalizedError::from(
					ErrorCode::NOT_FOUND,
					\__( '尚未開立發票，無可查詢的發票', 'power_checkout' ),
					[ 'provider' => self::ID ]
				);
			}

			$settings = EcpayInvoiceSettingsDTO::instance();
			$params   = QueryParams::from_issued_data( $settings->merchant_id, $issued_data );
			$client   = new InvoiceApiClient( $order );
			$result   = $client->query( $params );

			if (!\is_array( $result ) || !$result) {
				// 查詢為唯讀，失敗不記 order note（log_to_order=false）.
				return self::error_from_client( $client, '發票查詢失敗', $order, false );
			}

			// 統一鍵：綠界回 IIS_Number，補上 invoice_number 方便跨 provider 一致取用
			if (!isset( $result['invoice_number'] ) && isset( $result['IIS_Number'] )) {
				$result['invoice_number'] = (string) $result['IIS_Number'];
			}

			return $result;
		} catch (\Throwable $e) {
			return self::unknown_error( '發票查詢失敗', $e, $order, false );
		}
	}

	/**
	 * 取得發票號碼
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	public function get_invoice_number( \WC_Order $order ): string {
		$meta_keys   = new MetaKeys( $order );
		$issued_data = $meta_keys->get_issued_data();
		if (\is_array( $issued_data ) && isset( $issued_data['invoice_number'] )) {
			return (string) $issued_data['invoice_number'];
		}
		return '';
	}

	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 * false = 只拿 db, true = 會給預設值
	 *
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID );
			return \is_array( $option ) ? $option : [];
		}
		return EcpayInvoiceSettingsDTO::instance()->to_array();
	}

	/**
	 * 是否為 B2B 發票（結帳時選擇「公司」/有統編）
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return bool
	 */
	private static function is_b2b( \WC_Order $order ): bool {
		$issue_params = ( new MetaKeys( $order ) )->get_issue_params();
		if (!$issue_params) {
			return false;
		}
		$args = InvoiceParams::create( $issue_params );
		return isset( $args->invoiceType ) && EInvoiceType::COMPANY === $args->invoiceType;
	}

	/**
	 * 將回應整理為要儲存的 issued_data
	 *
	 * @param IssueResponse $response 開立回應
	 *
	 * @return array<string, mixed>
	 */
	private static function build_issued_data( IssueResponse $response ): array {
		return [
			'invoice_number' => $response->get_invoice_number(),
			'invoice_date'   => $response->InvoiceDate,
			'random_number'  => $response->RandomNumber,
			'rtn_code'       => $response->RtnCode,
			'rtn_msg'        => $response->RtnMsg,
		];
	}

	// ========================================================================
	// 正規化錯誤模型 helper（照抄 ezPay 第四階段-a 模板，綠界以 RtnCode + 中文 RtnMsg regex 映射）
	// ========================================================================

	/**
	 * 將綠界原始錯誤碼 + 訊息映射為正規化 {@see ErrorCode}（fallthrough → PROVIDER，保留 raw_code）
	 *
	 * 綠界電子發票（AES-JSON）官方文件未提供固定 RtnCode 對照表（RtnCode≠1 → 看 RtnMsg），
	 * 故映射以「少數已知數字碼 + 中文 RtnMsg regex 補強」為主，未命中一律 PROVIDER（絕不漏映射成誤判）。
	 *
	 *   - 驗章（SIGNATURE）/ 連線（NETWORK，HTTP 層）由 client 的 kind 在 {@see self::error_from_client()}
	 *     先行分流，不在本 mapper 內。
	 *   - 本 mapper 只負責「TransCode=1 但 RtnCode≠1」的業務錯誤（含 RtnMsg 含「財政部失敗」的應用層 NETWORK）。
	 *
	 * @param string $raw_code    綠界原始錯誤碼（內層 RtnCode 字串化，如 '10000009'）.
	 * @param string $raw_message 綠界原始錯誤訊息（內層 RtnMsg；regex 補強分類的權威來源）.
	 *
	 * @return ErrorCode 正規化錯誤碼.
	 */
	private static function map_error( string $raw_code, string $raw_message = '' ): ErrorCode {
		return match ( true ) {
			// 字軌號碼用罄 / 用完 / 不足 → NUMBER_EXHAUSTED.
			1 === \preg_match( '/字軌.{0,8}(用罄|用完|不足|已用)|(可開立|號碼).{0,8}(用罄|用完)/u', $raw_message ) => ErrorCode::NUMBER_EXHAUSTED,

			// 已作廢 / 已開立 / 重複（含 RelateNumber 重複）→ CONFLICT.
			1 === \preg_match( '/已作廢|已開立|已存在|重複|RelateNumber/u', $raw_message ) => ErrorCode::CONFLICT,

			// 財政部大平台 / 上傳 失敗或逾時（應用層連線錯）→ NETWORK.
			1 === \preg_match( '/財政部.{0,8}(失敗|逾時|連線)|大平台.{0,8}(失敗|逾時)|連線.{0,4}(失敗|逾時)/u', $raw_message ) => ErrorCode::NETWORK,

			// 查無 / 不存在 / 找不到 → NOT_FOUND.
			1 === \preg_match( '/查無|不存在|找不到/u', $raw_message ) => ErrorCode::NOT_FOUND,

			// 金鑰 / 憑證 / HashKey / HashIV / 未授權 / 認證 → AUTH.
			1 === \preg_match( '/HashKey|HashIV|金鑰|憑證|未授權|認證|授權失敗/iu', $raw_message ) => ErrorCode::AUTH,

			// 統一編號 / 稅額 / 金額 / 載具 / 捐贈碼 / 格式 / 參數 驗證錯誤 → VALIDATION.
			1 === \preg_match( '/統一編號|統編|稅額|金額.{0,4}(不符|錯誤)|載具|捐贈碼|愛心碼|格式.{0,4}(錯誤|不正確)|參數.{0,4}(錯誤|不正確|缺)/u', $raw_message ) => ErrorCode::VALIDATION,

			// 少數已知數字碼補強（RelateNumber 重複）→ CONFLICT.
			'10000009' === $raw_code => ErrorCode::CONFLICT,

			// 其餘未涵蓋業務碼 → PROVIDER（保留 raw_code 供 debug）.
			default => ErrorCode::PROVIDER,
		};
	}

	/**
	 * 由 client 落地的錯誤明細建立正規化 \WP_Error
	 *
	 * 分流：
	 *   kind=signature → SIGNATURE（CheckMacValue / AES 驗章失敗）
	 *   kind=network   → NETWORK（HTTP 連線 / 逾時）
	 *   kind=business  → {@see self::map_error()}(raw_code, raw_message)（業務錯誤碼 + 中文 regex 權威映射）
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
			'kind'        => EcpayInvoiceApiException::KIND_DECODE,
		];

		// $detail 為 client 保證的 4 鍵字串結構，鍵恆存在.
		$raw_code    = $detail['raw_code'];
		$raw_message = $detail['raw_message'];
		$kind        = $detail['kind'];

		$code = match ( $kind ) {
			EcpayInvoiceApiException::KIND_SIGNATURE => ErrorCode::SIGNATURE,
			EcpayInvoiceApiException::KIND_NETWORK   => ErrorCode::NETWORK,
			EcpayInvoiceApiException::KIND_BUSINESS  => self::map_error( $raw_code, $raw_message ),
			default                                  => ErrorCode::PROVIDER,
		};

		$message = self::user_message( $code, $action_label );

		self::logger(
			"❌ 綠界 {$action_label} #{$order->get_id()}：{$code->value}" . ( '' !== $raw_code ? "（{$raw_code}）" : '' ),
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
	 * @param \WC_Order $order 訂單
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
		if (\is_array( $issue_params ) && $issue_params) {
			$args = InvoiceParams::create( $issue_params );

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
