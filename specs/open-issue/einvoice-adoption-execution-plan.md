# Execution Plan — einvoice 設計優點導入（正規化錯誤模型 + 驗證層 + 狀態機 Mock + AES 抽取）

> Phase 01 Discovery 產出（2026-06-18）。後續 Phase 02-08 以此為 scope 依據。
> 由 clarifier（sub-agent）產出。4 個用戶獨有決策已由用戶定案（max scope，見 §決策裁決）。
> 來源：開源 SDK `paid-tw/einvoice`（台灣電子發票統一 TypeScript SDK）的設計優點，移植進 PC Invoice + Payment 領域。
>
> 硬約束：
>  - **never-throw 鐵律保留**：provider 公開方法（issue/cancel/issue_allowance/invalid_allowance/query_invoice、process_refund）
>    catch `\Throwable` 一律回 `\WP_Error`，絕不向 WC hook 拋例外（怕斷結帳 / 退款 / 狀態變更）。
>  - **成功仍回 array / true**：既有「拿到非空 array = 成功」呼叫端邏輯相容；呼叫端只需在前面加一道 `is_wp_error()`。
>  - **Payment NotifyURL / Webhook always-200 完全不變**：正規化錯誤模型「不」改變 callback 的 HTTP 回應；
>    callback 內部驗簽失敗仍回 HTTP 200，僅記 order note。WP_Error 只用於 process_refund + REST 退款/補單/查詢 + admin action。
>  - **ezPay AES-256-CBC 不併入 ECPay 共用 helper**：其 padding 行為（自補 PKCS#7 blocksize=32 + hex）與 ECPay 不同，混用會被平台回 KEY10002。
>  - 專案規範：`declare(strict_types=1)`、`final class`、PHP 8.1+ enum/readonly、PHPStan L9、text domain `power_checkout`、
>    hook 用 static method `[__CLASS__, 'method']`、PSR-4 `J7\PowerCheckout` → `inc/classes/`。

## 決策裁決（2026-06-18，用戶定案）

| # | 決策題 | 裁決（用戶定案 = max scope） |
|---|--------|------------------------------|
| Q1 | 導入範圍 | **全做 4 項**：#1 正規化錯誤物件 + #2 共用驗證層 + #3 狀態機 MockProvider + #4 抽 ECPay AES 到單一 Shared |
| Q2 | 錯誤契約相容性 | **WP_Error**：成功回 `array`/`true`、失敗回 `\WP_Error`（$code = 正規化 code enum value，$data 帶 raw_code / raw_message / provider / raw）。呼叫端加 `is_wp_error()` |
| Q3 | Payment 外溢 | **Invoice + Payment 一起**：正規化 code enum + 基底錯誤類別放 `inc/classes/Shared/`（領域中立），兩領域共用 |
| Q4 | 驗收 | **嚴格**：每 provider 錯誤碼→正規化 code 映射測試 + 驗證層 UBN/互斥/守恆獨立測試 + 既有全套測試不退化 + MockProvider 狀態機補狀態流測試（issue→void→CONFLICT）+ 補齊 Amego 缺漏測試。掛 error/edge group |

## 知識來源與第一性原理

einvoice 與 PC 的根本分叉：einvoice 輸入是 pure DTO、框架無關、可 throw；PC 輸入是 `\WC_Order`、綁 WC hook 生命週期、provider 層 never-throw。**導入優點不是把 PC 改寫成 einvoice，而是在尊重 PC 既有契約的前提下，移植 einvoice 的 4 個局部最優設計。**

einvoice 經原始碼佐證的 4 個設計優點：
1. **正規化錯誤模型** — 單一 `InvoiceError extends Error`，帶 `code`（9 種正規化 enum）+ `rawCode`/`rawMessage`/`raw` + `isInvoiceError` type guard。各 adapter 把自家錯誤碼 map 成正規化 code。
2. **共用執行期驗證** — 所有 adapter `issue()` 第一行跑共用 Zod schema（UBN checksum / 載具格式 / 互斥 / 金額守恆），跨 provider 一致。
3. **有狀態 MockProvider** — in-memory 狀態機（issue→void→CONFLICT、雙索引、真跑驗證），是 fake 不是 stub。
4.（capability 細粒度化為 einvoice 第 5 項優點，**本次不做** — 不在 Q1 定案的 4 項內。）

