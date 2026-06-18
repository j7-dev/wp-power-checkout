# 實作計劃：einvoice 設計優點導入（正規化錯誤模型 + 統一驗證層 + 狀態機 MockProvider + ECPay AES 抽取）

> Planner 產出（2026-06-18）。承接 clarifier Phase 01 Discovery 與 `einvoice-adoption-execution-plan.md`。
> 範圍模式：**HOLD SCOPE**（4 個用戶決策已定案 = max scope，不擴張、不縮減）。
> 下游：交接 `@zenbu-powers:tdd-coordinator` 執行 Red → Green → Refactor。
> 規範要求：PHP 改動經 `wordpress-master`；前端 Vue 改動經 `react-master`。

---

## 概述

把開源 SDK `paid-tw/einvoice` 的 4 個局部最優設計，在**尊重 power-checkout 既有契約**（never-throw、成功回 array、Payment callback always-200）的前提下，移植進 Invoice + Payment 領域。這不是業務功能新增，而是**改變既有操作的回傳契約（`[]` → `WP_Error`）+ 新增橫切基礎設施（正規化錯誤碼 enum、統一驗證層、狀態機測試替身、單一化 AES helper）**。

四項導入（用戶 Q1 定案全做）：
1. **正規化錯誤物件** — 失敗從「塌縮回 `[]`」演進為「回 `\WP_Error`，$code = 正規化 code enum value，$data 帶 raw_code / raw_message / provider / raw」。
2. **統一執行期驗證層** — 各 provider `issue()` 第一步跑跨 provider 一致的驗證（UBN checksum / 載具捐贈互斥 / 金額守恆），失敗即 `WP_Error(VALIDATION)`，不打第三方 API。
3. **狀態機 MockProvider** — 測試用 in-memory 狀態機（issue→void→CONFLICT、雙索引、真跑驗證），是 fake 不是 stub。
4. **ECPay AES 單一化** — 把演算法位元組相同的 `Invoice/Ecpay/AesCrypto` + `Payment/Ecpg/AesCrypto` 抽到單一領域中立 helper，三處（含 Logistics）共用。

---

## 需求重述

- **建構什麼**：一套領域中立的正規化錯誤模型（`Shared/Errors/`），讓 Invoice 4 provider + Payment 6+ 金流的失敗可被呼叫端區分、被前端精確顯示、被 debug 保留原始碼；一個擴充版統一驗證層；一個有狀態的測試替身；一份單一化的 ECPay AES helper。
- **服務對象**：呼叫端（REST 端點 / WC hook）、前端（InvoiceApp / MetaBox / RefundDialog）、開發者（測試）、維運（debug 保留 raw_code）。
- **成功的樣子**：既有全套測試（實測約 1157）不退化保持全綠；每 provider 錯誤碼→正規化 code 有映射測試；驗證層三項不變式有獨立測試；MockProvider 狀態流測試（issue→void→CONFLICT、NOT_FOUND、雙索引）；AES 抽取後密文與原實作位元組一致；Payment callback always-200 經斷言確認不變。

---

## 已知風險（來自研究 + 程式碼核實）

- 風險：改 `IInvoiceService` 回傳型別波及所有 provider + 呼叫端 — 緩解：PHPStan L9 在分析期抓出未處理 `WP_Error` 的呼叫端；逐一加 `is_wp_error()`。**先跑一次 baseline PHPStan 存證，改完再跑比對。**
- 風險：AES 抽取若密文不一致 → 第三方解密失敗（KEY10002 等）— 緩解：加密等價測試為 gating（位元組比對原實作）；ezPay 明確排除。**已核實兩份 ECPay AesCrypto 演算法位元組相同（見下「核實事實 #6」），風險實際很低。**
- 風險：Payment callback 誤改 always-200 → 第三方重送風暴 — 緩解：feature 硬約束 + 測試斷言 callback 驗簽失敗仍回 200；錯誤模型只用於 `process_refund` + REST + admin action，**碰都不碰 callback 的 HTTP 回應**。
- 風險：既有 feature 的 `回應狀態碼為 500` 斷言與新正規化 4xx/5xx 斷言衝突 — 緩解：**這是兩個不同的錯誤面**（見「核實事實 #3」），不衝突；保留既有 500 路徑（provider lookup 失敗），新增 provider WP_Error → 4xx/5xx 路徑。
- 風險：NormalizedError 引入專案前所未有的 `WP_Error $data` 慣例 — 緩解：核實全專案目前 `$data` param 從未使用（見「核實事實 #4」），factory 統一封裝，避免散裝建構走樣。
- 風險：WC `woocommerce_order_status_{status}` action hook 不消費回傳值 — 緩解：auto-issue/auto-cancel 的 `WP_Error` 不會被 hook 讀取，**必須在 provider 內部或 wrapper 記 order note**，否則失敗無痕（見「失敗模式登記表」FM-07）。
- 未發現其他額外已知風險（einvoice 移植為內部重構，無新外部依賴、無版本相容性問題）。

---

## 訪談 + 程式碼核實事實（實作期避免誤判，務必先讀）

1. **ezPay `return []` 實測為 15 處**（Execution Plan 寫 13，以實測為準）：`EzpayInvoiceProvider.php` 行 99/122（issue）、158/165/183（cancel）、220/234/243/265（issue_allowance）、286/294/310（invalid_allowance）、336/343/349（query_invoice）。每處對應的失敗語義已在「Phase 5 後端 #1」逐一標註。
2. **Amego 是失敗處理最弱的 provider**：`issue()`/`cancel()` 用 `?? []` null 合併（無顯式 try/catch、無顯式冪等檢查），與 ezPay/Ecpay/Paynow 的顯式檢查不同。Amego error-map + 補測試是本次重點之一（用戶 Q4 指定「補齊 Amego 缺漏測試」）。
3. **兩個不同的錯誤面，不可混為一談**：
   - **面 A（既有，保留）**：`InvoiceApiService::get_service()` 在 provider 找不到 / 型別不符 / 訂單不存在時 `throw \Exception`，被 WP ApiBase 包成 HTTP 500 + 訊息。既有 feature 斷言（`回應狀態碼為 500` + 「找不到訂單」/「不是 Invoice Service」）走這條。**這條不動。**
   - **面 B（新增）**：provider 回傳 `WP_Error`（業務錯誤碼 / 驗證 / 驗章 / 連線），REST callback 用 `is_wp_error()` 偵測，映射為 error_code + raw_code + 依 code 分類的 HTTP 4xx/5xx。新 feature 斷言走這條。
   - 兩條共存：面 A 是「找不到要呼叫的東西」，面 B 是「呼叫了但失敗」。
