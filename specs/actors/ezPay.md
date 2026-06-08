# ezPay

ezPay 電子發票加值服務平台，由**簡單行動支付股份有限公司**（藍新金流 NewebPay 集團旗下品牌）提供，串接台灣財政部電子發票。Power Checkout 將其新增為 Invoice domain 的第三個 provider（id=`ezpay`，與 Amego、綠界發票 `ecpay` 並存，後台可切換），實作統一 `IInvoiceService` + `ISupportsAllowance` + `ISupportsQuery` 介面。對應官方手冊 EZP_INVI_1.2.1「電子發票技術串接手冊（標準版）」。

## 描述

第三方系統。負責：

### 發票開立 / 作廢
- 開立發票 `/Api/invoice_issue`（Version 1.5）：即時開立（Status=1，回應帶 InvoiceNumber）/ 等待觸發（Status=0）/ 預約自動（Status=3）。首期僅用即時開立
- 作廢發票 `/Api/invoice_invalid`（Version 1.0）：以 InvoiceNumber + InvalidReason 作廢

### 折讓（ISupportsAllowance）
- 開立折讓 `/Api/allowance_issue`（Version 1.3）：對已開立發票開折讓單；Status=0 不立即確認 / Status=1 立即確認
- 作廢折讓 `/Api/allowanceInvalid`（Version 1.0）：只能作廢已確認折讓
- 觸發確認/取消折讓 `/Api/allowance_touch_issue`（Version 1.0）：非首期

### 查詢（ISupportsQuery）
- 查詢發票 `/Api/invoice_search`（Version 1.3）：SearchType=0（InvoiceNumber+RandomNum）/ SearchType=1（MerchantOrderNo+TotalAmt）；回傳明細 + InvoiceStatus + UploadStatus

## 關鍵屬性

- 加密：**AES-256-CBC**（HashKey 32 bytes / HashIV 16 bytes / PKCS#7 padding blocksize 32 + OPENSSL_ZERO_PADDING / 輸出小寫 hex）。**與綠界發票 AES-128-CBC + base64 不同，AesCrypto 不可複用**（與藍新 MPG 金流同套 AES-256/hex 規則）
- 傳輸：HTTP POST + Form Post（`application/x-www-form-urlencoded`），body 固定兩欄 `MerchantID_`（明文）+ `PostData_`（加密 hex）— **兩參數名稱結尾都有底線 `_`**
- 回應驗證：CheckCode（SHA256）— 5 欄位（InvoiceTransNo / MerchantID / MerchantOrderNo / RandomNum / TotalAmt）A-Z 排序 + 前後夾 HashIV / HashKey 後 SHA256 大寫比對
- 回應格式：RespondType=JSON 時最外層 `{ Status, Message, Result }`，Result 為 JSON 字串需再 parse；String 模式須驗結尾 `EndStr=##`
- 環境：測試 `https://cinv.ezpay.com.tw`、正式 `https://inv.ezpay.com.tw`（**測試正式不同網域**，切換需同時換網域+商店代號+HashKey+HashIV）
- 金額性質依 Category：**B2B 的 ItemPrice/ItemAmt 為未稅、B2C 為含稅**；平台僅檢核「ItemAmt = ItemCount × ItemPrice」與「TotalAmt = Amt + TaxAmt」
- 載具與捐贈互斥：CarrierType 有值時 LoveCode 必空（CarrierType 0=手機條碼 / 1=自然人憑證 / 2=ezPay 載具，CarrierType=2 時 BuyerEmail 必填）
- MerchantOrderNo 同商店不可重覆；以完全相同 PostData_ 重送會回原發票（冪等）
- Status=SUCCESS 只代表平台收件成功，**不代表已上傳財政部**（上傳結果須查 invoice_search 的 UploadStatus）
- 作廢限制：奇數月 14 日前可作廢前兩個月發票；已開折讓 / 未上傳財政部 / 上傳失敗 / 已作廢的發票不可作廢
- 憑證（MerchantID / HashKey / HashIV）由管理員於後台 Vue 設定頁填入，存 `woocommerce_ezpay_settings`；**不寫死於程式碼**
