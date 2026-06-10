# PayNow

PayNow（立吉富，立吉富股份有限公司，paynow.com.tw）台灣第三方支付、物流與電子發票加值服務商。Power Checkout 串接三大領域：

1. **金流（Payment domain）** — gateway `paynow`，體系 1 REST PaymentIntent + Component SDK v2 iframe 內嵌（已完成）
2. **物流（Logistics domain）** — provider `paynow_logistics`，超商取貨（7-11/全家/HiLife）+ 黑貓宅配 + 冷凍交貨便（規劃中，2026-06-10）
3. **電子發票（Invoice domain）** — provider `paynow`（或 `paynow_invoice`，見下方 CON），體系 3 Bearer JWT-Token（規劃中，2026-06-10）

本 gateway 為 Payment domain，與既有金流（SLP / ECPay AIO / ECPay ECPG / NewebPay MPG / PAYUNi UPP / PAYUNi UNi Embed）並列，並對齊統一介面 `IPaymentProvider`（extends `AbstractPaymentGateway`）。

> ⚠️ PayNow 文件有三套彼此獨立的串接體系（認證 / 端點 / 加密完全不同），本次只用體系 1：
> - **體系 1**：新版 REST + Component SDK（Bearer PrivateKey；iframe 站內付；Webhook HMAC-SHA256）← 本次採用
> - 體系 2：舊版 CashFlow（導轉 etopm.aspx + 背景 PayNowAPI_JS.aspx，GP→GK 換鑰）← 不採用
> - 體系 3：電子發票（Bearer JWT-Token）← 本次排除，列 GAP 後續

## 描述

第三方系統。負責：

### 金流 — 體系 1 內嵌式（gateway `paynow`）
- **建立付款意圖**（`POST /api/v1/payment-intents`，Bearer PrivateKey）：商家後端帶 amount / currency=TWD / allowedPaymentMethods / allowInstallments / webhookUrl / resultUrl / expireDays，PayNow 回 `result.id`（`pp_xxx`）+ `result.secret`（`pp_xxx_st_xxx`）；建立後狀態 `draft`
- **Component SDK v2 收單**（`https://js.paynow.com.tw/sdk/v2/index.js`）：前端 `PayNow.createPayment({publicKey, secret, env})` → `mount('#container')` → `checkout()`，消費者在 iframe 內完成付款（含信用卡 3DS / 超商代碼 / ATM 待繳 / LINE Pay / Apple Pay，皆由 SDK 內部處理）
- **付款結果通知（Webhook payment_result）**：PayNow 以 POST 推送付款結果到建立付款意圖時的 `webhookUrl`（Header `X-Payment-Center-Topic: payment_result`），交易結果以此為準（resultUrl 前景導頁可能漏收）
- **退款 / 查詢**（Bearer PrivateKey）：退款開立 `POST /api/v1/payment-intents/:id/refunds`、退款查詢 `GET /api/v1/refunds/:uuid`、補查付款意圖 `GET /api/v1/payment-intents/:id`

### 付款方式（首期全支援，Q1 裁決）
- 信用卡一次付清（CreditCard）
- 信用卡分期（CreditCardInstallment；期數 3/6/9/12/18/24，allowInstallments 限制）
- ATM 虛擬帳號（ATM；退款需 bankCode / bankBranchCode / bankAccount）
- 超商代碼（ConvenienceStore；ibon / FamiPort，codeType）
- LINE Pay（LINEPayOnline / LINEPayOffline）
- Apple Pay（ApplePay）
- ⚠️ 排除 ApplePayDeferred（不可與其他付款方式併用）

### 後台交易管理（Q4 裁決）
- 補查付款意圖（`GET /api/v1/payment-intents/:id`）— 對帳補單
- 退款（`POST /api/v1/payment-intents/:id/refunds`）— 信用卡 / ATM API 退款
- 退款查詢（`GET /api/v1/refunds/:uuid`）— 查退款狀態（success / failed / rejected / processing）
- ⚠️ PayNow 體系1 **無** capture（請款）/ void_auth（取消授權）對應端點 → gateway 維持 `AbstractPaymentGateway` no-op 預設，不覆寫