4. **`WP_Error $data` param 全專案從未使用**：現有 `new \WP_Error('code', 'msg')` 一律兩參數。NormalizedError factory 是專案第一個用 $data 的地方 → 用 factory 強制統一結構，禁止散裝。
5. **PaymentApiService 已呼叫 `is_wp_error()`**（行 77）但只 `throw new \Exception($result->get_error_message())` — 丟失 error_code。本次是**豐富化**（保留 error_code + raw_code 進回應體），非新增檢查。
6. **兩份 ECPay AesCrypto 演算法位元組相同**：`Invoice/Ecpay/Shared/Helpers/AesCrypto.php` 與 `Payment/Ecpg/Shared/Helpers/AesCrypto.php` — 同 `aes-128-cbc`、同 `wp_json_encode(JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)`、同 `urlencode`、同 `OPENSSL_RAW_DATA`、同 `base64_encode`、同 `substr(0,16)` key/iv。差異僅 namespace 與例外訊息前綴。兩份 docblock 都已明文邀請此重構。`Logistics/Ecpay/Http/LogisticsApiClient.php` 行 39 `use ...Payment\Ecpg\...\AesCrypto` 直接複用。
7. **`InvoiceParamsValidator` 是 checkout 表單級驗證**（throw `\InvalidArgumentException`），現驗：invoiceType 列舉、carrier 格式 `/^\/[0-9A-Z+\-.]{7}$/`、moica `/^[A-Z]{2}[0-9]{14}$/`、UBN **僅 8 碼格式** `/^[0-9]{8}$/`（無 checksum）、donateCode `/^[0-9]{3,7}$/`。統一驗證層在此之上補三項 dispatch 級不變式。
8. **MSW 前端測試層不存在**（`js/src/` 無 `mocks/`、無 `msw`）→ Execution Plan 的「MSW handlers（若前端測試層存在）」條件解析為**不適用，移出本次範圍**。前端只改 InvoiceApp / MetaBox / RefundDialog 三處錯誤顯示。
9. **前端錯誤顯示現況極簡**：axios interceptor（`js/src/api/index.ts`）只讀 `error.response.data.message` 丟 `ElNotification`；元件 `onError` 僅 `console.error`。error_code 解析需在 interceptor 或元件層新增。
10. **無 `specs/milestones/`** → 不掛 milestone tracker。`erm.dbml` 在 `specs/erm.dbml`，`api.yml` 在 `specs/api.yml`。
11. **測試 harness**：`tests/Integration/`，base `Tests\Integration\TestCase extends WP_UnitTestCase`，`API_MODE=mock` 經 `getenv` + 各 ApiClient `is_mock()` 分支回硬編 in-memory fixture（**無 fixtures 目錄**），CheckCode 用官方測試金鑰預算。group 白名單 `smoke/happy/error/edge/security` 必掛否則被跳過。Ecpay 缺 `ApiClientMockTest`（次要缺口）。

---

## 正規化 code 值域（領域中立，發票 + 金流共用）

放 `inc/classes/Shared/Errors/`。backed enum（string）。共 10 值：

| code（enum value） | 發票場景 | 金流場景 | REST HTTP 映射 |
|--------------------|---------|---------|----------------|
| `AUTH` | 金鑰/JWT 錯（KEY10002） | 商店憑證/簽章金鑰錯 | 401 |
| `VALIDATION` | UBN checksum/載具格式/互斥/金額守恆 | 退款金額不合法/必填缺 | 422 |
| `NOT_FOUND` | 查無發票 | 查無交易 | 404 |
| `CONFLICT` | 重複開立/已作廢/已開折讓（LIB10007） | 重複處理/狀態衝突 | 409 |
| `NUMBER_EXHAUSTED` | 字軌號碼用罄 | （發票專屬） | 409 |
| `SIGNATURE`（PC 新增，einvoice 無） | CheckCode 驗章失敗 | CheckMacValue/HMAC/HashInfo 驗簽失敗 | 400 |
| `UNSUPPORTED` | 不支援折讓/查詢 | 退款不支援/capture/void no-op | 400 |
| `NETWORK` | API 連線失敗/逾時 | 同 | 502 |
| `PROVIDER` | provider 回未分類錯誤碼 | 同 | 502 |
| `UNKNOWN` | 未預期 `\Throwable` | 同 | 500 |

> HTTP 映射為建議值，Phase 04 api.yml 落定。`UNKNOWN`→500 與「核實事實 #3」面 A 的既有 500 並存（一個是回應體帶 error_code，一個是 WP ApiBase 包例外訊息）。

---

## 各 provider 錯誤碼 → 正規化 code 映射表（實作期 error-map 依據）

| Provider | 原始錯誤碼形態 | 映射策略 | 對應 skill |
|----------|---------------|---------|-----------|
| Amego | 數字錯誤碼 + msg | 依區間映射；驗章→SIGNATURE；認證→AUTH；未涵蓋→PROVIDER | `amego-invoice` skill 錯誤碼表 |
| Ecpay（發票） | RtnCode + 中文 RtnMsg | RtnCode≠1 映射 + 中文訊息 regex 補強；CheckMacValue 不符→SIGNATURE | `ECPay-API-Skill` |
| Ezpay | LIB1000x / KEY1000x / 文字 | LIB10007→CONFLICT、KEY10002→AUTH、LIB99999/未涵蓋→PROVIDER；CheckCode 不符→SIGNATURE | `ezpay-invoice` skill |
| Paynow（發票） | type + message（JWT） | type≠success 映射；JWT/認證→AUTH；未涵蓋→PROVIDER | `paynow` skill |
| Payment 各金流 | 各家退款 API 回應碼 | 退款不支援→UNSUPPORTED；憑證→AUTH；逾時→NETWORK；金額守恆→VALIDATION；callback 驗簽→SIGNATURE（**僅記 note，不改 always-200**） | 各金流 skill |

> error-map 建議實作為各 provider 內的 `private static function map_error(...)` 或 provider 旁的小型 mapper class，回傳 `ErrorCode` enum。映射表本身的正確性由 Phase 07「錯誤碼映射」測試 gating。

---

## 架構變更（檔案級，依 Phase 分組）

### Shared 領域中立（新增）
- **create** `inc/classes/Shared/Errors/ErrorCode.php` — backed enum（string），10 值。
- **create** `inc/classes/Shared/Errors/NormalizedError.php` — factory：`from(ErrorCode $code, string $message, array $context = [])` 回 `\WP_Error`（$code = enum value、$data = `['raw_code'=>..,'raw_message'=>..,'provider'=>..,'raw'=>..]`）；`is_normalized_error(mixed $value): bool` type guard；getter `get_code()`/`get_raw_code()`。**所有 provider/REST 一律經此 factory，禁止散裝 `new \WP_Error` 帶 $data。**
- **create** `inc/classes/Shared/Helpers/EcpayAesCrypto.php` — 從 `Payment/Ecpg/AesCrypto` 提升（領域中立，演算法不變）。

