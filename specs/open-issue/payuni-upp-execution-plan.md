# Execution Plan — PAYUNi UPP V2 金流整合 + Payment 領域統一介面 IPaymentProvider（決策定案版）

> Phase 01 Discovery 產出。後續 Phase 02-08 的 scope 依據。
> 起始狀態：**existing**（既有 4 支金流 specs + code：SLP / ECPay AIO / ECPay ECPG / NewebPay MPG）。
> ✅ 全部 scope 決策已拍板（Q1-Q6）。本版為定案 scope。
> 技術 API reference：`payuni-upp-v2` skill（實作階段載入；訪談階段不深挖加密細節）。

## 決策定案摘要

| # | 決策 | 定案 |
|---|------|------|
| Q1 | 統一接口範圍 | **B：抽出豐富 `IPaymentProvider`**（第一性原理推論——Payment 領域應對齊 Logistics `ILogisticsProvider` / Invoice `IInvoiceService` 的顯式 provider 抽象哲學，不該是唯一只有單方法 `IGateway` 的例外）。既有 4 支金流全部 refactor 改 implements。**回歸風險高，須測試保護 + 漸進遷移**（見下方專章） |
| Q2 | 付款方式範圍 | **完整**：信用卡（一次付清 + 分期 InstFlag）、ATM 虛擬帳號、超商代碼 CVS、行動支付（LINE Pay / 街口 / Apple Pay / Google Pay） |
| Q3 | 後台交易管理 | **全部**：信用卡 API 退款（`/api/trade/close`）+ 交易查詢補單（`/api/trade/query`）+ 取消授權（`/api/trade/cancel`）。非信用卡退款依 PAYUNi 規格（多須後台人工，實作以 skill 確認） |
| Q4 | Block checkout | **要**：首期就做 `inc/assets/blocks/payuni_upp.tsx` |
| Q5 | 環境 | **Sandbox 先行**，prod 切換用後台設定 `mode` 開關（與既有金流一致） |
| Q6 | 憑證 | **GAP**：使用者尚未提供正式 MerID/HashKey/HashIV。實作用 PAYUNi sandbox 測試帳號驗證，prod 憑證後補。憑證存 `woocommerce_payuni_upp_settings`，**勿寫死於 code** |

---

## 統一介面 `IPaymentProvider` 抽象設計（Q1=B 的核心交付物）

### 第一性原理推理 trace

從基本事實出發：power-checkout 有 Payment / Logistics / Invoice 三個 provider 領域，後兩者都有顯式豐富 interface（`ILogisticsProvider` 10 方法 / `IInvoiceService`），唯獨 Payment 只有極簡 `IGateway`（單方法）+ 空 marker `IGatewaySettings`。此不一致並非金流 provider 的本質差異造成（金流同樣有 process / refund / query / capture / void / get_settings 等共同行為，可抽象），而是歷史演進偶然。因此正本清源抽出 `IPaymentProvider` 並讓既有金流對齊，最符合第一性原理。

### 介面方法（最大公約數 + 能力差異以回傳值表達）

| 方法 | 語義 | 既有實作來源 | 能力差異處理 |
|------|------|------------|------------|
| `process_payment(order_id): array` | 處理付款（既有 IGateway 已有） | 4 支皆有（AbstractPaymentGateway::process_payment final） | 全支援 |
| `process_refund(order_id, amount, reason): bool\|WP_Error` | API 退款前置檢查 | SLP / AIO / ECPG / MPG 皆有 | 不支援者回 `WP_Error('refund_unsupported')` |
| `query_trade(order): array` | 交易查詢 / 對帳補單 | MPG 有 QueryTradeInfo；AIO 有 QueryTradeClient | 不支援者回空 / throw |
| `capture(order): void` | 請款（關帳） | MPG 有（信用卡 Close CloseType=1） | 不支援者 no-op + order note |
| `void_auth(order): void` | 取消授權 | MPG 有（Cancel） | 不支援者 no-op + order note |
| `get_settings(with_default): array` (static) | 設定（既有 abstract 已有） | 4 支皆有 | 全支援 |
| `get_supported_payment_methods(): array` | 回傳啟用的付款方式清單 | 各 gateway 設定 allowed_payments | 全支援 |

> ⚠️ 介面方法的「精確簽章與是否全數納入」屬於 reconciler/planner 規劃細節，本 Execution Plan 列出 desired 方向。能力差異一律以「回傳 unsupported / WP_Error / no-op + order note」表達，**不強迫每支金流都有實質實作**。

### 遷移策略（降低回歸風險）

