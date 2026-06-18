<?php
/**
 * 綠界電子發票 API client
 *
 * 三層請求結構：{ MerchantID, RqHeader{ Timestamp, [RqID], Revision }, Data: AES加密(JSON) }
 * 雙層錯誤檢查：外層 TransCode === 1 → 解密 Data → 內層 RtnCode === 1
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/04-invoice-b2c.md
 * @see .claude/skills/ECPay-API-Skill/guides/05-invoice-b2b.md
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ecpay\Http;

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\AllowanceInvalidParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\AllowanceParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\CancelParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\QueryParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\PiiMasker;

/** 綠界電子發票 API client */
final class InvoiceApiClient {

	private const TIMEOUT = 60;

	/** @var EcpayInvoiceSettingsDTO 設定 */
	private readonly EcpayInvoiceSettingsDTO $settings;

	/** @var AesCrypto 加解密器 */
	private readonly AesCrypto $crypto;

	/**
	 * 最後一次失敗的結構化錯誤明細（供 provider 的 map_error 做正規化映射）
	 *
	 * 既有對外回傳契約不變（業務方法成功回 DTO / array、失敗仍回 null）；本欄為「附加的」錯誤明細管道。
	 * 每次業務方法（request / request_allowance / query）進入時重置為 null，失敗時於 catch 落地
	 * （raw_code / raw_message / raw / kind）。
	 *
	 * @var array{raw_code: string, raw_message: string, raw: string, kind: string}|null
	 */
	private ?array $last_error_detail = null;

