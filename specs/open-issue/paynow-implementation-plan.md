# 實作計劃：PayNow（立吉富）金流 gateway `paynow`（體系 1 內嵌式）

> Phase 01 Discovery 已由 clarifier 完成（9 份 spec 齊備），本檔為 Phase 02-08 的分階段 TDD 實作計劃。
> 上游依據：`specs/open-issue/paynow-execution-plan.md`（Execution Plan + 5 個澄清裁決 + GAP 登記）。
> 範例藍本：`inc/classes/Domains/Payment/PayuniUniEmbed/`（內嵌式，逐檔對映，**減一支 create-payment FrontendApi**）。
> API 唯一權威：`paynow` skill（`.claude/skills/paynow/`，禁上網）。
> 範圍模式：**HOLD SCOPE**（scope 已由 5 題澄清定案，本計劃專注防彈架構與邊界情況，不再擴張）。

---

## 概述

在 Power Checkout 新增 PayNow 體系 1 內嵌式金流 gateway（`paynow`），結構同構於既有 `PayuniUniEmbed`（iframe 站內付），
但因 PayNow Component SDK v2 `checkout()` 直接完成授權 + 3DS，**後端只需「建立 PaymentIntent → 收 Webhook」**，
故比 UNi Embed **少一支 create-payment REST 端點（FrontendApi）+ 少一個前端送回後端授權的中間步驟**。
支援信用卡 / 信用卡分期 / ATM / 超商代碼 / LINE Pay / Apple Pay（排除 ApplePayDeferred）；
含離線付款（ATM / 超商代碼）待繳分支、退款（信用卡 / ATM 走 REST refunds）、退款查詢、補查付款意圖。
classic + WC Blocks 同步。發票（體系 3）排除列 GAP。

## 需求重述

- **整合形態**：PayNow 體系 1（REST PaymentIntent + Component SDK v2 iframe 站內付）。
- **gateway**：`extends AbstractPaymentGateway`（已 `implements IPaymentProvider` 7 methods），`const ID='paynow'`。
- **付款方式**（Q1）：CreditCard / CreditCardInstallment / ATM / ConvenienceStore(ibon,FamiPort) / LINEPayOnline / LINEPayOffline / ApplePay；排除 ApplePayDeferred。
- **admin 能力**（Q4）：退款 + 退款查詢 + 補查付款意圖；capture/void_auth 維持 `AbstractPaymentGateway` no-op（PayNow 體系 1 無對應端點）。
- **結帳**（Q3）：classic + WC Blocks 同步（block 入口 `paynow.tsx` + BlocksIntegration）。
- **加密 / 驗簽**：自建 `WebhookVerifier`（HMAC-SHA256，key=PrivateKey，對 raw body，strtoupper + hash_equals）；**不複用 PayuniCrypto**（PayNow 體系 1 無對稱加密，不同源）。
- **meta 前綴**：`_pc_paynow_`；冪等鍵 `_pc_paynow_payment_intent_id`（Webhook 反查主鍵，PayNow `pp_xxx`）+ `_pc_paynow_trade_no`（`PCN{order_id}`）。
- **驗收**（Q5）：`API_MODE=mock` 跑綠為主軸；sandbox 端到端 + PrivateKey 申請列 GAP。

## 已知風險（來自研究 — paynow skill + 既有實作）

- **風險：Webhook 驗簽對象必須是 raw body** — PayNow `X-Payment-Center-Hmac-Sha256 = strtoupper(hash_hmac('sha256', rawBody, PrivateKey))`。
  若先 `json_decode` 再 `re-encode` 會因 key 順序 / 空白 / unicode escape 改變而驗簽永遠失敗。
  — 緩解：callback 一律 `$request->get_body()` 取原始字串驗簽，**驗簽通過後才** decode 取欄位（php-examples §2 已示範）。
- **風險：PayNow 三套體系混用** — `api.paynow.com.tw`（Bearer）vs 舊版 `www.paynow.com.tw`（PassCode）vs 發票 `invoiceapi-*`。
  — 緩解：本 gateway 只用體系 1（`PaynowRestClient` 已寫死 `api.paynow.com.tw` / `sandboxapi.paynow.com.tw`），不引入舊版 / 發票端點。
- **風險：反查主鍵歧義** — Webhook payload 同時帶 `OrderNo` / `PaymentNo`（= 我們的 `PCN{order_id}`）與 `PaymentIntentId`（`pp_xxx`）。
  spec 明定以 `PaymentIntentId`（`_pc_paynow_payment_intent_id`）反查（activity STEP:3 / paynow-callback.feature 規則）。
  — 緩解：`PaynowMetaKeys::get_order_by_payment_intent_id()` 為唯一反查入口；`_pc_paynow_trade_no` 僅作冪等 / 對帳輔助，**不**作 Webhook 主鍵。
- **風險：金額竄改** — Webhook `Amount` 須比對本地訂單應收。
  — 緩解：StatusManager `is_amount_matched()` 比照 UNi Embed（`ctype_digit` 格式守衛 + `ceil` 整數比對 + `<= 0` 拒絕）。
- **風險：離線付款（ATM / 超商）狀態流** — 先回「待繳資訊」(pending) → 顧客繳費後 PayNow 再推 `Status=Success`(processing)。
  UNi Embed 範本**沒有**離線分支（信用卡 only），需新增 `_pc_paynow_payment_info` 寫入 + 待繳維持 pending 邏輯。
  — 緩解：StatusManager 依 `PaymentType` + Webhook 階段分流；具體 vAccount / 繳費代碼欄位名待 sandbox（GAP，以 mock payload 驗證寫 meta 邏輯）。
- **風險：sandbox 憑證未到位（Q5 GAP）** — PublicKey/PrivateKey 使用者尚未申請。
  — 緩解：所有 HTTP client 走 `wp_remote_*`，測試以 filter mock 回應攔截（比照 UNi Embed `FILTER_MOCK_RESPONSE` 慣例）；`API_MODE=mock` 不打真實 API。
