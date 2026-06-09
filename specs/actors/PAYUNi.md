# PAYUNi

PAYUNi（統一金流）第三方金流服務商。Power Checkout 透過兩種整合方式串接 PAYUNi：

1. **UPP V2**（UNiPaypage Version 2.0，導轉式整合支付頁）— gateway `payuni_upp`
2. **UNi Embed V3**（免跳轉支付元件，內嵌式信用卡 iframe 收單）— gateway `payuni_uni_embed`

兩者均為 Payment domain gateway，與既有金流（SLP / ECPay AIO / ECPay ECPG / NewebPay MPG）並列，並對齊統一介面 `IPaymentProvider`。加密規則（AES-256-GCM + SHA256 HashInfo）與商店金鑰（HashKey/HashIV）兩者共用同一套；UNi Embed 直接複用 `Domains/Payment/Payuni/Shared/Helpers/PayuniCrypto`，不另開副本。

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

### 後台交易管理（UPP）
- 交易查詢（`/api/trade/query`）— 對帳補單
- 信用卡請退款（`/api/trade/close`）— API 退款
- 取消授權（`/api/trade/cancel`）

### 金流 — UNi Embed V3 內嵌式（gateway `payuni_uni_embed`）
- 免跳轉支付元件：商店頁面以 iframe 內嵌信用卡輸入欄位（卡號 / 有效期限 / CVC），消費者不離開商店網站完成付款
- 兩步驟幕後流程（V3）：
  1. 後端取 SDK_TOKEN（`/api/iframe/token_get`，Version 3.0）— **V3 此階段不送訂單資料**（僅 MerID + Timestamp + IFrameDomain），SDK_TOKEN 10 分鐘有效
  2. 前端 `uni-payment.js`（JS SDK Ver 2.0）蒐集卡片並取得綁定 TOKEN 結果（getTradeResult 在 V3 只綁定、不授權）
  3. 後端以原 SDK_TOKEN 呼叫幕後授權（`/api/iframe/merchant_trade`，請求 Version 1.0 / 回傳 Version 1.2），此階段才送 MerTradeNo + TradeAmt + ProdDesc
- 付款結果通知：
  - NotifyURL（幕後 Server-to-Server Form POST，3D 完成後回打；交易結果以此為準，商家須回 HTTP 200）
  - ReturnURL（前景，可能因關閉瀏覽器漏收）
- 3D 交易：merchant_trade（或 API3D=1 強制 3D）回傳導頁 URL，前端導向銀行 3D 驗證，銀行驗證後 Form POST 至 NotifyURL
- 僅支援信用卡（一次付清 / 分期 / 約定信用卡 / 記憶卡號 / 強制約定）；其他付款工具（ATM/CVS/LINE Pay 等）走 UPP

### 後台交易管理（UNi Embed，信用卡）
- 交易查詢（`/api/trade/query`）— 對帳補單（與 UPP 共用端點）
- 信用卡請退款（`/api/trade/close`，CloseType=2 退款 / CloseType=1 請款）
- 取消授權（`/api/trade/cancel`）

### 買方信用卡 Token（UNi Embed，記憶卡號 / 約定信用卡）
- 首次交易時以 `UseTokenType`（1=約定 / 2=記憶卡號 / 3=強制約定）+ `CreditToken`（付款人識別，建議會員編號 / Email / 手機）綁卡
- 授權成功後回傳 `CreditHash`（Token Hash）+ `CreditLife`（有效日期 MMYY）
- ⚠️ 商店端**只保存 PAYUNi 回傳的 Token Hash 與有效日期，絕不保存卡號 / CVC**
- 後續續期扣款走 UPP `/api/credit` 體系（共用，UNi Embed 本身僅負責首次綁卡）

## 關鍵屬性

- 加密：AES-256-GCM（與藍新 MPG 的 AES-256-CBC 不同；與綠界的 AES-128-CBC 不同），`HashInfo` 為 SHA256
- 僅支援新台幣（TWD）
- callback 須冪等處理（以商家訂單編號 MerTradeNo 為 key），PAYUNi 會重送
- 非信用卡付款（ATM/CVS/行動支付）是否支援 API 退款 — 依 PAYUNi 規格，非信用卡多須 PAYUNi 後台人工（實作階段以 payuni-upp-v2 skill 確認）
- 環境：Sandbox 先行（測試端點），prod 切換由後台設定 mode 開關控制
- 憑證（MerID / HashKey / HashIV）由管理員於後台 Vue 設定頁填入；UPP 存 `woocommerce_payuni_upp_settings`、UNi Embed 存 `woocommerce_payuni_uni_embed_settings`；**不寫死於程式碼**
- ⚠️ 正式憑證使用者尚未提供（GAP）— 實作階段用 PAYUNi sandbox 測試帳號驗證，prod 憑證後補
- UNi Embed order meta 前綴一律 `_pc_payuni_uni_`，與 UPP 的 `_pc_payuni_` 區隔避免衝突
- UNi Embed 須事先聯繫 PAYUNi 客服開通免跳轉元件 + 設定限定後端來源 IP；CSP 須允許 `vendor.payuni.com.tw`（script-src + frame-src）