訪談期間讀原始碼核實的精確事實（避免實作期誤判）：
- 資訊塌縮實證：`Ezpay/Services/EzpayInvoiceProvider.php` 13 處 `return []` 涵蓋驗證失敗 / 型別不符 / 例外 / 未開立 / 無折讓，全塌縮成同一回傳。
- AES 抽取邊界：`Invoice/Ecpay/AesCrypto`（AES-128-CBC base64）與 `Payment/Ecpg/AesCrypto`（AES-128-CBC base64）演算法相同 → 可合併；Logistics 已複用 `Payment/Ecpg`（erm.dbml line 337）。`Ezpay/AesCrypto`（AES-256-CBC hex blocksize=32）docblock 明確警告不可合併 → 排除。
- Payment 已有 WP_Error 先例：`IPaymentProvider::process_refund()` 回 `bool|\WP_Error`，docblock「不應 throw」。新模型形式化並豐富化。
- REST 現況：`InvoiceApiService` 直接 `WP_REST_Response($result, 200)`，不檢查失敗 → 改造後須 `is_wp_error()` 偵測並映射。

## 正規化 code 值域（領域中立，發票 + 金流共用）

放 `inc/classes/Shared/`（建議 `Shared/Errors/`）。backed enum（string）。

| code（enum value） | einvoice 對應 | 發票場景 | 金流場景 | REST HTTP 映射 |
|--------------------|--------------|---------|---------|----------------|
| `AUTH` | AUTH | 金鑰 / JWT 錯（如 KEY10002） | 商店憑證 / 簽章金鑰錯 | 401 |
| `VALIDATION` | VALIDATION | UBN checksum / 載具格式 / 互斥 / 金額守恆 | 退款金額不合法 / 必填缺 | 422 |
| `NOT_FOUND` | NOT_FOUND | 查無發票 | 查無交易 | 404 |
| `CONFLICT` | CONFLICT | 重複開立 / 已作廢 / 已開折讓（LIB10007） | 重複處理 / 狀態衝突 | 409 |
| `NUMBER_EXHAUSTED` | NUMBER_EXHAUSTED | 字軌號碼用罄 | （發票專屬） | 409 |
| `SIGNATURE` | （PC 新增，einvoice 無） | CheckCode 驗章失敗 | CheckMacValue / HMAC / HashInfo 驗簽失敗 | 400 |
| `UNSUPPORTED` | UNSUPPORTED | 不支援折讓 / 查詢 | 退款不支援 / capture/void no-op | 400 |
| `NETWORK` | NETWORK | API 連線失敗 / 逾時 | 同 | 502 |
| `PROVIDER` | PROVIDER | provider 回未分類錯誤碼 | 同 | 502 |
| `UNKNOWN` | UNKNOWN | 未預期 `\Throwable` | 同 | 500 |

> 比 einvoice 多 `SIGNATURE`（驗章失敗在 PC 金流是高頻且語義獨立的失敗類別），其餘 9 種對齊 einvoice。
> HTTP 映射為建議值，實作期可微調（Phase 04 api.yml 落定）。

## 各 provider 錯誤碼 → 正規化 code 映射（實作期 error-map）

| Provider | 原始錯誤碼形態 | 映射策略 |
|----------|---------------|---------|
| Amego | 數字錯誤碼區間 | 依區間映射（對齊 amego skill 錯誤碼表）；驗章相關→SIGNATURE；認證→AUTH |
| Ecpay（發票） | RtnCode + 中文 RtnMsg | RtnCode 映射 + 中文訊息 regex 補強；CheckMacValue 不符→SIGNATURE |
| Ezpay | LIB1000x / KEY1000x / 文字訊息 | LIB10007→CONFLICT、KEY10002→AUTH、未涵蓋→PROVIDER；CheckCode 不符→SIGNATURE |
| Paynow（發票） | type + message（JWT） | type 非 success 映射；JWT/認證→AUTH |
| Payment 各金流 | 各家退款 API 回應碼 | 退款不支援→UNSUPPORTED；憑證錯→AUTH；逾時→NETWORK；callback 驗簽→SIGNATURE（僅記 note，不改 always-200） |

## 概覽