- **風險：CSP** — `js.paynow.com.tw` 須在 script-src + frame-src 白名單。
  — 緩解：前端 SDK 固定由 CDN 載入（`loadScript`），不下載託管；CSP 配置列為部署 checklist（非程式碼）。
- **未發現額外已知風險**（PayNow 體系 1 為標準 REST + iframe，無舊版握手 / 對稱加密的雷區）。

## 架構變更

### 新增（PHP — `inc/classes/Domains/Payment/Paynow/`）

| 檔案 | 對應 UNi Embed 範本 | 差異 |
|------|--------------------|------|
| `Services/PaynowGateway.php` | `PayuniUniEmbedGateway.php` | before_process_payment 改呼叫 `create_payment_intent`；**不註冊 FrontendApi**；refund/query_trade 改 REST refunds / GET payment-intents |
| `Http/PaynowRestClient.php` | `TokenGetClient` + `MerchantTradeClient` + `UniDoActionClient` + `UniQueryTradeClient` **四合一** | Bearer PrivateKey；create/retrieve payment-intent + refund/retrieve refund（php-examples §1 turnkey） |
| `Http/PaynowCallback.php` | `PayuniUniEmbedCallback.php` | 驗簽改 HMAC-SHA256（WebhookVerifier，對 raw body）；反查改 PaymentIntentId |
| `Shared/Helpers/WebhookVerifier.php` | （UNi Embed 用 PayuniCrypto，PayNow **自建**） | HMAC-SHA256；php-examples §2 turnkey |
| `Shared/Helpers/PaynowMetaKeys.php` | `PayuniUniEmbedMetaKeys.php` | meta 前綴 `_pc_paynow_`；反查改 `get_order_by_payment_intent_id` |
| `Shared/Helpers/PaynowTradeNo.php` | `PayuniUniEmbedTradeNo.php` | `PCN{order_id}` |
| `Shared/Helpers/ItemName.php` | `PayuniUniEmbed/Shared/Helpers/ItemName.php` | 商品名組裝（description ≤255） |
| `Managers/StatusManager.php` | `PayuniUniEmbed/Managers/StatusManager.php` | 新增離線付款（payment_info + pending）分支；Status=Success/Failed；無 Gateway=9 守衛（改 PaymentIntentId 已反查保證） |
| `DTOs/PaynowSettingsDTO.php` | `PayuniUniEmbedSettingsDTO.php` | 欄位：public_key / private_key / mode / allowed_payment_methods / allow_installments / expire_days / iframe(無) |
| `DTOs/CreatePaymentIntentParams.php` | `PayuniRequestParams`（概念對應） | 組 amount/currency/allowedPaymentMethods/allowInstallments/webhookUrl/resultUrl/expireDays |
| `DTOs/RefundParams.php` | （新增） | 組 amount/reason/[bankCode/bankBranchCode/bankAccount] |
| `Shared/Enums/PaynowPaymentMethod.php` | `PayuniUniEmbedPaymentMethod.php` | 7 值（排除 ApplePayDeferred） |
| `Shared/Enums/PaynowIntentStatus.php` | `PayuniUniEmbedTradeStatus.php` | draft/processing/pending_review/success/canceled |
| `Shared/Enums/PaynowRefundStatus.php` | （新增） | success/failed/rejected/processing/validation_error |

> ⚠️ **減一支**：**不**建立 `Http/PaynowFrontendApi.php`（對應 UNi Embed 的 `PayuniUniEmbedFrontendApi`）。
> PayNow SDK `checkout()` 直接授權，無「前端送回後端 merchant_trade」中間步驟。

### 修改（PHP）

| 檔案 | 變更 |
|------|------|
| `inc/classes/Domains/Payment/ProviderRegister.php` | `$gateway_services` 加入 `Paynow\Services\PaynowGateway::ID => ::class`（line 17-24） |
| `CLAUDE.md` | 新增 PayNow Payment Flow 段 + Order Meta Keys 表 6 個 `_pc_paynow_*` + REST API 表 `/paynow/notify` + Key Hooks（doc-sync，Phase 08 收尾） |

### 新增（前端）

| 檔案 | 對應範本 |
|------|---------|
| `js/src/external/PaynowPayment/index.ts` | `js/src/external/PayuniUniEmbed/index.ts`（改寫：PayNow.createPayment/mount/checkout，**無 create-payment POST**，checkout 成功直接導 order-received，結果以 Webhook 為準） |
| `js/src/external/PaynowPayment/loadScript.ts` | `js/src/external/PayuniUniEmbed/loadScript.ts`（SDK URL `https://js.paynow.com.tw/sdk/v2/index.js`） |
| `js/src/external/PaynowPayment/types.ts` | `js/src/external/PayuniUniEmbed/types.ts`（IPaynowData：public_key/secret/env/order_received_url/container_id） |
| `inc/assets/blocks/paynow.tsx` | `inc/assets/blocks/payuni_uni_embed.tsx` |
| `js/src/pages/Payments/Paynow/index.vue` + `Shared/types.ts` + `Shared/enums.ts` | `js/src/pages/Payments/PayuniUniEmbed/{index.vue,Shared/*}` |

### 修改（前端 wiring）

| 檔案 | 變更 |
|------|------|
| `js/src/index.ts` | import + 呼叫 `MountPaynowPayment()`（line 7 / 60 對映） |
| `js/src/utils/env.ts` | export `PAYNOW_DATA = window?.power_checkout_paynow_data`（line 32 對映） |
| `js/src/types/global.d.ts` | `power_checkout_paynow_data?: IPaynowData`（line 73 對映） |
| `js/src/router/index.ts` | `ROUTER_MAPPER.paynow` + route `/payments/paynow`（line 4-10 / 43-44 對映） |

---

## 資料流分析

