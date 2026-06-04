<?php
/**
 * 綠界電子發票服務提供者
 *
 * 與 Amego 並列，後台可切換。實作 IInvoiceService：issue / cancel / get_invoice_number / get_settings。
 * issue / cancel 皆為冪等（已有對應 meta 直接回傳，不重打 API）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Services;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\CancelParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Http\InvoiceApiClient;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/** 綠界電子發票服務提供者 */
final class EcpayInvoiceProvider extends BaseService implements IInvoiceService {
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
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> API 資料
	 */
	public function issue( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已開立過直接回傳
		$meta_keys   = new MetaKeys( $order );
		$issued_data = $meta_keys->get_issued_data();
		if (\is_array( $issued_data ) && $issued_data) {
			/** @var array<string, mixed> $issued_data */
			return $issued_data;
		}
		// endregion 冪等

		try {
			$settings = EcpayInvoiceSettingsDTO::instance();
			$is_b2b   = self::is_b2b( $order );

			$params   = IssueParams::from_order( $order, $settings->merchant_id );
			$client   = new InvoiceApiClient( $order );
			$response = $client->issue( $params, $is_b2b );

			if (!$response instanceof IssueResponse) {
				return [];
			}

			$result = self::build_issued_data( $response );
			$meta_keys->update_issued_data( $result );
			$meta_keys->update_provider_id( self::ID );

			return $result;
		} catch (\Throwable $e) {
			self::logger( "開立發票失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 作廢發票（冪等）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> API 資料
	 */
	public function cancel( \WC_Order|int $order_or_id ): array {
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
				return [];
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
			self::logger( "作廢發票失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
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
}
