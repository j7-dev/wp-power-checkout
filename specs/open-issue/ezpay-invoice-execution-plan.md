# Execution Plan — ezPay 電子發票 provider 整合

> **[已實作完成 — 2026-06-08]** 全部功能已通過品質關卡並合入 `feat/ecpay-gateway-integration` 分支。
> 實作位置：`inc/classes/Domains/Invoice/Ezpay/`；前端：`js/src/pages/Invoices/Ezpay/`；測試：`tests/Integration/Invoice/Ezpay*`。

> Phase 01 Discovery 產出（clarifier）。後續 Phase 02-08 的 scope 依據。
> 起始狀態：**existing**（多 provider 共用發票抽象層已成熟：Amego + 綠界發票 ecpay）。
> 核心原則：ezPay 在既有 provider-agnostic 層下新增，與綠界發票 provider 對等；
> 差異僅在 **加密(AES-256-CBC/PKCS#7 blk32/hex) + envelope(MerchantID_/PostData_) + 欄位命名 + 生命週期 API**。
> 唯一 API reference：`.claude/skills/ezpay-invoice/`（EZP_INVI_1.2.1 標準版）。

## ⚠️ 決策來源聲明（待用戶覆核）

clarifier 以 sub-agent 身分執行，環境無法與用戶互動問答（`AskUserQuestion` 在 sub-agent 不可用、
非 CI 故 `gh issue comment` 不適用）。下列 6 個需求面以「有依據的推薦決策」落地（ASM，
依據為 ezPay 官方 skill / 既有 provider 架構 / 綠界發票對齊，**非憑空腦補**），記錄於
`specs/clarify/2026-06-08-1457.md`。**planner / 用戶接手時可一句話推翻任一項。**

| # | 需求面 | 推薦決策 | 依據 | 風險 |
|---|--------|---------|------|------|
| Q1 | 功能範圍 | 對齊綠界：issue + invalid + allowance(issue/invalid) + invoice_search。**不含** touch_issue(等待觸發/預約自動) 與 allowance_touch_issue(兩段式確認) | 既有兩 provider 能力一致；退款自動折讓 hook 可複用 | 低 — 若要 ezPay 特有兩段式開立需擴充 |
| Q2 | 開立時機 | 沿用 auto-issue（status hook + settings）+ 後台手動；預設 issue=`processing`、cancel=`refunded` | 與綠界/Amego 預設一致 | 低 — settings 可調 |
| Q3 | checkout 欄位 | 沿用共用 InvoiceParams/InvoiceApp/Validator；ezPay 載具映射既有 EIndividual | provider-agnostic 既有層 | 低 |
| Q4 | block checkout | 共用層 BlocksInvoiceIntegration 自動涵蓋，不另做 | provider-agnostic 既有層 | 低 |
| Q5 | B2B | 納入首期（B2C 含稅 / B2B 未稅金額換算） | checkout 已有公司欄位；綠界對齊 | 中 — B2B/B2C 金額性質不同是 ezPay 特有陷阱 |
| Q6 | 測試 | mock 為主(CI 安全) + sandbox(cinv.ezpay) 可選 + 離線 PHP harness | testing rule + 藍新 MPG 前例 | 低 — 本機 PHPUnit 受限見 memory |

## 概覽

| 類型 | 數量 |
|------|------|
| Create | Activity 1 + Feature 2（allowance, query）+ Actor 1 |
| Modify | Feature 2（invoice-issue, invoice-cancel 加 ezPay Rule）+ erm.dbml（enum + settings 表 + meta Note）+ api.yml（provider enum） |
| Delete | 無 |

## 載具映射表（結帳統一表單 → ezPay 參數）

| 結帳表單欄位 | ezPay 參數 | 備註 |
|-------------|-----------|------|
| invoiceType=individual + individual=cloud | CarrierType=2（ezPay 電子發票載具）| BuyerEmail 必填 |
| invoiceType=individual + individual=barcode | CarrierType=0（手機條碼）| CarrierNum=載具號碼，須 rawurlencode |
| invoiceType=individual + individual=moica | CarrierType=1（自然人憑證）| CarrierNum=憑證號碼 |
| invoiceType=individual + individual=paper | PrintFlag=Y（無載具）| CarrierType/LoveCode 皆空 |
| invoiceType=donate + donateCode | LoveCode=捐贈碼 | CarrierType 須空（互斥）|
| invoiceType=company + companyId | Category=B2B + BuyerUBN | PrintFlag 必填 Y；金額未稅 |

> ⚠️ 載具映射為 ASM（依 ezPay skill 載具規則 + 既有 EIndividual 推導），planner / 實作期須與用戶確認 cloud→CarrierType=2 的對應（雲端發票 vs ezPay 載具語義）。

## Phase 02: Entity Modeling（erm.dbml）✅ 已於 Discovery 增量

| 操作 | 目標 | 狀態 |
|------|------|------|
| modify | `Enum invoice_provider` 加 `ezpay` | ✅ done |
| create | `Table wp_options_ezpay_invoice_settings`（merchant_id/hash_key 32B/hash_iv 16B/mode + auto statuses + auto_allowance_on_refund）| ✅ done |
| modify | `Table wc_order_meta_invoice` 加 `_pc_allowance_data` + Note（ezPay 沿用共用 meta，序號存 issued_data JSON 內）| ✅ done |
| TODO | `Enum tax_type` 標 ezPay `9`=混合稅率（限 B2C）為非首期 | 待 entity-spec reconciler 評估（BDY） |

## Phase 03: BDD Analysis（features Examples）✅ 已於 Discovery 產出 Rules

| 操作 | 目標 | 狀態 |
|------|------|------|
| modify | `invoice/invoice-issue.feature` 加 ezPay 開立 Rule（B2C cloud/barcode/donate + B2B + 載具捐贈互斥 + CheckCode 驗章）| ✅ done |
| modify | `invoice/invoice-cancel.feature` 加 ezPay 作廢 Rule（+ 已開折讓不可作廢 LIB10007）| ✅ done |
| create | `invoice/ezpay-invoice-allowance.feature`（@command 開立/作廢折讓）| ✅ done |
| create | `invoice/ezpay-invoice-query.feature`（@query invoice_search + UploadStatus）| ✅ done |

> 註：折讓/查詢首次為發票 domain 建獨立 .feature，以 ezPay 為主體（背景用 ezpay）。綠界/Amego 折讓查詢規格留待各自需求補（scope 邊界 ASM）。

## Phase 04: API Contract（api.yml）✅ enum 已增量

| 操作 | 目標 | 狀態 |
|------|------|------|
| modify | `InvoiceIssueRequest.provider` enum 加 `ezpay` | ✅ done |
| TODO | ezPay settings schema（對等 AmegoSettings）| 待 api-spec reconciler（既有無 EcpaySettings schema，避免擴大 scope）|
| — | endpoints | **零新增**（沿用 `/invoices/issue｜cancel`，ProviderUtils 依 id 分派）|

> ⚠️ **planner 必讀**：既有 REST 只有 `/invoices/issue/{id}` + `/invoices/cancel/{id}`。
> **折讓與查詢無 REST 端點** — 綠界的 issue_allowance/query 目前僅被退款 hook
> `maybe_issue_allowance_on_refund` 內部呼叫 + 後台 metabox 查詢。
> ezPay 折讓走「退款自動折讓 hook」即可（無需新端點）；查詢走後台 MetaBox。
> 若要「手動折讓 UI」則需新端點 — 此為實作期決策（BDY），預設不做。

## Phase 05-08: Implementation（TDD scope）

> 目錄 `inc/classes/Domains/Invoice/Ezpay/`，1:1 對齊 `Invoice/Ecpay/` 結構，但加密層完全不同。

| 操作 | 目標 | 關鍵差異 / 注意 |
|------|------|----------------|
| create | `Ezpay/Shared/Helpers/AesCrypto.php` | **AES-256-CBC / key padEnd(32) / IV padEnd(16) / 自行補 PKCS#7(blk32) + OPENSSL_ZERO_PADDING / bin2hex 小寫**。query string 加密（非 json）。**禁複用** Ecpay AesCrypto（AES-128/base64）。可參照藍新 MPG `TradeInfoCrypto` 同套規則 |
| create | `Ezpay/Shared/Helpers/CheckCodeService.php` | 回應 CheckCode SHA256：5 欄位(InvoiceTransNo/MerchantID/MerchantOrderNo/RandomNum/TotalAmt) A-Z 排序 + `HashIV=..&{排序}&HashKey=..` → SHA256 大寫 |
| create | `Ezpay/Shared/Enums/` | EApi（7 端點 + 各自 Version：issue 1.5 / touch 1.0 / invalid 1.0 / allowance_issue 1.3 / allowance_touch 1.0 / allowanceInvalid 1.0 / search 1.3）、ETaxType(1/2/3/9)、ECarrierType(0/1/2)、ECategory(B2B/B2C)、ECustomsClearance(1/2)、ERespondType(JSON/String) |
| create | `Ezpay/DTOs/` | EzpayInvoiceSettingsDTO、IssueParams（含 B2B/B2C 金額換算 + 載具映射 + 多商品 \| 分隔）、CancelParams、IssueResponse、AllowanceParams、AllowanceInvalidParams、QueryParams、QueryResponse |
| create | `Ezpay/Http/InvoiceApiClient.php` | issue/cancel/issue_allowance/invalid_allowance/query + build_envelope（MerchantID_ + PostData_）+ CheckCode 驗章 + mock 系列（對齊 Ecpay client method 清單）|
| create | `Ezpay/Services/EzpayInvoiceProvider.php` | implements IInvoiceService + ISupportsAllowance + ISupportsQuery；const ID='ezpay'；冪等(沿用 MetaKeys)+ build_issued_data |
| modify | `Invoice/ProviderRegister.php` | `$invoice_providers` 加 `EzpayInvoiceProvider::ID => EzpayInvoiceProvider::class` |
| create | `js/src/pages/Invoices/Ezpay/index.vue` | 設定頁（merchant_id/hash_key/hash_iv/mode + auto_issue/cancel statuses + auto_allowance 開關）。Element Plus only |
| modify | `js/src/router/index.ts` | route `/invoices/ezpay` + `ROUTER_MAPPER.ezpay` |
| create | `inc/tests/Domains/Invoice/Ezpay/` | AesCrypto round-trip(對 ezPay 官方範例驗證)、CheckCode、issue/cancel/allowance/query mock、金額計算(B2B 未稅/B2C 含稅)、載具映射、冪等、CheckCode 不符不寫入 |

## 第一性原理提醒（給實作 agent）

1. **加密是最大陷阱**：ezPay AES-256-CBC + hex 與綠界 AES-128 + base64 完全不同。
   官方範例 `openssl_encrypt(addpadding($str), 'AES-256-CBC', $key, OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING, $iv)`
   — 先自行補 PKCS#7(blk32) 再用 ZERO_PADDING。重複補或不補都會 KEY10002。
2. **金額方向**：B2C 的 ItemPrice/ItemAmt 含稅、B2B 未稅。弄反金額會錯。平台只檢核
   `ItemAmt=ItemCount×ItemPrice` 與 `TotalAmt=Amt+TaxAmt`。
3. **Status=SUCCESS ≠ 財政部上傳成功**：上傳結果須查 invoice_search 的 UploadStatus。
4. **MerchantOrderNo 冪等**：同商店不可重覆；相同 PostData_ 重送回原發票。建議用 order id 衍生。
5. **本機測試受限**（見 memory newebpay-mpg）：composer test bootstrap fatal → 用 PHPStan L9 +
   離線 PHP harness 驗純邏輯（crypto/CheckCode/金額/載具映射）；crypto 建議用真實 cinv.ezpay sandbox 端到端驗一次。

## Hand-off

→ `@zenbu-powers:planner`：依本 Execution Plan 制定分階段實作計劃（TDD）。
specs 路徑：`specs/`（features/invoice/、activities/ezPay電子發票生命週期.activity、actors/ezPay.md、erm.dbml、api.yml）。
**請先向用戶覆核上方 6 項推薦決策（Q1-Q6）再開工。**
