<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Http;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AllowanceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\CancelAllowanceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\InvoiceQueryParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\LotteryQueryParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\TrackGetParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;

/**
 * 光貿電子方票
 * TODO 可以抽離為共用
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class ApiClient {

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order,
		/** @var Requester 請求器 */
		private readonly Requester $requester
	) {
	}

	/** 開立發票 */
	public function issue( string $provider_id ): IssueInvoiceResponseDTO|null {
		$response_dto = $this->requester->post( EApi::ISSUE );

		$meta_keys = new MetaKeys( $this->order);
		if ($response_dto) {
			$meta_keys->update_issued_data( $response_dto->to_array());
		}
		$meta_keys->update_provider_id( $provider_id );

		return $response_dto;
	}


	/** 作廢發票 */
	public function cancel(): IssueInvoiceResponseDTO|null {
		$response_dto = $this->requester->post( EApi::CANCEL );

		if ($response_dto) {
			$meta_keys = new MetaKeys( $this->order);
			$meta_keys->clear_data();
			$meta_keys->update_cancelled_data( $response_dto->to_array());
		}
		return $response_dto;
	}

	/**
	 * 開立折讓（g0401）
	 *
	 * 僅負責發送請求；折讓 meta 由 AmegoProvider 寫入（與 issue/cancel 不同，
	 * 因折讓單號由請求端產生，須在 provider 層彙整後寫入）。
	 *
	 * @param AllowanceParamsDTO $params 折讓參數
	 *
	 * @return IssueInvoiceResponseDTO|null
	 */
	public function issue_allowance( AllowanceParamsDTO $params ): IssueInvoiceResponseDTO|null {
		return $this->requester->post_data( EApi::ALLOWANCE, $params );
	}

	/**
	 * 作廢折讓（g0501）
	 *
	 * @param CancelAllowanceParamsDTO $params 折讓作廢參數
	 *
	 * @return IssueInvoiceResponseDTO|null
	 */
	public function invalid_allowance( CancelAllowanceParamsDTO $params ): IssueInvoiceResponseDTO|null {
		return $this->requester->post_data( EApi::ALLOWANCE_INVALID, $params );
	}

	/**
	 * 發票查詢（invoice_query，唯讀）
	 *
	 * @param InvoiceQueryParamsDTO $params 查詢參數
	 *
	 * @return array<string, mixed>|null 發票明細；失敗回 null
	 */
	public function query_invoice( InvoiceQueryParamsDTO $params ): ?array {
		return $this->requester->query( EApi::INVOICE_QUERY, $params );
	}

	/**
	 * 中獎發票查詢（lottery_status，唯讀）
	 *
	 * @param LotteryQueryParamsDTO $params 查詢參數（年 + 期別）
	 *
	 * @return array<string, mixed>|null 中獎清單；失敗回 null
	 */
	public function query_lottery( LotteryQueryParamsDTO $params ): ?array {
		return $this->requester->query( EApi::LOTTERY_STATUS, $params );
	}

	/**
	 * 字軌取號（track_get）
	 *
	 * 注意：此為取號操作（消耗字軌本數），供進階自管號碼場景；
	 * 後台 UI / REST 整合標記為後續。
	 *
	 * @param TrackGetParamsDTO $params 取號參數（年 + 期別 + 本數）
	 *
	 * @return array<string, mixed>|null 字軌起訖；失敗回 null
	 */
	public function get_track( TrackGetParamsDTO $params ): ?array {
		return $this->requester->query( EApi::TRACK_GET, $params );
	}
}
