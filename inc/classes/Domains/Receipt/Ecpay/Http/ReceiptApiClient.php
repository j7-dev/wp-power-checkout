<?php
/**
 * 綠界電子收據 API client
 *
 * 三層請求結構：{ MerchantID, RqHeader{ Timestamp }, Data: AES加密(JSON) }
 * ⚠️ 電子收據 RqHeader **只需 Timestamp，不需要 Revision**（與 B2C/B2B 發票不同）。
 * 雙層錯誤檢查：外層 TransCode === 1 → 解密 Data → 內層 RtnCode === 1。
 *
 * 加密複用既有電子發票 AesCrypto（AES-128-CBC，演算法與綠界發票一致）。
 * MOCK 模式（API_MODE=mock）回固定 fixture，不打真 API。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/25-receipt.md
 * @see .claude/skills/ECPay-API-Skill/guides/14-aes-encryption.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Receipt\Ecpay\Http;

use J7\PowerCheckout\Domains\Invoice\Ecpay\Shared\Helpers\AesCrypto;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\EcpayReceiptSettingsDTO;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptCancelParams;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptIssueParams;
use J7\PowerCheckout\Domains\Receipt\Ecpay\DTOs\ReceiptIssueResponse;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Services\EcpayReceiptProvider;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Enums\EReceiptApi;
use J7\PowerCheckout\Domains\Receipt\Ecpay\Shared\Helpers\ReceiptPiiMasker;

/** 綠界電子收據 API client */
final class ReceiptApiClient {

	private const TIMEOUT = 60;

	/** @var EcpayReceiptSettingsDTO 設定 */
	private readonly EcpayReceiptSettingsDTO $settings;

	/** @var AesCrypto 加解密器 */
	private readonly AesCrypto $crypto;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order,
	) {
		$this->settings = EcpayReceiptSettingsDTO::instance();
		$this->crypto   = new AesCrypto( $this->settings->hash_key, $this->settings->hash_iv );
	}

	/**
	 * 開立收據
	 *
	 * @param ReceiptIssueParams $params 開立參數
	 *
	 * @return ReceiptIssueResponse|null
	 */
	public function issue( ReceiptIssueParams $params ): ?ReceiptIssueResponse {
		return $this->request( EReceiptApi::ISSUE, $params->to_array() );
	}

	/**
	 * 作廢收據
	 *
	 * @param ReceiptCancelParams $params 作廢參數
	 *
	 * @return ReceiptIssueResponse|null
	 */
	public function cancel( ReceiptCancelParams $params ): ?ReceiptIssueResponse {
		return $this->request( EReceiptApi::INVALID, $params->to_array() );
	}

	/**
	 * 發送 AES-JSON 請求
	 *
	 * @param EReceiptApi          $api  端點
	 * @param array<string, mixed> $data 內層 Data
	 *
	 * @return ReceiptIssueResponse|null
	 */
	private function request( EReceiptApi $api, array $data ): ?ReceiptIssueResponse {
		try {
			// MOCK 模式：不打真 API，回固定 fixture
			if (self::is_mock()) {
				return $this->mock_response( $api );
			}

			$api_url  = $this->settings->get_api_url() . $api->value;
			$envelope = $this->build_envelope( $data );

			EcpayReceiptProvider::logger(
				"{$api->label()} {$api->value} 請求 #{$this->order->get_id()}",
				'info',
				[
					'api_url' => $api_url,
					// 安全：遮蔽 PII（Email / 手機 / 識別碼 / 姓名 / 地址）後才入 log
					'data'    => ReceiptPiiMasker::mask_receipt_data( $data ),
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
			$response_dto = new ReceiptIssueResponse( $decrypted );
			if (!$response_dto->is_success()) {
				throw new \Exception( "RtnCode={$response_dto->RtnCode} {$response_dto->RtnMsg}" );
			}

			EcpayReceiptProvider::logger(
				"✅ {$api->label()} {$api->value} 成功 #{$this->order->get_id()}",
				'info',
				// 安全：成功回應寫入 order note 前同樣遮蔽 PII（收據編號等非 PII 保留）
				$api->is_issue() ? ReceiptPiiMasker::mask_receipt_data( $decrypted ) : [],
				0,
				$this->order
			);

			return $response_dto;
		} catch (\Throwable $e) {
			EcpayReceiptProvider::logger(
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
	 * 建立三層請求結構（RqHeader 僅 Timestamp，無 Revision）
	 *
	 * @param array<string, mixed> $data 內層 Data
	 *
	 * @return array<string, mixed>
	 */
	private function build_envelope( array $data ): array {
		return [
			'MerchantID' => $this->settings->merchant_id,
			'RqHeader'   => [ 'Timestamp' => \time() ],
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
	 * @param EReceiptApi $api 端點
	 *
	 * @return ReceiptIssueResponse
	 */
	private function mock_response( EReceiptApi $api ): ReceiptIssueResponse {
		if ($api->is_issue()) {
			return new ReceiptIssueResponse(
				[
					'RtnCode'      => 1,
					'RtnMsg'       => '開立成功',
					'ReceiptNo'    => 'Sale2026040800000448',
					'RelateNumber' => 'RCMOCK',
					'ReceiptDate'  => \current_time( 'Y/m/d H:i:s' ),
				]
			);
		}

		return new ReceiptIssueResponse(
			[
				'RtnCode' => 1,
				'RtnMsg'  => '作廢成功',
			]
		);
	}
}