| 類型 | 數量 |
|------|------|
| Create | feature ×5（invoice ×3、payment ×1、shared ×1）、activity ×1 |
| Modify | feature ×2（invoice-issue、invoice-cancel）、activity ×1（ezPay 生命週期）、actor ×0 |
| Delete | 0 |

Phase 01 Discovery 已完成的檔案異動見 §Discovery 產出清單（文末）。

## Phase 02: Entity Modeling（erm.dbml）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Enum normalized_error_code` | 正規化 code 值域（10 值，領域中立；發票 + 金流共用） |
| create（可選） | note on `wc_order_meta_invoice` | 失敗不寫 issued_data 仍成立；無新 meta key（錯誤以 WP_Error 回傳，不落 meta） |

> 本次「不」新增自訂資料表、不新增 order meta key（錯誤是回傳值，不是持久化狀態）。erm.dbml 僅增 enum。

## Phase 03: BDD Analysis（句型對齊）

| 操作 | 目標 | 說明 |
|------|------|------|
| analyze | invoice-error-model / invoice-validation / invoice-mock-statemachine | 新句型：「回傳值是 WP_Error」「WP_Error 的 code 為 X」「error_data 包含 raw_code」「統一驗證層驗證」「MockProvider 對訂單 X 開立」 |
| analyze | payment-error-model | 新句型：「process_refund 回傳 WP_Error」「callback 仍回 HTTP 200」 |
| analyze | ecpay-aes-shared | 新句型：「密文位元組一致」「解密結果與原始明文一致」 |
| re-analyze | invoice-issue / invoice-cancel（modify） | 新增的正規化錯誤斷言句型 |

## Phase 04: API Contract（api.yml）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | `POST /invoices/issue/{id}` 錯誤回應 | 錯誤回應 schema 補 `error_code`（正規化 code）+ `raw_code` + `message`；狀態碼依 code 分類（見 §值域 HTTP 映射） |
| modify | `POST /invoices/cancel/{id}` 錯誤回應 | 同上 |
| modify | `POST /invoices/allowance/{id}`、`allowance-cancel/{id}`、`GET /invoices/query/{id}` | 同上（折讓 / 查詢失敗亦回正規化錯誤） |
| modify | `POST /refund`、`POST /refund/manual` 錯誤回應 | 退款失敗回應補 `error_code` + `message`（Payment 領域） |
| create | shared `NormalizedError` schema | `{ error_code: enum, raw_code?: string, message: string }` |

## Phase 05-06: Implementation（後端 + 前端 + MSW）

### 後端核心（Shared 領域中立）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Shared/Errors/ErrorCode.php` | backed enum（string），10 個正規化 code |
| create | `Shared/Errors/NormalizedError.php`（或 factory） | 建構 `\WP_Error`：$code = ErrorCode value，$data = raw_code/raw_message/provider/raw；附 `is_normalized_error()` type guard + getter |

### 後端 #1 錯誤模型（Invoice）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | `Invoice/Shared/Interfaces/IInvoiceService.php` | issue/cancel 回傳型別 `array` → `array|\WP_Error`；docblock 載明成功 array / 失敗 WP_Error |
| modify | `Invoice/Shared/Interfaces/ISupportsAllowance.php` | issue_allowance/invalid_allowance 回傳 `array` → `array|\WP_Error` |
| modify | `Invoice/Shared/Interfaces/ISupportsQuery.php` | query_invoice 回傳 `array` → `array|\WP_Error` |
| modify | `Invoice/Ezpay/Services/EzpayInvoiceProvider.php` | 13 處 `return []` → 回對應正規化 WP_Error；新增 ezPay error-map（LIB/KEY/CheckCode） |
| modify | `Invoice/Ecpay/Services/EcpayInvoiceProvider.php` | 同上；新增 ECPay error-map（RtnCode + 中文 regex + CheckMacValue） |
| modify | `Invoice/Amego/Services/AmegoProvider.php` | 同上；新增 Amego error-map（數字區間） |
| modify | `Invoice/Paynow/Services/PaynowInvoiceProvider.php` | 同上；新增 PayNow error-map（type + JWT） |
| modify | `Invoice/Shared/Services/InvoiceApiService.php` | 5 端點 callback：`is_wp_error($result)` → 映射為錯誤回應（error_code + raw_code + message + HTTP code）；成功維持 200 |
| modify | `Invoice/ProviderRegister.php` | auto-issue / auto-cancel hook：接 WP_Error 時記 order note，不向 hook 拋（never-throw 保留） |
| modify | `woocommerce_order_refunded` 折讓 hook（provider-agnostic 層） | 接 WP_Error 時記 order note，不中斷退款流程 |

