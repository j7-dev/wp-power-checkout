<?php
/**
 * 藍新 ezPay 電子發票 API client
 *
 * 傳輸結構：HTTP POST + application/x-www-form-urlencoded，body 固定兩欄
 *   { MerchantID_:<商店代號明文>, PostData_:<業務參數 AES-256-CBC 加密後小寫 hex> }
 * 業務參數（含 RespondType=JSON / Version / TimeStamp + 各端點欄位）先以 UrlEncoder 組成
 * query string（值經 rawurlencode），再交 AesCrypto 加密成 PostData_。
 *
 * 回應雙層結構：外層 { Status, Message, Result }；Status==='SUCCESS' 才算成功，
 * Result 為 JSON 字串需再 json_decode；成功回應另帶 CheckCode（SHA256）供回應端驗證。
 *
 * 對外提供五個業務方法（皆從訂單 / meta 自組參數）：
 *   issue()            開立發票（成功寫入 issued_data + provider_id meta，回 IssueResponse）
 *   cancel()           作廢發票（回 IssueResponse；meta 清理由 provider 負責）
 *   issue_allowance()  開立折讓（回 AllowanceResponse）
 *   invalid_allowance() 作廢折讓（回 AllowanceResponse）
 *   query()            查詢發票（唯讀，回 QueryResponse；不寫任何 meta）
 *
 * MOCK 模式（API_MODE=mock）回固定 fixture，完全不對外發 HTTP 請求（CI 安全、測試隔離）。
 * fixture 一律帶「以官方測試金鑰算出的 CheckCode」，故設定錯誤金鑰時 CheckCode 驗證會失敗
 * （decode 階段 throw → 業務方法回 null），讓「金鑰錯誤」測試在零外呼下仍可驗證安全行為。
 *
 * @see .claude/skills/ezpay-invoice/references/api-reference.md
 * @see .claude/skills/ezpay-invoice/references/concepts.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Ezpay\Http;

use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\AllowanceInvalidParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\AllowanceParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\AllowanceResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\CancelParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\EzpaySettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\QueryParams;
use J7\PowerCheckout\Domains\Invoice\Ezpay\DTOs\QueryResponse;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\CheckCodeService;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\PiiMasker;
use J7\PowerCheckout\Domains\Invoice\Ezpay\Shared\Helpers\UrlEncoder;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Plugin;

/** 藍新 ezPay 電子發票 API client */
final class InvoiceApiClient {

	/** @var int wp_remote_post timeout（秒） */
	private const TIMEOUT = 60;

	/** @var string MOCK fixture 用的官方測試 HashKey（手冊公開，僅供算 fixture CheckCode；非正式憑證） */
	private const MOCK_HASH_KEY = 'abcdefghijklmnopqrstuvwxyzabcdef';

	/** @var string MOCK fixture 用的官方測試 HashIV（手冊公開） */
	private const MOCK_HASH_IV = '1234567891234567';

	/** @var array<int, string> 開立 / 查詢回應的 CheckCode 五欄位（concepts §CheckCode 回應驗證） */
	private const ISSUE_CHECK_KEYS = [ 'InvoiceTransNo', 'MerchantID', 'MerchantOrderNo', 'RandomNum', 'TotalAmt' ];

	/** @var array<int, string> 折讓回應的 CheckCode 欄位（折讓回應不含 InvoiceTransNo/RandomNum/TotalAmt，改以折讓欄位自洽驗證） */
	private const ALLOWANCE_CHECK_KEYS = [ 'AllowanceNo', 'MerchantID', 'MerchantOrderNo', 'AllowanceAmt', 'InvoiceNumber' ];

	/** @var EzpaySettingsDTO 設定（含憑證與 API base URL） */
	private readonly EzpaySettingsDTO $settings;

	/** @var AesCrypto PostData_ 加解密器（AES-256-CBC + 自補 PKCS#7 blocksize=32 + hex） */
	private readonly AesCrypto $crypto;

	/** @var CheckCodeService 回應 CheckCode 驗證器（以設定金鑰建立；SHA256 大寫 + timing-safe） */
	private readonly CheckCodeService $check_code;

