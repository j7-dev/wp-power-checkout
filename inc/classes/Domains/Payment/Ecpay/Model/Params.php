<?php
// phpcs:disable
declare(strict_types=1);

namespace J7\PowerPayment\Domains\Payment\Ecpay\Model;

use J7\WpUtils\Classes\DTO;
use J7\PowerPayment\Utils\Base;

/**
 * 綠界全方位金流 API 必填參數
 * @see https://developers.ecpay.com.tw/?p=2862
 */
final class Params extends DTO
{

	/** @var string *特店編號 (10) */
	public string $MerchantID;

	/** @var string *特店訂單編號 (20) 英數字大小寫混合 */
	public string $MerchantTradeNo;

	/** @var string *特店交易時間 (20) yyyy/MM/dd HH:mm:ss */
	public string $MerchantTradeDate;

	/** @var string *交易類型 (20) 請固定填入 aio */
	public string $PaymentType = 'aio';

	/** @var int *交易金額，整數，不可有小數點，僅限新台幣 */
	public int $TotalAmount;

	/** @var string *交易描述 (200) 請勿帶入特殊字元 */
	public string $TradeDesc;

	/**
	 * @var string *商品名稱 (400)
	 * 如果商品名稱有多筆，需在金流選擇頁一行一行顯示商品名稱的話，商品名稱請以符號#分隔。
	 * 超過 400 字綠界將自動截斷
	 * */
	public string $ItemName;

	/**
	 * @var string *付款完成通知回傳網址 (200)
	 * @see https://developers.ecpay.com.tw/?p=2878
	 *  */
	public string $ReturnURL;

	/**
	 * @var string *付款方式 (20)
	 *
	 * Credit：信用卡及銀聯卡(需申請開通)
	 * TWQR ：歐付寶TWQR行動支付(需申請開通)
	 * WebATM：網路ATM
	 * ATM：自動櫃員機
	 * CVS：超商代碼
	 * BARCODE：超商條碼
	 * ApplePay: Apple Pay(僅支援手機支付)
	 * BNPL：裕富無卡分期(需申請開通)
	 * ALL：不指定付款方式，由綠界顯示付款方式選擇頁面。
	 * */
	public string $ChoosePayment;

	/**
	 * @var string *檢查碼
	 * @see https://developers.ecpay.com.tw/?p=2902
	 *  */
	public string $CheckMacValue;

	/** @var int *CheckMacValue加密類型 請固定填入1，使用SHA256加密。 */
	public int $EncryptType = 1;

	/** @var string 特店旗下店舖代號 (10) 提供特店填入分店代號使用，僅可用英數字大小寫混合。 */
	public string $StoreID;

	/**
	 * @var string Client端返回特店的按鈕連結 (200)
	 * 消費者點選此按鈕後，會將頁面導回到此設定的網址
	 * 注意事項
	 * 1. 導回時不會帶付款結果到此網址，只是將頁面導回而已。
	 * 2. 設定此參數，綠界會在付款完成或取號完成頁面上顯示[返回商店]的按鈕。
	 * 3. 設定此參數，發生簡訊OTP驗證失敗時，頁面上會顯示[返回商店]的按鈕。
	 * 4. 若未設定此參數，則綠界付款完成頁或取號完成頁面，不會顯示[返回商店]的按鈕。
	 * 5. 若導回網址未使用https時，部份瀏覽器可能會出現警告訊息。
	 * 6. 參數內容若有包含%26(&)及%3C(<) 這二個值時，請先進行urldecode() 避免呼叫API失敗。
	 * */
	public string $ClientBackURL;

	/** @var string 商品銷售網址 (200) */
	public string $ItemURL;

	/** @var string 備註 (100) */
	public string $Remark;

	/**
	 * @var string 付款子項目 (20)
	 * 若設定此參數，建立訂單將轉導至綠界訂單成立頁，依設定的付款方式及付款子項目帶入訂單，無法選擇其他付款子項目。請參考付款方式一覽表
	 * @see https://developers.ecpay.com.tw/?p=5679
	 * */
	public string $ChooseSubPayment;