### 後端 #2 驗證層（Invoice）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify/extend | `Invoice/Shared/Helpers/InvoiceParamsValidator.php`（或新增 dispatch 級 validator） | 既有 checkout 表單級驗證之上，補：UBN checksum（財政部演算法，現只驗 8 碼格式）、載具/捐贈互斥（dispatch 級）、金額守恆（salesAmount+taxAmount===totalAmount） |
| modify | 各 provider `issue()` 第一步 | 呼叫統一驗證層；失敗即回 `WP_Error(VALIDATION)`，不打第三方 API |

### 後端 #1 錯誤模型（Payment，Q3 外溢）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | `Payment/Shared/Interfaces/IPaymentProvider.php` | process_refund docblock 載明：失敗回 `\WP_Error`，$data 帶正規化 code（型別已是 `bool|\WP_Error`，無需改簽名） |
| modify | `Payment/Shared/Abstracts/AbstractPaymentGateway.php` | 退款不支援的安全預設 → 回 `WP_Error(UNSUPPORTED)`（取代裸 false / 裸 WP_Error）；capture/void no-op 不變 |
| modify | 各金流 `process_refund()`（Payuni / Paynow / Ecpg / EcpayAIO / Shopline 等） | 退款失敗回正規化 WP_Error；退款金額守恆→VALIDATION；憑證→AUTH；逾時→NETWORK |
| modify | `Payment/Shared/Services/PaymentApiService.php`（REST /refund） | `is_wp_error()` → 映射為錯誤回應（error_code + message） |
| no-change | 各金流 NotifyURL / Webhook callback | **always-200 不變**；驗簽失敗僅記 order note（可內部用 SIGNATURE 標示），不改 HTTP 回應 |