### Invoice 介面（修改回傳型別）
- **modify** `Invoice/Shared/Interfaces/IInvoiceService.php` — `issue()`/`cancel()`：`array` → `array|\WP_Error`。
- **modify** `Invoice/Shared/Interfaces/ISupportsAllowance.php` — `issue_allowance()`/`invalid_allowance()`：`array` → `array|\WP_Error`。
- **modify** `Invoice/Shared/Interfaces/ISupportsQuery.php` — `query_invoice()`：`array` → `array|\WP_Error`。

### Invoice provider（4 家，`return []` → 正規化 WP_Error + error-map）
- **modify** `Invoice/Ezpay/Services/EzpayInvoiceProvider.php` — 15 處 `return []` → 對應正規化 WP_Error；新增 ezPay error-map。
- **modify** `Invoice/Ecpay/Services/EcpayInvoiceProvider.php` — `return []` → WP_Error；新增 ECPay error-map（RtnCode + 中文 regex + CheckMacValue）。
- **modify** `Invoice/Amego/Services/AmegoProvider.php` — `?? []` / `return []` → WP_Error；新增 Amego error-map；補顯式 try/catch（never-throw）。
- **modify** `Invoice/Paynow/Services/PaynowInvoiceProvider.php` — `return []` → WP_Error；新增 PayNow error-map（type + JWT）。

### Invoice 統一驗證層
- **modify** `Invoice/Shared/Helpers/InvoiceParamsValidator.php`（或新增 dispatch 級 validator method）— 補：UBN 財政部 checksum 演算法、載具/捐贈互斥（dispatch 級）、金額守恆 `salesAmount+taxAmount===totalAmount`。失敗回 `ErrorCode::VALIDATION`（dispatch 級回 WP_Error，非 throw；checkout 表單級維持既有 throw）。
- **modify** 各 provider `issue()` 第一步 — 呼叫統一驗證層；失敗即回 `WP_Error(VALIDATION)`，**不打第三方 API**。

### Invoice 呼叫端（加 `is_wp_error()`）
- **modify** `Invoice/Shared/Services/InvoiceApiService.php` — 5 端點 callback：`is_wp_error($result)` → 映射為 error_code + raw_code + message + 依 code 的 HTTP code；成功維持 200。**保留既有 `get_service()` throw → 500 路徑（面 A）不動。**
- **modify** `Invoice/ProviderRegister.php` — auto-issue（`woocommerce_order_status_{status}`）/ auto-cancel hook：因 action hook 不消費回傳值，需改為 **wrapper static method**（`[__CLASS__, 'auto_issue_wrapper']`）內呼叫 provider 並 `is_wp_error()` → 記 order note；不向 hook 拋（never-throw 保留）。
- **modify** `Invoice/ProviderRegister.php`（`maybe_issue_allowance_on_refund`，行 95–141）— 折讓回 WP_Error 時記 order note，不中斷退款流程（現已 catch \Throwable，補 `is_wp_error()` 分支）。

### Payment 領域（Q3 外溢）
- **modify** `Payment/Shared/Interfaces/IPaymentProvider.php` — `process_refund` docblock 載明失敗回 `\WP_Error` 帶正規化 code（型別已是 `bool|\WP_Error`，**不改簽名**）。
- **modify** `Payment/Shared/Abstracts/AbstractPaymentGateway.php` — 預設 `process_refund` 回 `false` → 改回 `NormalizedError::from(UNSUPPORTED, ...)`；capture/void no-op 不變。
- **modify** 各金流 `process_refund()`（Payuni / PayuniUniEmbed / Paynow / Ecpg / EcpayAIO / ShoplinePayment）— 現有 `WP_Error('refund_unsupported', ...)` → 改用 `NormalizedError::from(UNSUPPORTED, ...)`；退款金額守恆→VALIDATION；憑證→AUTH；逾時→NETWORK；ShoplinePayment 的 `refund_failed`（exception）→ UNKNOWN/PROVIDER。
- **modify** `Payment/Shared/Services/PaymentApiService.php`（REST /refund）— 行 77 既有 `is_wp_error()` 分支：保留 error_code + raw_code + message 進回應體（取代只丟 message）。
- **no-change** 各金流 NotifyURL / Webhook callback — **always-200 完全不變**；驗簽失敗僅記 order note（內部可用 SIGNATURE 標示），不改 HTTP 回應。

### ECPay AES 抽取（三處共用）
- **modify** `Invoice/Ecpay/Shared/Helpers/AesCrypto.php` — 改為 `extends`/轉呼叫共用 `Shared/Helpers/EcpayAesCrypto`（或薄包裝保留型別），保證密文位元組一致。
- **modify** `Payment/Ecpg/Shared/Helpers/AesCrypto.php` — 同上。
- **modify** `Logistics/Ecpay/Http/LogisticsApiClient.php` — `use` 改指向共用 helper（原複用 Payment/Ecpg）。
- **no-change** `Invoice/Ezpay/Shared/Helpers/AesCrypto.php` — **排除**（AES-256-CBC hex blocksize=32，不可併，混用回 KEY10002）。

### 測試替身（新增）
- **create** `tests/.../MockInvoiceProvider.php`（測試命名空間）— 實作 `IInvoiceService + ISupportsAllowance + ISupportsQuery`；in-memory 狀態（issue→已開立 / void→已作廢）；雙索引（orderId ↔ invoice_number）；`issue()` 真跑統一驗證層；以正規化 code 回 WP_Error；重複 issue/void→CONFLICT；void 未開立→NOT_FOUND。**不更動 API_MODE 管線。**

### 前端（Vue）
- **modify** `js/src/external/InvoiceApp/Steps/index.vue` + `App.vue` — `onError` 解析 `error.response.data.error_code` + `message`，顯示精確錯誤（取代純 console.error）。
- **modify** `js/src/external/RefundDialog/Dialog.vue` — 退款失敗解析 `error_code`（UNSUPPORTED → 提示手動退款）。
- **modify（評估）** `js/src/api/index.ts` interceptor — 視 react-master 判斷是否在 interceptor 統一附帶 error_code 對應的友善訊息，或留在元件層。
- **規格** `inc/assets/blocks/types/types.d.ts` / 前端 types — 補 `error_code` 回應欄位型別。

### 規格產出（Phase 02 / 04，由 tdd-coordinator 流程前置）
- **modify** `specs/erm.dbml` — 新增 `Enum normalized_error_code`（10 值）。不新增資料表、不新增 order meta key（錯誤是回傳值，非持久化狀態）。
- **modify** `specs/api.yml` — issue/cancel/allowance/allowance-cancel/query + refund/refund-manual 錯誤回應補 `error_code` + `raw_code` + `message`；新增 shared `NormalizedError` schema。

---

## 資料流分析

### 流程 1：發票開立（issue）— 驗證 → provider → error-map → REST 映射

