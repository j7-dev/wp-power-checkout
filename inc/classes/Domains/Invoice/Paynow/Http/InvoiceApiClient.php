<?php
/**
 * PayNow 立吉富電子發票 API client（體系 3）
 *
 * 傳輸結構：HTTP + application/json，認證以 Bearer JWT-Token（純 Bearer，無對稱加密信封 / CheckCode）。
 *   - 開立 / 作廢 / 折讓 / 折讓作廢：POST JSON body 至對應端點。
 *   - 查詢（取得發票資料）：GET + query string（InvoiceNumber / OrderNo …）。
 *
 * 回應外層固定結構：{ status, type, message, result, request_id }；`type === 'success'` 才算成功，
 * 內層 result 即為業務結果（PayNow 發票 API 無對稱簽章，Bearer Token 即為全部認證）。
 *
 * 對外提供五個業務方法（皆從訂單 / meta / 傳入參數自組）：
 *   issue()             開立發票（成功寫入 issued_data + provider_id meta，回 IssueResponse）。
 *   cancel()            作廢發票（回 IssueResponse；meta 清理由 provider 負責）。
 *   allowance()         開立折讓（回 AllowanceResponse）。
 *   invalid_allowance() 作廢折讓（回 AllowanceResponse）。
 *   query()             查詢發票（唯讀 GET，回 QueryResponse；不寫任何 meta）。
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，完全不對外發 HTTP 請求（CI 安全、測試隔離）。
 *
 * @see .claude/skills/paynow/references/invoice-api.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Paynow\Http;

use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\PaynowInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryParams;
use J7\PowerCheckout\Domains\Invoice\Paynow\DTOs\QueryResponse;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Plugin;

/** PayNow 立吉富電子發票 API client */
final class InvoiceApiClient {

	/** @var int wp_remote_* timeout（秒） */
	private const TIMEOUT = 60;

	/** @var string 開立發票端點（POST） */
	private const ENDPOINT_ISSUE = '/api/invoices/issue';

	/** @var string 作廢發票端點（POST） */
	private const ENDPOINT_CANCEL = '/api/invoices/cancel';

	/** @var string 開立折讓端點（POST） */
	private const ENDPOINT_ALLOWANCE = '/api/invoices/allowance';

	/** @var string 折讓作廢端點（POST） */
	private const ENDPOINT_CANCEL_ALLOWANCE = '/api/invoices/cancel-allowance';

	/** @var string 取得發票資料端點（GET） */
	private const ENDPOINT_QUERY = '/api/invoices';

	/** @var PaynowInvoiceSettingsDTO 設定（含 jwt_token 與 API base URL） */
	private readonly PaynowInvoiceSettingsDTO $settings;

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單（log / order note 與業務方法所需）.
	 */
	public function __construct(
		private readonly \WC_Order $order,
	) {
		$this->settings = PaynowInvoiceSettingsDTO::instance();
	}

