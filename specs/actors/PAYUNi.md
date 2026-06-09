# PAYUNi

PAYUNi（統一金流）第三方金流服務商。Power Checkout 透過 UPP V2（UNiPaypage Version 2.0，導轉式整合支付頁）整合線上付款，新增為 Payment domain 新 gateway `payuni_upp`，與既有金流（SLP / ECPay AIO / ECPay ECPG / NewebPay MPG）並列，並對齊新抽出的統一介面 `IPaymentProvider`。

## 描述

第三方系統。負責：

### 金流 — UPP V2 導轉式（gateway `payuni_upp`）
- 託管付款頁面（UNiPaypage）：消費者在 PAYUNi 頁面選擇付款方式並完成付款或取號
- 接收建單請求（`/api/upp`，瀏覽器 auto-form POST），以 `EncryptInfo`（AES-256-GCM 加密）+ `HashInfo`（SHA256）驗證請求完整性
- 付款結果通知：
  - NotifyURL（幕後 Server-to-Server，Form POST，商家須回應成功，避免 PAYUNi 重送）
  - ReturnURL（前景，導回商家結果頁）
  - CustomerURL（取號通知導回，ATM/CVS 取號資訊顯示）

### 付款方式（首期全支援）
- 信用卡（一次付清 + 分期 `InstFlag`）
- ATM 虛擬帳號
- 超商代碼（CVS）
- 行動支付：LINE Pay、街口支付、Apple Pay、Google Pay

### 後台交易管理
- 交易查詢（`/api/trade/query`）— 對帳補單
- 信用卡請退款（`/api/trade/close`）— API 退款
- 取消授權（`/api/trade/cancel`）

## 關鍵屬性

- 加密：AES-256-GCM（與藍新 MPG 的 AES-256-CBC 不同；與綠界的 AES-128-CBC 不同），`HashInfo` 為 SHA256
- 僅支援新台幣（TWD）
- callback 須冪等處理（以商家訂單編號 MerTradeNo 為 key），PAYUNi 會重送
- 非信用卡付款（ATM/CVS/行動支付）是否支援 API 退款 — 依 PAYUNi 規格，非信用卡多須 PAYUNi 後台人工（實作階段以 payuni-upp-v2 skill 確認）
- 環境：Sandbox 先行（測試端點），prod 切換由後台設定 mode 開關控制
- 憑證（MerID / HashKey / HashIV）由管理員於後台 Vue 設定頁填入，存 `woocommerce_payuni_upp_settings`；**不寫死於程式碼**
- ⚠️ 正式憑證使用者尚未提供（GAP）— 實作階段用 PAYUNi sandbox 測試帳號驗證，prod 憑證後補
