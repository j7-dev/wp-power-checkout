<?php
/**
 * 綠界站內付 2.0 GetTokenbyTrade 請求內層 Data 參數
 *
 * 屬性命名一律 camelCase 對齊綠界 API（綠界以首字大寫，屬性名即綠界欄位名，
 * 直接 to_array() → AES 加密為 Data）。
 *
 * 三層結構中本 DTO 僅是「內層 Data」，外層 MerchantID / RqHeader 由 EcpgApiClient 組裝。
 * 注意：Data 內層也必須含一份 MerchantID（綠界規則：外層與 Data 內層各一份）。
 *
 * ConsumerInfo 必填規則（GetToken RtnCode≠1 最常見根因）：
 *  - 一次付款（RememberCard=0）：ConsumerInfo 物件必傳，Email 或 Phone 擇一必填
 *  - 記憶卡號（RememberCard=1）：另需 MerchantMemberID
 * 本 DTO 於 validate() 強制 Email/Phone 至少一個非空。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/02-payment-ecpg.md §GetTokenbyTrade
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Ecpg\DTOs;

use J7\WpUtils\Classes\DTO;

/** 綠界站內付 2.0 GetTokenbyTrade 內層 Data 參數 */
final class GetTokenParams extends DTO {

	/** @var string 特店編號（Data 內層，必填，不可省略） */
	public string $MerchantID = '';

	/** @var int 是否記憶卡號（0=否一次付款 / 1=是記憶卡號） */
	public int $RememberCard = 0;

	/** @var int 付款介面類型（站內付 Web 信用卡，依綠界 PaymentUIType） */
	public int $PaymentUIType = 2;

	/** @var string 付款方式清單（"1"=信用卡 / "3"=ATM / "4"=CVS / "5"=BARCODE） */
	public string $ChoosePaymentList = '1';

	/**
	 * @var array<string, mixed> 訂單資訊
	 *
	 * 必含：MerchantTradeDate / MerchantTradeNo / TotalAmount / ReturnURL / TradeDesc / ItemName
	 */
	public array $OrderInfo = [];

	/**
	 * @var array<string, mixed> 卡片相關資訊
	 *
	 * 信用卡含 OrderResultURL（前端 3D 驗證完成後導回）。ATM/CVS/BARCODE 不需此欄位。
	 */
	public array $CardInfo = [];

	/**
	 * @var array<string, mixed> ATM 虛擬帳號取號資訊（ChoosePaymentList=3 必填）
	 *
	 * ExpireDate（Int，繳費有效天數 1~60，預設 3）；ATMBankCode（選填，指定繳費銀行）。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/02b-ecpg-atm-cvs-spa.md §ATM
	 */
	public array $ATMInfo = [];

	/**
	 * @var array<string, mixed> CVS 超商代碼取號資訊（ChoosePaymentList=4 必填）
	 *
	 * StoreExpireDate（Int，逾期分鐘數，預設 10080=7天，最長 43200=30天）；CVSCode / Desc_1~4（選填）。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/02b-ecpg-atm-cvs-spa.md §CVS
	 */
	public array $CVSInfo = [];

	/**
	 * @var array<string, mixed> BARCODE 超商條碼取號資訊（ChoosePaymentList=5 必填）
	 *
	 * StoreExpireDate（Int，繳費有效天數，預設 7，最長 30）。
	 *
	 * @see .claude/skills/ECPay-API-Skill/guides/02b-ecpg-atm-cvs-spa.md §BARCODE
	 */
	public array $BarcodeInfo = [];

	/**
	 * @var array<string, mixed> 消費者資訊（必填物件）
	 *
	 * Email 或 Phone 擇一必填；RememberCard=1 時 MerchantMemberID 亦必填。
	 */
	public array $ConsumerInfo = [];

	/**
	 * 自訂驗證邏輯
	 *
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	protected function validate(): void {
		parent::validate();

		if ('' === $this->MerchantID) {
			throw new \Exception( 'GetTokenParams: Data 內層 MerchantID 必填' );
		}

		// ConsumerInfo 物件必傳，Email 或 Phone 擇一必填（缺漏為 GetToken RtnCode≠1 最常見根因）
		$email = isset( $this->ConsumerInfo['Email'] ) ? \trim( (string) $this->ConsumerInfo['Email'] ) : '';
		$phone = isset( $this->ConsumerInfo['Phone'] ) ? \trim( (string) $this->ConsumerInfo['Phone'] ) : '';
		if ('' === $email && '' === $phone) {
			throw new \Exception( 'GetTokenParams: ConsumerInfo 的 Email 或 Phone 至少需填一個' );
		}

		// RememberCard=1（記憶卡號）時 MerchantMemberID 必填
		if (1 === $this->RememberCard) {
			$member_id = isset( $this->ConsumerInfo['MerchantMemberID'] )
			? \trim( (string) $this->ConsumerInfo['MerchantMemberID'] )
			: '';
			if ('' === $member_id) {
				throw new \Exception( 'GetTokenParams: RememberCard=1 時 ConsumerInfo.MerchantMemberID 必填' );
			}
		}

		// OrderInfo 必含 MerchantTradeNo 與 TotalAmount
		$trade_no = isset( $this->OrderInfo['MerchantTradeNo'] ) ? (string) $this->OrderInfo['MerchantTradeNo'] : '';
		if ('' === $trade_no) {
			throw new \Exception( 'GetTokenParams: OrderInfo.MerchantTradeNo 必填' );
		}
	}
}
