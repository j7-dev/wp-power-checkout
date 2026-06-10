<?php
/**
 * PayNow 立吉富電子發票服務提供者（體系 3）
 *
 * 與 Amego / 綠界 / ezPay 並列，後台可切換。實作三介面：
 *   IInvoiceService     issue / cancel / get_invoice_number / get_settings
 *   ISupportsAllowance  issue_allowance / invalid_allowance（部分退款開 / 作廢折讓）
 *   ISupportsQuery      query_invoice（唯讀查詢，不改任何 meta / 狀態）
 *
 * 所有對外動作皆冪等（已有對應 meta 直接回傳，不重打 API），且 catch \Throwable →
 * logger(error, 同步 order note) → 回 []，絕不破壞 WooCommerce 主流程（退款 / 狀態變更）。
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
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
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
	 * 已開立過直接回傳 issued_data（不重打 API）。實際開立、IssueParams 驗證（載具/捐贈互斥、
	 * 零稅率必填原因）與 issued_data + provider_id meta 寫入皆由 InvoiceApiClient::issue() 負責；
	 * client 內部已 catch \Throwable 並回 null（驗證失敗 / type≠success），故本方法 client 回 null
	 * 即視為失敗 → 回 []，issued_data 不被寫入。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 開立資料；失敗回空陣列.
	 */
	public function issue( \WC_Order|int $order_or_id ): array {
		try {
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			// region 冪等：已開立過直接回傳.
			$meta_keys   = new MetaKeys( $order );
			$issued_data = $meta_keys->get_issued_data();
			if ( \is_array( $issued_data ) && $issued_data ) {
				/** @var array<string, mixed> $issued_data */
				return $issued_data;
			}
			// endregion 冪等.

			$client   = new InvoiceApiClient( $order );
			$response = $client->issue( self::ID );

			if ( ! $response instanceof IssueResponse ) {
				return [];
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
			$order_id = ( $order_or_id instanceof \WC_Order ) ? $order_or_id->get_id() : (int) $order_or_id;
			self::logger( "PayNow 開立發票失敗 #{$order_id}： {$e->getMessage()}", 'error', [], 5 );
			return [];
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
	 * @return array<string, mixed> 作廢資料；失敗回空陣列.
	 */
	public function cancel( \WC_Order|int $order_or_id ): array {
		try {
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			// region 冪等：已作廢過直接回傳.
			$meta_keys      = new MetaKeys( $order );
			$cancelled_data = $meta_keys->get_cancelled_data();
			if ( $cancelled_data ) {
				return $cancelled_data;
			}
			// endregion 冪等.

			// 前置：已開折讓時不可作廢發票 —— 先擋，不浪費 API、不清除 issued_data.
			if ( $meta_keys->get_allowance_data() ) {
				self::logger(
					"作廢發票失敗 #{$order->get_id()}：已開立折讓，須先作廢折讓",
					'warning',
					[],
					0,
					$order
				);
				return [];
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->cancel();

			if ( ! $response instanceof IssueResponse || ! $response->is_success() ) {
				return [];
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
			$order_id = ( $order_or_id instanceof \WC_Order ) ? $order_or_id->get_id() : (int) $order_or_id;
			self::logger( "作廢發票失敗 #{$order_id}： {$e->getMessage()}", 'error', [], 5 );
			return [];
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
	 * @return array<string, mixed> 折讓資料；失敗回空陣列.
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array {
		unset( $notify_mail ); // PayNow 折讓不使用此參數（保留 ISupportsAllowance 介面簽章相容）.

		try {
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

			// 前置：須已開立發票（未開立不打 API，直接回 []）.
			if ( empty( $issued_data['invoice_number'] ) ) {
				self::logger( "開立折讓失敗 #{$order->get_id()}：發票尚未開立", 'warning', [], 0, $order );
				return [];
			}

			// 金額驗證：> 0 且 ≤ 原發票總額.
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
				return [];
			}

			$params   = AllowanceParams::from_issued_data( $issued_data, $allowance_amount );
			$client   = new InvoiceApiClient( $order );
			$response = $client->allowance( $params );

			if ( ! $response instanceof AllowanceResponse ) {
				return [];
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
			$order_id = ( $order_or_id instanceof \WC_Order ) ? $order_or_id->get_id() : (int) $order_or_id;
			self::logger( "開立折讓失敗 #{$order_id}： {$e->getMessage()}", 'error', [], 5 );
			return [];
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data，含 allowance_number）。成功後清除 allowance_data，
	 * 使後續可重新開立折讓。無折讓資料時直接回 []（無從作廢，不打 API）。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 作廢結果；失敗回空陣列.
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array {
		try {
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			$meta_keys      = new MetaKeys( $order );
			$allowance_data = $meta_keys->get_allowance_data();

			// 無折讓資料：無從作廢.
			if ( ! $allowance_data ) {
				return [];
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->invalid_allowance( $allowance_data );

			if ( ! $response instanceof AllowanceResponse || ! $response->is_success() ) {
				return [];
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
			$order_id = ( $order_or_id instanceof \WC_Order ) ? $order_or_id->get_id() : (int) $order_or_id;
			self::logger( "作廢折讓失敗 #{$order_id}： {$e->getMessage()}", 'error', [], 5 );
			return [];
		}
	}

	/**
	 * 查詢發票明細（唯讀，GET /api/invoices）
	 *
	 * 以已開立發票 meta 查詢；未開立則回空陣列且「不打 API」。回傳跨 provider 一致的標準化鍵
	 * （invoice_number / invoice_status / total_amount …）。本方法為純唯讀：不寫任何 meta、不改訂單狀態。
	 * client 回 null（type≠success）時回空陣列。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 發票明細；查無或失敗回空陣列.
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array {
		try {
			$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

			$meta_keys   = new MetaKeys( $order );
			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 未開立發票：不打 API，直接回空陣列.
			if ( empty( $issued_data['invoice_number'] ) ) {
				return [];
			}

			$params   = QueryParams::from_issued_data( $issued_data );
			$client   = new InvoiceApiClient( $order );
			$response = $client->query( $params );

			if ( ! $response instanceof QueryResponse ) {
				return [];
			}

			return $response->to_array();
		} catch ( \Throwable $e ) {
			$order_id = ( $order_or_id instanceof \WC_Order ) ? $order_or_id->get_id() : (int) $order_or_id;
			self::logger( "發票查詢失敗 #{$order_id}： {$e->getMessage()}", 'error', [], 5 );
			return [];
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
}