## 關鍵屬性

- **認證**：所有 REST 請求 `Authorization: Bearer {PrivateKey}`（type=apiKey, in=header）。PublicKey 用於前端 SDK 初始化；PrivateKey 用於後端發起付款/退款/查詢 + Webhook 驗簽，**絕不可外洩**
- **加密 / 驗簽**：體系1 無對稱加密；Webhook 驗簽用 **HMAC-SHA256**（`X-Payment-Center-Hmac-Sha256` = `strtoupper(hash_hmac('sha256', rawBody, PrivateKey))`，對 raw payload 驗，勿先 json_decode 再 re-encode；timing-safe `hash_equals`）。**與 PAYUNi AES-256-GCM / ezPay AES-256-CBC / ECPay AES-128-CBC 完全不同源，不可套用其他服務商 crypto**
- **PaymentIntent 狀態機**：`draft`（已建尚未付款）→ `processing`（進行中，失敗退回 draft）→ `pending_review`（待 3DS）→ `success`（完成）；`canceled`（僅 draft 可轉，processing/success 不可取消，要退款）
- **統一回應格式**：`{ status, type, message, result, requestId, paginate }`（成功 status=200, type="success"）
- **Webhook 處理**：須驗簽 + 冪等 + 金額防竄改，處理完務必回 **HTTP 200**（PayNow 確認收到）；Webhook payload 含 Status（Success/Failed）/ OrderNo / PaymentNo / PaymentIntentId / TransactionNo / Amount / PaymentType / Meta（卡末四碼/授權碼/卡別/CardToken）
- **離線付款**：ATM / 超商代碼為離線付款 — 先回「產生繳款資訊」（vAccount / 繳費代碼 / ExpireDate）寫入 `_pc_paynow_payment_info` + pending，待消費者繳費後 PayNow 再推 Webhook Status=Success
- 僅支援新台幣（TWD）
- **環境**：正式 `api.paynow.com.tw`、測試 `sandboxapi.paynow.com.tw`；SDK env 參數切換 `sandbox`/`production`（非換 SDK URL）。正式 / 測試環境完全獨立，帳號 / 金鑰需個別申請，上線前不可在正式環境測試交易
- **憑證**（PublicKey / PrivateKey）由管理員於後台 Vue 設定頁填入，存 `woocommerce_paynow_settings`，**不寫死於程式碼**
- ⚠️ **sandbox 憑證使用者尚未申請（GAP，Q5）** — 以 API_MODE=mock 跑綠為驗收主軸；PrivateKey 需來信 PayNow 申請（信件主旨「申請 PayNow 串接私鑰 (PrivateKey)」），憑證到位後補 sandbox 端到端驗證
- order meta 前綴一律 `_pc_paynow_`，與既有金流（PAYUNi `_pc_payuni_` / `_pc_payuni_uni_`、ECPay `_pc_ecpay_`）區隔避免衝突
- CSP 須允許 `https://js.paynow.com.tw`（script-src + frame-src）

### 物流（provider `paynow_logistics`，規劃中 2026-06-10）

實作統一介面 `ILogisticsProvider`（10 methods），與 ECPay `ecpay_logistics` / PAYUNi `payuni_logistics` 平行（PayNow 為第三個物流 provider）。

> ⚠️ **知識來源**：`paynow` skill **無物流 API**（只有金流 + 發票）。物流規格依 **woomp**（MorePower Addon，`../woomp/includes/paynow-shipping/`）既有可運作實作反推；端點/加密/欄位有程式碼實證，**待 PayNow 官方物流 API 文件核對**（GAP）。

