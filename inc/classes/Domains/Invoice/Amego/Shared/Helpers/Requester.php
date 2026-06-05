<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\UniParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\PiiMasker;
use J7\WpUtils\Classes\DTO;


/**
 * Requester 請求器
 * 用來發請求 & 格式化回應
 * 預先填好 Header
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class Requester {

	private const API_URL = 'https://invoice-api.amego.tw'; // 目前測試或正式都請用同一個 API 網址

	private const TIMEOUT = 60;

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $order
	) {}

	/**
	 * 發送請求
	 *
	 * @param EApi $api 要呼叫哪個 api
	 *
	 * @return IssueInvoiceResponseDTO|null 回應資料
	 */
	public function post( EApi $api ): ?IssueInvoiceResponseDTO {
		try {

			// MOCK 模式：不打真 API，回固定 fixture（CI 安全、測試隔離）
			if ( self::is_mock() ) {
				return self::mock_response( $api );
			}

			$request_body_dto = $api->prepare_request_param( $this->order );
			return $this->send( $api, $request_body_dto );
		} catch ( \Throwable $e ) {
			// LOG 記錄
			AmegoProvider::logger(
				"❌ {$api->label()} {$api->value} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5,
				$this->order
			);
			return null;
		}
	}

	/**
	 * 以「已備妥的 DTO」發送請求（折讓 / 查詢類使用）
	 *
	 * 折讓（g0401/g0501）與查詢類端點的 data 形狀由各自的 DTO 決定，
	 * 無法用 EApi::prepare_request_param( $order ) 統一準備，故獨立此方法。
	 *
	 * @param EApi $api 端點
	 * @param DTO  $dto 已備妥的請求參數 DTO
	 *
	 * @return IssueInvoiceResponseDTO|null 回應資料
	 */
	public function post_data( EApi $api, DTO $dto ): ?IssueInvoiceResponseDTO {
		try {
			// MOCK 模式：不打真 API，回固定 fixture（CI 安全、測試隔離）
			if ( self::is_mock() ) {
				return self::mock_response( $api );
			}

			return $this->send( $api, $dto );
		} catch ( \Throwable $e ) {
			// LOG 記錄
			AmegoProvider::logger(
				"❌ {$api->label()} {$api->value} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5,
				$this->order
			);
			return null;
		}
	}

	/**
	 * 查詢類請求（唯讀），回傳完整回應 data（陣列），而非 IssueInvoiceResponseDTO
	 *
	 * 用於 invoice_query / allowance_query / lottery_status / track_get 等查詢端點，
	 * 這些端點回傳的 data 結構各異（含明細 / 折讓 / 中獎清單），不套用開立 DTO。
	 *
	 * @param EApi $api 端點
	 * @param DTO  $dto 已備妥的查詢參數 DTO
	 *
	 * @return array<string, mixed>|null 回應 data；失敗回 null
	 */
	public function query( EApi $api, DTO $dto ): ?array {
		try {
			// MOCK 模式：不打真 API，回固定 fixture（CI 安全、測試隔離）
			if ( self::is_mock() ) {
				return self::mock_query_response( $api );
			}

			$uni_params = UniParamsDTO::create( $dto );
			$api_url    = self::API_URL . $api->value;

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => \http_build_query( $uni_params->to_array() ),
					'headers'  => [
						'Content-Type' => 'application/x-www-form-urlencoded',
					],
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			if ( \is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			/** @var array{code?: int, msg?: string, data?: mixed} $response_body */
			$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			$code = (int) ( $response_body['code'] ?? -1 );
			if ( 0 !== $code ) {
				throw new \Exception( (string) ( $response_body['msg'] ?? "code={$code}" ) );
			}

			/** @var array<string, mixed> $data */
			$data = \is_array( $response_body['data'] ?? null ) ? $response_body['data'] : $response_body;
			return $data;
		} catch ( \Throwable $e ) {
			AmegoProvider::logger(
				"❌ {$api->label()} {$api->value} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5,
				$this->order
			);
			return null;
		}
	}

	/**
	 * 實際發送 HTTP 請求（共用 issue / cancel / 折讓 / 查詢）
	 *
	 * @param EApi $api              端點
	 * @param DTO  $request_body_dto 請求參數 DTO
	 *
	 * @return IssueInvoiceResponseDTO 回應資料（成功）
	 * @throws \Exception 請求失敗 / 業務失敗
	 */
	private function send( EApi $api, DTO $request_body_dto ): IssueInvoiceResponseDTO {
		$uni_params = UniParamsDTO::create( $request_body_dto );
		$api_url    = self::API_URL . $api->value;

		// LOG 記錄（安全：請求 body 遮蔽 PII 後才入 log）
		AmegoProvider::logger(
			"{$api->label()} {$api->value} 請求參數 #{$this->order->get_id()}",
			'info',
			[
				'api_url'      => $api_url,
				'request_body' => PiiMasker::mask_invoice_data( $request_body_dto->to_array() ),
			],
		);

		$response = \wp_remote_post(
			$api_url,
			[
				'body'     => \http_build_query( $uni_params->to_array() ),
				'headers'  => [
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				'blocking' => true,
				'timeout'  => self::TIMEOUT,
			]
		);

		if ( \is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		/** @var array<string, mixed>|array{code: int, msg: string} $response_body */
		$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

		$response_dto = new IssueInvoiceResponseDTO( $response_body );
		if ( !$response_dto->is_success() ) {
			throw new \Exception( $response_dto->msg );
		}

		// LOG 記錄（安全：成功回應遮蔽 PII 後才寫入 order note；作廢 / 折讓作廢不帶內容）
		AmegoProvider::logger(
			"✅ {$api->label()} {$api->value} 成功 #{$this->order->get_id()}",
			'info',
			\in_array( $api, [ EApi::CANCEL, EApi::ALLOWANCE_INVALID ], true ) ? [] : PiiMasker::mask_invoice_data( $response_body ),
			0,
			$this->order
		);

		return $response_dto;
	}

	/** @return bool 是否為 MOCK 模式（測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * MOCK 回應（固定 fixture）
	 *
	 * 開立回固定發票號碼與隨機碼；作廢僅回 code/msg。
	 * 與其餘 6 個 API client 的 mock 行為一致。
	 *
	 * @param EApi $api 端點
	 *
	 * @return IssueInvoiceResponseDTO
	 */
	private static function mock_response( EApi $api ): IssueInvoiceResponseDTO {
		if ( EApi::ISSUE === $api ) {
			return new IssueInvoiceResponseDTO(
				[
					'code'           => 0,
					'msg'            => 'OK',
					'invoice_number' => 'AG00000001',
					'invoice_time'   => \time(),
					'random_number'  => '1234',
				]
			);
		}

		// 作廢 / 折讓開立 / 折讓作廢：g0401 / g0501 僅回 code/msg fixture
		return new IssueInvoiceResponseDTO(
			[
				'code' => 0,
				'msg'  => 'OK',
			]
		);
	}

	/**
	 * 查詢類 MOCK 回應（固定 fixture）
	 *
	 * @param EApi $api 端點
	 *
	 * @return array<string, mixed>
	 */
	private static function mock_query_response( EApi $api ): array {
		return match ( $api ) {
			EApi::INVOICE_QUERY => [
				'invoice_number' => 'AG00000001',
				'invoice_status' => 99,
				'invoice_date'   => \gmdate( 'Ymd' ),
				'random_number'  => '1234',
				'total_amount'   => 100,
				'product_item'   => [],
				'allowance'      => [],
			],
			EApi::ALLOWANCE_QUERY => [
				'allowance_number' => 'AG00000001ALW',
				'invoice_status'   => 99,
				'total_amount'     => 48,
				'tax_amount'       => 2,
			],
			EApi::LOTTERY_STATUS => [
				'list' => [],
			],
			EApi::TRACK_GET => [
				'code'  => 'AG',
				'start' => '00000001',
				'end'   => '00000050',
			],
			default => [],
		};
	}
}
