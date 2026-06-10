# Execution Plan — PayNow（立吉富）金流，gateway `paynow`（體系 1 內嵌式）

> Phase 01 Discovery 產出。後續 Phase 02-08 以此為 scope 依據。
> 狀態：**5 個 scope 澄清題已裁決**（2026-06-09，見 §澄清裁決）。Behavior Design 已產出（activity ×1、feature ×5、actor ×1、ui ×1）。
>
> 硬約束：新 gateway 必須 `implements IPaymentProvider`（透過 extends `AbstractPaymentGateway`）。
> 整合形態：PayNow **體系 1**（REST PaymentIntent + Component SDK v2 iframe 站內付）；
>   結構同構於既有 `PayuniUniEmbed`（內嵌式）——抄其目錄結構與生命週期。
> 知識來源：專案內 `paynow` skill（唯一權威，禁止上網查）。

## 澄清裁決（2026-06-09）

| # | 澄清題 | 裁決 |
|---|--------|------|
| Q1 | 付款方式範圍 | **全部**：CreditCard / CreditCardInstallment / ATM / 超商代碼(ibon,FamiPort) / LINE Pay(Online+Offline) / Apple Pay；**排除 ApplePayDeferred**（不可與其他併用） |
| Q2 | 發票（體系3） | **排除**，金流先行；PayNow 電子發票（體系3）列 GAP 後續實作 |
| Q3 | WC Blocks | **classic + blocks 同步**（含 block 入口 `paynow.tsx` + BlocksIntegration） |
| Q4 | admin 能力 | **退款 + 退款查詢 + 補查付款意圖全做**；capture/void_auth 維持 AbstractPaymentGateway no-op（PayNow 體系1 無對應端點） |
| Q5 | sandbox 憑證 | **無**（用戶尚未申請）；以 API_MODE=mock 跑綠為驗收主軸，sandbox PublicKey/PrivateKey + 端到端驗證列 GAP |

## 為何選體系 1（第一性原理）

PayNow 文件有三套獨立體系：1=新版 REST+SDK（Bearer，iframe 站內付）、2=舊版 CashFlow（導轉+背景握手換鑰）、3=電子發票。
skill `concepts.md` §2 明示「WooCommerce / Power Checkout 新整合建議走體系 1 + 體系 3」。
體系 1 一套即涵蓋：建立付款意圖（draft）→ 前端 SDK 收卡 → Webhook payment_result → 退款 / 查詢，
無需體系 2 的 GP→GK→操作 三段握手。故金流選體系 1；發票（體系 3）依 Q2 裁決排除，列 GAP 後續。

## 與範本（UNi Embed）的關鍵差異

PayNow 體系1 iframe 流程**無需** create-payment REST 端點（不同於 UNi Embed 的 merchant_trade 兩步驟）：
PayNow Component SDK `checkout()` 直接由 SDK 與 PayNow 完成授權 + 3DS，後端只需「建立 PaymentIntent → 收 Webhook」。
故 PayNow 比 UNi Embed **少一支 FrontendApi（create-payment）+ 少一個 STEP（前端送回後端授權）**。退款/查詢/補單則對齊 UNi Embed 的 admin 能力。

## 概覽

| 類型 | 數量 |
|------|------|
| Create | activity ×1、feature ×5、ui ×1、actor ×1 |
| Modify | 0 |
| Delete | 0 |

## 與既有實作的複用對照

| 複用對象 | 來源 | 用途 |
|---------|------|------|
| 內嵌式骨架 | `PayuniUniEmbed`（secret → 前端 SDK → 3DS → callback/notify） | 架構對照，逐檔對映（減 create-payment 那支） |
| 統一介面 | `Payment/Shared/Interfaces/IPaymentProvider` + `AbstractPaymentGateway` | gateway extends |
| 前端內嵌元件 | `js/src/external/PayuniUniEmbed/`（載 SDK、mount、checkout、3DS 導頁、order-received 掛載） | 對照改寫為 PayNow Component SDK v2 |
| Blocks 註冊 | `Shared/Helpers/BlocksIntegration` + `inc/assets/blocks/payuni_uni_embed.tsx` | 對照新增 `paynow.tsx` |
| Vue 設定頁 | `js/src/pages/Payments/PayuniUniEmbed/index.vue` + router | 對照新增 |
| 退款 / 補單 REST | 既有 `power-checkout/v1 /refund` + admin order action（payuni_uni_embed pc_payuni_uni_* 慣例） | 對照新增 paynow 後台操作 |
| ⚠️ 加密 **不複用** | PayNow 體系1 無對稱加密（與 PAYUNi AES-256-GCM 不同源） | 自建 `WebhookVerifier`（HMAC-SHA256, key=PrivateKey） |

## 生命週期（對照 UNi Embed，體系 1 iframe）