	/**
	 * 開立發票
	 *
	 * 從訂單 + 結帳發票資訊自組 IssueParams（B2C/B2B 金額分流 + 載具映射）→ POST 請求 → 解析 result →
	 * 成功則寫入 issued_data（invoice_number / invoice_date / order_no / total_amount）與 provider_id meta。
	 *
	 * @param string $provider_id 發票服務 ID（寫入 provider_id meta，固定 'paynow_invoice'）.
	 *
	 * @return IssueResponse|null 成功回 IssueResponse；失敗回 null.
	 */
	public function issue( string $provider_id ): ?IssueResponse {
		try {
			$params = IssueParams::from_order( $this->order );
			$result = $this->post( self::ENDPOINT_ISSUE, $params->to_array() );
			if ( null === $result ) {
				return null;
			}

			$response = new IssueResponse( $result );
			if ( ! $response->is_success() ) {
				return null;
			}

			// 成功：寫入 issued_data + provider_id meta（鍵名對齊 PayNow 發票測試契約）.
			$meta_keys = new MetaKeys( $this->order );
			$meta_keys->update_issued_data(
				[
					'invoice_number' => $response->invoice_number,
					'invoice_date'   => $response->invoice_date,
					'order_no'       => $response->order_no,
					'total_amount'   => $response->total_amount,
				]
			);
			$meta_keys->update_provider_id( $provider_id );

			return $response;
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 開立發票失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 作廢發票
	 *
	 * 從 issued_data meta 取發票號碼自組請求 → POST。meta 清理由 provider 負責（成功後）。
	 *
	 * @return IssueResponse|null 成功回 IssueResponse；失敗回 null.
	 */
	public function cancel(): ?IssueResponse {
		try {
			$issued_data    = $this->get_issued_data();
			$invoice_number = (string) ( $issued_data['invoice_number'] ?? '' );
			if ( '' === $invoice_number ) {
				throw new \RuntimeException( '找不到發票號碼，無法作廢' );
			}

			$result = $this->post( self::ENDPOINT_CANCEL, [ 'invoice_number' => $invoice_number ] );
			if ( null === $result ) {
				return null;
			}

			// 作廢成功：以 result 建 IssueResponse；補上原發票號碼供呼叫端判定成功.
			$result['invoice_number'] = $result['invoice_number'] ?? $invoice_number;
			return new IssueResponse( $result );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 作廢發票失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 開立折讓（部分退款）
	 *
	 * @param AllowanceParams $params 折讓開立參數（含原發票號碼 + 折讓含稅金額）.
	 *
	 * @return AllowanceResponse|null 成功回 AllowanceResponse；失敗回 null.
	 */
	public function allowance( AllowanceParams $params ): ?AllowanceResponse {
		try {
			$result = $this->post( self::ENDPOINT_ALLOWANCE, $params->to_array() );
			if ( null === $result ) {
				return null;
			}

			$response = new AllowanceResponse( $result );
			return $response->is_success() ? $response : null;
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 開立折讓失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 作廢折讓
	 *
	 * @param array<string, mixed> $allowance_data 已開立折讓 meta（含 allowance_number）.
	 *
	 * @return AllowanceResponse|null 成功回 AllowanceResponse；失敗回 null.
	 */
	public function invalid_allowance( array $allowance_data ): ?AllowanceResponse {
		try {
			$allowance_number = (string) ( $allowance_data['allowance_number'] ?? '' );
			if ( '' === $allowance_number ) {
				throw new \RuntimeException( '找不到折讓號碼，無法作廢折讓' );
			}

			$result = $this->post( self::ENDPOINT_CANCEL_ALLOWANCE, [ 'allowance_number' => $allowance_number ] );
			if ( null === $result ) {
				return null;
			}

			// 補上折讓號供呼叫端判定成功.
			$result['allowance_number'] = $result['allowance_number'] ?? $allowance_number;
			return new AllowanceResponse( $result );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 作廢折讓失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 查詢發票明細（唯讀，GET /api/invoices）
	 *
	 * 以發票號碼（query string）查詢；不寫任何 meta、不改訂單狀態。
	 *
	 * @param QueryParams $params 查詢參數（含 InvoiceNumber query string 欄位）.
	 *
	 * @return QueryResponse|null 成功回 QueryResponse；失敗回 null.
	 */
	public function query( QueryParams $params ): ?QueryResponse {
		try {
			$result = $this->get( self::ENDPOINT_QUERY, $params->to_array() );
			if ( null === $result ) {
				return null;
			}

			return new QueryResponse( $this->resolve_query_invoice( $result ) );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 查詢發票失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 發送 POST JSON 請求並回傳解析後的 result（陣列）
	 *
	 * MOCK 模式回固定 fixture，不外呼。實模式：Bearer header + JSON body → wp_remote_post → decode_result。
	 * 任何失敗（網路 / type≠success）皆 catch 後回 null（錯誤碼寫入 log）。
	 *
	 * @param string               $endpoint 端點 path.
	 * @param array<string, mixed> $body     request body 業務欄位.
	 *
	 * @return array<string, mixed>|null 成功回 result 陣列；失敗回 null.
	 */
	private function post( string $endpoint, array $body ): ?array {
		try {
			if ( self::is_mock() ) {
				return $this->decode_result( $this->mock_response( $endpoint ) );
			}

			$api_url = $this->settings->api_url() . $endpoint;

			Plugin::logger(
				"PayNow 發票 POST {$endpoint} 請求 #{$this->order->get_id()}",
				'info',
				[ 'api_url' => $api_url ]
			);

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => (string) \wp_json_encode( $body ),
					'headers'  => $this->build_headers(),
					'method'   => 'POST',
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			return $this->handle_response( $response );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 發票 POST {$endpoint} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 發送 GET 請求並回傳解析後的 result（陣列）
	 *
	 * MOCK 模式回固定 fixture，不外呼。實模式：Bearer header + query string → wp_remote_get → decode_result。
	 *
	 * @param string                $endpoint     端點 path.
	 * @param array<string, string> $query_params query string 欄位.
	 *
	 * @return array<string, mixed>|null 成功回 result 陣列；失敗回 null.
	 */
	private function get( string $endpoint, array $query_params ): ?array {
		try {
			if ( self::is_mock() ) {
				return $this->decode_result( $this->mock_response( $endpoint ) );
			}

			$api_url = \add_query_arg(
				\array_map( 'rawurlencode', $query_params ),
				$this->settings->api_url() . $endpoint
			);

			Plugin::logger(
				"PayNow 發票 GET {$endpoint} 請求 #{$this->order->get_id()}",
				'info',
				[ 'api_url' => $api_url ]
			);

			$response = \wp_remote_get(
				$api_url,
				[
					'headers'  => $this->build_headers(),
					'method'   => 'GET',
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			return $this->handle_response( $response );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ PayNow 發票 GET {$endpoint} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 組裝請求 headers（Bearer JWT-Token + JSON Content-Type）
	 *
	 * @return array<string, string> 請求 headers.
	 */
	private function build_headers(): array {
		return [
			'Authorization' => "Bearer {$this->settings->jwt_token}",
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		];
	}

	/**
	 * 解析 wp_remote_* 回應外層，回傳解析後的 result 陣列
	 *
	 * @param array<string, mixed>|\WP_Error $response wp_remote_* 回應.
	 *
	 * @return array<string, mixed> 解析後的 result 陣列.
	 * @throws \RuntimeException 當網路錯誤或外層 type≠success.
	 */
	private function handle_response( $response ): array {
		if ( \is_wp_error( $response ) ) {
			throw new \RuntimeException( $response->get_error_message() );
		}

		/** @var array<string, mixed> $body */
		$body = (array) \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

		return $this->decode_result( $body );
	}

	/**
	 * 解析 PayNow 回應外層 { status, type, message, result, request_id }，回傳內層 result 陣列
	 *
	 * 步驟：
	 *  1. 驗 type === 'success'，否則 throw \RuntimeException（訊息含 message）。
	 *  2. 回傳內層 result 陣列（缺則空陣列）。
	 *
	 * @param array<string, mixed> $response 外層回應 { status, type, message, result, request_id }.
	 *
	 * @return array<string, mixed> 內層 result 陣列.
	 * @throws \RuntimeException 當 type≠success.
	 */
	private function decode_result( array $response ): array {
		$type = (string) ( $response['type'] ?? '' );
		if ( 'success' !== $type ) {
			$message = (string) ( $response['message'] ?? 'unknown' );
			throw new \RuntimeException( "PayNow 發票回應失敗 type={$type}：{$message}" );
		}

		$result = $response['result'] ?? [];
		return \is_array( $result ) ? $result : [];
	}

	/**
	 * 由查詢 result 取出單一發票物件
	 *
	 * 查詢端點 result 可能為「單一發票物件」或「發票清單（含 invoice_number 即視為單筆，否則取首筆）」。
	 *
	 * @param array<string, mixed> $result decode 後的查詢 result.
	 *
	 * @return array<string, mixed> 單一發票物件.
	 */
	private function resolve_query_invoice( array $result ): array {
		// 已是單一發票物件（含 invoice_number 鍵）.
		if ( isset( $result['invoice_number'] ) ) {
			return $result;
		}

		// 清單形式：取首筆關聯陣列.
		foreach ( $result as $item ) {
			if ( \is_array( $item ) ) {
				/** @var array<string, mixed> $item */
				return $item;
			}
		}

		return $result;
	}

	/**
	 * 從 order 取得已開立發票 meta（陣列）
	 *
	 * @return array<string, mixed> issued_data meta（無則空陣列）.
	 */
	private function get_issued_data(): array {
		$issued_data = ( new MetaKeys( $this->order ) )->get_issued_data();
		return \is_array( $issued_data ) ? $issued_data : [];
	}

	/** @return bool 是否為 MOCK 模式（API_MODE=mock，測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * MOCK 回應（外層信封 { status, type, message, result, request_id }，result 依端點回固定 fixture）
	 *
	 * 此 fixture 模擬 PayNow 發票 API 成功回應（type=success）；零外呼，CI 安全。
	 *
	 * @param string $endpoint 端點 path.
	 *
	 * @return array<string, mixed> 模擬的外層回應.
	 */
	private function mock_response( string $endpoint ): array {
		$result = match ( $endpoint ) {
			self::ENDPOINT_ISSUE            => $this->mock_issue_result(),
			self::ENDPOINT_CANCEL           => $this->mock_cancel_result(),
			self::ENDPOINT_ALLOWANCE        => $this->mock_allowance_result(),
			self::ENDPOINT_CANCEL_ALLOWANCE => $this->mock_cancel_allowance_result(),
			self::ENDPOINT_QUERY            => $this->mock_query_result(),
			default                         => [],
		};

		return [
			'status'     => 200,
			'type'       => 'success',
			'message'    => '',
			'result'     => $result,
			'request_id' => 'mock-request-id',
		];
	}

	/**
	 * 開立發票 MOCK result
	 *
	 * @return array<string, mixed>
	 */
	private function mock_issue_result(): array {
		return [
			'invoice_number' => 'AB12345678',
			'invoice_date'   => \gmdate( 'Y-m-d\TH:i:s' ),
			'order_no'       => IssueParams::build_merchant_order_no( $this->order ),
			'total_amount'   => (int) \round( (float) $this->order->get_total() ),
		];
	}

	/**
	 * 作廢發票 MOCK result（含原發票號碼，供 IssueResponse::is_success() 判定）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_cancel_result(): array {
		return [
			'invoice_number' => 'AB12345678',
			'invoice_date'   => \gmdate( 'Y-m-d\TH:i:s' ),
		];
	}

	/**
	 * 開立折讓 MOCK result
	 *
	 * @return array<string, mixed>
	 */
	private function mock_allowance_result(): array {
		return [
			'allowance_number' => 'A20260101000001',
			'invoice_number'   => 'AB12345678',
			'allowance_date'   => \gmdate( 'Y-m-d\TH:i:s' ),
			'allowance_amount' => 300,
			'remain_amount'    => 750,
		];
	}

	/**
	 * 作廢折讓 MOCK result（含折讓號，供 AllowanceResponse::is_success() 判定）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_cancel_allowance_result(): array {
		return [
			'allowance_number' => 'A20260101000001',
			'allowance_date'   => \gmdate( 'Y-m-d\TH:i:s' ),
		];
	}

	/**
	 * 查詢發票 MOCK result（單一發票物件）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_query_result(): array {
		return [
			'invoice_number' => 'AB12345678',
			'invoice_status' => 'issued',
			'total_amount'   => (int) \round( (float) $this->order->get_total() ),
			'invoice_date'   => \gmdate( 'Y-m-d\TH:i:s' ),
			'order_no'       => IssueParams::build_merchant_order_no( $this->order ),
		];
	}
}
