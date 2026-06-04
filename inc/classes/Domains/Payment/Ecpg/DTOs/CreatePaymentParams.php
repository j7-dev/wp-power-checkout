<?php
/**
 * 綠界站內付 2.0 CreatePayment 請求內層 Data 參數
 *
 * 前端站內付元件（綠界 JS SDK）收集卡片資訊後取得 PayToken，連同 GetTokenbyTrade 階段
 * 使用的同一 MerchantTradeNo 送回後端，後端以此 DTO 組裝 CreatePayment 的 Data。
 *
 * 屬性命名一律 camelCase 對齊綠界 API。外層 MerchantID / RqHeader 由 EcpgApiClient 組裝；
 * Data 內層亦必須含一份 MerchantID（綠界規則：外層與 Data 內層各一份）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md §CreatePayment
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界站內付 2.0 CreatePayment 內層 Data 參數 */
final class CreatePaymentParams extends DTO {

	/** @var string 特店編號（Data 內層，必填，不可省略） */
	public string $MerchantID = '';

	/** @var string 前端站內付元件回傳的付款 Token（PayToken，JWT 格式，不做格式驗證） */
	public string $PayToken = '';

	/** @var string 特店交易編號（必須與 GetTokenbyTrade 完全相同） */
	public string $MerchantTradeNo = '';

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === $this->MerchantID) {
			throw new \Exception( 'CreatePaymentParams: Data 內層 MerchantID 必填' );
		}

		// PayToken 由 SDK 內部產生，格式未定義（JWT 含特殊字元），僅驗證非空
		if ('' === \trim( $this->PayToken )) {
			throw new \Exception( 'CreatePaymentParams: PayToken 必填（前端站內付元件取得）' );
		}

		if ('' === $this->MerchantTradeNo) {
			throw new \Exception( 'CreatePaymentParams: MerchantTradeNo 必填（須與 GetTokenbyTrade 相同）' );
		}
	}
}