	/**
	 * @var string Client端回傳付款結果網址 (200)
	 * 有別於ReturnURL (server端的網址)，OrderResultURL為商家前端的 URL，用於在消費者完成付款後，接收綠界系統回傳的付款結果參數。消費者付款完成後，綠界會將付款結果參數以POST方式回傳到到該網址。詳細說明請參考付款結果通知。
	 * @see https://developers.ecpay.com.tw/?p=2878
	 *
	 * 注意事項：
	 * 若與[ClientBackURL]同時設定，將會以此參數為主。
	 * 銀聯卡及非即時交易( ATM、CVS、BARCODE )不支援此參數。
	 * 付款結果通知請依ReturnURL (server端的網址)為主,避免因前端操作或網路問題未收到OrderResultURL 特店的client端(前端)的通知。
	 * 參數內容若有包含%26(&)及%3C(<) 這二個值時，請先進行urldecode() 避免呼叫API失敗。
	 */
	public string $OrderResultURL;

	/**
	 * @var 'N' | 'Y' 是否需要額外付款資訊 (1)
	 * 若不回傳額外的付款資訊時，參數值請傳：Ｎ
	 * 若要回傳額外的付款資訊時，參數值請傳：Ｙ
	 * 付款完成後綠界後端會以POST方式回傳額外付款資訊到特店的ReturnURL 與OrderResultURL。
	 * @see https://developers.ecpay.com.tw/?p=5675
	 */
	public string $NeedExtraPaidInfo;

	/**
	 * @var string 隱藏付款方式
	 * 當付款方式[ChoosePayment]為ALL時，可隱藏不需要的付款方式，多筆請以井號分隔 (#)。
	 * 可用的參數值：
	 * Credit：信用卡
	 * WebATM：網路ATM
	 * ATM：自動櫃員機
	 * CVS：超商代碼
	 * BARCODE：超商條碼
	 * ApplePay: Apple Pay
	 * TWQR ：歐付寶TWQR行動支付
	 * BNPL：裕富無卡分期
	 */
	public string $IgnorePayment;

	/** @var string 特約合作平台商代號 (10) 為專案合作的平台商使用。 */
	public string $PlatformID;

	/** @var string 自訂名稱欄位1 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField1;

	/** @var string 自訂名稱欄位2 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField2;

	/** @var string 自訂名稱欄位3 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField3;

	/** @var string 自訂名稱欄位4 (50) 提供合作廠商使用記錄客製化欄位。 */
	public string $CustomField4;

	/** @var 'ENG' | 'KOR' | 'JPN' | 'CHI' 語系設定 */
	public string $Language;

	/** 自訂驗證邏輯 */
	protected function validate(): void
	{

		if ('aio' !== $this->PaymentType) {
			throw new \Exception("PaymentType 必須為 aio, 但目前為 {$this->PaymentType}");
		}

		if (Base::include_special_char($this->MerchantTradeNo)) {
			throw new \Exception("MerchantTradeNo 不能包含特殊字元, 但目前為 {$this->MerchantTradeNo}");
		}

		// 檢查字串長度
		if (strlen($this->MerchantTradeNo) > 20) {
			throw new \Exception("MerchantTradeNo 長度不能超過 20 個字, 但目前為 " . strlen($this->MerchantTradeNo) . " 字");
		}

		if (Base::include_special_char($this->TradeDesc)) {
			throw new \Exception("TradeDesc 不能包含特殊字元, 但目前為 {$this->TradeDesc}");
		}

		$payment_options = ['Credit', 'TWQR', 'WebATM', 'ATM', 'CVS', 'BARCODE', 'ApplePay', 'BNPL'];
		if (!in_array($this->ChoosePayment, [...$payment_options, 'ALL'])) {
			throw new \Exception("ChoosePayment 必須為 " . implode(', ', [...$payment_options, 'ALL']) . " 其中一個, 但目前為 {$this->ChoosePayment}");
		}

		if ($this->EncryptType !== 1) {
			throw new \Exception("EncryptType 必須為 1, 但目前為 {$this->EncryptType}");
		}


		if (isset($this->NeedExtraPaidInfo)) {
			if (!in_array($this->NeedExtraPaidInfo, ['N', 'Y'])) {
				throw new \Exception("NeedExtraPaidInfo 必須為 'N' | 'Y' 其中一個, 但目前為 {$this->NeedExtraPaidInfo}");
			}
		}

		if (isset($this->IgnorePayment)) {
			if (!in_array($this->IgnorePayment, $payment_options)) {
				throw new \Exception("IgnorePayment 必須為 " . implode(', ', $payment_options) . " 其中一個, 但目前為 {$this->IgnorePayment}");
			}
		}

		if (isset($this->Language)) {
			if (!in_array($this->Language, ['ENG', 'KOR', 'JPN', 'CHI'])) {
				throw new \Exception("Language 必須為 'ENG' | 'KOR' | 'JPN' | 'CHI' 其中一個, 但目前為 {$this->Language}");
			}
		}
	}
}
// phpcs:enable