### 流程 1：結帳建立付款意圖（before_process_payment → create_payment_intent）

```
顧客下單 ──▶ before_process_payment ──▶ 金額/幣別守衛 ──▶ create_payment_intent ──▶ 寫 meta ──▶ 回 order-received URL
   │              │                          │                    │                     │
   ▼              ▼                          ▼                    ▼                     ▼
[order nil?]  [冪等:已有 intent_id?]   [currency≠TWD?]      [API 非 success?]      [secret 空?]
              └─ 有→直接回 URL不重建    └─ 拒絕+提示TWD      └─ throw→pending+note   └─ throw
```

- nil path：`wc_get_order` 回非 `WC_Order` → 父類 `process_payment` 已守（throw → failure）。
- 冪等：已有 `_pc_paynow_payment_intent_id` → 不重複 `create_payment_intent`（比照 UNi Embed SDK_TOKEN 冪等）。
- empty path：`allowedPaymentMethods` 不可空、不可含 `ApplePayDeferred`（DTO validate）。
- error path：API 回非 success → throw → 父類 catch → order note + 維持 pending（**不**轉狀態、**不**寫 meta）。

### 流程 2：前端 SDK 收單（order-received 頁，無後端中間步驟）

```
before_order_received ──▶ localize(public_key/secret/env) ──▶ MountPaynowPayment ──▶ SDK checkout()
   │                          │                                   │                      │
   ▼                          ▼                                   ▼                      ▼
[intent_id 空?→不渲染]    [secret 空?→不渲染]              [SDK 載入失敗?→提示]    [response.error?→提示不導頁]
                                                                                  [成功→導 order-received，結果以 Webhook 為準]
```

- ⚠️ 與 UNi Embed 差異：**無** `POST create-payment`；`checkout()` 直接與 PayNow 完成授權 + 3DS。前端成功僅代表流程完成，**付款結果一律以後端 Webhook 為準**。

### 流程 3：Webhook payment_result（source of truth，always HTTP 200）

```
PayNow POST raw body ──▶ 取 raw + sig header ──▶ HMAC-SHA256 驗簽 ──▶ decode ──▶ 反查訂單 ──▶ 金額防竄改 ──▶ 冪等 ──▶ StatusManager ──▶ 200
   │                        │                       │                  │            │             │              │           │
   ▼                        ▼                       ▼                  ▼            ▼             ▼              ▼           ▼
[空 body?]             [sig header 缺?]        [hash_equals 失敗?]  [非JSON?]   [intent_id 查無?] [Amount≠本地?] [已processing?] [\Throwable?]
└─全部→log+回200       └─log+回200             └─log+回200(不處理)  └─throw→catch回200 └─log+回200    └─pending+告警  └─skip       └─catch回200
```

- **驗簽對象 = raw body**（勿 re-encode）。
- 反查主鍵 = `PaymentIntentId`（`_pc_paynow_payment_intent_id`），**非** TradeNo。
- 所有失敗分支（含 `\Throwable`）一律回 HTTP 200（避免 PayNow 重送風暴）。

### 流程 4：離線付款待繳（ATM / 超商代碼，Webhook 兩階段）

```
Webhook(待繳階段) ──▶ PaymentType=ATM/ConvenienceStore ──▶ 寫 _pc_paynow_payment_info ──▶ 維持 pending
       │                                                          │
       ▼                                                          ▼
   [Status=?]                                              [order-received/後台可見]
顧客繳費 ──▶ Webhook(Status=Success) ──▶ 走流程3 ──▶ 金額防竄改 ──▶ payment_complete ──▶ processing + 寫 payment_detail
```

> ⚠️ GAP：PayNow 體系 1 離線付款繳款資訊（vAccount/繳費代碼/條碼/ExpireDate）是 Webhook 攜帶 vs SDK 顯示，待 sandbox 確認。mock 階段以「假設 Webhook 攜帶待繳欄位」驗證寫 meta + 維持 pending 邏輯。

### 流程 5：退款（admin → process_refund → REST refunds）

```
admin 觸發退款 ──▶ process_refund ──▶ 金額/付款方式守衛 ──▶ refund(REST) ──▶ 寫 _pc_paynow_refund_detail + note
   │                  │                    │                   │                 │
   ▼                  ▼                    ▼                   ▼                 ▼
[order nil?→false] [金額≤0/超額?→false] [非信用卡/ATM?→WP_Error] [ATM缺bank?→拒絕] [rejected/failed?→note不標退款]
                                          refund_unsupported    [processing?→note待查]
```

- 信用卡 / ATM → REST `POST /payment-intents/:id/refunds`（ATM 必填 bankCode/bankBranchCode/bankAccount）。
- 超商代碼 / LINE Pay / ApplePay → `WP_Error('refund_unsupported')`（依 PayNow 規格實作階段以 error-codes + refund 段確認，不支援者人工退款）。
- 退款狀態：success → 寫 refund_detail + note；rejected（含 RejectReason）/ failed → note 不標退款；processing → note 待退款查詢。

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|------------|----------|---------|------------|
| `before_process_payment` | 訂單不存在 | `\Exception` | 父類 catch → log + 通用 notice | 是（通用訊息） |
| `before_process_payment` | 幣別非 TWD | 業務拒絕 | throw → 父類 catch → notice「僅支援 TWD」 | 是 |
| `before_process_payment` | create_payment_intent API 非 success | `\RuntimeException` | throw → 父類 catch → order note + 維持 pending | 是（通用訊息） |
| `PaynowRestClient::request` | wp_error / 非 JSON / type≠success | `\RuntimeException` | 由呼叫端 catch（gateway / callback / admin action） | 否（log + note） |
| `PaynowCallback::notify` | HMAC 驗簽失敗 | 驗簽拒絕 | log warning + **回 200 不處理** | 否 |
| `PaynowCallback::notify` | PaymentIntentId 查無訂單 | 反查失敗 | log warning + 回 200 | 否 |
| `PaynowCallback::notify` | Amount 與本地不符 | 竄改防護 | StatusManager 維持 pending + 告警 note + 回 200 | 後台可見 note |
| `PaynowCallback::notify` | 任何 `\Throwable` | 例外 | catch + log + 回 200 | 否 |
| `process_refund` | 金額 ≤0 / 超過訂單總額 | 邊界 | 回 `false`（不呼叫 API） | WC 顯示退款失敗 |
| `process_refund` | 非信用卡 / ATM | 能力不支援 | 回 `WP_Error('refund_unsupported')` | 是 |
| `handle_payment_gateway_refund` | ATM 退款缺 bank 三欄 | 參數缺漏 | order note 提示必填 + 不發 API | 後台可見 |
| `handle_payment_gateway_refund` | REST refund 失敗 | API 失敗 | wpdb ROLLBACK + note + 刪 refund | 後台可見 |
| `query_trade` / 補查付款意圖 | 連線 / 解析失敗 | `\Throwable` | catch → 回空陣列 + log（IPaymentProvider 契約：不 throw） | 否 |
| admin 補查 / 退款查詢 | API 失敗 | `\Throwable` | catch → log + order note | 後台可見 |