- **物流服務**：7-11 店到店(01)、全家店到店(03)、HiLife 店到店(05)、黑貓宅配(06)；冷凍交貨便（7-11 冷凍 C2C 21、全家冷凍 C2C 23、大宗冷凍 22/24）第二期
- **端點**（grounded from woomp）：建單 `POST /api/Orderapi/Add_Order`、重新取號 `POST /api/Orderapi/ReNewOrder`、取消 `DELETE /api/Orderapi/CancelOrder`、查詢 `GET /api/Orderapi/Get_Order_Info`、選店地圖 `{api_url}/Member/Order/Choselogistics`、列印 per-service（Order711/OrderFamiC2C/OrderHiLife/PrintBlackCatLabel...）
- **認證**：`user_account` + `apicode`（商家 API 密碼）；`PassCode = strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))`
- **加密**：**TripleDES DES-EDE3** 固定 key/IV、NO_PADDING、base64（建單 `JsonOrder` 欄位）。**與金流 HMAC-SHA256 / PAYUNi AES-256-GCM / ECPay AES-128-CBC 全不同源，自建 helper，不複用**
- **DeliverMode**：01=取貨付款（COD）/ 02=取貨不付款（線上付款）
- **貨態通知**：⚠️ PayNow 物流為「主動查詢」模式（woomp 無 webhook 推送證據）→ `handle_status_callback()` 退化為查詢補單，無 ServerReplyURL 推送端點（BDY，待官方文件確認是否有貨態 webhook）
- **逆物流**：⚠️ woomp 無 PayNow 退貨 API 證據 → `create_return()` throw `\Exception('尚未實作')`（GAP）
- order meta 前綴 `_pc_paynow_logistics_`（與金流 `_pc_paynow_` 區隔）
- **憑證**（user_account / apicode）存 `woocommerce_paynow_logistics_settings`，不寫死；⚠️ sandbox 憑證使用者尚未申請（GAP）

### 電子發票（provider `paynow`，規劃中 2026-06-10）

實作統一介面 `IInvoiceService` + 選配 `ISupportsAllowance` + `ISupportsQuery`（與 Amego / ECPay / ezPay 平行；PayNow 為第四個 invoice provider，能力等級同 ezPay）。

> 知識來源：`paynow` skill `references/invoice-api.md`（體系 3，完整端點 + 欄位表）。

- **體系 3**：Bearer **商家 JWT-Token**；測試 `invoiceapi-dev.paynow.com.tw` / 正式 `invoiceapi-prod.paynow.com.tw`；**無對稱加密**（純 Bearer，與金流 HMAC / 物流 TripleDES 皆不同）
- **能力**：開立 `POST /api/invoices/issue`、作廢 `POST /api/invoices/cancel`、折讓 `POST /api/invoices/allowance`、折讓作廢 `POST /api/invoices/cancel-allowance`、查詢 `GET /api/invoices`
- **載具**（carrier_type）：None（紙本）/ PhoneBarCodeCarrier（手機條碼）/ **EasyCardCarrier（悠遊卡，PayNow 獨有）** / CitizenDigitalCardNo（自然人憑證）/ BuyerSno（PayNow 會員載具）
- **課稅別**（tax_type）：SaleTax / FreeTax / ZeroTax / MixTax；**非統編 tax_amount=0**（國稅局算稅），統編帶實際稅額；零稅率必填 `is_pass_customs` + `zero_tax_rate_reason`；載具與捐贈互斥
- **共用層**：沿用 provider-agnostic 結帳表單 / order meta（`_pc_issued_invoice_data` / `_pc_invoice_provider_id`）/ REST `/invoices/issue|cancel` / 退款自動折讓 hook；僅 provider id 不同
- **排除**：POS 取號 / POS 開立（一般電商不走 POS，GAP 後續）
- **憑證**（JWT-Token）存 `woocommerce_paynow_invoice_settings`（或 `woocommerce_paynow_settings`，視 ID 裁決），不寫死；⚠️ sandbox 憑證使用者尚未申請（GAP）
- ⚠️ **CON（須裁決）**：發票 provider ID 與金流 gateway ID 同名 `paynow`。Invoice 與 Payment 為不同 domain 不同 register，但 ProviderUtils 容器與 WC option key 須確保唯一。**建議發票 provider ID 用 `paynow_invoice` + option `woocommerce_paynow_invoice_settings`**，避免撞金流 `woocommerce_paynow_settings`；實作前確認

> ⚠️ 原金流 Execution Plan（2026-06-09 Q2）將「PayNow 電子發票（體系3）」列為 deferred GAP；本次（2026-06-10）正式納入規劃，與物流一同實作。
</content>