```
checkout 表單                provider.issue()                        REST callback
參數                         ┌──────────────────────────┐
  │                          │ ① 統一驗證層              │
  ▼                          │   (UBN checksum/互斥/守恆)│
INPUT ──▶ VALIDATION(表單級) ─▶ ② 第三方 API 呼叫 ──▶ ③ error-map ──▶ OUTPUT(array|WP_Error) ──▶ is_wp_error?
  │            │              │   (成功/錯誤碼/驗章/逾時) │       │                                   │
  ▼            ▼              │            │              │       ▼                                   ▼
[nil order?] [throw          │            ▼              │  [array → 200]                    [WP_Error → 4xx/5xx
[empty?]      InvalidArg]    │ ①失敗→WP_Error(VALIDATION)│  [WP_Error → 依 code 分類]          + error_code + raw_code]
                             │   不打 API                │
                             │ ②逾時→WP_Error(NETWORK)   │
                             │ ③驗章→WP_Error(SIGNATURE) │
                             │ ③錯誤碼→map→WP_Error      │
                             │ catch \Throwable          │
                             │   →WP_Error(UNKNOWN)+note │
                             └──────────────────────────┘
shadow paths:
  nil:    order_or_id 解析不到訂單 → provider 內回 WP_Error(NOT_FOUND) 或 REST 面 A throw（依呼叫點）
  empty:  驗證層任一規則失敗 → WP_Error(VALIDATION)，API 未被呼叫（斷言：第三方未被呼叫）
  error:  第三方錯誤碼 → error-map → 對應 code；未涵蓋 → PROVIDER；driver 例外 → UNKNOWN
  partial: 開立成功但 meta 寫入失敗 → 既有 logger；不在本次擴張範圍（保持現狀）
  idempotent: 已開立（_pc_issued_invoice_data 有值）→ 直接回 array，不呼叫 API、不驗證（既有冪等不變）
```

### 流程 2：發票作廢（cancel）— 狀態前置 → provider → error-map

```
INPUT(order) ──▶ 冪等檢查 ──▶ 狀態前置(已開折讓?) ──▶ 第三方 API ──▶ error-map ──▶ OUTPUT(array|WP_Error)
  │                │              │                      │             │
  ▼                ▼              ▼                      ▼             ▼
[nil?]      [已作廢→回 array]  [已開折讓(LIB10007)    [逾時→NETWORK] [LIB10007→CONFLICT
[empty?]    [冪等不呼叫 API]    →WP_Error(CONFLICT)    [驗章→SIGNATURE] 保留 raw_code]
                                不清 issued_data]                     [issued_data 不被清除]
shadow paths:
  nil:    無 invoice_number → 視 provider：回 WP_Error(NOT_FOUND) 或 REST 面 A
  conflict: 已開折讓 → CONFLICT，issued_data 保留（feature 斷言「未被清除」）
  error:  作廢失敗一律不清 issued_data（保留可重試）；記 order note
```

### 流程 3：退款（process_refund）— Payment 領域 WP_Error

```
WC 退款 ──▶ process_refund ──▶ 付款方式判定 ──▶ 第三方退款 API ──▶ OUTPUT(true|WP_Error) ──▶ REST /refund
  │              │                  │                  │                  │                      │
  ▼              ▼                  ▼                  ▼                  ▼                      ▼
[nil?]    [金額>可退餘額      [不支援API退款    [逾時→NETWORK]      [true→200]           [is_wp_error→error_code
[amount?]  →VALIDATION,       →UNSUPPORTED,    [憑證→AUTH]         [WP_Error→            + raw_code + message]
            API 未呼叫]        不打 API]                            依 code 分類]
shadow paths:
  unsupported: ATM/CVS/LINEPay 等 → UNSUPPORTED（提示手動退款），第三方未呼叫
  validation:  退款金額超出可退餘額 → VALIDATION，第三方未呼叫
  never-throw: process_refund catch \Throwable → WP_Error，不向 WC 退款流程傳播
```

### 流程 4：NotifyURL / Webhook callback（always-200，錯誤模型不介入 HTTP）

```
第三方 POST ──▶ 驗簽(HMAC/CheckMacValue/HashInfo) ──▶ 解密 ──▶ 訂單查找 ──▶ 狀態更新 ──▶ 一律 HTTP 200
  │                  │                                  │          │           │
  ▼                  ▼                                  ▼          ▼           ▼
[empty body?]   [驗簽失敗 → 記 order note            [解密失敗   [查無訂單   [catch \Throwable
                  (內部標 SIGNATURE) → 不改狀態        → note]    → note]    → note]
                  → 仍回 HTTP 200]                                            → 仍回 HTTP 200]
⚠️ 硬約束：本流程的 HTTP 回應「完全不變」。WP_Error / 正規化 code 不用於 callback 的 HTTP status。
   驗簽失敗只在 order note 內部記 SIGNATURE 語義（可選），HTTP 永遠 200。
```

### 流程 5：MockProvider 狀態機（測試替身）