	/**
	 * MOCK 錯誤注入（測試用）：非 null 時於 MOCK 模式覆寫成功 fixture，觸發錯誤路徑
	 *
	 * 讓錯誤路徑測試能在 API_MODE=mock 下注入下列任一形狀（零外呼）：
	 *   - { trans_code: int }（≠1）+ [trans_msg]    → 外層失敗：驗章類 TransMsg → SIGNATURE，否則 NETWORK
	 *   - { rtn_code: int }（≠1）+ [rtn_msg]         → 內層業務失敗 → KIND_BUSINESS → map_error(rtn_code, rtn_msg)
	 *   - { force_throw: true }                       → 觸發 client 內非預期 \Throwable（驗 never-throw → UNKNOWN）
	 * 測試 tearDown 必須 reset 為 null。
	 *
	 * @var array<string, mixed>|null
	 */
	public static ?array $mock_error_override = null;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order,
	) {
		$this->settings = EcpayInvoiceSettingsDTO::instance();
		$this->crypto   = new AesCrypto( $this->settings->hash_key, $this->settings->hash_iv );
	}

	/**
	 * 取得最後一次失敗的結構化錯誤明細
	 *
	 * 由 provider 在 client 業務方法回 null 後呼叫，取得 raw_code / raw_message / raw / kind，
	 * 交給自身的 map_error() 做正規化映射。null 代表「無錯誤明細」（成功或未呼叫）。
	 *
	 * @return array{raw_code: string, raw_message: string, raw: string, kind: string}|null 錯誤明細.
	 */
	public function get_last_error_detail(): ?array {
		return $this->last_error_detail;
	}

	/**
	 * 開立發票
	 *
	 * @param IssueParams $params 開立參數
	 * @param bool        $is_b2b 是否為 B2B（有統編）
	 *
	 * @return IssueResponse|null
	 */
	public function issue( IssueParams $params, bool $is_b2b ): ?IssueResponse {
		$api = $is_b2b ? EApi::B2B_ISSUE : EApi::B2C_ISSUE;
		return $this->request( $api, $params->to_array() );
	}

	/**
	 * 作廢發票
	 *
	 * @param CancelParams $params 作廢參數
	 * @param bool         $is_b2b 是否為 B2B
	 *
	 * @return IssueResponse|null
	 */
	public function cancel( CancelParams $params, bool $is_b2b ): ?IssueResponse {
		$api = $is_b2b ? EApi::B2B_INVALID : EApi::B2C_INVALID;
		return $this->request( $api, $params->to_array() );
	}

	/**
	 * 開立折讓（部分退款）
	 *
	 * @param AllowanceParams $params 折讓參數
	 * @param bool            $is_b2b 是否為 B2B
	 *
	 * @return AllowanceResponse|null
	 */
	public function issue_allowance( AllowanceParams $params, bool $is_b2b ): ?AllowanceResponse {
		$api = $is_b2b ? EApi::B2B_ALLOWANCE : EApi::B2C_ALLOWANCE;
		return $this->request_allowance( $api, $params->to_request_data( $is_b2b ) );
	}

	/**
	 * 作廢折讓
	 *
	 * @param AllowanceInvalidParams $params 折讓作廢參數
	 * @param bool                   $is_b2b 是否為 B2B
	 *
	 * @return AllowanceResponse|null
	 */
	public function invalid_allowance( AllowanceInvalidParams $params, bool $is_b2b ): ?AllowanceResponse {
		$api = $is_b2b ? EApi::B2B_ALLOWANCE_INVALID : EApi::B2C_ALLOWANCE_INVALID;
		return $this->request_allowance( $api, $params->to_request_data( $is_b2b ) );
	}

	/**
	 * 查詢發票明細（GetIssue，唯讀）
	 *
	 * 回傳解密後的內層 Data（陣列），含發票明細欄位；失敗回 null。
	 *
	 * @param QueryParams $params 查詢參數
	 *
	 * @return array<string, mixed>|null
	 */
	public function query( QueryParams $params ): ?array {
		$api = EApi::B2C_GET_ISSUE;

		// 每次請求重置錯誤明細（成功路徑保持 null）.
		$this->last_error_detail = null;

		try {
			// MOCK 模式：不打真 API，回固定 fixture（可經 $mock_error_override 注入錯誤回應）.
			if (self::is_mock()) {
				if (null !== self::$mock_error_override) {
					self::throw_mock_override( self::$mock_error_override );
				}
				return $this->mock_query_response();
			}

			$api_url  = $this->settings->get_api_url() . $api->value;
			$envelope = $this->build_envelope( $api, $params->to_request_data() );

			EcpayInvoiceProvider::logger(
				"{$api->label()} {$api->value} 請求 #{$this->order->get_id()}",
				'info',
				[
					'api_url' => $api_url,
					'data'    => PiiMasker::mask_invoice_data( $params->to_request_data() ),
				],
			);

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => (string) \wp_json_encode( $envelope ),
					'headers'  => [ 'Content-Type' => 'application/json' ],
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			if (\is_wp_error( $response )) {
				// 對外連線失敗 / 逾時 → kind=network，provider 映射 NETWORK.
				throw new EcpayInvoiceApiException(
					$response->get_error_message(),
					'',
					$response->get_error_message(),
					EcpayInvoiceApiException::KIND_NETWORK
				);
			}

			/** @var array{TransCode?: int, TransMsg?: string, Data?: string} $body */
			$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			$trans_code = (int) ( $body['TransCode'] ?? 0 );
			if (1 !== $trans_code) {
				$trans_msg = (string) ( $body['TransMsg'] ?? 'unknown' );
				// 外層失敗：驗章類 TransMsg → SIGNATURE，否則 NETWORK（AES/格式/傳輸層）.
				throw new EcpayInvoiceApiException(
					"TransCode={$trans_code} AES/格式錯誤: {$trans_msg}",
					'',
					$trans_msg,
					self::is_signature_message( $trans_msg )
						? EcpayInvoiceApiException::KIND_SIGNATURE
						: EcpayInvoiceApiException::KIND_NETWORK
				);
			}

			/** @var array<string, mixed> $decrypted */
			$decrypted = $this->crypto->decrypt( (string) ( $body['Data'] ?? '' ) );
			$rtn_code  = (int) ( $decrypted['RtnCode'] ?? 0 );
			if (1 !== $rtn_code) {
				$rtn_msg = (string) ( $decrypted['RtnMsg'] ?? '' );
				// 內層業務碼 → kind=business，provider map_error(RtnCode, RtnMsg).
				throw new EcpayInvoiceApiException(
					"RtnCode={$rtn_code} {$rtn_msg}",
					(string) $rtn_code,
					$rtn_msg,
					EcpayInvoiceApiException::KIND_BUSINESS
				);
			}

			return $decrypted;
		} catch (\Throwable $e) {
			// 落地結構化錯誤明細供 provider map_error；既有 null 回傳契約不變.
			$this->last_error_detail = self::to_error_detail( $e );

			EcpayInvoiceProvider::logger(
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
	 * 發送 AES-JSON 請求
	 *
	 * @param EApi                 $api  端點
	 * @param array<string, mixed> $data 內層 Data
	 *
	 * @return IssueResponse|null
	 */
	private function request( EApi $api, array $data ): ?IssueResponse {
		// 每次請求重置錯誤明細（成功路徑保持 null）.
		$this->last_error_detail = null;

		try {
			// MOCK 模式：不打真 API，回固定 fixture（可經 $mock_error_override 注入錯誤回應）.
			if (self::is_mock()) {
				if (null !== self::$mock_error_override) {
					self::throw_mock_override( self::$mock_error_override );
				}
				return $this->mock_response( $api );
			}

			$api_url  = $this->settings->get_api_url() . $api->value;
			$envelope = $this->build_envelope( $api, $data );

			EcpayInvoiceProvider::logger(
				"{$api->label()} {$api->value} 請求 #{$this->order->get_id()}",
				'info',
				[
					'api_url' => $api_url,
					// 安全：遮蔽 PII（Email / 手機 / 統編 / 載具 / 姓名 / 地址）後才入 log
					'data'    => PiiMasker::mask_invoice_data( $data ),
				],
			);

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => (string) \wp_json_encode( $envelope ),
					'headers'  => [ 'Content-Type' => 'application/json' ],
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			if (\is_wp_error( $response )) {
				// 對外連線失敗 / 逾時 → kind=network，provider 映射 NETWORK.
				throw new EcpayInvoiceApiException(
					$response->get_error_message(),
					'',
					$response->get_error_message(),
					EcpayInvoiceApiException::KIND_NETWORK
				);
			}

			/** @var array{TransCode?: int, TransMsg?: string, Data?: string} $body */
			$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			// 第一層：TransCode
			$trans_code = (int) ( $body['TransCode'] ?? 0 );
			if (1 !== $trans_code) {
				$trans_msg = (string) ( $body['TransMsg'] ?? 'unknown' );
				// 外層失敗：驗章類 TransMsg → SIGNATURE，否則 NETWORK（AES/格式/傳輸層）.
				throw new EcpayInvoiceApiException(
					"TransCode={$trans_code} AES/格式錯誤: {$trans_msg}",
					'',
					$trans_msg,
					self::is_signature_message( $trans_msg )
						? EcpayInvoiceApiException::KIND_SIGNATURE
						: EcpayInvoiceApiException::KIND_NETWORK
				);
			}

			// 第二層：解密 Data，檢查 RtnCode
			$decrypted    = $this->crypto->decrypt( (string) ( $body['Data'] ?? '' ) );
			$response_dto = new IssueResponse( $decrypted );
			if (!$response_dto->is_success()) {
				// 內層業務碼 → kind=business，provider map_error(RtnCode, RtnMsg).
				throw new EcpayInvoiceApiException(
					"RtnCode={$response_dto->RtnCode} {$response_dto->RtnMsg}",
					(string) $response_dto->RtnCode,
					$response_dto->RtnMsg,
					EcpayInvoiceApiException::KIND_BUSINESS
				);
			}

			EcpayInvoiceProvider::logger(
				"✅ {$api->label()} {$api->value} 成功 #{$this->order->get_id()}",
				'info',
				// 安全：成功回應寫入 order note 前同樣遮蔽 PII（發票號碼等非 PII 保留）
				$api->is_issue() ? PiiMasker::mask_invoice_data( $decrypted ) : [],
				0,
				$this->order
			);

			return $response_dto;
		} catch (\Throwable $e) {
			// 落地結構化錯誤明細供 provider map_error；既有 null 回傳契約不變.
			$this->last_error_detail = self::to_error_detail( $e );

			EcpayInvoiceProvider::logger(
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
	 * 建立三層請求結構
	 *
	 * @param EApi                 $api  端點
	 * @param array<string, mixed> $data 內層 Data
	 *
	 * @return array<string, mixed>
	 */
	private function build_envelope( EApi $api, array $data ): array {
		$rq_header = [
			'Timestamp' => \time(),
			'Revision'  => $api->revision(),
		];

		// B2B 需額外帶 RqID（UUID v4，每次唯一，用於冪等保護）
		if ($api->is_b2b()) {
			$rq_header['RqID'] = \wp_generate_uuid4();
		}

		return [
			'MerchantID' => $this->settings->merchant_id,
			'RqHeader'   => $rq_header,
			'Data'       => $this->crypto->encrypt( $data ),
		];
	}

	/**
	 * 發送折讓 AES-JSON 請求（回 AllowanceResponse）
	 *
	 * 與 request() 同樣的三層結構與雙層錯誤檢查，但回傳型別為 AllowanceResponse。
	 *
	 * @param EApi                 $api  端點
	 * @param array<string, mixed> $data 內層 Data
	 *
	 * @return AllowanceResponse|null
	 */
	private function request_allowance( EApi $api, array $data ): ?AllowanceResponse {
		// 每次請求重置錯誤明細（成功路徑保持 null）.
		$this->last_error_detail = null;

		try {
			// MOCK 模式：不打真 API，回固定 fixture（可經 $mock_error_override 注入錯誤回應）.
			if (self::is_mock()) {
				if (null !== self::$mock_error_override) {
					self::throw_mock_override( self::$mock_error_override );
				}
				return $this->mock_allowance_response( $api );
			}

			$api_url  = $this->settings->get_api_url() . $api->value;
			$envelope = $this->build_envelope( $api, $data );

			EcpayInvoiceProvider::logger(
				"{$api->label()} {$api->value} 請求 #{$this->order->get_id()}",
				'info',
				[
					'api_url' => $api_url,
					// 安全：遮蔽 PII 後才入 log
					'data'    => PiiMasker::mask_invoice_data( $data ),
				],
			);

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => (string) \wp_json_encode( $envelope ),
					'headers'  => [ 'Content-Type' => 'application/json' ],
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			if (\is_wp_error( $response )) {
				// 對外連線失敗 / 逾時 → kind=network，provider 映射 NETWORK.
				throw new EcpayInvoiceApiException(
					$response->get_error_message(),
					'',
					$response->get_error_message(),
					EcpayInvoiceApiException::KIND_NETWORK
				);
			}

			/** @var array{TransCode?: int, TransMsg?: string, Data?: string} $body */
			$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			// 第一層：TransCode
			$trans_code = (int) ( $body['TransCode'] ?? 0 );
			if (1 !== $trans_code) {
				$trans_msg = (string) ( $body['TransMsg'] ?? 'unknown' );
				// 外層失敗：驗章類 TransMsg → SIGNATURE，否則 NETWORK（AES/格式/傳輸層）.
				throw new EcpayInvoiceApiException(
					"TransCode={$trans_code} AES/格式錯誤: {$trans_msg}",
					'',
					$trans_msg,
					self::is_signature_message( $trans_msg )
						? EcpayInvoiceApiException::KIND_SIGNATURE
						: EcpayInvoiceApiException::KIND_NETWORK
				);
			}

			// 第二層：解密 Data，檢查 RtnCode
			$decrypted    = $this->crypto->decrypt( (string) ( $body['Data'] ?? '' ) );
			$response_dto = new AllowanceResponse( $decrypted );
			if (!$response_dto->is_success()) {
				// 內層業務碼 → kind=business，provider map_error(RtnCode, RtnMsg).
				throw new EcpayInvoiceApiException(
					"RtnCode={$response_dto->RtnCode} {$response_dto->RtnMsg}",
					(string) $response_dto->RtnCode,
					$response_dto->RtnMsg,
					EcpayInvoiceApiException::KIND_BUSINESS
				);
			}

			EcpayInvoiceProvider::logger(
				"✅ {$api->label()} {$api->value} 成功 #{$this->order->get_id()}",
				'info',
				$api->is_allowance() ? [ 'allowance_number' => $response_dto->get_allowance_number() ] : [],
				0,
				$this->order
			);

			return $response_dto;
		} catch (\Throwable $e) {
			// 落地結構化錯誤明細供 provider map_error；既有 null 回傳契約不變.
			$this->last_error_detail = self::to_error_detail( $e );

			EcpayInvoiceProvider::logger(
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
	 * MOCK 錯誤注入分流：依 $mock_error_override 形狀丟對應種類的 EcpayInvoiceApiException
	 *
	 * 在 MOCK 模式且 $mock_error_override 非 null 時由各業務方法呼叫，於回 fixture 前攔截：
	 *   - force_throw          → 丟一般 \RuntimeException（非本型別）→ catch 落地 KIND_DECODE，
	 *                            但供「provider 端 catch \Throwable → UNKNOWN」測試（client 回 null，
	 *                            provider 改走 error_from_client → PROVIDER）。
	 *     ⚠️ 真正驗 UNKNOWN 由 provider 內部 \Throwable 觸發；本注入用於「force_throw」直接丟出，
	 *     讓 request() catch 後落地，provider 仍可辨識。
	 *   - trans_code ≠ 1       → 外層失敗：驗章類 TransMsg（含 CheckMacValue / verify / AES）→ SIGNATURE，
	 *                            否則 → NETWORK。
	 *   - rtn_code ≠ 1         → 內層業務失敗 → KIND_BUSINESS（raw_code = rtn_code、raw_message = rtn_msg）。
	 *
	 * @param array<string, mixed> $override 注入內容.
	 *
	 * @return void
	 * @throws EcpayInvoiceApiException 依注入形狀丟對應種類例外.
	 * @throws \RuntimeException When force_throw is set，丟非本型別例外（驗 never-throw / decode 落地）.
	 */
	private static function throw_mock_override( array $override ): void {
		if ( ! empty( $override['force_throw'] ) ) {
			throw new \RuntimeException( 'MOCK 強制觸發非預期例外（測試 never-throw）' );
		}

		// 外層 TransCode≠1.
		if ( isset( $override['trans_code'] ) && 1 !== (int) $override['trans_code'] ) {
			$trans_code = (int) $override['trans_code'];
			$trans_msg  = (string) ( $override['trans_msg'] ?? 'AES/格式錯誤' );
			$kind       = self::is_signature_message( $trans_msg )
			? EcpayInvoiceApiException::KIND_SIGNATURE
			: EcpayInvoiceApiException::KIND_NETWORK;
			throw new EcpayInvoiceApiException(
				"TransCode={$trans_code} {$trans_msg}",
				'',
				$trans_msg,
				$kind
			);
		}

		// 內層 RtnCode≠1（業務碼）.
		if ( isset( $override['rtn_code'] ) && 1 !== (int) $override['rtn_code'] ) {
			$rtn_code = (string) $override['rtn_code'];
			$rtn_msg  = (string) ( $override['rtn_msg'] ?? '' );
			throw new EcpayInvoiceApiException(
				"RtnCode={$rtn_code} {$rtn_msg}",
				$rtn_code,
				$rtn_msg,
				EcpayInvoiceApiException::KIND_BUSINESS
			);
		}
	}

	/**
	 * 判斷外層 TransMsg 是否屬「驗章 / 加密驗證失敗」類（→ SIGNATURE）
	 *
	 * 綠界 AES-JSON 發票協議外層 TransCode≠1 多為 AES 加解密 / 格式問題；其中 CheckMacValue /
	 * verify / 驗章 / 簽章 類訊息對應「回應可能遭竄改或金鑰不符」，映射 SIGNATURE。
	 *
	 * @param string $trans_msg 外層 TransMsg.
	 *
	 * @return bool 是驗章類回 true.
	 */
	private static function is_signature_message( string $trans_msg ): bool {
		return 1 === \preg_match( '/CheckMacValue|verify|驗章|簽章|check.?mac/i', $trans_msg );
	}

	/**
	 * 將攔截到的例外正規化為「錯誤明細」（raw_code / raw_message / raw / kind）
	 *
	 * EcpayInvoiceApiException 攜帶綠界原始碼與種類，原樣映射；其餘 \Throwable（JSON decode / 型別等）
	 * 一律歸 decode 種類（無 raw_code），交由 provider 映射 PROVIDER。
	 *
	 * @param \Throwable $e 攔截到的例外.
	 *
	 * @return array{raw_code: string, raw_message: string, raw: string, kind: string} 錯誤明細.
	 */
	private static function to_error_detail( \Throwable $e ): array {
		if ( $e instanceof EcpayInvoiceApiException ) {
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
			'kind'        => EcpayInvoiceApiException::KIND_DECODE,
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
	 * @param EApi $api 端點
	 *
	 * @return IssueResponse
	 */
	private function mock_response( EApi $api ): IssueResponse {
		if ($api->is_issue()) {
			return new IssueResponse(
				[
					'RtnCode'      => 1,
					'RtnMsg'       => '開立成功',
					'InvoiceNo'    => 'PC00000001',
					'InvoiceDate'  => \gmdate( 'Y-m-d H:i:s' ),
					'RandomNumber' => '1234',
				]
			);
		}

		return new IssueResponse(
			[
				'RtnCode' => 1,
				'RtnMsg'  => '作廢成功',
			]
		);
	}

	/**
	 * 折讓 MOCK 回應（固定 fixture）
	 *
	 * @param EApi $api 端點
	 *
	 * @return AllowanceResponse
	 */
	private function mock_allowance_response( EApi $api ): AllowanceResponse {
		if ($api->is_allowance()) {
			return new AllowanceResponse(
				[
					'RtnCode'                  => 1,
					'RtnMsg'                   => '折讓成功',
					'IA_Allow_No'              => '2026010100000001',
					'IA_Invoice_No'            => 'AB12345678',
					'IIS_Remain_Allowance_Amt' => 0,
				]
			);
		}

		return new AllowanceResponse(
			[
				'RtnCode' => 1,
				'RtnMsg'  => '折讓作廢成功',
			]
		);
	}

	/**
	 * 查詢 MOCK 回應（固定 fixture，回發票明細）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_query_response(): array {
		return [
			'RtnCode'            => 1,
			'RtnMsg'             => '查詢成功',
			'IIS_Number'         => 'AG00000001',
			'IIS_Identifier'     => '0000000000',
			'IIS_Invoice_Date'   => \gmdate( 'Y-m-d' ),
			'IIS_Random_Number'  => '1234',
			'IIS_Sales_Amount'   => 100,
			'IIS_Invalid_Status' => '0',
			'IIS_Award_Flag'     => '0',
		];
	}
}