> **CRITICAL GAP 檢查**：上表無「處理方式=無 + 使用者可見=靜默」之列 → 通過。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|---------|---------|------------|----------|
| Webhook 驗簽 | 偽造 / 竄改 sig | 是（hash_equals） | 是（callback test 驗簽失敗場景） | 否（回 200） | 不處理；正確 Webhook 重送或 admin 補查 |
| Webhook 反查 | PaymentIntentId 不存在 | 是 | 是（查無訂單場景） | 否 | admin 補查付款意圖 |
| Webhook 金額 | Amount 竄改 | 是（StatusManager） | 是（金額不符場景） | 後台 note | 維持 pending，人工核對 |
| Webhook 冪等 | PayNow 重送 | 是（已 processing skip） | 是（重複通知場景） | 否 | skip |
| 離線付款 | 待繳階段誤判已付款 | 是（Status 分流） | 是（ATM/超商待繳場景） | order-received 可見待繳 | 繳費後 Webhook 補 Success |
| 退款 | 不支援的付款方式 | 是（WP_Error） | 是（超商不支援場景） | 是 | PayNow 後台人工退款 |
| 退款 | ATM 缺銀行資料 | 是（拒絕送出） | 是（ATM 必填場景） | 後台 | 補填後重試 |
| create_payment_intent | API 失敗 | 是（throw + pending） | 是（建立失敗場景） | 是 | 重新結帳 |
| 前端 SDK | checkout response.error | 是（前端提示不導頁） | 否（前端，sandbox GAP） | 是 | 重填卡片 |
| 補查付款意圖 | Webhook 漏收 | 是（GET payment-intents → 補單） | 是（補查補單場景） | 後台 | admin 點補查 |

---

## 實作步驟（Phase 02-08，TDD 紅綠順序）

