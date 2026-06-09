# Execution Plan — PAYUNi UNi Embed V3（內嵌式信用卡，gateway `payuni_uni_embed`）

> Phase 01 Discovery 產出。後續 Phase 02-08 以此為 scope 依據。
> 硬約束：新 gateway 必須 `implements IPaymentProvider`（透過 extends `AbstractPaymentGateway`）。
> 加密複用 `Domains/Payment/Payuni/Shared/Helpers/PayuniCrypto`（不開第 3 份副本）。
> 憑證 GAP：sandbox 先行（V3 官方公開測試金鑰），prod 憑證後補，存 `woocommerce_payuni_uni_embed_settings` 不寫死。

## 概覽

| 類型 | 數量 |
|------|------|
| Create | activity ×1、feature ×6、ui ×1 |
| Modify | actor `PAYUNi.md` ×1（補 UNi Embed 段） |
| Delete | 0 |

## 與既有實作的複用對照

| 複用對象 | 來源 | 用途 |
|---------|------|------|
| 加密 | `Payuni/Shared/Helpers/PayuniCrypto`（AES-256-GCM + SHA256） | `use` 同一 class，不複製 |
| 內嵌式骨架 | `Ecpg`（token → 前端 SDK → create-payment REST → 3DS → callback） | 架構對照 |
| 統一介面 | `Payment/Shared/Interfaces/IPaymentProvider` + `AbstractPaymentGateway` | gateway extends |
| 後台交易 client | `Payuni/Http/DoActionClient`、`QueryTradeClient`（close/cancel/query 共用 PAYUNi 端點） | 信用卡退款/請款/取消授權/查詢補單 |
| MetaKeys / TradeNo / Enums | `Payuni/Shared/Helpers/*`、`Payuni/Shared/Enums/*` | 對照新增 UNi 專屬版本（meta key 前綴 `_pc_payuni_uni_`） |

## Phase 02: Entity Modeling（erm.dbml — Phase 02 reconciler 處理）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | Table `wc_order_meta_payuni_uni_payment` | UNi Embed 專屬 order meta（與既有 `_pc_payuni_*` UPP meta key 區隔，前綴 `_pc_payuni_uni_`） |
| create（欄位） | `_pc_payuni_uni_trade_no` | MerTradeNo 冪等鍵（merchant_trade 階段送；NotifyURL 反查主鍵）。⚠️ V3：token_get 階段不送訂單，MerTradeNo 在 merchant_trade 才產生 |
| create（欄位） | `_pc_payuni_uni_sdk_token` | token_get 取得的 SDK_TOKEN（10 分鐘有效，供前端 SDK + merchant_trade 共用） |
| create（欄位） | `_pc_payuni_uni_payment_detail` | merchant_trade / NotifyURL 解密後授權結果（含 TradeNo / Gateway=9 / PaymentType=1 / AuthCode 等） |
| create（欄位） | `_pc_payuni_uni_capture_status` | 信用卡請款/取消授權狀態（''｜'captured'｜'voided'） |
| create（欄位） | `_pc_payuni_uni_credit_hash` | 買方 Token Hash（CreditHash，授權成功才壓碼）。⚠️ 僅存 hash，絕不存卡號/CVC |
| create（欄位） | `_pc_payuni_uni_credit_life` | 買方 Token 有效日期（CreditLife，MMYY 格式） |
| create | Enum 擴充（如需要） | payment_method / trade_status 值域（DataSource、TradeStatus 1/2/3/8）— 由 reconciler 從 feature datatable + activity BRANCH 萃取 |

## Phase 03: BDD Analysis（features — 本 Phase 01 產出骨架，Phase 03 補 Example + 句型分析）

| 操作 | 目標 | 類型 | 外部觸發動作 |
|------|------|------|-------------|
| create | `payment/payuni-uni-embed-checkout.feature` | @command | 顧客下單 → 後端 token_get 取 SDK_TOKEN（V3：token_get 不送訂單資料，只 MerID+Timestamp+IFrameDomain） |
| create | `payment/payuni-uni-embed-create-payment.feature` | @command | 前端 SDK 綁卡後 POST 回後端（order_key auth）→ merchant_trade 幕後授權（含 API3D 強制 3D 分流） |
| create | `payment/payuni-uni-embed-callback.feature` | @command | PAYUNi NotifyURL 幕後通知付款結果（3D 完成後 Form POST；HashInfo 驗證 + 冪等 + 金額防竄改） |
| create | `payment/payuni-uni-embed-refund.feature` | @command | 管理員退款（信用卡 /api/trade/close，CloseType=2） |
| create | `payment/payuni-uni-embed-trade-management.feature` | @command | 管理員查詢補單(/api/trade/query) + 請款(close CloseType=1) + 取消授權(/api/trade/cancel) |
| create | `payment/payuni-uni-embed-token-management.feature` | @command/@query | 買方 Token 生命週期：綁卡建立(UseTokenType)、token 扣款、token 查詢、token 取消、失效/過期處理 |

