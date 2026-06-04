<?php
/**
 * 綠界全方位物流 v2 CreateByTempTrade 內層 Data 參數
 *
 * 階段 B 成立物流單：憑選店階段取得的 TempLogisticsID 將暫存訂單正式成立，回傳統一物流單號 LogisticsID。
 * 收件人姓名 / 電話 / 地址等已由消費者於選店頁填入暫存訂單，故 CreateByTempTrade 僅需 TempLogisticsID。
 *
 * 屬性命名一律 camelCase 對齊綠界 API。外層 MerchantID / RqHeader（含 Revision 1.0.0 + 即時 Timestamp）
 * 由 LogisticsApiClient::build_envelope() 組裝。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md §正式成立訂單（CreateByTempTrade）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Ecpay\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界全方位物流 v2 CreateByTempTrade 內層 Data 參數 */
final class CreateShipmentParams extends DTO {

	/** @var string 暫存物流單號（選店回呼取得，必填） */
	public string $TempLogisticsID = '';

	/** @var string 自訂交易編號（選填，留空則綠界自動產生；空字串時 to_ecpay_data() 不送出） */
	public string $MerchantTradeNo = '';

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === \trim( $this->TempLogisticsID )) {
			throw new \Exception( 'CreateShipmentParams: TempLogisticsID 必填（選店回呼取得）' );
		}
	}

	/**
	 * 轉為綠界 Data 陣列（過濾留空的選填欄位）
	 *
	 * MerchantTradeNo 留空時不送出（讓綠界自動產生）。
	 *
	 * @return array<string, mixed>
	 */
	public function to_ecpay_data(): array {
		$data = $this->to_array();

		if ('' === (string) ( $data['MerchantTradeNo'] ?? '' )) {
			unset( $data['MerchantTradeNo'] );
		}

		return $data;
	}
}
