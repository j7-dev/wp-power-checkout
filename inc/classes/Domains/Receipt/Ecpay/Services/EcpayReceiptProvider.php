<?php
/**
 * 綠界電子收據服務提供者
 *
 * 與電子發票（Amego / 綠界發票）並存可選，後台可同時啟用。實作 IReceiptService：
 * issue / cancel / get_receipt_number / get_settings。
 * issue / cancel 皆為冪等（已有對應 meta 直接回傳，不重打 API）。
 *
 * 收據類型（一般 / 公益 / 政治）由設定 default_receipt_type 決定，於 ReceiptIssueParams 組裝。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Services;

use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\EcpayReceiptSettingsDTO;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptCancelParams;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptIssueParams;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptIssueResponse;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Http\ReceiptApiClient;
use J7\PowerCheckout\Domains\Receipt\Shared\Helpers\ReceiptMetaKeys;
use J7\PowerCheckout\Domains\Receipt\Shared\Interfaces\IReceiptService;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/** 綠界電子收據服務提供者 */
final class EcpayReceiptProvider extends BaseService implements IReceiptService {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const ID = 'ecpay_receipt';

	/**
	 * 記錄 log（info / error / warning 會同步記錄到 order note）
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | warning | debug ...
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
	 * 開立收據（冪等）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> API 資料
	 */
	public function issue( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已開立過直接回傳
		$meta_keys   = new ReceiptMetaKeys( $order );
		$issued_data = $meta_keys->get_issued_data();
		if ($issued_data) {
			return $issued_data;
		}
		// endregion 冪等

		try {
			$settings = EcpayReceiptSettingsDTO::instance();
			$params   = ReceiptIssueParams::from_order( $order, $settings );

			// 政治獻金金額上限防呆（送出前驗證，違規不靜默截斷）
			$violation = $params->check_amount_limit();
			if (null !== $violation) {
				self::logger( "開立收據前驗證失敗 #{$order->get_id()}： {$violation}", 'error', [], 0, $order );
				return [];
			}

			$client   = new ReceiptApiClient( $order );
			$response = $client->issue( $params );

			if (!$response instanceof ReceiptIssueResponse) {
				return [];
			}

			$result = self::build_issued_data( $response, $params->ReceiptType );
			$meta_keys->update_issued_data( $result );
			$meta_keys->update_provider_id( self::ID );

			return $result;
		} catch (\Throwable $e) {
			self::logger( "開立收據失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 作廢收據（冪等）
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array<string, mixed> API 資料
	 */
	public function cancel( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id );

		// region 冪等：已作廢過直接回傳
		$meta_keys      = new ReceiptMetaKeys( $order );
		$cancelled_data = $meta_keys->get_cancelled_data();
		if ($cancelled_data) {
			return $cancelled_data;
		}
		// endregion 冪等

		try {
			$settings = EcpayReceiptSettingsDTO::instance();

			$issued_data = $meta_keys->get_issued_data();

			$params   = ReceiptCancelParams::from_issued_data( $settings->merchant_id, $issued_data );
			$client   = new ReceiptApiClient( $order );
			$response = $client->cancel( $params );

			if (!$response instanceof ReceiptIssueResponse) {
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
			self::logger( "作廢收據失敗 #{$order->get_id()}： {$e->getMessage()}", 'error', [], 5, $order );
			return [];
		}
	}

	/**
	 * 取得收據編號
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	public function get_receipt_number( \WC_Order $order ): string {
		$issued_data = ( new ReceiptMetaKeys( $order ) )->get_issued_data();
		if (isset( $issued_data['receipt_number'] )) {
			return (string) $issued_data['receipt_number'];
		}
		return '';
	}

	/**
	 * @param bool $with_default 是否帶預設值（true）或只拿 DB 值（false）
	 *
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			$option = ProviderUtils::get_option( self::ID );
			return \is_array( $option ) ? $option : [];
		}
		return EcpayReceiptSettingsDTO::instance()->to_array();
	}

	/**
	 * 將回應整理為要儲存的 issued_data
	 *
	 * @param ReceiptIssueResponse $response     開立回應
	 * @param int                  $receipt_type 收據類型
	 *
	 * @return array<string, mixed>
	 */
	private static function build_issued_data( ReceiptIssueResponse $response, int $receipt_type ): array {
		return [
			'receipt_number' => $response->get_receipt_number(),
			'relate_number'  => $response->RelateNumber,
			'receipt_date'   => $response->ReceiptDate,
			'receipt_type'   => $receipt_type,
			'rtn_code'       => $response->RtnCode,
			'rtn_msg'        => $response->RtnMsg,
		];
	}
}