> 測試 suite：`tests/Integration/Payment/`（namespace `Tests\Integration\`，base `Tests\Integration\TestCase`）。
> **group 白名單**：每個 test 必須掛 `smoke`/`happy`/`error`/`edge`/`security` 至少一個（否則被跳過）。
> 驗收命令（每 Cycle）：`vendor/bin/phpunit --filter <ClassName>`（`API_MODE=mock`）+ 全綠後 `vendor/bin/phpstan analyse -d memory_limit=2G`。
> ⚠️ 本機 LocalWP 若 WP_UnitTestCase 無法跑，依 MEMORY「wp-env Gate」走 `wp-env run tests-cli`；pure-logic 部分可比照 `tests/offline/`。

### Phase 02 — Entity Modeling（erm.dbml reconciler；無 TDD code）

1. **erm.dbml 增 6 meta 欄位 + enum**（由 `aibdd-form-entity-spec` reconciler 處理，非 planner / tdd）
   - 欄位：`_pc_paynow_trade_no` / `_pc_paynow_payment_intent_id` / `_pc_paynow_secret` / `_pc_paynow_payment_detail` / `_pc_paynow_payment_info` / `_pc_paynow_refund_detail`
   - enum：PaymentType(7) / PaymentIntent status(5) / Webhook Status(2) / Refund status(5)
   - 依賴：無。風險：低。可平行：是（與 Phase 03 平行）。

### Phase 03 — BDD Analysis（features 補 Example + 句型；無 production code）

2. **5 支 feature 補具體 Example**（由 `aibdd-form-feature-spec` / `aibdd-form-bdd-analysis` 處理）
   - 以 paynow skill payment-rest-api §10（Webhook payload）+ §4/§5 + concepts §離線 補 mock 範例值；sandbox 實際值待 GAP。
   - 依賴：Phase 02 enum。風險：低。可平行：與 Phase 02 平行。

### Phase 04 — API Contract（api.yml reconciler；無 TDD code）

3. **api.yml 增 `POST /paynow/notify`**（由 `aibdd-form-api-spec` reconciler 處理）
   - reuse 既有 `POST /refund`；admin order action（query_trade / 退款查詢）為 WC order action 非 REST。
   - **不**建 create-payment 端點（PayNow 體系 1 無）。
   - 依賴：Phase 03。風險：低。

> Phase 02-04 為規格 reconciler 作業，產出 spec 檔，**非** tdd-coordinator 的 Red-Green。tdd-coordinator 從 **Phase 05** 起跑 TDD。

---

### Phase 05 — Cycle 1：Foundation（DTO + Helpers + Enums + 註冊 + Gateway 骨架）

> 此 Cycle 為純邏輯，最適合 mock 驗收。逐檔 1:1 對映 UNi Embed Cycle 0。

4. **`Shared/Enums/PaynowPaymentMethod.php`**（red: `PaynowEnumTest`）
   - 行動：7 值 backed enum（排除 ApplePayDeferred）+ label() + is_offline()（ATM/ConvenienceStore）。
   - 測試：`PaynowEnumTest`（group `smoke`）— 值正確 / is_offline 分類。
   - 依賴：無。風險：低。
5. **`Shared/Enums/PaynowIntentStatus.php` + `PaynowRefundStatus.php`**（同 `PaynowEnumTest`）
   - 行動：intent 5 值（is_success/is_draft）；refund 5 值（is_success/is_rejected/is_processing）。
   - 依賴：無。風險：低。
6. **`Shared/Helpers/PaynowTradeNo.php`**（red: `PaynowTradeNoTest`）
   - 行動：`generate(order_id) => "PCN{order_id}"` + `parse`。比照 `PayuniUniEmbedTradeNo`。
   - 測試：group `smoke`。依賴：無。風險：低。
7. **`Shared/Helpers/PaynowMetaKeys.php`**（red: `PaynowMetaKeysTest`）
   - 行動：6 個 const + getter/updater（HPOS：`$order->get_meta`/`update_meta_data`）+ `get_order_by_payment_intent_id()`（⚠️ 主鍵改 intent_id，非 trade_no）。
   - 測試：group `happy`/`edge`（反查命中 / 查無回 null / 空字串守衛）。依賴：無。風險：低。
8. **`Shared/Helpers/ItemName.php`**（red: `PaynowItemNameTest` 或併入 settings test）
   - 行動：商品名組裝（description ≤255 截斷）。比照 UNi Embed ItemName。依賴：無。風險：低。
9. **`DTOs/PaynowSettingsDTO.php`**（red: `PaynowSettingsDTOTest`）
   - 行動：`extends BaseSettingsDTO`（或 `DTO` + `IGatewaySettings`，依 mode 處理對齊 UNi Embed）；欄位 public_key/private_key/mode/allowed_payment_methods/allow_installments/expire_days；test mode 用 sandbox host；trim_invisible_deep 清憑證。
   - 測試：group `happy`/`security`（憑證不寫死 prod / trim / mode validate）。依賴：Enums。風險：中（mode 與端點切換）。
10. **`DTOs/CreatePaymentIntentParams.php` + `RefundParams.php`**（red: `PaynowParamsTest`）
    - 行動：CreatePaymentIntentParams 組 amount/currency=TWD/allowedPaymentMethods/allowInstallments/webhookUrl/resultUrl/expireDays（守衛：不含 ApplePayDeferred、分期數合法 3/6/9/12/18/24）；RefundParams 組 amount/reason/[bank 三欄]。
    - 測試：group `happy`/`edge`/`security`（ApplePayDeferred 排除 / 分期數白名單 / ATM bank 必填）。依賴：Enums。風險：中。
11. **`Services/PaynowGateway.php` 骨架 + `Managers/StatusManager.php` 骨架**（red: `PaynowGatewayTest` smoke + `PaynowStatusManagerTest` smoke）
    - 行動：gateway `const ID='paynow'` / `extends AbstractPaymentGateway` / `get_settings` / `before_process_payment`（呼叫 create_payment_intent，本 Cycle 可先 mock client）/ `get_supported_payment_methods`（7 值）；StatusManager 骨架（Status=Success/Failed 分流，金額防竄改）。
    - 測試：gateway 可實例化 / ID 正確 / supported methods；StatusManager 金額不符維持 pending。
    - 依賴：DTO/Helpers/Enums。風險：中。
12. **註冊到 `ProviderRegister::$gateway_services`**（red: `PaynowRegisterTest`）
    - 行動：`ProviderRegister.php` line 17-24 加入 `Paynow\Services\PaynowGateway::ID => ::class`。
    - 測試：group `smoke`（gateway 出現在 woocommerce_payment_gateways）。依賴：gateway 骨架。風險：低。

**Cycle 1 驗收**：`vendor/bin/phpunit --filter "Paynow"` 全綠 + phpstan level 9 clean。
**可平行**：步驟 4-10 彼此獨立（Enums / Helpers / DTOs 無互相依賴）→ 可平行紅綠；11-12 依賴前述。

---

### Phase 06 — Cycle 2：RestClient + create_payment_intent 串接（before_process_payment 完整化）

13. **`Http/PaynowRestClient.php`**（red: `PaynowRestClientTest`）
    - 行動：php-examples §1 turnkey — `create_payment_intent` / `retrieve_payment_intent` / `refund` / `retrieve_refund`；Bearer PrivateKey；base_url 依 sandbox；`request()` 統一回應格式守衛（type≠success → throw）。HTTP 以 `wp_remote_request`，測試用 `pre_http_request` filter 或 mock filter 攔截。
    - 測試：group `happy`/`error`（成功解析 result / API 非 success throw / wp_error throw / 非 JSON throw）。
    - 依賴：SettingsDTO。風險：中。
14. **`PaynowGateway::before_process_payment` 接真 client**（red: `PaynowGatewayTest` 擴充 happy/error/edge）
    - 行動：金額守衛 + 幣別 TWD 守衛 + 冪等（已有 intent_id 不重建）+ create_payment_intent → 寫 `_pc_paynow_payment_intent_id` + `_pc_paynow_secret` + `_pc_paynow_trade_no`(PCN) → 回 order-received URL；失敗 throw（pending + note）。
    - 測試對映 paynow-checkout.feature 3 場景：成功建立 / 非 TWD 拒絕 / 建立失敗維持 pending。
    - 依賴：RestClient。風險：中。
15. **`PaynowGateway::before_order_received` localize SDK config**（red: `PaynowGatewayTest` 擴充）
    - 行動：localize public_key/secret/env/order_received_url/container_id 到前端 bundle（比照 UNi Embed `build_sdk_config`，但**無** create_payment_url）。
    - 測試：group `happy`（有 secret 才 localize / 無 secret 不渲染）。依賴：步驟 14。風險：低。

**Cycle 2 驗收**：`vendor/bin/phpunit --filter "PaynowRestClient|PaynowGateway"` 全綠 + phpstan。
**依賴**：Cycle 1。**可平行**：13 與 14 有依賴（14 用 13）；15 依賴 14。

---

### Phase 07 — Cycle 3：WebhookVerifier + Callback + StatusManager 完整化（含離線付款）

16. **`Shared/Helpers/WebhookVerifier.php`**（red: `PaynowWebhookVerifierTest`）
    - 行動：php-examples §2 turnkey — `verify(raw_body, signature) => hash_equals(strtoupper(hash_hmac('sha256', raw, private_key)), strtoupper(sig))`。**對 raw body**，不 re-encode。
    - 測試：group `security`（正確簽章通過 / 竄改 body 失敗 / 竄改 sig 失敗 / 空 sig 失敗 / 大小寫正規化）。
    - 依賴：無（key 由 settings 注入）。風險：中（資安核心）。**可與 Cycle 2 平行**。
17. **`Managers/StatusManager.php` 完整化（含離線付款分支）**（red: `PaynowStatusManagerTest` 擴充 happy/edge/security）
    - 行動：Status=Success → 金額防竄改（ctype_digit + ceil 比對）→ 冪等（已 processing skip）→ 依 PaymentType 分流：
      - 即時付款（信用卡/分期/LINE Pay/ApplePay）→ payment_complete() → processing + 寫 `_pc_paynow_payment_detail`。
      - 離線付款待繳階段（ATM/ConvenienceStore 且尚無 Success）→ 寫 `_pc_paynow_payment_info` + 維持 pending。
      - Status=Failed → 維持 pending + order note。
    - 測試對映 paynow-callback + paynow-payment-info feature：成功轉處理中 / 失敗維持 / 金額不符維持 / ATM 待繳寫 info / 超商待繳。
    - 依賴：MetaKeys/Enums。風險：中（離線分支為範本所無，最需仔細）。
18. **`Http/PaynowCallback.php`**（red: `PaynowCallbackTest` happy/error/security/edge）
    - 行動：`ApiBase` + `SingletonTrait`；namespace `power-checkout/paynow`；endpoint `notify`；`permission_callback __return_true`；
      `post_notify_callback`：取 `$request->get_body()` raw + `X-Payment-Center-Hmac-Sha256` header → WebhookVerifier 驗簽 → decode → 反查 `get_order_by_payment_intent_id` → 冪等 → StatusManager → **一律回 200**；所有失敗分支（含 \Throwable）回 200。
    - 測試對映 paynow-callback feature 5 場景：成功轉處理中 / 失敗維持 / HMAC 失敗回 200 不更新 / 金額不符回 200 / 重複通知冪等。全部斷言回 HTTP 200。
    - 依賴：WebhookVerifier + StatusManager + MetaKeys。風險：中。
19. **`PaynowGateway::init` 註冊 callback + block checkout**（red: `PaynowRegisterTest` 擴充）
    - 行動：`init()` 註冊 `PaynowCallback::register_hooks()`（**不**註冊 FrontendApi）+ `register_checkout_blocks`（`woocommerce_blocks_loaded` → `woocommerce_blocks_payment_method_type_registration` → `new BlocksIntegration($gateway)`，比照 5 個既有 gateway）。
    - 測試：group `smoke`（callback 端點註冊 / block integration 註冊）。依賴：步驟 18。風險：低。

**Cycle 3 驗收**：`vendor/bin/phpunit --filter "PaynowWebhookVerifier|PaynowStatusManager|PaynowCallback|PaynowRegister"` 全綠 + phpstan。
**依賴**：Cycle 1（16 可與 Cycle 2 平行）。**可平行**：16 獨立；17/18/19 有依賴鏈。

---

### Phase 08 — Cycle 4：退款 + admin 交易管理 + 前端 + doc-sync

20. **`PaynowGateway::process_refund` + `handle_payment_gateway_refund`（REST refunds）**（red: `PaynowRefundTest` happy/error/edge/security）
    - 行動：`process_refund` 金額守衛（≤0/超額→false）+ 付款方式分流（信用卡/ATM→true；其餘→WP_Error('refund_unsupported')）；`handle_payment_gateway_refund`（static，覆寫父類）→ wpdb TRANSACTION → RestClient::refund（ATM 帶 bank 三欄）→ 成功寫 `_pc_paynow_refund_detail` + note；失敗 ROLLBACK + note + 刪 refund。判定依 `_pc_paynow_payment_detail` 的 PaymentType（非前端）。
    - 測試對映 paynow-refund feature 4 場景：信用卡全額退款成功 / ATM 缺 bank 拒絕 / 超商不支援 WP_Error / rejected 記原因不標退款。
    - 依賴：RestClient + MetaKeys。風險：中。
21. **`PaynowGateway::query_trade` + admin order actions（補查付款意圖 / 退款查詢）**（red: `PaynowTradeManagementTest` happy/edge）
    - 行動：`query_trade`（override，catch→回陣列不 throw）；`add_order_actions`（`pc_paynow_query_trade` / `pc_paynow_refund_query`）+ handler：補查 GET payment-intents/:id → status=success + 尚未 processing → 走 StatusManager 補單（金額防竄改 + 冪等）；退款查詢 GET refunds/:uuid → 寫回 `_pc_paynow_refund_detail` + note。capture/void_auth **不覆寫**（維持 no-op）。
    - 測試對映 paynow-trade-management feature 3 場景：補查 success 補單 / 補查 draft 不補單 / 退款查詢寫回明細。
    - 依賴：RestClient + StatusManager。風險：中。
22. **前端內嵌元件 `js/src/external/PaynowPayment/`**（前端，sandbox GAP，無 PHPUnit）
    - 行動：`loadScript`（SDK CDN v2）+ `index.ts`（PayNow.createPayment/mount('#paynow-container')/checkout → 成功導 order-received，**無 create-payment POST**；response.error 提示不導頁；離線付款 SDK 顯示繳款資訊）+ `types.ts`。
    - wiring：`index.ts` 呼叫 `MountPaynowPayment()`；`env.ts` export `PAYNOW_DATA`；`global.d.ts` 型別。
    - 驗收：`pnpm lint`（ESLint）+ `pnpm build`（編譯通過）；端到端走 sandbox（GAP）。
    - 依賴：步驟 15（localize）。風險：中（外站 SDK 細節 GAP）。
23. **`inc/assets/blocks/paynow.tsx`**（前端 React WC Blocks）
    - 行動：比照 `payuni_uni_embed.tsx`，`registerPaymentMethod`（getSetting `paynow_data`）。
    - 驗收：`pnpm build:blocks` 編譯通過 → `inc/assets/dist/blocks/paynow.js`。依賴：步驟 19（BlocksIntegration）。風險：低。
24. **Vue 設定頁 `js/src/pages/Payments/Paynow/index.vue` + router**（前端）
    - 行動：比照 `PayuniUniEmbed/index.vue`（PublicKey/PrivateKey/mode/allowedPaymentMethods/allowInstallments/expireDays 欄位）+ `ROUTER_MAPPER.paynow` + route。
    - 驗收：`pnpm build` + 後台設定頁可開（手動）。依賴：無（與後端平行）。風險：低。
25. **doc-sync `CLAUDE.md`**（doc）
    - 行動：新增 PayNow Payment Flow 段、Order Meta Keys 表 6 列、REST API 表 `/paynow/notify`、Key Hooks `woocommerce_payment_gateways` 列補 PayNow、Integrated Services 段補 PayNow。
    - 依賴：全部完成。風險：低（doc-sync-playbook）。

**Cycle 4 驗收**：`vendor/bin/phpunit --filter "PaynowRefund|PaynowTradeManagement"` 全綠 + **全 PayNow 套件** `vendor/bin/phpunit --filter "Paynow"` 全綠 + `vendor/bin/phpstan analyse -d memory_limit=2G` clean + `pnpm lint` + `pnpm build` + `pnpm build:blocks`。
**可平行**：20/21（後端 admin）與 22/23/24（前端）可平行；25 最後收尾。

---

## 平行 / 依賴總表

| Cycle | 可平行內部步驟 | 依賴前序 | 阻擋後序 |
|-------|--------------|---------|---------|
| Phase 02 / 03 | 兩者互相平行 | — | Phase 04 |
| Phase 04 | — | Phase 03 | Phase 05 |
| Cycle 1（05） | Enums / Helpers / DTOs（4-10）平行 | Phase 04 | Cycle 2/3 |
| Cycle 2（06） | RestClient→gateway 串接（鏈式） | Cycle 1 | Cycle 4 退款/補查 |
| Cycle 3（07） | WebhookVerifier(16) **可與 Cycle 2 平行** | Cycle 1 | Cycle 4 |
| Cycle 4（08） | 後端 admin(20-21) ∥ 前端(22-24)；doc(25) 收尾 | Cycle 2 + 3 | — |

---

## 測試策略

- **單元 / 整合測試**（`tests/Integration/Payment/`，11 支對映 UNi Embed 12 支**減 FrontendApi 那支**）：
  `PaynowEnumTest` / `PaynowTradeNoTest` / `PaynowMetaKeysTest` / `PaynowSettingsDTOTest` / `PaynowParamsTest` /
  `PaynowGatewayTest` / `PaynowRestClientTest` / `PaynowWebhookVerifierTest` / `PaynowStatusManagerTest` /
  `PaynowCallbackTest` / `PaynowRefundTest` / `PaynowTradeManagementTest` / `PaynowRegisterTest`。
- **group 標註**：每 test 至少一個 `smoke`/`happy`/`error`/`edge`/`security`（白名單外不執行）。
- **API mock**：HTTP 以 `pre_http_request` filter 或 mock filter 攔截（不打真實 PayNow）。
- **E2E（Playwright）**：order-received 內嵌付款 + Webhook 回打 → **sandbox GAP**，憑證到位後補。
- **測試執行指令**：
  - 單類：`vendor/bin/phpunit --filter PaynowCallbackTest`
  - 單方法：`vendor/bin/phpunit --filter "PaynowCallbackTest::test_hmac_invalid_returns_200"`
  - 全 PayNow：`vendor/bin/phpunit --filter "Paynow"`（`API_MODE=mock`）
  - 靜態分析：`vendor/bin/phpstan analyse -d memory_limit=2G`
  - 前端：`pnpm lint` / `pnpm build` / `pnpm build:blocks`
- **關鍵邊界情況**：
  - Webhook：raw body 驗簽（勿 re-encode）/ 偽造 sig / 金額竄改 / 重送冪等 / \Throwable 回 200。
  - 反查主鍵為 PaymentIntentId（非 TradeNo）。
  - 離線付款待繳維持 pending、繳費後 Success 轉 processing（兩階段）。
  - 退款分流：信用卡/ATM 走 REST、ATM 缺 bank 拒絕、超商/LINE Pay/ApplePay WP_Error。
  - DTO：ApplePayDeferred 排除、分期數白名單。
  - 憑證不寫死 prod、trim invisible。

## 依賴項目

- 既有：`AbstractPaymentGateway` / `IPaymentProvider` / `BlocksIntegration` / `ProviderUtils` / `BaseSettingsDTO` / `ApiBase` + `SingletonTrait`（wp-utils）。
- 外部：PayNow Component SDK v2（CDN `https://js.paynow.com.tw/sdk/v2/index.js`，非 npm）；PayNow REST（`sandboxapi.paynow.com.tw` / `api.paynow.com.tw`）。
- **GAP（阻擋 sandbox 端到端，不阻擋 mock 驗收）**：PublicKey/PrivateKey（需來信 PayNow 申請，信件主旨「申請 PayNow 串接私鑰 (PrivateKey)」）。