	/** @var UrlEncoder PostData_ 明文 query string 組裝器（rawurlencode） */
	private readonly UrlEncoder $url_encoder;

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單（log / order note 與業務方法所需）.
	 */
	public function __construct(
		private readonly \WC_Order $order,
	) {
		$this->settings    = EzpaySettingsDTO::instance();
		$this->crypto      = new AesCrypto( $this->settings->hash_key, $this->settings->hash_iv );
		$this->check_code  = new CheckCodeService( $this->settings->hash_key, $this->settings->hash_iv );
		$this->url_encoder = new UrlEncoder();
	}

	/**
	 * 開立發票
	 *
	 * 從訂單 + 結帳發票資訊自組 IssueParams（B2C/B2B 金額分流 + 載具映射）→ 請求 → 驗 CheckCode →
	 * 成功則寫入 issued_data（invoice_number / invoice_trans_no / random_num / invoice_date）與 provider_id meta。
	 *
	 * @param string $provider_id 發票服務 ID（寫入 provider_id meta，固定 'ezpay'）.
	 *
	 * @return IssueResponse|null 成功回 IssueResponse；失敗回 null.
	 */
	public function issue( string $provider_id ): ?IssueResponse {
		try {
			$params = IssueParams::from_order( $this->order );
			$result = $this->request( EApi::INVOICE_ISSUE, $params->to_array(), self::ISSUE_CHECK_KEYS );
			if ( null === $result ) {
				return null;
			}

			$response = new IssueResponse( $result );
			if ( ! $response->is_success() ) {
				return null;
			}

			// 成功：寫入 issued_data + provider_id meta（鍵名對齊 ezPay 測試契約）.
			$meta_keys = new MetaKeys( $this->order );
			$meta_keys->update_issued_data(
				[
					'invoice_number'   => $response->invoice_number,
					'invoice_trans_no' => $response->invoice_trans_no,
					'random_num'       => $response->random_num,
					'invoice_date'     => $response->create_time,
					'total_amt'        => $response->total_amt,
				]
			);
			$meta_keys->update_provider_id( $provider_id );

			return $response;
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ ezPay 開立發票失敗 #{$this->order->get_id()}： {$e->getMessage()}",
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
	 * 從 issued_data meta 取發票號碼自組 CancelParams → 請求。meta 清理由 provider 負責（成功後）。
	 *
	 * @return IssueResponse|null 成功回 IssueResponse；失敗回 null.
	 */
	public function cancel(): ?IssueResponse {
		try {
			$issued_data = $this->get_issued_data();
			$params      = CancelParams::from_issued_data( $issued_data );

			// 作廢回應不含 CheckCode 五欄位 → 不驗 CheckCode（傳空欄位集，request 內略過驗證）.
			$result = $this->request( EApi::INVOICE_INVALID, $params->to_array(), [] );
			if ( null === $result ) {
				return null;
			}

			// 作廢成功：以 Result 建 IssueResponse；補上原發票號碼供呼叫端判定成功.
			$result['InvoiceNumber'] = $result['InvoiceNumber'] ?? ( $issued_data['invoice_number'] ?? '' );
			return new IssueResponse( $result );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ ezPay 作廢發票失敗 #{$this->order->get_id()}： {$e->getMessage()}",
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
	 * @param int    $allowance_amount  折讓含稅金額（> 0）.
	 * @param string $merchant_order_no 原開立時的自訂編號（由 provider 以同規則推得）.
	 * @param string $notify_mail       折讓通知 Email（空字串不通知）.
	 *
	 * @return AllowanceResponse|null 成功回 AllowanceResponse；失敗回 null.
	 */
	public function issue_allowance( int $allowance_amount, string $merchant_order_no, string $notify_mail = '' ): ?AllowanceResponse {
		try {
			$issued_data = $this->get_issued_data();
			$params      = AllowanceParams::from_issued_data( $issued_data, $merchant_order_no, $allowance_amount, $notify_mail );

			$result = $this->request( EApi::ALLOWANCE_ISSUE, $params->to_array(), self::ALLOWANCE_CHECK_KEYS );
			if ( null === $result ) {
				return null;
			}

			$response = new AllowanceResponse( $result );
			return $response->is_success() ? $response : null;
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ ezPay 開立折讓失敗 #{$this->order->get_id()}： {$e->getMessage()}",
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
	 * @param array<string, mixed> $allowance_data 已開立折讓 meta（含 allowance_no）.
	 *
	 * @return AllowanceResponse|null 成功回 AllowanceResponse；失敗回 null.
	 */
	public function invalid_allowance( array $allowance_data ): ?AllowanceResponse {
		try {
			$params = AllowanceInvalidParams::from_allowance_data( $allowance_data );

			// 作廢折讓回應不含折讓 CheckCode 欄位集 → 不驗 CheckCode.
			$result = $this->request( EApi::ALLOWANCE_INVALID, $params->to_array(), [] );
			if ( null === $result ) {
				return null;
			}

			// 補上折讓號供呼叫端判定成功.
			$result['AllowanceNo'] = $result['AllowanceNo'] ?? ( $allowance_data['allowance_no'] ?? '' );
			return new AllowanceResponse( $result );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ ezPay 作廢折讓失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 查詢發票明細（唯讀，invoice_search）
	 *
	 * 以已開立發票 meta（發票號碼 + 隨機碼）查詢；不寫任何 meta、不改訂單狀態。
	 *
	 * @return QueryResponse|null 成功回 QueryResponse；失敗回 null.
	 */
	public function query(): ?QueryResponse {
		try {
			$issued_data = $this->get_issued_data();
			$params      = QueryParams::from_issued_data( $issued_data );

			$result = $this->request( EApi::INVOICE_SEARCH, $params->to_array(), self::ISSUE_CHECK_KEYS );
			if ( null === $result ) {
				return null;
			}

			return new QueryResponse( $result );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ ezPay 查詢發票失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 建立傳輸信封 { MerchantID_, PostData_ }
	 *
	 * 流程：注入傳輸層共用欄位（RespondType=JSON / Version<取自端點> / TimeStamp<Unix 秒>）至業務參數
	 * 之前 → UrlEncoder 組成 rawurlencode query string → AesCrypto 加密成小寫 hex → 回傳兩欄信封。
	 *
	 * @param EApi                 $api  端點（提供該支 API 規定的 Version）.
	 * @param array<string, mixed> $data 業務參數（各端點 PostData_ 欄位，不含 RespondType/Version/TimeStamp）.
	 *
	 * @return array{MerchantID_: string, PostData_: string} POST body 兩欄.
	 */
	public function build_envelope( EApi $api, array $data ): array {
		$post_data = array_merge(
			[
				'RespondType' => 'JSON',
				'Version'     => $api->version(),
				'TimeStamp'   => (string) \time(),
			],
			$data
		);

		$plaintext = $this->url_encoder->encode( $post_data );

		return [
			'MerchantID_' => $this->settings->merchant_id,
			'PostData_'   => $this->crypto->encrypt( $plaintext ),
		];
	}

	/**
	 * 發送 ezPay API 請求並回傳解密後的 Result（陣列）
	 *
	 * MOCK 模式回固定 fixture，不外呼。實模式：組信封 → wp_remote_post（form-urlencoded）→ decode_result。
	 * 任何失敗（網路 / Status≠SUCCESS / CheckCode 不符）皆 catch 後回 null（錯誤碼寫入 log）。
	 *
	 * @param EApi                 $api             端點.
	 * @param array<string, mixed> $data            業務參數.
	 * @param array<int, string>   $check_code_keys 本端點 CheckCode 驗證欄位集（空陣列代表略過 CheckCode 驗證）.
	 *
	 * @return array<string, mixed>|null 成功回 Result 陣列；失敗回 null.
	 */
	public function request( EApi $api, array $data, array $check_code_keys = self::ISSUE_CHECK_KEYS ): ?array {
		try {
			// MOCK 模式：不打真 API，回固定 fixture（已含官方金鑰算出的 CheckCode）.
			if ( self::is_mock() ) {
				return $this->decode_result( $this->mock_response( $api ), $check_code_keys );
			}

			$api_url  = $this->settings->get_api_url() . $api->value;
			$envelope = $this->build_envelope( $api, $data );

			Plugin::logger(
				"ezPay {$api->label()} {$api->value} 請求 #{$this->order->get_id()}",
				'info',
				[
					'api_url' => $api_url,
					// 安全：遮蔽 PII（Email / 載具 / 姓名 / 統編 / 地址）後才入 log.
					'data'    => PiiMasker::mask_invoice_data( $data ),
				]
			);

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => \http_build_query( $envelope ),
					'headers'  => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			if ( \is_wp_error( $response ) ) {
				throw new \RuntimeException( $response->get_error_message() );
			}

			/** @var array<string, mixed> $body */
			$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			return $this->decode_result( $body, $check_code_keys );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"❌ ezPay {$api->label()} {$api->value} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5
			);
			return null;
		}
	}

	/**
	 * 解析 ezPay 回應外層，回傳解密後的 Result 陣列
	 *
	 * 步驟：
	 *  1. 驗 Status === 'SUCCESS'，否則 throw（訊息含 Status 錯誤碼與 Message）。
	 *  2. Result 為 JSON 字串 → json_decode 成陣列。
	 *  3. 若指定了 CheckCode 欄位集且回應帶 CheckCode → 以 CheckCodeService（設定金鑰）驗證，不符則 throw。
	 *     金鑰錯誤時驗證必失敗（fixture 以官方金鑰算 CheckCode），達成安全防呆。
	 *
	 * @param array<string, mixed> $response        外層回應 { Status, Message, Result }.
	 * @param array<int, string>   $check_code_keys CheckCode 驗證欄位集（空陣列代表略過驗證）.
	 *
	 * @return array<string, mixed> 解密後的 Result 陣列.
	 * @throws \RuntimeException 當 Status≠SUCCESS 或 CheckCode 不符.
	 */
	public function decode_result( array $response, array $check_code_keys = self::ISSUE_CHECK_KEYS ): array {
		$status = (string) ( $response['Status'] ?? '' );
		if ( 'SUCCESS' !== $status ) {
			$message = (string) ( $response['Message'] ?? 'unknown' );
			throw new \RuntimeException( "ezPay 回應失敗 Status={$status}：{$message}" );
		}

		$result_raw = $response['Result'] ?? '';
		/** @var array<string, mixed> $result */
		$result = \is_array( $result_raw )
		? $result_raw
		: (array) \json_decode( (string) $result_raw, true, 512, JSON_THROW_ON_ERROR );

		// CheckCode 驗證：指定欄位集 + 回應帶 CheckCode + 欄位齊備才驗.
		$check_code = (string) ( $result['CheckCode'] ?? '' );
		if ( [] !== $check_code_keys && '' !== $check_code && $this->has_keys( $result, $check_code_keys ) ) {
			$fields = [];
			foreach ( $check_code_keys as $key ) {
				$fields[ $key ] = $result[ $key ];
			}
			if ( ! $this->check_code->verify( $fields, $check_code ) ) {
				throw new \RuntimeException( 'ezPay 回應 CheckCode 驗證失敗（回應可能非來自 ezPay 或金鑰不符）' );
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

	/**
	 * 判斷 Result 是否含指定欄位（皆存在才回 true）
	 *
	 * @param array<string, mixed> $result Result 陣列.
	 * @param array<int, string>   $keys   欄位集.
	 *
	 * @return bool 全部齊備回 true.
	 */
	private function has_keys( array $result, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! isset( $result[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool 是否為 MOCK 模式（API_MODE=mock，測試用，不打真 API） */
	private static function is_mock(): bool {
		$mode = \str_replace( ' ', '', \getenv( 'API_MODE' ) ?: '' );
		return 'mock' === \strtolower( $mode );
	}

	/**
	 * MOCK 回應（外層信封 { Status, Message, Result }，Result 為陣列且帶官方金鑰算出的 CheckCode）
	 *
	 * 此 fixture 以官方測試金鑰（MOCK_HASH_KEY/IV）計算 CheckCode：設定金鑰相符（happy）→ 驗證通過；
	 * 設定金鑰錯誤（安全測試）→ 驗證失敗 → decode throw → 業務方法回 null。零外呼。
	 *
	 * @param EApi $api 端點.
	 *
	 * @return array{Status: string, Message: string, Result: array<string, mixed>} 模擬的外層回應.
	 */
	private function mock_response( EApi $api ): array {
		$result = match ( $api ) {
			EApi::INVOICE_ISSUE     => $this->mock_issue_result(),
			EApi::ALLOWANCE_ISSUE   => $this->mock_allowance_result(),
			EApi::INVOICE_INVALID   => $this->mock_invoice_invalid_result(),
			EApi::ALLOWANCE_INVALID => $this->mock_allowance_invalid_result(),
			EApi::INVOICE_SEARCH    => $this->mock_search_result(),
		};

		return [
			'Status'  => 'SUCCESS',
			'Message' => 'MOCK 成功',
			'Result'  => $result,
		];
	}

	/**
	 * 開立發票 MOCK Result（含 ISSUE_CHECK_KEYS 五欄位 + 官方金鑰 CheckCode）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_issue_result(): array {
		$fields = [
			'MerchantID'      => $this->settings->merchant_id,
			'InvoiceTransNo'  => '20260101000000001',
			'MerchantOrderNo' => 'PC' . $this->order->get_id(),
			'TotalAmt'        => (int) \round( (float) $this->order->get_total() ),
			'RandomNum'       => '1234',
		];

		return \array_merge(
			$fields,
			[
				'InvoiceNumber' => 'EV00000001',
				'CreateTime'    => \gmdate( 'Y-m-d H:i:s' ),
				'CheckCode'     => $this->mock_check_code( $fields, self::ISSUE_CHECK_KEYS ),
			]
		);
	}

	/**
	 * 開立折讓 MOCK Result（含 ALLOWANCE_CHECK_KEYS 欄位 + 官方金鑰 CheckCode）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_allowance_result(): array {
		$fields = [
			'MerchantID'      => $this->settings->merchant_id,
			'AllowanceNo'     => 'A20260101000001',
			'MerchantOrderNo' => 'PC' . $this->order->get_id(),
			'AllowanceAmt'    => (int) \round( (float) $this->order->get_total() ),
			'InvoiceNumber'   => 'EV00000001',
		];

		return \array_merge(
			$fields,
			[
				'RemainAmt' => 0,
				'CheckCode' => $this->mock_check_code( $fields, self::ALLOWANCE_CHECK_KEYS ),
			]
		);
	}

	/**
	 * 作廢發票 MOCK Result（精簡，不帶 CheckCode 五欄位 → 不驗 CheckCode）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_invoice_invalid_result(): array {
		return [
			'MerchantID'    => $this->settings->merchant_id,
			'InvoiceNumber' => 'EV00000001',
			'CreateTime'    => \gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * 作廢折讓 MOCK Result（精簡）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_allowance_invalid_result(): array {
		return [
			'MerchantID'  => $this->settings->merchant_id,
			'AllowanceNo' => 'A20260101000001',
			'CreateTime'  => \gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * 查詢發票 MOCK Result（含 ISSUE_CHECK_KEYS 五欄位 + 官方金鑰 CheckCode + 上傳狀態）
	 *
	 * @return array<string, mixed>
	 */
	private function mock_search_result(): array {
		$fields = [
			'MerchantID'      => $this->settings->merchant_id,
			'InvoiceTransNo'  => '20260101000000001',
			'MerchantOrderNo' => 'PC' . $this->order->get_id(),
			'TotalAmt'        => (int) \round( (float) $this->order->get_total() ),
			'RandomNum'       => '1234',
		];

		return \array_merge(
			$fields,
			[
				'InvoiceNumber' => 'EV00000001',
				'InvoiceStatus' => '1',
				'UploadStatus'  => '1',
				'TaxType'       => '1',
				'CreateTime'    => \gmdate( 'Y-m-d H:i:s' ),
				'CheckCode'     => $this->mock_check_code( $fields, self::ISSUE_CHECK_KEYS ),
			]
		);
	}

	/**
	 * 以「官方測試金鑰」對指定欄位集算 CheckCode（供 MOCK fixture 用）
	 *
	 * @param array<string, mixed> $fields 來源欄位（含 CheckCode 欄位集所需鍵）.
	 * @param array<int, string>   $keys   CheckCode 欄位集.
	 *
	 * @return string CheckCode（SHA256 大寫）.
	 */
	private function mock_check_code( array $fields, array $keys ): string {
		$checker  = new CheckCodeService( self::MOCK_HASH_KEY, self::MOCK_HASH_IV );
		$selected = [];
		foreach ( $keys as $key ) {
			$selected[ $key ] = $fields[ $key ] ?? '';
		}
		return $checker->compute( $selected );
	}
}
