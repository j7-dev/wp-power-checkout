# ECPay AIO V5 -- 查詢與作業 API 完整參考

> 本文件涵蓋查詢訂單、信用卡請退款、定期定額訂單作業、取號查詢等 API。

## 目錄

- [查詢訂單 (QueryTradeInfo)](#查詢訂單)
- [信用卡請退款 (DoAction)](#信用卡請退款)
- [信用卡單筆明細查詢 (QueryTrade)](#信用卡單筆明細查詢)
- [定期定額訂單查詢 (QueryCreditCardPeriodInfo)](#定期定額訂單查詢)
- [定期定額訂單作業 (CreditCardPeriodAction)](#定期定額訂單作業)
- [ATM/CVS/BARCODE 取號結果查詢 (QueryPaymentInfo)](#atmcvsbarcode-取號結果查詢)

---

## 查詢訂單

**端點**:
- 測試: `https://payment-stage.ecpay.com.tw/Cashier/QueryTradeInfo/V5`
- 正式: `https://payment.ecpay.com.tw/Cashier/QueryTradeInfo/V5`

**Method**: POST, `application/x-www-form-urlencoded`

### 請求參數

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| MerchantID | String(10) | 是 | 特店編號 |
| MerchantTradeNo | String(20) | 是 | 訂單產生時的特店交易編號 |
| TimeStamp | Int | 是 | Unix Timestamp，驗證時間區間為 3 分鐘內有效 |
| CheckMacValue | String | 是 | 檢查碼 |
| PlatformID | String(10) | 否 | 平台商代號 |

### 回應參數

| 參數 | 型別 | 說明 |
|------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| StoreID | String(20) | 店舖代號 |
| TradeNo | String(20) | 綠界交易編號 |
| TradeAmt | Int | 交易金額 |
| PaymentDate | String(20) | 付款時間 `yyyy/MM/dd HH:mm:ss` |
| PaymentType | String(50) | 交易付款方式 |
| HandlingCharge | Number | 手續費合計 |
| PaymentTypeChargeFee | Number | 交易手續費金額 |
| TradeDate | String(20) | 訂單成立時間 |
| TradeStatus | String(8) | `0`=未付款, `1`=已付款, `10200095`=交易未成立 |
| ItemName | String(400) | 商品名稱 |
| CustomField1~4 | String(50) | 自訂欄位 |
| CheckMacValue | String | 檢查碼 |

---

## 信用卡請退款

**端點**: `https://payment.ecpay.com.tw/CreditDetail/DoAction` (僅正式環境)

**Method**: POST, `application/x-www-form-urlencoded`

### 請求參數

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| MerchantID | String(10) | 是 | 特店編號 |
| MerchantTradeNo | String(20) | 是 | 特店交易編號 |
| TradeNo | String(20) | 是 | 綠界交易編號（須保存與特店交易編號的關聯） |
| Action | String(1) | 是 | `C`=請款, `R`=退款, `E`=取消, `N`=放棄 |
| TotalAmount | Int | 是 | 交易金額 |
| CheckMacValue | String | 是 | 檢查碼 |
| PlatformID | String(10) | 否 | 平台商代號 |

### Action 說明

| Action | 名稱 | 說明 |
|--------|------|------|
| `C` | 請款 (Capture) | 對已授權的交易進行請款 |
| `R` | 退款 (Refund) | 對已請款的交易進行退款 |
| `E` | 取消 (Cancel) | 取消已授權但未請款的交易 |
| `N` | 放棄 (Abandon) | 放棄已授權的交易 |

### 回應參數

| 參數 | 型別 | 說明 |
|------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| TradeNo | String(20) | 綠界交易編號 |
| RtnCode | Int | `1`=成功，其餘為失敗 |
| RtnMsg | String(200) | 交易訊息 |

---

## 信用卡單筆明細查詢

**端點**: `https://payment.ecpay.com.tw/CreditDetail/QueryTrade/V2` (僅正式環境)

**Method**: POST, `application/x-www-form-urlencoded`

### 請求參數

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| MerchantID | String(10) | 是 | 特店編號 |
| CreditRefundId | Int | 是 | 信用卡授權單號 |
| CreditAmount | Int | 是 | 交易金額 |
| CreditCheckCode | Int | 是 | 商家檢查碼（可在廠商後台取得） |
| CheckMacValue | String | 是 | 檢查碼 |

### 回應參數

| 參數 | 型別 | 說明 |
|------|------|------|
| RtnMsg | String(200) | 回應訊息（成功時為空） |
| RtnValue | JSON | 回應內容 |
| TradeID | Int | 授權單號 |
| amount | Int | 交易金額 |
| clsamt | Int | 已關帳金額 |
| authtime | String(20) | 訂單成立時間 |
| status | String(30) | 交易狀態 |
| close_data | JSON | 關帳明細 |

### 交易狀態值

- 無關帳明細時：`已取消`, `未授權`, `已授權`
- 有關帳明細時：`已關帳`, `已取消`, `操作取消`

---

## 定期定額訂單查詢

**端點**:
- 測試: `https://payment-stage.ecpay.com.tw/Cashier/QueryCreditCardPeriodInfo`
- 正式: `https://payment.ecpay.com.tw/Cashier/QueryCreditCardPeriodInfo`

**Method**: POST, `application/x-www-form-urlencoded`

### 請求參數

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| MerchantID | String(10) | 是 | 特店編號 |
| MerchantTradeNo | String(20) | 是 | 特店交易編號 |
| TimeStamp | Int | 是 | Unix Timestamp，3 分鐘內有效 |
| CheckMacValue | String | 是 | 檢查碼 |
| PlatformID | String(10) | 否 | 平台商代號 |

### 回應參數

| 參數 | 型別 | 說明 |
|------|------|------|
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| TradeNo | String(20) | 首次授權的綠界交易編號 |
| RtnCode | Int | `1`=授權成功 |
| PeriodType | String(1) | 週期種類 |
| Frequency | Int | 執行頻率 |
| ExecTimes | Int | 執行次數 |
| PeriodAmount | Int | 每次授權金額 |
| amount | Int | 首次授權金額 |
| gwsr | Int | 首次授權交易單號 |
| process_date | String(20) | 首次授權時間 |
| auth_code | String(6) | 首次交易授權碼 |
| card4no | String(4) | 卡號末 4 碼 |
| card6no | String(6) | 卡號前 6 碼 |
| TotalSuccessTimes | Int | 已成功授權次數 |
| TotalSuccessAmount | Int | 已成功授權總金額 |
| ExecStatus | String(1) | `0`=已終止, `1`=執行中, `2`=執行完成 |
| ExecLog | Array | 每次授權紀錄（含 RtnCode, amount, gwsr, process_date, auth_code, TradeNo） |

---

## 定期定額訂單作業

**端點**:
- 測試: `https://payment-stage.ecpay.com.tw/Cashier/CreditCardPeriodAction`
- 正式: `https://payment.ecpay.com.tw/Cashier/CreditCardPeriodAction`

**Method**: POST, `application/x-www-form-urlencoded`

### 請求參數

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| MerchantID | String(10) | 是 | 特店編號 |
| MerchantTradeNo | String(20) | 是 | 特店交易編號 |
| Action | String(20) | 是 | `ReAuth`=補授權失敗交易, `Cancel`=終止後續交易 |
| TimeStamp | Int | 是 | Unix Timestamp |
| CheckMacValue | String | 是 | 檢查碼 |
| PlatformID | String(10) | 否 | 平台商代號 |

### 回應參數

| 參數 | 型別 | 說明 |
|------|------|------|
| RtnCode | Int | `1`=成功 |
| RtnMsg | String(200) | 交易訊息 |
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| CheckMacValue | String | 檢查碼 |

---

## ATM/CVS/BARCODE 取號結果查詢

**端點**:
- 測試: `https://payment-stage.ecpay.com.tw/Cashier/QueryPaymentInfo`
- 正式: `https://payment.ecpay.com.tw/Cashier/QueryPaymentInfo`

**Method**: POST, `application/x-www-form-urlencoded`

**注意**: 訂單已完成付款時，此查詢不可用，請改用查詢訂單 API。

### 請求參數

| 參數 | 型別 | 必填 | 說明 |
|------|------|------|------|
| MerchantID | String(10) | 是 | 特店編號 |
| MerchantTradeNo | String(20) | 是 | 特店交易編號 |
| TimeStamp | Int | 是 | Unix Timestamp，3 分鐘內有效 |
| CheckMacValue | String | 是 | 檢查碼 |
| PlatformID | String(10) | 否 | 平台商代號 |

### 回應共用參數

| 參數 | 型別 | 說明 |
|------|------|------|
| RtnCode | Int | 交易狀態碼 |
| RtnMsg | String(200) | 交易訊息 |
| MerchantID | String(10) | 特店編號 |
| MerchantTradeNo | String(20) | 特店交易編號 |
| StoreID | String(20) | 店舖代號 |
| TradeNo | String(20) | 綠界交易編號 |
| TradeAmt | Int | 交易金額 |
| PaymentType | String(20) | 付款方式 |
| TradeDate | String(20) | 訂單成立時間 |
| CustomField1~4 | String(50) | 自訂欄位 |
| CheckMacValue | String | 檢查碼 |

### CVS 回應專屬參數

| 參數 | 型別 | 說明 |
|------|------|------|
| PaymentNo | String(14) | 繳費代碼 |
| PaymentURL | String(100) | 繳費連結 |
| ExpireDate | String(10) | 繳費期限 `yyyy/MM/dd HH:mm:ss` |

### ATM 回應專屬參數

| 參數 | 型別 | 說明 |
|------|------|------|
| BankCode | String(3) | 繳費銀行代碼 |
| vAccount | String(16) | 虛擬帳號 |
| ExpireDate | String(10) | 繳費期限 `yyyy/MM/dd` |

### BARCODE 回應專屬參數

| 參數 | 型別 | 說明 |
|------|------|------|
| Barcode1 | String(20) | 第一段條碼 |
| Barcode2 | String(20) | 第二段條碼 |
| Barcode3 | String(20) | 第三段條碼 |
| ExpireDate | String(20) | 繳費期限 `yyyy/MM/dd HH:mm:ss` |