## 風險與緩解措施

- **高**：Webhook 驗簽對象（raw body）+ 反查主鍵（PaymentIntentId）+ 金額竄改 — 以 `PaynowWebhookVerifierTest`（security）+ `PaynowStatusManagerTest`（security）+ `PaynowCallbackTest` 全分支覆蓋；驗簽通過才 decode。
- **中**：離線付款兩階段（範本所無）— StatusManager 依 PaymentType + Status 分流，mock payload 驗證寫 `_pc_paynow_payment_info` + 維持 pending；vAccount 欄位名 sandbox 補。
- **中**：退款付款方式分流 — 依 `_pc_paynow_payment_detail` PaymentType（非前端）；不支援者 WP_Error。
- **中**：sandbox 憑證 GAP — mock filter 攔截 HTTP，`API_MODE=mock` 跑綠為驗收主軸。
- **低**：前端 SDK 外站細節（docs.paynow.com.tw/component/）— `pnpm build` 編譯保證，行為驗證走 sandbox（GAP）。
- **低**：CSP — 部署 checklist（`js.paynow.com.tw` script-src + frame-src），非程式碼。

## 錯誤處理策略

統一採專案既有 pattern（`.claude/rules/wordpress-php.rule.md`）：
- gateway 支付：`process_payment`（final）catch `\Throwable` → `logger` + 通用 `wc_add_notice`，不外露內部。
- Webhook callback：所有路徑（含 `\Throwable`）回 HTTP 200，驗簽 / 反查 / 解析失敗 log warning 不處理。
- RestClient：失敗 throw `\RuntimeException`，由呼叫端（gateway/callback/admin action）catch + log + order note。
- `query_trade`：IPaymentProvider 契約 — catch → 回空陣列，**不** throw。
- 退款：wpdb TRANSACTION + ROLLBACK + 刪 refund（比照 UNi Embed）。