```
1. before_process_payment()：寫冪等鍵 _pc_paynow_trade_no(PCN{order_id})
   → PaynowRestClient::create_payment_intent(amount/currency=TWD/allowedPaymentMethods/allowInstallments/webhookUrl/resultUrl/expireDays)
   → 回 result.id(pp_xxx) + result.secret(pp_xxx_st_xxx)
   → 存 _pc_paynow_payment_intent_id + _pc_paynow_secret → 回 order-received URL
2. before_order_received()：localize PublicKey + secret + env(sandbox/production) → 前端
3. 前端 MountPaynowPayment()：載 js.paynow.com.tw/sdk/v2 → createPayment({publicKey,secret,env}) → mount('#container') → checkout()
   （信用卡 3DS / 超商代碼 / ATM 待繳 / LINE Pay / Apple Pay 皆由 SDK iframe 內處理）
4. PayNow POST payment_result 到 webhookUrl（/wp-json/power-checkout/paynow/notify）
   → 驗簽鏈：X-Payment-Center-Hmac-Sha256 = strtoupper(hash_hmac('sha256', rawBody, PrivateKey))
   → hash_equals → 反查 _pc_paynow_payment_intent_id → 金額防竄改 → 冪等 → StatusManager → 回 HTTP 200
5. StatusManager 映射 Webhook Status / PaymentIntent status：
   Status=Success → 金額防竄改 guard → payment_complete() → processing
   ATM/超商代碼待繳（離線付款）→ 寫 _pc_paynow_payment_info + pending
   Status=Failed → pending + order note
6. 補查（query_trade）：GET /api/v1/payment-intents/:id 補對狀態（status=success → 補單）
```

## Phase 02: Entity Modeling（erm.dbml — Phase 02 reconciler 處理）

| 操作 | 目標 | 說明 |
|------|------|------|
| create（欄位） | `_pc_paynow_trade_no` | 商家 OrderNo/paymentNo 冪等鍵（格式 `PCN{order_id}`） |
| create（欄位） | `_pc_paynow_payment_intent_id` | PaymentIntent id（`pp_xxx`）；Webhook 反查主鍵 |
| create（欄位） | `_pc_paynow_secret` | PaymentIntent secret（`pp_xxx_st_xxx`）；交前端 SDK |
| create（欄位） | `_pc_paynow_payment_detail` | Webhook payment_result 解析後明細（TransactionNo / PaymentType / Meta：卡末四碼/授權碼/卡別/CardToken） |
| create（欄位） | `_pc_paynow_payment_info` | ATM/超商代碼待繳資訊（vAccount / 繳費代碼 / 條碼 / ExpireDate）— 因 Q1 含離線付款方式 |
| create（欄位） | `_pc_paynow_refund_detail` | 退款 result（refund uuid / status：success/failed/rejected/processing） |
| create | Enum | PaymentType（CreditCard/CreditCardInstallment/ATM/ConvenienceStore/LINEPayOnline/LINEPayOffline/ApplePay）、PaymentIntent status（draft/processing/pending_review/success/canceled）、Webhook Status（Success/Failed）、Refund status（success/failed/rejected/processing）— reconciler 從 feature datatable + activity BRANCH 萃取 |

## Phase 03: BDD Analysis（features — 本 Phase 01 產出骨架，Phase 03 補 Example + 句型）

| 操作 | 目標 | 類型 | 外部觸發動作 |
|------|------|------|-------------|
| create | `payment/paynow-checkout.feature` | @command | 顧客下單 → 後端 create_payment_intent 取 secret（回 order-received，前端 SDK 收卡；含付款方式範圍/分期/金額約束） |
| create | `payment/paynow-callback.feature` | @command | PayNow Webhook payment_result（HMAC-SHA256 驗簽 + 反查 + 金額防竄改 + 冪等 + always 200） |
| create | `payment/paynow-payment-info.feature` | @command | ATM/超商代碼待繳資訊寫入 + 繳費後 Webhook 補 Success（Q1 含離線付款方式） |
| create | `payment/paynow-refund.feature` | @command | 管理員退款（信用卡走 REST refunds；ATM 帶 bankCode/分行/帳號；超商代碼/LINE Pay 視 PayNow 規格） |
| create | `payment/paynow-trade-management.feature` | @command/@query | 管理員補查付款意圖（GET payment-intents/:id）+ 退款查詢（GET refunds/:uuid）（Q4） |

> Q2 裁決排除：PayNow 電子發票（體系3）`invoice/paynow-invoice-*.feature` 不在本次 scope，列 GAP 後續。

## Phase 04: API Contract（api.yml — Phase 04 reconciler 處理）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `POST /paynow/notify` | webhookUrl（HMAC-SHA256 驗簽在 callback 內；permission `__return_true`；always HTTP 200） |
| reuse | REST `/refund`（沿用既有 power-checkout/v1 /refund） | gateway process_refund 分流（信用卡/ATM REST refunds；超商/LINE Pay 不支援 → WP_Error） |
| create | admin order action（query_trade=補查付款意圖、退款查詢） | 後台訂單頁手動操作（比照既有 payuni_uni_embed pc_payuni_uni_* actions）（Q4） |

