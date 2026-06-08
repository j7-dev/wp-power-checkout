<?php
/**
 * 藍新 ezPay 電子發票服務提供者
 *
 * 與 Amego / 綠界並列，後台可切換。實作三介面：
 *   IInvoiceService     issue / cancel / get_invoice_number / get_settings
 *   ISupportsAllowance  issue_allowance / invalid_allowance（部分退款開 / 作廢折讓）
 *   ISupportsQuery      query_invoice（唯讀查詢財政部上傳狀態）
 *
 * 所有對外動作皆冪等（已有對應 meta 直接回傳，不重打 API），且 catch \Throwable →
 * logger(error, 同步 order note) → 回 [] / null，絕不破壞 WooCommerce 主流程。
 *
 * ⚠️ meta 鍵名與綠界 Ecpay 刻意不同（對齊 ezPay 測試契約）：
 *   issued_data    invoice_number / invoice_trans_no / random_num（非 random_number）
 *   allowance_data allowance_no（非 allowance_number） / allowance_amount
 * 實際寫入 issued_data 由 InvoiceApiClient::issue() 負責（含 invoice_trans_no / random_num）。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md
 * @see .claude/skills/ezpay-invoice/references/concepts.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Services;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\QueryResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
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
	 * 已開立過直接回傳 issued_data。實際開立與 meta 寫入由 InvoiceApiClient::issue() 負責。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 開立資料；失敗回空陣列.
	 */
	public function issue( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已開立過直接回傳.
		$meta_keys   = new MetaKeys( $order );
		$issued_data = $meta_keys->get_issued_data();
		if ( \is_array( $issued_data ) && $issued_data ) {
			/** @var array<string, mixed> $issued_data */
			return $issued_data;
		}
		// endregion 冪等.

		try {
			$client   = new InvoiceApiClient( $order );
			$response = $client->issue( self::ID );

			if ( ! $response instanceof IssueResponse ) {
				return [];
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
			self::logger( "開立發票失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 作廢發票（冪等）
	 *
	 * 已作廢過直接回傳 cancelled_data。前置：已開折讓（有 allowance_data）時拒絕作廢
	 * （ezPay LIB10007：已開折讓無法作廢發票），不浪費 API，且不清除 issued_data。
	 * 成功後清除開立資料，再寫入作廢資料。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 作廢資料；失敗回空陣列.
	 */
	public function cancel( \WC_Order|int $order_or_id ): array {
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
				return [];
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->cancel();

			if ( ! $response instanceof IssueResponse ) {
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

			self::logger( "✅ ezPay 作廢發票成功 #{$order->get_id()}", 'info', [], 0, $order );

			return $result;
		} catch ( \Throwable $e ) {
			self::logger( "作廢發票失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 開立折讓（部分退款開折讓單，冪等）
	 *
	 * 前置：發票須已開立（有 issued_data）。折讓金額須 > 0 且 ≤ 原發票金額。
	 * 同一訂單已有折讓資料時直接回傳（冪等）。折讓即時確認（Status=1），不做兩段式。
	 * CheckCode 驗證失敗（金鑰不符 / 回應偽造）時 client 回 null → 本方法回 []，不寫 allowance_data。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 * @param float         $amount      折讓金額（含稅）.
	 * @param string        $notify_mail 折讓通知 Email（空字串不通知）.
	 *
	 * @return array<string, mixed> 折讓資料；失敗回空陣列.
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array {
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

			// 前置：須已開立發票.
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

			$merchant_order_no = IssueParams::build_merchant_order_no( $order );

			$client   = new InvoiceApiClient( $order );
			$response = $client->issue_allowance( $allowance_amount, $merchant_order_no, $notify_mail );

			if ( ! $response instanceof AllowanceResponse ) {
				return [];
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
			self::logger( "開立折讓失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data）。成功後清除 allowance_data，使後續可重新開立折讓。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 作廢結果；失敗回空陣列.
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();

		// 無折讓資料：無從作廢.
		if ( ! $allowance_data ) {
			return [];
		}

		try {
			$client   = new InvoiceApiClient( $order );
			$response = $client->invalid_allowance( $allowance_data );

			if ( ! $response instanceof AllowanceResponse ) {
				return [];
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
			self::logger( "作廢折讓失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 查詢發票明細（唯讀，invoice_search）
	 *
	 * 以已開立發票 meta 查詢；未開立則回空陣列且「不打 API」。回傳跨 provider 一致的標準化鍵
	 * （invoice_number / invoice_status / upload_status / total_amt …）。
	 * 本方法為純唯讀：不寫任何 meta、不改訂單狀態。CheckCode 驗證失敗時回空陣列。
	 *
	 * @param \WC_Order|int $order_or_id 訂單.
	 *
	 * @return array<string, mixed> 發票明細；查無或失敗回空陣列.
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		try {
			$meta_keys   = new MetaKeys( $order );
			$issued_data = $meta_keys->get_issued_data();
			/** @var array<string, mixed> $issued_data */
			$issued_data = \is_array( $issued_data ) ? $issued_data : [];

			// 未開立發票：不打 API，直接回空陣列.
			if ( empty( $issued_data['invoice_number'] ) ) {
				return [];
			}

			$client   = new InvoiceApiClient( $order );
			$response = $client->query();

			if ( ! $response instanceof QueryResponse ) {
				return [];
			}

			return $response->to_array();
		} catch ( \Throwable $e ) {
			self::logger( "發票查詢失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
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
		return EzpaySettingsDTO::instance()->to_array();
	}
}
