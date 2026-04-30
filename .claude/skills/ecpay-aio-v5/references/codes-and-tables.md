# ECPay AIO V5 -- 錯誤代碼與對照表

## 目錄

- [交易訊息代碼一覽表](#交易訊息代碼一覽表)
- [PaymentType 回覆付款方式對照表](#paymenttype-回覆付款方式對照表)
- [ChoosePayment 付款方式值](#choosepayment-付款方式值)
- [ChooseSubPayment 付款子項目值](#choosesubpayment-付款子項目值)

---

## 交易訊息代碼一覽表

| 代碼 | 訊息 | 說明與解法 |
|------|------|-----------|
| 1 | 付款成功 | RtnCode=1 代表交易成功 |
| 2 | ATM 取號成功 | ATM 取號結果通知的成功碼 |
| 10100058 | Pay Fail 付款失敗 | 輸入卡號後 3D 驗證失敗。建議：更換瀏覽器/裝置、關閉 VPN、更新瀏覽器版本、更換 IP |
| 10100073 | CVS/BARCODE 取號成功 | CVS/BARCODE 取號結果通知的成功碼 |
| 10100248 | 觸動風險控管 | 連續刷卡或可疑電話號碼，需聯繫發卡行 |
| 10200095 | 交易未成立 | TradeStatus 查詢結果：交易未成立 |
| 10200141 | 商店未開啟收款服務 | 確認 API URL 是否正確（測試/正式環境）、確認服務已啟用 |
| 10200146 | 此商店不支援信用卡分期 | 需申請啟用分期付款服務 |
| 10300023 | 本次交易未提供任何付款方式 | 檢查 MerchantID 是否正確、確認服務審核狀態 |
| 10300024 | 資料驗證錯誤 | 消費者在 3D 驗證時操作異常導致，請回到商家頁面重新操作 |
| 10800001 | 觸動風險控管 | 同 10100248 |
| 5100070 | 建立訂單失敗 | 交易金額超出付款方式的限額範圍 |
| 5100071 | 權限不足 | 未啟用嵌入式付款頁面服務，需聯繫客服 |

---

## PaymentType 回覆付款方式對照表

付款結果通知中 PaymentType 欄位的可能值：

### 信用卡

| PaymentType | 說明 |
|-------------|------|
| `Credit_CreditCard` | 信用卡 (MasterCard/JCB/VISA) |
| `Flexible_Installment` | 永豐 30 期 |

### WebATM

| PaymentType | 說明 |
|-------------|------|
| `WebATM_BOT` | 台灣銀行 WebATM |
| `WebATM_CHINATRUST` | 中國信託 WebATM |
| `WebATM_FIRST` | 第一銀行 WebATM |
| `WebATM_LAND` | 土地銀行 WebATM |
| `WebATM_TACHONG` | 大眾銀行 WebATM |

### ATM

| PaymentType | 說明 |
|-------------|------|
| `ATM_BOT` | 台灣銀行 ATM |
| `ATM_CHINATRUST` | 中國信託 ATM |
| `ATM_FIRST` | 第一銀行 ATM |
| `ATM_LAND` | 土地銀行 ATM |
| `ATM_CATHAY` | 國泰世華銀行 ATM |
| `ATM_PANHSIN` | 板信銀行 ATM |
| `ATM_KGI` | 凱基銀行 ATM |

### CVS 超商代碼

| PaymentType | 說明 |
|-------------|------|
| `CVS_CVS` | 超商代碼繳款 |
| `CVS_OK` | OK 超商 |
| `CVS_FAMILY` | 全家超商 |
| `CVS_HILIFE` | 萊爾富超商 |
| `CVS_IBON` | 7-11 ibon |

### 其他

| PaymentType | 說明 |
|-------------|------|
| `BARCODE_BARCODE` | 超商條碼繳款 |
| `TWQR_OPAY` | 歐付寶 TWQR 行動支付 |
| `BNPL_URICH` | 裕富數位無卡分期 |
| `BNPL_ZINGALA` | 中租銀角零卡 |
| `DigitalPayment_Jkopay` | 街口支付 |
| `DigitalPayment_IPASS` | 一卡通 iPASS MONEY |

---

## ChoosePayment 付款方式值

建立訂單時 ChoosePayment 參數可用值：

| 值 | 說明 |
|----|------|
| `ALL` | 全方位金流（顯示所有已啟用的付款方式） |
| `Credit` | 信用卡（含一次付清、分期、定期定額） |
| `ATM` | ATM 虛擬帳號 |
| `CVS` | 超商代碼 |
| `BARCODE` | 超商條碼 |
| `WebATM` | 網路 ATM |
| `ApplePay` | Apple Pay |
| `TWQR` | TWQR 行動支付 (歐付寶) |
| `BNPL` | 無卡分期 (裕富/中租) |
| `WeiXin` | 微信支付 |
| `DigitalPayment` | 綠界 Pay (街口支付等) |

---

## ChooseSubPayment 付款子項目值

### ATM 子項目

| 值 | 說明 |
|----|------|
| `FIRST` | 第一銀行 |
| `CATHAY` | 國泰世華 |
| `PANHSIN` | 板信銀行 |
| `KGI` | 凱基銀行 |

### CVS 子項目

| 值 | 說明 |
|----|------|
| `CVS` | 超商代碼 (不指定超商) |
| `OK` | OK 超商 |
| `FAMILY` | 全家 |
| `HILIFE` | 萊爾富 |
| `IBON` | 7-11 ibon |

### BNPL 子項目

| 值 | 說明 |
|----|------|
| `URICH` | 裕富數位 |
| `ZINGALA` | 中租銀角零卡 |

### WebATM 子項目

| 值 | 說明 |
|----|------|
| `BOT` | 台灣銀行 |
| `CHINATRUST` | 中國信託 |
| `FIRST` | 第一銀行 |
| `LAND` | 土地銀行 |

> 標注「暫不提供」的銀行選項在官方文件中已列出但目前不可用，包括：TAISHIN, ESUN, FUBON, CATHAY(WebATM), MEGA, SINOPAC 等。