> 注意：PayNow 體系1 iframe 流程**無需** create-payment REST（不同於 UNi Embed 的 merchant_trade）——
> PayNow SDK `checkout()` 直接由 SDK 與 PayNow 完成授權 + 3DS，後端只需建立 PaymentIntent + 收 Webhook。
> 故 PayNow 比 UNi Embed **少一個** create-payment 端點（少一支 FrontendApi）。

## Phase 05-08: Implementation（TDD）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Domains/Payment/Paynow/` domain folder | 比照 `PayuniUniEmbed/` 結構（減 create-payment 那支） |
| create | `Services/PaynowGateway.php` | extends `AbstractPaymentGateway`；const ID='paynow'；before_process_payment(create_payment_intent)、before_order_received(localize SDK)、process_refund、query_trade(GET payment-intents/:id)、get_supported_payment_methods；capture/void_auth 不覆寫(no-op) |
| create | `Http/PaynowRestClient.php` | 建立/查詢付款意圖、退款開立/查詢（Bearer PrivateKey；skill php-examples 已有範例骨架） |
| create | `Http/PaynowCallback.php` | webhookUrl（HMAC-SHA256 驗簽 + 反查 + 金額防竄改 + 冪等 + always 200） |
| create | `Shared/Helpers/WebhookVerifier.php` | HMAC-SHA256（key=PrivateKey，strtoupper + hash_equals）；對 raw body 驗，勿 re-encode |
| create | `DTOs/PaynowSettingsDTO.php` | extends BaseSettingsDTO（PublicKey/PrivateKey/sandbox/allowedPaymentMethods/allowInstallments/expireDays） |
| create | `DTOs/CreatePaymentIntentParams.php`、`RefundParams.php` | request 組裝 |
| create | `Managers/StatusManager.php` | Status/status 映射（Success→金額guard→processing；待繳→payment_info+pending；Failed→pending+note） |
| create | `Shared/Helpers/PaynowMetaKeys.php`、`PaynowTradeNo.php`、`ItemName.php` | meta key 前綴 `_pc_paynow_` |
| create | `Shared/Enums/PaynowPaymentMethod.php`、`PaynowIntentStatus.php`、`PaynowRefundStatus.php` | 付款方式 / 付款意圖狀態 / 退款狀態 enum |
| create | `inc/assets/blocks/paynow.tsx` + BlocksIntegration | block checkout 註冊（Q3） |
| create | 前端內嵌元件 `js/src/external/PaynowPayment/` | 比照 `PayuniUniEmbed/`：載 js.paynow.com.tw/sdk/v2、createPayment/mount/checkout、3DS、order-received 掛載 |
| create | `js/src/pages/Payments/Paynow/index.vue` + router + ROUTER_MAPPER | Vue 設定頁 |
| register | `Payment\ProviderRegister::$gateway_services` | 註冊 `Paynow\Services\PaynowGateway::ID => ::class` |

## GAP / 風險登記

| 項目 | 狀態 | 處理 |
|------|------|------|
| sandbox 憑證（PublicKey/PrivateKey） | **GAP（Q5=無）** | 用戶尚未申請；以 API_MODE=mock 跑綠為驗收主軸。**需來信 PayNow 申請 PrivateKey**（信件主旨「申請 PayNow 串接私鑰 (PrivateKey)」），憑證到位後補 sandbox 端到端驗證 |
| sandbox 端到端驗證 | **GAP（Q5=無）** | 憑證到位後執行 `sandboxapi.paynow.com.tw` + `js.paynow.com.tw` env='sandbox' 端到端；目前以 mock 為準 |
| prod 憑證 | GAP | prod 後補，存 `woocommerce_paynow_settings` 不寫死 |
| PayNow 電子發票（體系3） | **GAP（Q2=排除，後續實作）** | 本次金流先行；後續另開 `Domains/Invoice/Paynow`（體系3 Bearer JWT，與 ezpay/ecpay invoice 對照），endpoint `/api/invoices/issue|cancel|allowance` |
| CSP 配置 | 須注意 | script-src + frame-src 須允許 `js.paynow.com.tw`（測試環境 SDK env 切換，非換 URL） |
| 後端收單 checkout 端點需 PayNow 開通 | 不採用 | 本 Plan 走 SDK iframe（不走 `/checkout` 後端收單），免開通 |
| ApplePayDeferred | 排除 | 不可與其他付款方式併用（Q1 裁決排除） |
| 超商/LINE Pay/ApplePay 退款能力 | 邊界 BDY | 信用卡/ATM 走 REST refunds；超商代碼/LINE Pay/ApplePay 是否支援 API 退款依 PayNow 規格，實作階段以 paynow skill error-codes + refund 段確認，不支援者 `WP_Error('refund_unsupported')` 人工退款 |
| Example 具體資料 | 待補 | Phase 03 以 paynow skill + sandbox 驗證補充（NotifyURL payload / vAccount / 繳費代碼具體值待 sandbox） |
</content>