1. 定義 `IPaymentProvider`（取代或擴充既有 `IGateway`）。
2. `AbstractPaymentGateway implements IPaymentProvider`，並為非共通方法提供安全預設實作（capture/void_auth no-op、query_trade 空、process_refund return false）。
3. 既有 4 支金流逐支驗證：把各自已有的 refund/query/capture/void 對齊到介面方法簽章；**行為等價重構，不改對外行為**。
4. 最後新增 PAYUNi `payuni_upp` implements。
5. `IGatewaySettings`（空 marker）一併檢討：是否升級為有意義契約或移除。

---

## 概覽

| 類型 | 數量 |
|------|------|
| Create | Activity 1（PAYUNi UPP 導轉付款流程）+ 付款 Feature 5 + Actor 1（PAYUNi）+ UI 1（PAYUNi 付款頁面） |
| Modify | erm.dbml（PAYUNi meta + enum）+ api.yml（PAYUNi callback endpoints + gateway 設定）+ Actor 既有金流抽象描述（可選） |
| Refactor | `IPaymentProvider` 抽出 + 既有 4 支金流改 implements（**最高回歸風險項**） |
| Delete | 無 |

---

## Phase 02: Entity Modeling（erm.dbml）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | order meta keys | 新增 PAYUNi 專用 meta：`_pc_payuni_trade_no`（MerTradeNo 冪等鍵）、`_pc_payuni_payment_detail`（PAYUNi 回傳付款明細）、`_pc_payuni_payment_info`（ATM/CVS 取號資訊：虛擬帳號/超商代碼/繳費期限）、`_pc_payuni_capture_status`（請款/取消授權狀態） |
| modify | enum | 新增 `payuni_payment_method`（Credit / CreditInst / ATM / CVS / LINEPAY / JKOPAY / APPLEPAY / GOOGLEPAY），值域以 payuni-upp-v2 skill 為準 |

## Phase 03: BDD Analysis（features Examples）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | payment/payuni-upp-checkout | UPP 建單 + EncryptInfo(AES-256-GCM) + HashInfo(SHA256) + auto-form 跳轉 UNiPaypage |
| create | payment/payuni-upp-callback | NotifyURL 幕後通知（HashInfo 驗證 + 冪等 + 成功回應） |
| create | payment/payuni-upp-payment-info | 取號通知（ATM/CVS 虛擬帳號/超商代碼）— 視 PAYUNi 是否獨立通知或併於 callback，實作以 skill 確認 |
| create | payment/payuni-refund | 信用卡 trade/close API 退款；非信用卡標人工 |
| create | payment/payuni-trade-management | 交易查詢補單（trade/query）+ 取消授權（trade/cancel）後台訂單操作 |

## Phase 04: API Contract（api.yml）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | POST /power-checkout/payuni/upp/notify | NotifyURL 幕後通知（Form POST，HashInfo 驗證，回應成功） |
| create | POST /power-checkout/payuni/upp/return | ReturnURL 前景導回（可選，視實作） |
| modify | gateway 設定 schema | 既有 settings endpoint 的 gateway provider 值域擴充 `payuni_upp` |

## Phase 05-08: Implementation

### Refactor — 統一介面（最高回歸風險，須測試保護）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Payment/Shared/Interfaces/IPaymentProvider.php` | 豐富 payment provider 介面（process_payment / process_refund / query_trade / capture / void_auth / get_settings / get_supported_payment_methods） |
| modify | `Payment/Shared/Abstracts/AbstractPaymentGateway.php` | implements IPaymentProvider；為非共通方法提供安全預設實作 |
| refactor | `ShoplinePayment/Services/RedirectGateway.php` | 對齊 IPaymentProvider 簽章（行為等價，refund 既有走 SLP API） |
| refactor | `EcpayAIO/Services/AioRedirectGateway.php` | 對齊 IPaymentProvider（query/refund 既有，capture/void no-op） |
| refactor | `Ecpg/Services/EcpgGateway.php` | 對齊 IPaymentProvider（信用卡退款既有） |
| refactor | `NewebpayMpg/Services/MpgRedirectGateway.php` | 對齊 IPaymentProvider（已 implements IGateway；query/refund/capture/void 全有，最接近完整實作的範本） |
| review | `Payment/Shared/Interfaces/IGateway.php` + `IGatewaySettings.php` | 檢討：IGateway 升級/併入 IPaymentProvider；空 marker IGatewaySettings 升級或移除 |
| test | `tests/Integration/` 既有金流測試 | refactor 前後皆須綠燈（行為等價驗證） |

