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

use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\CancelParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\EcpayInvoiceSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueParams;
use J7\PowerCheckout\Domains\Invoice\Ecpay\DTOs\IssueResponse;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Services\EcpayInvoiceProvider;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\AesCrypto;

/** 綠界電子發票 API client */
final class InvoiceApiClient {

	private const TIMEOUT = 60;

	/** @var EcpayInvoiceSettingsDTO 設定 */
	private readonly EcpayInvoiceSettingsDTO $settings;

	/** @var AesCrypto 加解密器 */
	private readonly AesCrypto $crypto;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order,
	) {
		$this->settings = EcpayInvoiceSettingsDTO::instance();
		$this->crypto   = new AesCrypto( $this->settings->hash_key, $this->settings->hash_iv );
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
	 * 發送 AES-JSON 請求
	 *
	 * @param EApi                 $api  端點
	 * @param array<string, mixed> $data 內層 Data
	 *
	 * @return IssueResponse|null
	 */
	private function request( EApi $api, array $data ): ?IssueResponse {
		try {
			// MOCK 模式：不打真 API，回固定 fixture
			if (self::is_mock()) {
				return $this->mock_response( $api );
			}

			$api_url  = $this->settings->get_api_url() . $api->value;
			$envelope = $this->build_envelope( $api, $data );

			EcpayInvoiceProvider::logger(
				"{$api->label()} {$api->value} 請求 #{$this->order->get_id()}",
				'info',
				[
					'api_url' => $api_url,
					'data'    => $data,
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
				throw new \Exception( $response->get_error_message() );
			}

			/** @var array{TransCode?: int, TransMsg?: string, Data?: string} $body */
			$body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			// 第一層：TransCode
			$trans_code = (int) ( $body['TransCode'] ?? 0 );
			if (1 !== $trans_code) {
				$trans_msg = (string) ( $body['TransMsg'] ?? 'unknown' );
				throw new \Exception( "TransCode={$trans_code} AES/格式錯誤: {$trans_msg}" );
			}

			// 第二層：解密 Data，檢查 RtnCode
			$decrypted    = $this->crypto->decrypt( (string) ( $body['Data'] ?? '' ) );
			$response_dto = new IssueResponse( $decrypted );
			if (!$response_dto->is_success()) {
				throw new \Exception( "RtnCode={$response_dto->RtnCode} {$response_dto->RtnMsg}" );
			}

			EcpayInvoiceProvider::logger(
				"✅ {$api->label()} {$api->value} 成功 #{$this->order->get_id()}",
				'info',
				$api->is_issue() ? $decrypted : [],
				0,
				$this->order
			);

			return $response_dto;
		} catch (\Throwable $e) {
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
}