## 限制條件（此計劃不做）

- ❌ **不**建立 create-payment FrontendApi（PayNow SDK checkout 直接授權，無此中間步驟）。
- ❌ **不**覆寫 capture / void_auth（PayNow 體系 1 無對應端點，維持 AbstractPaymentGateway no-op）。
- ❌ **不**複用 PayuniCrypto / ECPay AesCrypto（PayNow 體系 1 無對稱加密，自建 WebhookVerifier HMAC-SHA256）。
- ❌ **不**實作 PayNow 電子發票（體系 3，Q2 排除，列 GAP 後續另開 `Domains/Invoice/Paynow`）。
- ❌ **不**支援 ApplePayDeferred（Q1 排除，不可與其他付款方式併用）。
- ❌ **不**走後端收單 `/checkout` 端點（需 PayNow 開通；本 Plan 走 SDK iframe）。
- ❌ **不**實作綁卡 Customer / CardToken 為主線（Webhook Meta.CardToken 可保存供後續，**絕不存卡號 / CVC**）。
- ❌ **不**用體系 2 舊版 CashFlow（GP→GK 握手 / PassCode）。

## 成功標準

- [ ] `vendor/bin/phpunit --filter "Paynow"` 全綠（`API_MODE=mock`）。
- [ ] `vendor/bin/phpstan analyse -d memory_limit=2G` 無新增錯誤（level 9）。
- [ ] `pnpm lint` + `pnpm build` + `pnpm build:blocks` 通過。
- [ ] gateway `paynow` 出現在 classic checkout + WC Blocks checkout 並可選。
- [ ] Webhook `/paynow/notify` 驗簽（HMAC-SHA256, raw body）+ PaymentIntentId 反查 + 金額防竄改 + 冪等 + always 200，全分支覆蓋。
- [ ] 離線付款（ATM/超商）待繳寫 `_pc_paynow_payment_info` + pending；繳費後 Success 轉 processing。
- [ ] 退款（信用卡/ATM REST refunds）+ 不支援付款方式 WP_Error + ATM bank 必填守衛。
- [ ] 補查付款意圖 + 退款查詢 admin order action 可用。
- [ ] meta 全用 `_pc_paynow_` 前綴，與既有金流隔離。
- [ ] `CLAUDE.md` doc-sync 完成（Flow / Meta Keys / REST API / Hooks）。
- [ ] GAP 登記：sandbox PublicKey/PrivateKey 申請 + sandbox 端到端 + 電子發票（體系 3）後續。