### 新增 — Payment（PAYUNi gateway，範本 = NewebpayMpg）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Payment/Payuni/Services/PayuniUppGateway.php` | extends AbstractPaymentGateway implements IPaymentProvider，`const ID='payuni_upp'`；override before_order_received（auto-form submit /api/upp）、process_refund、query_trade、capture、void_auth、get_settings、process_admin_options、init、register_checkout_blocks。仿 MpgRedirectGateway |
| create | `Payment/Payuni/Shared/Helpers/{Crypto}.php` | AES-256-GCM + SHA256 HashInfo（payuni-upp-v2 skill；與藍新 CBC 不同，不可複用） |
| create | `Payment/Payuni/Shared/Helpers/{TradeNo,ItemName,UrlEncoder}.php` | 仿 NewebpayMpg Helpers |
| create | `Payment/Payuni/DTOs/PayuniSettingsDTO.php` | extends BaseSettingsDTO implements IGatewaySettings（或新契約）；欄位：merchant_id/hash_key/hash_iv/mode/allowed_payments/installment_periods/min_amount/max_amount/expire_min。**憑證存 WC option，不寫死** |
| create | `Payment/Payuni/DTOs/PayuniRequestParams.php` | UPP 建單參數（EncryptInfo + HashInfo + to_form_params） |
| create | `Payment/Payuni/Http/PayuniCallback.php`（extends ApiBase） | NotifyURL REST 端點；HashInfo 驗證 + 冪等 |
| create | `Payment/Payuni/Http/{QueryTradeClient,DoActionClient}.php` | trade/query + trade/close + trade/cancel |
| create | `Payment/Payuni/Managers/StatusManager.php` | PAYUNi 回傳狀態 → WC 訂單狀態（對齊 MPG StatusManager） |
| create | `Payment/Payuni/Shared/Helpers/PayuniMetaKeys.php` | order meta CRUD（HPOS-aware，仿 MpgMetaKeys） |
| modify | `Payment/ProviderRegister.php` | `$gateway_services` 加入 `payuni_upp` |

### 新增 — Frontend

| 操作 | 目標 |
|------|------|
| create | `inc/assets/blocks/payuni_upp.tsx`（block checkout payment method 註冊，仿 newebpay_mpg.tsx） |
| create | `js/src/pages/Payments/Payuni/index.vue` + Shared/{types,enums}.ts |
| modify | `js/src/router/index.ts` ROUTER_MAPPER（加 `/payments/payuni_upp`） |

---

## 待評估 Library

無新第三方 PHP library：
- AES-256-GCM = PHP 內建 `openssl_encrypt('aes-256-gcm', ...)`
- HashInfo SHA256 = PHP 內建 `hash('sha256', ...)`
- 不觸發 lib-skill-creator 流程。API reference 全程使用 `payuni-upp-v2` skill。

## GAP / 殘留待決點（不腦補，交實作/planner）

| 項目 | 狀態 | 處理 |
|------|------|------|
| 正式 MerID / HashKey / HashIV | **GAP（Q6）** | 使用者未提供。實作用 sandbox 測試帳號；prod 憑證後補，存 WC option 不寫死 |
| 非信用卡（ATM/CVS/行動支付）API 退款能力 | 待 skill 確認 | PAYUNi 多數非信用卡須後台人工；以 payuni-upp-v2 skill 規格為準，不支援者回 `refund_unsupported` |
| 取號通知是否獨立 endpoint | 待 skill 確認 | ATM/CVS 取號資訊是併於 NotifyURL 或獨立通知，依 PAYUNi 規格 |
| 分期期數 InstFlag 值域 | 後台設定 | 預設全開，細部依 payuni-upp-v2 skill §信用卡分期 |
| IGateway / IGatewaySettings 去留 | refactor 細節 | 由 planner 決定升級併入或保留，行為等價 |

## 回歸風險登記（Q1=B 的代價）

- **影響面**：既有 4 支金流（shopline_payment_redirect、ecpay_aio、ecpay_ecpg、newebpay_mpg）全部改 implements IPaymentProvider。
- **風險等級**：高（4 支既有金流 callback/refund/payment 行為可能因介面契約變動回歸破壞）。
- **緩解**：
  1. 介面取既有能力最大公約數，差異以回傳值表達，不強迫實作。
  2. 漸進遷移（介面 → 抽象類預設實作 → 逐支驗證 → 最後 PAYUNi）。
  3. `tests/Integration/` 既有金流測試 refactor 前後皆綠燈；視為行為等價重構。