### 後端 #4 AES 抽取

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Shared/Helpers/EcpayAesCrypto.php`（領域中立） | 從 `Payment/Ecpg/AesCrypto` 提升（AES-128-CBC + base64）；建構子注入 HashKey/HashIV |
| modify | `Invoice/Ecpay/Shared/Helpers/AesCrypto.php` | 改為轉呼叫 / 替換為共用 helper（保證密文位元組一致） |
| modify | `Payment/Ecpg/Shared/Helpers/AesCrypto.php` | 同上 |
| modify | `Logistics/Ecpay/Http/LogisticsApiClient.php` | 改用共用 helper（原複用 Payment/Ecpg） |
| no-change | `Invoice/Ezpay/Shared/Helpers/AesCrypto.php` | **排除**（AES-256-CBC，不可併） |

### 後端 #3 狀態機 MockProvider

| 操作 | 目標 | 說明 |
|------|------|------|
| create | 測試用 `MockInvoiceProvider`（測試命名空間 / fixtures） | 實作 IInvoiceService + ISupportsAllowance + ISupportsQuery；in-memory 狀態（issue→已開立 / void→已作廢）；雙索引（orderId ↔ invoice_number）；真跑統一驗證層；以正規化 code 回 WP_Error；重複 issue/void→CONFLICT；void 未開立→NOT_FOUND。不更動 API_MODE 管線 |

### 前端（Vue InvoiceApp / MetaBox + RefundDialog）

| 操作 | 目標 | 說明 |
|------|------|------|
| modify | InvoiceApp / Invoice MetaBox 錯誤顯示 | 解析回應的 `error_code` + `message`，顯示精確錯誤（取代既有泛用失敗訊息）；ElNotification 由 interceptor 處理 |
| modify | RefundDialog 錯誤顯示 | 退款失敗解析 `error_code`（如 UNSUPPORTED → 提示手動退款） |
| modify（MSW，若有前端測試層） | `src/mocks/handlers/` invoice/refund handler | 對每個正規化 error_code 加錯誤分支（對齊 api.yml 錯誤回應） |

## Phase 07-08: Test Plan（Q4 = 嚴格）

掛 group：`error` / `edge`（+ 既有 `invoice` / provider 名）。位置：`tests/Integration/`（active suite）。API_MODE=mock。

| 測試類別 | 覆蓋 | group |
|----------|------|-------|
| 錯誤碼映射（每 provider） | Amego / Ecpay / Ezpay / Paynow 各自原始錯誤碼 → 正規化 code（含 SIGNATURE / CONFLICT / AUTH / NUMBER_EXHAUSTED / PROVIDER） | error |
| 驗證層 | UBN checksum（合法/不合法）、手機條碼格式、載具/捐贈互斥、金額守恆（守恆/不守恆） | error, edge |
| WP_Error 契約 | 成功回 array、失敗回 WP_Error 帶 code/raw_code/provider；type guard 可辨識 | error |
| REST 映射 | issue/cancel/refund 端點 WP_Error → error_code + raw_code + HTTP code；成功維持 200 | error |
| never-throw | provider 內部 \Throwable → 回 UNKNOWN，不向 hook 傳播；留 order note | edge |
| Payment 退款 | UNSUPPORTED / VALIDATION / AUTH / NETWORK 映射；callback always-200 不變（驗簽失敗仍 200） | error, edge |
| MockProvider 狀態機 | issue→void→CONFLICT、void 未開立→NOT_FOUND、重複→CONFLICT、雙索引查詢、開立前真跑驗證 | edge |
| AES 等價 | 共用 helper 密文與原 Invoice/Ecpay + Payment/Ecpg 位元組一致；解密回原文；ezPay 不受影響 | edge |
| Amego 補漏 | 補齊既有缺漏的 Amego provider 測試（對齊 ezPay/Paynow 覆蓋度） | invoice |
| 回歸 | 既有全套測試（~1157）不退化，保持全綠 | （既有 group） |

## 受影響既有呼叫端清單（須加 `is_wp_error()` 判斷）

1. `Invoice/ProviderRegister.php` — auto-issue（`woocommerce_order_status_{status}`）/ auto-cancel hook
2. `woocommerce_order_refunded` 折讓 hook（provider-agnostic 退款自動折讓層）
3. `Invoice/Shared/Services/InvoiceApiService.php` — 5 個 REST 端點 callback
4. `Payment/Shared/Services/PaymentApiService.php` — REST /refund、/refund/manual
5. Vue `InvoiceApp` / Invoice `MetaBox` / `RefundDialog` 前端錯誤顯示
6.（MSW handlers，若前端測試層存在）

## 風險與緩解

| 風險 | 緩解 |
|------|------|
| 改 IInvoiceService 回傳型別波及所有 provider + 呼叫端 | PHPStan L9 會在編譯期抓出未處理 WP_Error 的呼叫端；逐一加 `is_wp_error()` |
| AES 抽取若密文不一致 → 第三方解密失敗（KEY10002） | 加密等價測試為 gating（位元組比對原實作）；ezPay 明確排除 |
| Payment callback 誤改 always-200 → 第三方重送風暴 | feature 硬約束 + 測試斷言 callback 失敗仍回 200；錯誤模型只用於 process_refund/REST |
| 既有 feature 失敗斷言（回應狀態碼 500）與新正規化斷言衝突 | modify 既有 feature：保留語義、演進為 error_code 斷言（已於 Phase 01 完成 invoice-issue/cancel） |

## Discovery 產出清單（Phase 01 已完成）

**Create**
- `specs/activities/發票錯誤模型與驗證流程.activity`
- `specs/features/invoice/invoice-error-model.feature`
- `specs/features/invoice/invoice-validation.feature`
- `specs/features/invoice/invoice-mock-statemachine.feature`
- `specs/features/payment/payment-error-model.feature`
- `specs/features/shared/ecpay-aes-shared.feature`
- `specs/clarify/2026-06-18-1100.md`

**Modify**
- `specs/features/invoice/invoice-issue.feature`（補開立失敗正規化錯誤斷言）
- `specs/features/invoice/invoice-cancel.feature`（補作廢失敗正規化錯誤斷言）
- `specs/activities/ezPay電子發票生命週期.activity`（失敗分支改 WP_Error + 交叉引用）

**待後續 Phase 處理**（不在 Discovery 範圍）
- erm.dbml（Phase 02）：新增 `normalized_error_code` enum
- api.yml（Phase 04）：錯誤回應補 error_code/raw_code，新增 NormalizedError schema
