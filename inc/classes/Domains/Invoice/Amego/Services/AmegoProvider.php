<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Services;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AllowanceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\CancelAllowanceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\InvoiceQueryParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsAllowance;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\ISupportsQuery;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
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
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>
	 */
	public function issue( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id);

		// region 如果已經發行過，就不重複發行
		$meta_keys   = new MetaKeys( $order);
		$issued_data = $meta_keys->get_issued_data();
		if (\is_array($issued_data) && $issued_data) {
			/** @var array<string, mixed> $issued_data */
			return $issued_data;
		}
		// endregion 如果已經發行過，就不重複發行

		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester);
		$result    = $client->issue(self::ID);
		return $result?->to_array() ?? [];
	}

	/**
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed>
	 */
	public function cancel( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id);
		// region 如果已經取消過，就不重複發行
		$meta_keys      = new MetaKeys( $order);
		$cancelled_data = $meta_keys->get_cancelled_data();
		if ($cancelled_data) {
			return $cancelled_data;
		}
		// endregion 如果已經取消過，就不重複發行

		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester);
		$result    = $client->cancel();
		return $result?->to_array() ?? [];
	}

	/**
	 * 開立折讓（部分退款開折讓單，冪等）
	 *
	 * 前置：發票須已開立（有 issued_data.invoice_number）。折讓金額須 > 0 且 ≤ 原發票金額。
	 * 同一訂單已有折讓資料時，直接回傳（冪等，避免重複折讓）。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 * @param float         $amount      折讓金額（含稅）
	 * @param string        $notify_mail 折讓通知 Email（空字串不通知）
	 *
	 * @return array<string, mixed> 折讓資料；失敗回空陣列
	 */
	public function issue_allowance( \WC_Order|int $order_or_id, float $amount, string $notify_mail = '' ): array {
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

			// 前置：須已開立發票
			if (empty( $issued_data['invoice_number'] )) {
				self::logger( "開立折讓失敗 #{$order->get_id()}：發票尚未開立", 'warning', [], 0, $order );
				return [];
			}

			// 金額驗證：> 0 且 ≤ 原發票總額
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
				return [];
			}

			$params    = AllowanceParamsDTO::from_order( $order, $issued_data, $allowance_amount, $notify_mail );
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$response  = $client->issue_allowance( $params );

			if (!$response instanceof IssueInvoiceResponseDTO) {
				return [];
			}

			$result = [
				'allowance_number' => $params->AllowanceNumber,
				'allowance_amount' => $allowance_amount,
				'invoice_number'   => (string) $issued_data['invoice_number'],
				'code'             => $response->code,
				'msg'              => $response->msg,
			];
			$meta_keys->update_allowance_data( $result );

			return $result;
		} catch (\Throwable $e) {
			self::logger( "開立折讓失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 作廢折讓（冪等）
	 *
	 * 須有已開立折讓（allowance_data）。成功後清除 allowance_data，使後續可重新開立。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> 作廢結果；失敗回空陣列
	 */
	public function invalid_allowance( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		$meta_keys      = new MetaKeys( $order );
		$allowance_data = $meta_keys->get_allowance_data();

		// 無折讓資料：無從作廢
		if (!$allowance_data) {
			return [];
		}

		try {
			$params    = CancelAllowanceParamsDTO::from_allowance_data( $allowance_data );
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$response  = $client->invalid_allowance( $params );

			if (!$response instanceof IssueInvoiceResponseDTO) {
				return [];
			}

			$result = [
				'code'   => $response->code,
				'msg'    => $response->msg,
				'status' => 'allowance_invalid',
			];

			// 成功作廢折讓：清除折讓資料
			$meta_keys->clear_allowance_data();

			return $result;
		} catch (\Throwable $e) {
			self::logger( "作廢折讓失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 查詢發票明細（唯讀，invoice_query）
	 *
	 * 以已開立發票號碼查詢；未開立則回空陣列。
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> 發票明細；查無或失敗回空陣列
	 */
	public function query_invoice( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		try {
			$invoice_number = $this->get_invoice_number( $order );
			if ('' === $invoice_number) {
				return [];
			}

			$params    = InvoiceQueryParamsDTO::by_invoice_number( $invoice_number );
			$requester = new Requester( $order );
			$client    = new ApiClient( $order, $requester );
			$result    = $client->query_invoice( $params );

			return \is_array( $result ) ? $result : [];
		} catch (\Throwable $e) {
			self::logger( "發票查詢失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
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
}
