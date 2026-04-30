# ECPay AIO V5 -- 通知/回調參數完整參考

> 本文件涵蓋所有 ECPay 主動回傳至商家的通知類型及其參數。

## 目錄

- [付款結果通知 (ReturnURL)](#付款結果通知-returnurl)
- [ATM/CVS/BARCODE 取號結果通知 (PaymentInfoURL)](#atmcvsbarcode-取號結果通知)
- [定期定額付款結果通知 (PeriodReturnURL)](#定期定額付款結果通知)
- [BNPL 無卡分期申請結果通知](#bnpl-無卡分期申請結果通知)
- [NeedExtraPaidInfo=Y 額外回傳參數](#額外回傳參數-needextrapaidinfoy)

---

## 付款結果通知 (ReturnURL)

ECPay 以 POST 傳送至商家 ReturnURL。商家必須回應純文字 `1|OK`。

| 參數 | 型別 | 說明 |
|------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| StoreID | String(20) | 店舖代號 |
| RtnCode | Int | **1=付款成功**，其餘代碼為失敗或異常 |
| RtnMsg | String(200) | 交易訊息 |
| TradeNo | String(20) | 綠界交易編號 |
| TradeAmt | Int | 交易金額 |
| PaymentDate | String(20) | 付款時間 `yyyy/MM/dd HH:mm:ss` |
| PaymentType | String(50) | 付款方式代碼（如 `Credit_CreditCard`、`ATM_FIRST` 等） |
| PaymentTypeChargeFee | Number | 交易手續費 |
| TradeDate | String(20) | 訂單成立時間 `yyyy/MM/dd HH:mm:ss` |
| PlatformID | String(10) | 平台商代號 |
| SimulatePaid | Int | `0`=真實付款, `1`=模擬付款 |
| CustomField1 | String(50) | 自訂欄位 1 |
| CustomField2 | String(50) | 自訂欄位 2 |
| CustomField3 | String(50) | 自訂欄位 3 |
| CustomField4 | String(50) | 自訂欄位 4 |
| CheckMacValue | String | 檢查碼（商家必須驗證） |

**處理邏輯**:
1. 收到 POST 資料
2. 用回傳參數（排除 CheckMacValue）重新計算 CheckMacValue
3. 比對計算結果與收到的 CheckMacValue
4. 驗證通過且 RtnCode=1 時才更新訂單為已付款
5. 回應純文字 `1|OK`

**重新通知機制**: ECPay 若未收到 `1|OK`，會每 5~15 分鐘重新通知一次，共 4 次。

---

## ATM/CVS/BARCODE 取號結果通知

ECPay 以 POST 傳送至商家 PaymentInfoURL。商家必須回應 `1|OK`。

### 共用參數

| 參數 | 型別 | 說明 |
|------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店訂單編號 |
| StoreID | String(20) | 店舖代號 |
| RtnCode | Int | **ATM 成功=2**, **CVS/BARCODE 成功=10100073** |
| RtnMsg | String(200) | 交易訊息 |
| TradeNo | String(20) | 綠界交易編號 |
| TradeAmt | Int | 交易金額 |
| PaymentType | String(20) | 付款方式 |
| TradeDate | String(20) | 訂單成立時間 `yyyy/MM/dd HH:mm:ss` |
| CustomField1~4 | String(50) | 自訂欄位 |
| CheckMacValue | String | 檢查碼 |

### ATM 專屬回傳

| 參數 | 型別 | 說明 |
|------|------|------|
| BankCode | String(3) | 繳費銀行代碼 |
| vAccount | String(16) | 繳費虛擬帳號 |
| ExpireDate | String(100) | 繳費期限 `yyyy/MM/dd` |

### CVS/BARCODE 專屬回傳

| 參數 | 型別 | 說明 |
|------|------|------|
| PaymentNo | String(14) | 繳費代碼（僅 CVS 有值，BARCODE 為空） |
| ExpireDate | String(20) | 繳費期限 `yyyy/MM/dd HH:mm:ss` |
| Barcode1 | String(20) | 第一段條碼（僅 BARCODE 有值，CVS 為空） |
| Barcode2 | String(20) | 第二段條碼 |
| Barcode3 | String(20) | 第三段條碼 |

---

## 定期定額付款結果通知

ECPay 以 POST 傳送至 PeriodReturnURL（或 ReturnURL）。商家必須回應 `1|OK`。

| 參數 | 型別 | 說明 |
|------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| StoreID | String(20) | 店舖代號 |
| RtnCode | Int | **1=授權成功**，其餘為失敗 |
| RtnMsg | String(200) | 交易訊息 |
| PeriodType | String(1) | 週期種類 D/M/Y |
| Frequency | Int | 執行頻率 |
| ExecTimes | Int | 執行次數 |
| Amount | Int | 此次授權金額 |
| Gwsr | Int | 授權交易單號 |
| ProcessDate | String(20) | 處理時間 `yyyy/MM/dd HH:mm:ss` |
| AuthCode | String(6) | 授權碼 |
| FirstAuthAmount | Int | 第一筆授權金額 |
| TotalSuccessTimes | Int | 目前已成功授權次數 |
| SimulatePaid | Int | `1`=模擬付款（僅測試環境） |
| CustomField1~4 | String(50) | 自訂欄位 |
| CheckMacValue | String | 檢查碼 |

---

## BNPL 無卡分期申請結果通知

ECPay 以 POST 傳送至 PaymentInfoURL。商家必須回應 `1|OK`。
參數結構與付款結果通知類似，差異在於 RtnCode 和 RtnMsg 反映的是申請狀態而非付款狀態。

---

## 額外回傳參數 (NeedExtraPaidInfo=Y)

當建立訂單時設定 `NeedExtraPaidInfo=Y`，付款結果通知會額外回傳以下參數。

**重要**: 所有額外回傳參數都必須加入 CheckMacValue 計算。

### 信用卡額外參數

| 參數 | 說明 |
|------|------|
| gwsr | 授權交易單號 |
| process_date | 處理時間 `yyyy/MM/dd HH:mm:ss` |
| auth_code | 授權碼 |
| amount | 交易金額 |
| stage | 分期期數 |
| stast | 首期金額 |
| staed | 各期金額 |
| eci | 3D 驗證值 |
| card4no | 卡號末 4 碼 |
| card6no | 卡號前 6 碼 |
| red_dan | 紅利折抵點數 |
| red_de_amt | 紅利折抵金額 |
| red_ok_amt | 實際折抵金額 |
| red_yet | 剩餘紅利點數 |

### 定期定額額外參數

| 參數 | 說明 |
|------|------|
| PeriodType | 週期種類 |
| Frequency | 執行頻率 |
| ExecTimes | 執行次數 |
| PeriodAmount | 每次授權金額 |
| TotalSuccessTimes | 已成功授權次數 |
| TotalSuccessAmount | 已成功授權總金額 |

### WebATM 額外參數

| 參數 | 說明 |
|------|------|
| WebATMAccBank | 付款人銀行代碼 |
| WebATMAccNo | 付款人帳號末 5 碼 |
| WebATMBankName | 銀行名稱 |

### ATM 額外參數

| 參數 | 說明 |
|------|------|
| ATMAccBank | 付款人銀行代碼 |
| ATMAccNo | 付款人帳號末 5 碼 |

### CVS/BARCODE 額外參數

| 參數 | 說明 |
|------|------|
| PaymentNo | 繳費代碼 |
| PayFrom | 繳費超商 (`family`, `hilife`, `okmart`, `ibon`) |

### TWQR 額外參數

| 參數 | 說明 |
|------|------|
| TWQRTradeNo | 行動支付交易編號 |