## 預估複雜度：中

（13 PHP 檔 + 7 前端檔 + 2 wiring 修改 + 1 register 修改 + doc-sync ≈ 23 檔；structure 1:1 抄 UNi Embed，client/verifier turnkey from php-examples；最大新工為離線付款分支與 HMAC 驗簽資安。檔案數在 HOLD SCOPE 合理範圍，未觸 >15 REDUCTION 門檻之「淨新邏輯」——多數為範本對映。）

---

## Hand-off

→ **`@zenbu-powers:tdd-coordinator`** 執行 Phase 05-08 的 TDD 紅綠循環（Phase 02-04 為 spec reconciler 作業，由對應 aibdd-form-* skill 處理）。

**交接附件**：
- 本計劃：`specs/open-issue/paynow-implementation-plan.md`
- Execution Plan：`specs/open-issue/paynow-execution-plan.md`
- 9 份 spec：`specs/activities/PayNow立吉富內嵌付款流程.activity`、`specs/actors/PayNow.md`、`specs/ui/PayNow內嵌付款元件.md`、`specs/features/payment/paynow-*.feature`（×5）、`specs/clarify/2026-06-09-1630.md`
- API 唯一權威：`.claude/skills/paynow/`（payment-rest-api / php-examples / encryption / error-codes / concepts）
- 範本藍本：`inc/classes/Domains/Payment/PayuniUniEmbed/`（逐檔對映，減 FrontendApi）
