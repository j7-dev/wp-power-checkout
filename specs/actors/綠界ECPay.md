# 綠界ECPay

綠界科技（ECPay）第三方金流 + 電子發票服務商。Power Checkout 透過兩種金流整合模式（AIO 導轉式、ECPG 站內付 2.0）整合線上付款，並可選用綠界電子發票（與 Amego 並存）。

## 描述

第三方系統。負責：

### 金流 — AIO 導轉式（gateway `ecpay_aio`）
- 託管付款頁面（綠界 Cashier V5）：消費者在綠界頁面選擇付款方式並完成付款或取號
- 接收建單請求（AioCheckOut/V5），以 CheckMacValue（SHA256）驗證請求完整性
- 付款結果通知：
  - ReturnURL（幕後 Server-to-Server，Form POST，商家須回 `1|OK`）
  - OrderResultURL（前景，導回商家結果頁）
  - PaymentInfoURL（ATM/CVS/BARCODE 取號通知，Form POST，商家須回 `1|OK`）

### 金流 — ECPG 站內付 2.0（gateway `ecpay_ecpg`）
- GetTokenbyTrade / CreatePayment（ecpg domain，AES-128-CBC 加密 Data）
- ThreeDURL 3D Secure 驗證（2025/8 起強制，幾乎必出現）
- ReturnURL（幕後 JSON POST + AES 解密，商家須回 `1|OK`）
- 查詢 / 退款走 ecpayment domain（與 ecpg domain 不可混用）

### 退款
- 信用卡退款 API（DoAction，Action=R 退款 / Action=N 取消授權）

### 電子發票（Invoice provider `ecpay`，與 Amego 並存）
- B2C / B2B 開立、作廢（AES-JSON）

## 關鍵屬性

- 僅支援新台幣（TWD）
- AIO callback 的 RtnCode 為字串型別（成功付款 `'1'`；ATM 取號成功 `'2'`；CVS/BARCODE 取號成功 `'10100073'`）
- ECPG callback 解密後 RtnCode 為整數型別，須雙層錯誤檢查（TransCode 傳輸層 → RtnCode 業務層）
- callback 最多重送 4 次，須冪等處理（以 MerchantTradeNo 為 key）
- 非信用卡付款（ATM/WebATM/CVS/BARCODE/ApplePay）不支援 API 退款，須綠界後台人工
- 綠界付款頁不可用 iframe 嵌入
- 憑證（MerchantID / HashKey / HashIV）由管理員於後台 Vue 設定頁填入，存 `woocommerce_{gateway_id}_settings`；測試環境用綠界公開測試帳號（3002607…）；**不寫死於程式碼**