## Phase 04: API Contract（api.yml — Phase 04 reconciler 處理）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `POST /payuni/uni-embed/create-payment` | 前端 SDK 綁卡後 POST PayToken/SDK_TOKEN（order_key auth，比照 ECPG `ecpg/create-payment`）→ 後端 merchant_trade |
| create | `POST /payuni/uni-embed/notify` | NotifyURL（AES-256-GCM + HashInfo 驗證；permission `__return_true`，驗證在 callback 內；always HTTP 200） |
| create | REST `/refund`（沿用既有 power-checkout/v1 /refund） | 信用卡退款（gateway process_refund 分流） |
| create | admin order action（query/capture/cancel_auth） | 後台訂單頁手動操作（比照既有 payuni_upp pc_payuni_* actions） |
| create | 買方 Token 查詢/取消 endpoint | 依 feature token-management 句型推導（與 UPP credit_bind 體系對照） |

## Phase 05-08: Implementation（TDD）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Domains/Payment/PayuniUniEmbed/` domain folder | 比照 `Ecpg/` + `Payuni/` 結構 |
| create | `Services/PayuniUniEmbedGateway.php` | extends `AbstractPaymentGateway implements IPaymentProvider`；const ID='payuni_uni_embed'；before_process_payment(token_get)、before_order_received(SDK 設定)、process_refund、query_trade、capture、void_auth、get_supported_payment_methods |
| create | `Http/TokenGetClient.php`、`Http/MerchantTradeClient.php` | iframe/token_get（Version 3.0）、iframe/merchant_trade（請求 1.0 / 回傳 1.2） |
| create | `Http/PayuniUniEmbedFrontendApi.php` | 前端 create-payment REST（order_key auth，比照 EcpgFrontendApi） |
| create | `Http/PayuniUniEmbedCallback.php` | NotifyURL（AES-256-GCM 解密 + HashInfo + 冪等 + 金額防竄改 + always 200） |
| reuse | `Http/DoActionClient.php`、`Http/QueryTradeClient.php` | 從 `Payuni/` 複用 close/cancel/query（信用卡） |
| create | `DTOs/`、`Managers/StatusManager.php`、`Shared/Helpers/`（MetaKeys/TradeNo/ItemName）、`Shared/Enums/` | 比照 Payuni；MetaKeys 前綴 `_pc_payuni_uni_` |
| reuse | `Payuni/Shared/Helpers/PayuniCrypto` | `use` 同一 class |
| create | `inc/assets/blocks/payuni_uni_embed.tsx` | block checkout 註冊 |
| create | 前端內嵌元件（比照 `js/src/external/EcpgPayment/`） | 載入 uni-payment.js SDK、createSession/start/onUpdate/getTradeResult、3DS 導頁、order-received 頁掛載 |
| create | `js/src/pages/Payments/PayuniUniEmbed/index.vue` + router | Vue 設定頁 + ROUTER_MAPPER |
| register | `Payment\ProviderRegister::$gateway_services` | 註冊 gateway |

## GAP / 風險登記

| 項目 | 狀態 | 處理 |
|------|------|------|
| prod 憑證（MerID/HashKey/HashIV） | GAP | sandbox 先行；prod 後補，存 `woocommerce_payuni_uni_embed_settings` |
| sandbox 端到端實測 | 待做 | V3 官方測試卡（4147631000000001 等）；後台限定 IP 須事先設定（TOKEN03005/03006） |
| CSP 配置 | 須注意 | script-src + frame-src 須允許 `vendor.payuni.com.tw`（+ sandbox-vendor） |
| 買方 Token 安全 | 硬約束 | 只存 `credit_hash`/`credit_life`，絕不存卡號/CVC；token-management feature 含失效/過期處理 |
| Example 具體資料 | 待補 | Phase 03 以 payuni-uni-embed-v3 skill + sandbox 驗證補充 |
| 續期扣款 | 邊界 | UNi Embed 本身僅負責首次綁卡；後續續期收款走 UPP `/api/credit` 體系（共用，本 feature 引用不重新實作） |