```
issue(order) ──▶ 驗證層 ──▶ 狀態查 ──▶ 寫狀態+雙索引 ──▶ array(invoice_number)
                   │           │
                   ▼           ▼
            [VALIDATION]  [已開立→CONFLICT]
void(order) ──▶ 狀態查 ──▶ 轉已作廢 ──▶ array
                   │
                   ▼
            [未開立→NOT_FOUND] [已作廢→CONFLICT]
query(orderId 或 invoice_number) ──▶ 雙索引查 ──▶ array(對應另一鍵)
非法轉換：issue 後 issue=CONFLICT；void 未 issue=NOT_FOUND；void 後 void=CONFLICT
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型(正規化 code) | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| `provider.issue()` 驗證層 | UBN checksum/互斥/金額不守恆 | VALIDATION | 回 WP_Error，不打 API | 是（REST 422 + message） |
| `provider.issue()` 第三方 | 業務錯誤碼（已映射） | AUTH/CONFLICT/NUMBER_EXHAUSTED/NOT_FOUND | error-map → WP_Error + raw_code | 是（REST + error_code） |
| `provider.issue()` 第三方 | 業務錯誤碼（未映射） | PROVIDER | WP_Error + raw_code | 是 |
| `provider.issue()` 驗章 | CheckCode/CheckMacValue 不符 | SIGNATURE | WP_Error，不寫 issued_data | 是（400） |
| `provider.issue()` 連線 | API 逾時/無回應 | NETWORK | WP_Error | 是（502） |
| `provider.issue()` 未預期 | driver 拋 \Throwable | UNKNOWN | catch → logger → order note → WP_Error | 是（500）+ order note |
| `provider.cancel()` 狀態 | 已開折讓（LIB10007） | CONFLICT | WP_Error + raw_code，保留 issued_data | 是（409） |
| `provider.cancel()` 冪等 | 已作廢 | （成功路徑） | 回既有 cancelled_data array | 否（200） |
| `issue_allowance()` 前置 | 無 issued invoice / 金額不合法 | NOT_FOUND / VALIDATION | WP_Error | 是 |
| `invalid_allowance()` 前置 | 無 allowance_data | NOT_FOUND | WP_Error | 是 |
| `query_invoice()` 前置 | 無 issued invoice | NOT_FOUND | WP_Error | 是 |
| REST `InvoiceApiService` 5 端點 | provider 回 WP_Error（面 B） | 依 code | `is_wp_error()` → error_code + raw_code + HTTP 4xx/5xx | 是 |
| REST `InvoiceApiService` get_service | provider 找不到/型別不符/訂單不存在（面 A） | （非正規化） | 既有 throw \Exception → WP ApiBase 500（**不動**） | 是（500 + 既有訊息） |
| auto-issue/cancel hook | provider 回 WP_Error | 依 code | wrapper `is_wp_error()` → 記 order note，**不向 hook 拋** | 否（僅 order note） |
| `maybe_issue_allowance_on_refund` | 折讓回 WP_Error | 依 code | `is_wp_error()` → order note，不中斷退款 | 否（僅 order note） |
| `process_refund()` 不支援 | 付款方式不支援 API 退款 | UNSUPPORTED | WP_Error，不打 API | 是（REST + 提示手動） |
| `process_refund()` 金額 | 退款金額>可退餘額 | VALIDATION | WP_Error，不打 API | 是 |
| `process_refund()` 憑證/逾時 | 商店憑證錯 / API 逾時 | AUTH / NETWORK | WP_Error | 是 |
| `process_refund()` 未預期 | \Throwable | UNKNOWN | catch → WP_Error，不向 WC 退款流程傳播 | 是 |
| REST `PaymentApiService` /refund | process_refund 回 WP_Error | 依 code | 行 77 既有 `is_wp_error()` → 補 error_code + raw_code | 是 |
| **callback 驗簽失敗** | HMAC/CheckMacValue/HashInfo 不符 | （內部 SIGNATURE，不外溢 HTTP） | **記 order note + 一律 HTTP 200** | 否（HTTP 永遠 200） |
| AES 抽取 | 密文與原實作不一致 | （建構期 gating） | 加密等價測試 fail 即擋 merge | 開發者（CI） |

> **CRITICAL GAP 檢查**：唯一「使用者不可見」的錯誤路徑是 auto-issue/cancel hook + callback 驗簽失敗 — 兩者**皆有 order note 記錄**（非靜默），符合「處理方式≠無」。無 CRITICAL GAP。

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試?(本計劃新增) | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| FM-01 issue 驗證層 | 不合法參數打到第三方 | 是（前置攔截） | 是（驗證層 UBN/互斥/守恆 + 「第三方未被呼叫」斷言） | 是 | 修正參數重送 |
| FM-02 issue error-map | 錯誤碼漏映射→誤判 | 是（fallthrough→PROVIDER） | 是（每 provider 映射測試 + PROVIDER fallthrough） | 是 | raw_code 保留供 debug |
| FM-03 issue 驗章 | SIGNATURE 誤寫 issued_data | 是（驗章失敗不寫） | 是（SIGNATURE + issued_data 未寫斷言） | 是 | 重新開立 |
| FM-04 cancel 狀態衝突 | CONFLICT 誤清 issued_data | 是（保留 issued_data） | 是（CONFLICT + issued_data 未清斷言 + raw_code=LIB10007） | 是 | 先作廢折讓再作廢 |
| FM-05 never-throw 破口 | provider \Throwable 漏 catch→斷 WC hook | 是（catch \Throwable→UNKNOWN） | 是（UNKNOWN + 例外不傳播 + order note 斷言） | 是 | order note 追溯 |
| FM-06 callback always-200 破口 | 驗簽失敗誤回非 200→第三方重送風暴 | 是（一律 200） | 是（驗簽失敗仍 200 + 狀態未變 + SIGNATURE note 斷言） | 否 | 第三方正常重送 |
| FM-07 hook 回傳值無痕 | action hook 不讀 WP_Error→失敗靜默 | 是（wrapper 記 order note） | 是（auto-issue 失敗留 order note 斷言） | 否（order note） | order note 追溯 |
| FM-08 AES 抽取破口 | 抽取後密文位元組變動→第三方解密失敗 | 是（等價測試 gating） | 是（密文位元組一致 + 解密回原文 + ezPay 不受影響） | 開發者 | 還原抽取 |
| FM-09 ezPay 排除破口 | 誤把 ezPay AES-256 併入→KEY10002 | 是（明確排除 + 測試） | 是（ezPay 維持獨立實作斷言） | 開發者 | — |
| FM-10 process_refund 金額守恆 | 超額退款打到第三方 | 是（前置 VALIDATION） | 是（VALIDATION + 第三方未呼叫斷言） | 是 | 修正金額 |
| FM-11 MockProvider 狀態流 | 狀態機非法轉換未擋 | 是（CONFLICT/NOT_FOUND） | 是（issue→void→CONFLICT、NOT_FOUND、雙索引、開立前驗證） | 開發者 | — |
| FM-12 PHPStan 漏網呼叫端 | 新 WP_Error 未被某呼叫端處理 | 是（PHPStan L9 比對） | 是（baseline vs after PHPStan diff） | 開發者 | 補 is_wp_error |
| FM-13 既有測試退化 | 回傳型別改動破壞既有斷言 | 是（全套回歸） | 是（~1157 全綠 gating） | 開發者 | 修正斷言或實作 |

---

## 實作步驟（分階段，每階段可獨立驗證）

> 排序原則：先地基（Shared）、再介面、再 provider（一家家綠）、再呼叫端、再 Payment、再 AES、再 MockProvider、最後前端。每階段結束跑對應測試 + PHPStan 關卡。TDD 由 tdd-coordinator 以 Red→Green→Refactor 驅動；以下為「實作切片順序」。

### 第一階段：Shared 地基（正規化錯誤模型）— 無外部依賴，先行
1. **建立 ErrorCode enum**（檔案：`inc/classes/Shared/Errors/ErrorCode.php`）
   - 行動：backed enum（string）10 值（AUTH/VALIDATION/NOT_FOUND/CONFLICT/NUMBER_EXHAUSTED/SIGNATURE/UNSUPPORTED/NETWORK/PROVIDER/UNKNOWN）；附 `to_http_status(): int` method（依值域表）。
   - 原因：領域中立地基，發票 + 金流共用。
   - 依賴：無。風險：低。
2. **建立 NormalizedError factory**（檔案：`inc/classes/Shared/Errors/NormalizedError.php`）
   - 行動：`from(ErrorCode, string $message, array $context): \WP_Error`（$code=enum value，$data=raw_code/raw_message/provider/raw）；`is_normalized_error(mixed): bool`；getter。
   - 原因：統一 WP_Error 結構，禁散裝。
   - 依賴：步驟 1。風險：低。
   - 成功標準：WP_Error 契約測試（type guard 可辨識、可取 code/raw_code）綠。
- **階段關卡**：新 Shared 測試綠 + `vendor/bin/phpstan analyse` 無新增錯誤。

### 第二階段：Invoice 介面回傳型別演進
3. **改三介面回傳型別**（檔案：`IInvoiceService.php` / `ISupportsAllowance.php` / `ISupportsQuery.php`）
   - 行動：`array` → `array|\WP_Error`；docblock 載明成功 array / 失敗 WP_Error。
   - 原因：契約演進的型別基礎。
   - 依賴：步驟 1-2。風險：中（波及所有 implementor）。
   - 成功標準：PHPStan 在此時會「亮」出所有 provider 與呼叫端的型別缺口 → 作為後續階段的 work list。**先存一份 baseline PHPStan 輸出。**

### 第三階段：統一驗證層（先於 provider 改造，provider 第一步要呼叫它）
4. **擴充 InvoiceParamsValidator**（檔案：`Invoice/Shared/Helpers/InvoiceParamsValidator.php`）
   - 行動：新增 dispatch 級 method（如 `validate_for_dispatch(): ErrorCode|null` 或回 WP_Error）：UBN 財政部 checksum、載具/捐贈互斥、金額守恆。checkout 表單級維持既有 throw；dispatch 級回正規化錯誤。
   - 原因：跨 provider 一致的執行期驗證。
   - 依賴：步驟 1。風險：中（checksum 演算法須正確）。
   - 成功標準：驗證層獨立測試綠（UBN 合法 `04595257`/不合法 `12345678`、手機條碼 `/ABC1234` vs `ABC1234`、互斥、守恆 952+48=1000 vs 900+50=950）。

### 第四階段：Invoice provider 逐一改造（一家綠再下一家）
5. **ezPay provider**（檔案：`Invoice/Ezpay/Services/EzpayInvoiceProvider.php`）
   - 行動：15 處 `return []` → 對應正規化 WP_Error（驗證失敗→VALIDATION、型別不符→PROVIDER、例外→UNKNOWN、未開立→NOT_FOUND、無折讓→NOT_FOUND、LIB10007→CONFLICT、KEY10002→AUTH、CheckCode→SIGNATURE）；新增 ezPay error-map；`issue()` 第一步呼叫驗證層。
   - 依賴：步驟 1-4。風險：中。
   - 成功標準：ezPay 錯誤碼映射測試 + 既有 ezPay 測試（8 檔）全綠。
6. **Ecpay provider**（檔案：`Invoice/Ecpay/Services/EcpayInvoiceProvider.php`）
   - 行動：`return []` → WP_Error；ECPay error-map（RtnCode≠1 + 中文 regex + CheckMacValue→SIGNATURE）；驗證層。
   - 依賴：步驟 1-4。風險：中。
   - 成功標準：Ecpay 映射測試 + 既有 Ecpay 測試綠。
7. **Amego provider**（檔案：`Invoice/Amego/Services/AmegoProvider.php`）
   - 行動：`?? []` → WP_Error；補顯式 try/catch（never-throw）；Amego error-map（數字碼 + msg）；驗證層。
   - 依賴：步驟 1-4。風險：中（Amego 現有失敗處理最弱）。
   - 成功標準：Amego 映射測試 + **補齊 Amego 缺漏測試**（用戶 Q4 指定）綠。
8. **PayNow provider**（檔案：`Invoice/Paynow/Services/PaynowInvoiceProvider.php`）
   - 行動：`return []` → WP_Error；PayNow error-map（type≠success + JWT→AUTH）；驗證層。
   - 依賴：步驟 1-4。風險：中。
   - 成功標準：PayNow 映射測試 + 既有 PayNow 測試綠。
- **階段關卡**：4 provider 各自測試 + 全 Invoice 測試綠 + PHPStan provider 層無錯。

### 第五階段：Invoice 呼叫端（加 is_wp_error）
9. **InvoiceApiService 5 端點**（檔案：`Invoice/Shared/Services/InvoiceApiService.php`）
   - 行動：每端點 `is_wp_error($result)` → 映射 error_code + raw_code + message + HTTP（依 `ErrorCode::to_http_status()`）；成功維持 200；**保留既有 get_service throw→500 面 A**。
   - 依賴：步驟 5-8。風險：中。
   - 成功標準：REST 映射測試（VALIDATION→422、NETWORK→502、成功→200、面 A→500）綠。
10. **ProviderRegister auto-issue/cancel wrapper**（檔案：`Invoice/ProviderRegister.php`）
    - 行動：auto-issue/cancel 改 wrapper static method，內 `is_wp_error()` → 記 order note，不拋；`maybe_issue_allowance_on_refund` 補 `is_wp_error()` 分支。
    - 依賴：步驟 5-8。風險：中（hook 簽名 / WC 行為）。
    - 成功標準：auto-issue 失敗留 order note 斷言 + never-throw 斷言綠。

### 第六階段：Payment 領域外溢
11. **IPaymentProvider docblock + Abstract 預設**（檔案：`IPaymentProvider.php` / `AbstractPaymentGateway.php`）
    - 行動：docblock 載明 WP_Error 帶正規化 code（不改簽名）；預設 `process_refund` 回 `false` → `NormalizedError::from(UNSUPPORTED)`。
    - 依賴：步驟 1-2。風險：低。
12. **各金流 process_refund**（檔案：Payuni / PayuniUniEmbed / Paynow / Ecpg / EcpayAIO / ShoplinePayment 的 Gateway）
    - 行動：`WP_Error('refund_unsupported')` → `NormalizedError::from(UNSUPPORTED)`；金額守恆→VALIDATION；憑證→AUTH；逾時→NETWORK；ShoplinePayment `refund_failed`→UNKNOWN/PROVIDER。
    - 依賴：步驟 11。風險：中。
    - 成功標準：Payment 退款映射測試（UNSUPPORTED/VALIDATION/AUTH/NETWORK）+ 既有 4 退款測試綠。
13. **PaymentApiService /refund 豐富化**（檔案：`Payment/Shared/Services/PaymentApiService.php`）
    - 行動：行 77 既有 `is_wp_error()` → 補 error_code + raw_code + message 進回應體。
    - 依賴：步驟 12。風險：低。
14. **callback always-200 確認（no-change + 斷言）**（檔案：各金流 Callback Http 類）
    - 行動：**不改程式**；新增/補強測試斷言：驗簽失敗仍回 HTTP 200 + 狀態未變 + （可選）SIGNATURE order note。
    - 依賴：無。風險：低（純測試保護）。
    - 成功標準：callback always-200 測試綠（這是防止未來誤改的護欄）。

### 第七階段：ECPay AES 抽取（gating 測試先行）
15. **建立共用 EcpayAesCrypto**（檔案：`inc/classes/Shared/Helpers/EcpayAesCrypto.php`）
    - 行動：從 `Payment/Ecpg/AesCrypto` 提升（演算法不變，namespace 改 `J7\PowerCheckout\Shared\Helpers`）。
    - 依賴：無。風險：低（已核實兩份位元組相同）。
16. **三處改用共用 helper**（檔案：`Invoice/Ecpay/AesCrypto` + `Payment/Ecpg/AesCrypto` 改 extends/轉呼叫；`Logistics/Ecpay/Http/LogisticsApiClient` 改 use）
    - 行動：保留原類名（薄包裝 `extends` 共用 helper）以免波及大量 use；或直接改 use。由 wordpress-master 定奪最小波及方案。
    - 依賴：步驟 15 + AES 等價測試。風險：中（密文一致性）。
    - 成功標準：密文位元組一致測試（vs 原 Invoice/Ecpay + 原 Payment/Ecpg）+ 解密回原文 + ezPay 不受影響 + 既有 Ecpay 發票/物流/ECPG 測試全綠。

### 第八階段：狀態機 MockProvider
17. **建立 MockInvoiceProvider**（檔案：`tests/.../MockInvoiceProvider.php`）
    - 行動：實作三介面；in-memory 狀態 + 雙索引；`issue()` 真跑驗證層；正規化 code 回 WP_Error。
    - 依賴：步驟 1-4。風險：低。
    - 成功標準：狀態機測試（首開→已開立、重複 issue→CONFLICT、void→已作廢、void 未開立→NOT_FOUND、重複 void→CONFLICT、雙索引查詢、開立前真跑驗證→VALIDATION）綠。

### 第九階段：前端錯誤顯示
18. **InvoiceApp / MetaBox 錯誤解析**（檔案：`js/src/external/InvoiceApp/Steps/index.vue` + `App.vue`）
    - 行動：`onError` 解析 `error.response.data.error_code` + `message`，精確顯示；ElNotification 由 interceptor 處理。
    - 依賴：步驟 9（後端回應契約）。風險：低。經 **react-master**。
19. **RefundDialog 錯誤解析**（檔案：`js/src/external/RefundDialog/Dialog.vue`）
    - 行動：退款失敗解析 error_code（UNSUPPORTED → 提示手動退款）。
    - 依賴：步驟 13。風險：低。經 **react-master**。
20. **interceptor 評估 + types**（檔案：`js/src/api/index.ts` + 前端 types）
    - 行動：react-master 判斷 error_code 友善訊息放 interceptor 或元件；補 error_code 型別。
    - 依賴：步驟 18-19。風險：低。

### 第十階段：規格收尾（erm.dbml / api.yml）
21. **erm.dbml + api.yml**（檔案：`specs/erm.dbml` + `specs/api.yml`）
    - 行動：erm 增 `normalized_error_code` enum；api.yml 錯誤回應補 error_code/raw_code + NormalizedError schema。
    - 依賴：全部實作定案。風險：低（文件）。

---

## 測試策略（Q4 = 嚴格）

掛 group：`error` / `edge`（+ 既有 `invoice` / provider 名 / `integration`）。位置：`tests/Integration/`（active suite）。`API_MODE=mock`。

| 測試類別 | 覆蓋 | group | 對應 feature |
|----------|------|-------|-------------|
| 錯誤碼映射（每 provider） | Amego/Ecpay/Ezpay/Paynow 原始碼→正規化 code（含 SIGNATURE/CONFLICT/AUTH/NUMBER_EXHAUSTED/PROVIDER/NETWORK/UNKNOWN） | error | invoice-error-model |
| 驗證層 | UBN checksum（`04595257` 過/`12345678` 不過）、手機條碼（`/ABC1234` 過/`ABC1234` 不過）、載具捐贈互斥、金額守恆（952+48=1000 過/900+50=950 不過）、跨 provider 一致 | error, edge | invoice-validation |
| WP_Error 契約 | 成功回 array、失敗回 WP_Error 帶 code/raw_code/provider；type guard 辨識 | error | invoice-error-model |
| REST 映射 | issue/cancel/refund WP_Error→error_code+raw_code+HTTP（422/502/409）；成功 200；**面 A 既有 500 不退化** | error | invoice-error-model / invoice-issue / invoice-cancel |
| never-throw | provider \Throwable→UNKNOWN，不向 hook 傳播；留 order note | edge | invoice-error-model |
| Payment 退款 | UNSUPPORTED/VALIDATION/AUTH/NETWORK 映射 | error, edge | payment-error-model |
| callback always-200 | 驗簽失敗仍 HTTP 200 + 狀態未變 + SIGNATURE note（**護欄**） | edge | payment-error-model |
| MockProvider 狀態機 | issue→void→CONFLICT、void 未開立→NOT_FOUND、重複→CONFLICT、雙索引、開立前真跑驗證 | edge | invoice-mock-statemachine |
| AES 等價 | 共用 helper 密文 vs 原 Invoice/Ecpay + 原 Payment/Ecpg 位元組一致；解密回原文；ezPay 不受影響 | edge | ecpay-aes-shared |
| Amego 補漏 | 補齊 Amego provider 測試（對齊 ezPay/Paynow 覆蓋度） | invoice | invoice-issue / invoice-cancel |
| 回歸 | 既有全套（實測約 1157）不退化全綠 | （既有 group） | 全部 |

**測試執行指令**：
```bash
# 全套（mock，CI 安全）
composer test
# 單類別
vendor/bin/phpunit --filter EzpayInvoiceProviderTest
vendor/bin/phpunit --group error
vendor/bin/phpunit --group edge
# 靜態分析（gating，baseline vs after 比對）
php -d memory_limit=2G vendor/bin/phpstan analyse
# 前端
pnpm lint
```
> 注意（記憶事實）：本地若 LocalWP DB 限制，測試/PHPStan 走 `wp-env run tests-cli`；PHPStan 用 `php -d memory_limit=2G vendor/bin/phpstan`（`-d` 是 PHP flag）；`--filter` 須帶路徑如 `tests/Integration/Payment/`。已知兩個 pre-existing 失敗（ezpay edge + RedirectSettingsDTO）不計入本次退化判定。

**關鍵邊界情況**（須覆蓋）：UBN checksum 邊界（第 7 碼乘 1 進位）、金額守恆四捨五入邊界、冪等（已開立/已作廢直接回 array 不驗證不呼叫 API）、空 order_or_id、driver 拋例外、callback 驗簽失敗但 HTTP 仍 200、AES 抽取後 base64 alphabet 不變（+/=，非 URL-safe）。

---

## 依賴項目

- 無新外部套件（內部重構）。
- 既有：`j7-dev/wp-utils`（DTO / ApiBase / SingletonTrait）、WordPress `WP_Error`、WC hooks、PHPStan L9、PHPUnit。
- skill 知識來源（error-map 對齊）：`amego-invoice`、`ecpay-invoice`(ECPay-API-Skill)、`ezpay-invoice`、`paynow`。

---

## 風險與緩解措施

- **高**：改 IInvoiceService 回傳型別波及所有 provider + 呼叫端 — PHPStan L9 baseline vs after 比對抓漏網呼叫端；逐一加 `is_wp_error()`；介面改完先存 PHPStan work list。
- **高**：AES 抽取密文不一致 → 第三方 KEY10002 — 加密等價測試 gating（位元組比對原兩實作）；已核實演算法位元組相同，風險已大幅降低。
- **中**：Payment callback 誤改 always-200 → 重送風暴 — 錯誤模型碰都不碰 callback HTTP；新增 always-200 護欄測試。
- **中**：既有 feature `回應狀態碼為 500` 與新 4xx/5xx 衝突 — 兩個不同錯誤面（面 A provider-lookup vs 面 B provider-WP_Error），保留面 A、新增面 B，互不干涉。
- **中**：UBN 財政部 checksum 演算法實作錯誤 — 用財政部標準演算法 + 已知合法/不合法測試向量 gating。
- **中**：Amego 失敗處理最弱，補測試成本高 — 對齊 ezPay/Paynow 測試模式（API_MODE=mock + in-memory fixture）。
- **低**：NormalizedError $data 新慣例走樣 — factory 強制統一結構，禁散裝。
- **低**：前端 error_code 解析位置（interceptor vs 元件）— 交 react-master 定奪。

---

## 錯誤處理策略

採 **never-throw + WP_Error-as-data** 策略，貫穿全案：

1. **provider 公開方法**（issue/cancel/issue_allowance/invalid_allowance/query_invoice、process_refund）：catch `\Throwable` 一律回 `WP_Error`（經 `NormalizedError::from()`），**絕不向 WC hook 拋例外**（怕斷結帳/退款/狀態變更）。
2. **成功仍回 array / true**：既有「拿到非空 array = 成功」呼叫端邏輯相容；呼叫端只需在前面加一道 `is_wp_error()`。
3. **REST 層**：`is_wp_error()` 偵測 → 映射為 error_code + raw_code + message + 依 code 的 HTTP；成功維持 200。
4. **WC action hook（auto-issue/cancel/折讓）**：因 hook 不消費回傳值，於 wrapper 內 `is_wp_error()` → 記 order note，不拋。
5. **Payment NotifyURL / Webhook callback**：**always-200 完全不變**；驗簽失敗僅記 order note（內部可標 SIGNATURE），HTTP 永遠 200。錯誤模型不介入 callback 的 HTTP 回應。

---

## 限制條件（此計劃不會做的事）

- ❌ **不**改 Payment callback / Webhook 的 always-200 行為（HTTP 回應碰都不碰）。
- ❌ **不**把 ezPay AES-256-CBC 併入 ECPay 共用 helper（padding 不同，混用回 KEY10002）。
- ❌ **不**新增自訂資料表、**不**新增 order meta key（錯誤是回傳值，非持久化狀態；erm.dbml 僅增 enum）。
- ❌ **不**做 einvoice 第 5 項優點「capability 細粒度化」（不在 Q1 定案的 4 項內）。
- ❌ **不**改 API_MODE=mock 管線（MockProvider 是額外的測試替身，與 API_MODE 並存）。
- ❌ **不**動 `InvoiceApiService::get_service()` 既有 throw→HTTP 500 路徑（面 A，provider-lookup 失敗）。
- ❌ **不**改 `process_refund` 的型別簽名（已是 `bool|\WP_Error`，僅豐富化 $data + docblock）。
- ❌ **不**新增 MSW 前端測試層（專案目前無此層；前端只改既有錯誤顯示）。
- ❌ **不**改 provider 的成功回傳形狀與既有冪等行為（成功仍回 array、已開立/已作廢直接回快取）。
- ❌ **不**處理 touch_issue / allowance_touch_issue（兩段式）等非本次範圍功能。

---

## 成功標準

- [ ] `inc/classes/Shared/Errors/ErrorCode.php`（10 值 backed enum）+ `NormalizedError.php`（factory + type guard）建立並測試綠。
- [ ] 三 Invoice 介面回傳型別演進為 `array|\WP_Error`，PHPStan L9 無新增錯誤。
- [ ] 統一驗證層補 UBN checksum / 載具捐贈互斥 / 金額守恆，獨立測試綠（含跨 provider 一致）。
- [ ] 4 Invoice provider（Amego/Ecpay/Ezpay/Paynow）`return []`/`?? []` 全數演進為正規化 WP_Error + error-map，每 provider 錯誤碼映射測試綠。
- [ ] ezPay 15 處 `return []` 逐一對應正確正規化 code。
- [ ] `InvoiceApiService` 5 端點 + `ProviderRegister` auto-issue/cancel/折讓 hook 全加 `is_wp_error()`；面 A 既有 500 路徑保留。
- [ ] Payment：`AbstractPaymentGateway` 預設 + 6 金流 `process_refund` 改用 NormalizedError；`PaymentApiService` /refund 補 error_code；退款映射測試綠。
- [ ] Payment callback always-200 護欄測試綠（驗簽失敗仍 200 + 狀態未變 + SIGNATURE note）。
- [ ] ECPay AES 單一化：共用 helper 密文與原兩實作位元組一致，三處（Invoice/Ecpay + Payment/Ecpg + Logistics）共用；ezPay 排除且不受影響。
- [ ] 狀態機 MockProvider 建立，狀態流測試（issue→void→CONFLICT、NOT_FOUND、雙索引、開立前真跑驗證）綠。
- [ ] 補齊 Amego 缺漏測試（對齊 ezPay/Paynow 覆蓋度）。
- [ ] 前端 InvoiceApp / MetaBox / RefundDialog 解析 error_code + message 精確顯示（經 react-master）。
- [ ] `specs/erm.dbml` 增 `normalized_error_code` enum；`specs/api.yml` 錯誤回應補 error_code/raw_code + NormalizedError schema。
- [ ] 既有全套測試（實測約 1157，扣除 2 個 pre-existing 失敗）不退化，保持全綠。

## 預估複雜度：高

> 高複雜度來源：契約變更波及面廣（3 介面 + 4 Invoice provider + 6 金流 + 5 REST 端點 + 多 hook + 前端），跨 Invoice/Payment/Shared/Logistics 四處，且須在「never-throw + always-200 + 成功回 array」三條既有鐵律下演進。緩解靠分階段（一家 provider 一綠）+ PHPStan baseline 比對 + 等價測試 gating + 既有全套回歸。
