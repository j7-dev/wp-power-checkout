<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\UniParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Http\AmegoApiException;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\PiiMasker;
use J7\WpUtils\Classes\DTO;


/**
 * Requester 請求器
 * 用來發請求 & 格式化回應
 * 預先填好 Header
 *
 * 正規化錯誤模型（einvoice 導入第四階段-c）：本層既有對外回傳契約不變
 * （post/post_data 成功回 IssueInvoiceResponseDTO、失敗仍回 null；query 成功回 array、失敗回 null），
 * 但每次請求進入時重置 $last_error_detail，失敗時於 catch 落地結構化錯誤明細
 * （raw_code / raw_message / raw / kind）供上層 provider 的 map_error() 做正規化映射，
 * 讀取入口為 {@see self::get_last_error_detail()}。
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class Requester {

	private const API_URL = 'https://invoice-api.amego.tw'; // 目前測試或正式都請用同一個 API 網址

	private const TIMEOUT = 60;

	/**
	 * 最後一次失敗的結構化錯誤明細（供 provider 的 map_error 做正規化映射）
	 *
	 * 既有對外回傳契約不變（成功回 DTO / array、失敗仍回 null）；本欄為「附加的」錯誤明細管道。
	 * 每次 post / post_data / query 進入時重置為 null，失敗時於 catch 落地（raw_code / raw_message / raw / kind）。
	 *
	 * @var array{raw_code: string, raw_message: string, raw: string, kind: string}|null
	 */
	private ?array $last_error_detail = null;

	/**
	 * MOCK 錯誤注入（測試用）：非 null 時 mock 路徑回此外層回應，覆寫成功 fixture
	 *
	 * 讓錯誤路徑測試（code=16 / 22 / 3050141 / 未涵蓋碼…）能在 API_MODE=mock 下注入「code 非 0」的
	 * 外層回應，觸發 business 錯誤路徑（與 ezPay InvoiceApiClient::$mock_error_override 機制一致）。
	 * 測試 tearDown 必須 reset 為 null。形狀：{ code: int, msg?: string, ... }。
	 *
	 * @var array<string, mixed>|null
	 */
	public static ?array $mock_error_override = null;

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $order
	) {}

	/**
	 * 取得最後一次失敗的結構化錯誤明細
	 *
	 * 由 provider 在本層業務方法回 null 後呼叫，取得 raw_code / raw_message / raw / kind，
	 * 交給自身的 map_error() 做正規化映射。null 代表「無錯誤明細」（成功或未呼叫）。
	 *
	 * @return array{raw_code: string, raw_message: string, raw: string, kind: string}|null 錯誤明細.
	 */
	public function get_last_error_detail(): ?array {
		return $this->last_error_detail;
	}

	/**
	 * 發送請求
	 *
	 * @param EApi $api 要呼叫哪個 api
	 *
	 * @return IssueInvoiceResponseDTO|null 回應資料
	 */
	public function post( EApi $api ): ?IssueInvoiceResponseDTO {
		// 每次請求重置錯誤明細（成功路徑保持 null）.
		$this->last_error_detail = null;

		try {

			// MOCK 模式：不打真 API，回固定 fixture（CI 安全、測試隔離）；可經 $mock_error_override 注入錯誤回應.
			if ( self::is_mock() ) {
				return $this->decode_mock_response( $api );
			}

			$request_body_dto = $api->prepare_request_param( $this->order );
			return $this->send( $api, $request_body_dto );
		} catch ( \Throwable $e ) {
			// 落地結構化錯誤明細供 provider map_error；既有 null 回傳契約不變.
			$this->last_error_detail = self::to_error_detail( $e );

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
		// 每次請求重置錯誤明細（成功路徑保持 null）.
		$this->last_error_detail = null;

		try {
			// MOCK 模式：不打真 API，回固定 fixture（CI 安全、測試隔離）；可經 $mock_error_override 注入錯誤回應.
			if ( self::is_mock() ) {
				return $this->decode_mock_response( $api );
			}

			return $this->send( $api, $dto );
		} catch ( \Throwable $e ) {
			// 落地結構化錯誤明細供 provider map_error；既有 null 回傳契約不變.
			$this->last_error_detail = self::to_error_detail( $e );

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
		// 每次請求重置錯誤明細（成功路徑保持 null）.
		$this->last_error_detail = null;

		try {
			// MOCK 模式：不打真 API，回固定 fixture（CI 安全、測試隔離）；可經 $mock_error_override 注入錯誤回應.
			if ( self::is_mock() ) {
				if ( null !== self::$mock_error_override ) {
					return $this->decode_query_body( self::$mock_error_override );
				}
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
				// 對外連線失敗 / 逾時 → kind=network，provider 映射 NETWORK.
				throw new AmegoApiException(
					$response->get_error_message(),
					'',
					$response->get_error_message(),
					AmegoApiException::KIND_NETWORK
				);
			}

			/** @var array{code?: int, msg?: string, data?: mixed} $response_body */
			$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			return $this->decode_query_body( $response_body );
		} catch ( \Throwable $e ) {
			// 落地結構化錯誤明細供 provider map_error；既有 null 回傳契約不變.
			$this->last_error_detail = self::to_error_detail( $e );

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
	 * 解析查詢類回應外層，回傳 data 陣列（code 非 0 即丟 business 例外攜帶 raw_code）
	 *
	 * @param array<string, mixed> $response_body 外層回應 { code, msg, data? }.
	 *
	 * @return array<string, mixed> 回應 data（成功）.
	 * @throws AmegoApiException 當 code 非 0（business，攜帶 raw_code = code 字串）.
	 */
	private function decode_query_body( array $response_body ): array {
		$code = (int) ( $response_body['code'] ?? -1 );
		if ( 0 !== $code ) {
			$msg = (string) ( $response_body['msg'] ?? "code={$code}" );
			// code 即 Amego 原始錯誤碼（如 16 / 22 / 3050141）→ 攜帶 raw_code 供 provider map_error.
			throw new AmegoApiException(
				"Amego 查詢回應失敗 code={$code}：{$msg}",
				(string) $code,
				$msg,
				AmegoApiException::KIND_BUSINESS
			);
		}

		/** @var array<string, mixed> $data */
		$data = \is_array( $response_body['data'] ?? null ) ? $response_body['data'] : $response_body;
		return $data;
	}

	/**
	 * 實際發送 HTTP 請求（共用 issue / cancel / 折讓 / 查詢）
	 *
	 * @param EApi $api              端點
	 * @param DTO  $request_body_dto 請求參數 DTO
	 *
	 * @return IssueInvoiceResponseDTO 回應資料（成功）
	 * @throws AmegoApiException 請求失敗（network）/ 業務失敗（business，攜帶 raw_code = code 字串）
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
			// 對外連線失敗 / 逾時 → kind=network，provider 映射 NETWORK.
			throw new AmegoApiException(
				$response->get_error_message(),
				'',
				$response->get_error_message(),
				AmegoApiException::KIND_NETWORK
			);
		}

		/** @var array<string, mixed>|array{code: int, msg: string} $response_body */
		$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

		$response_dto = $this->build_response_dto( $response_body );

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

	/**
	 * 將回應外層建成成功的 IssueInvoiceResponseDTO（code 非 0 即丟 business 例外攜帶 raw_code）
	 *
	 * 開立 / 作廢 / 折讓共用此解析：成功（code=0）回 DTO；失敗丟 {@see AmegoApiException}（kind=business），
	 * 攜帶 raw_code = code 字串（如 '16' / '22' / '3050141'）供上層 provider map_error 做正規化映射。
	 *
	 * @param array<string, mixed> $response_body 外層回應 { code, msg, ... }.
	 *
	 * @return IssueInvoiceResponseDTO 成功回應 DTO.
	 * @throws AmegoApiException 當 code 非 0（business，攜帶 raw_code = code 字串）.
	 */
	private function build_response_dto( array $response_body ): IssueInvoiceResponseDTO {
		$response_dto = new IssueInvoiceResponseDTO( $response_body );
		if ( $response_dto->is_success() ) {
			return $response_dto;
		}

		$code = (int) ( $response_body['code'] ?? -1 );
		$msg  = (string) ( $response_body['msg'] ?? $response_dto->msg );
		// code 即 Amego 原始錯誤碼（如 16 簽章 / 22 未申請 / 3050141 已開折讓）→ 攜帶 raw_code 供 provider map_error.
		throw new AmegoApiException(
			"Amego 回應失敗 code={$code}：{$msg}",
			(string) $code,
			$msg,
			AmegoApiException::KIND_BUSINESS
		);
	}

	/**
	 * MOCK 模式解析（開立 / 作廢 / 折讓共用）：可經 $mock_error_override 注入錯誤外層回應
	 *
	 * 注入時走 {@see self::build_response_dto()} 解析（觸發 business 錯誤路徑）；未注入回固定成功 fixture。
	 *
	 * @param EApi $api 端點.
	 *
	 * @return IssueInvoiceResponseDTO 成功回應 DTO.
	 * @throws AmegoApiException 當注入了 code 非 0 的外層回應（business）.
	 */
	private function decode_mock_response( EApi $api ): IssueInvoiceResponseDTO {
		if ( null !== self::$mock_error_override ) {
			return $this->build_response_dto( self::$mock_error_override );
		}
		return self::mock_response( $api );
	}

	/**
	 * 將攔截到的例外正規化為「錯誤明細」（raw_code / raw_message / raw / kind）
	 *
	 * AmegoApiException 攜帶 Amego 原始碼與種類，原樣映射；其餘 \Throwable（JSON decode / 準備參數等）
	 * 一律歸 decode 種類（無 raw_code），交由 provider 映射 PROVIDER。
	 *
	 * @param \Throwable $e 攔截到的例外.
	 *
	 * @return array{raw_code: string, raw_message: string, raw: string, kind: string} 錯誤明細.
	 */
	private static function to_error_detail( \Throwable $e ): array {
		if ( $e instanceof AmegoApiException ) {
			return [
				'raw_code'    => $e->get_raw_code(),
				'raw_message' => $e->get_raw_message(),
				'raw'         => $e->getMessage(),
				'kind'        => $e->get_kind(),
			];
		}

		return [
			'raw_code'    => '',
			'raw_message' => $e->getMessage(),
			'raw'         => $e->getMessage(),
			'kind'        => AmegoApiException::KIND_DECODE,
		];
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
