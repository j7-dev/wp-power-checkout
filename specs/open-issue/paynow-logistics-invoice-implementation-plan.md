# 實作計劃：PayNow（立吉富）物流 + 電子發票（統一介面）

> Planner 產出（2026-06-10）。基於 Phase 01 Discovery（clarifier）規格制定。
> Hand-off：`@zenbu-powers:tdd-coordinator`（TDD Red→Green→Refactor）。
> 範圍模式：**EXPANSION（受控）** — 兩個全新 provider，但邊界由既有 `ILogisticsProvider` / `IInvoiceService` 介面鎖死，零介面破壞。

---

## 概述

為 power-checkout 新增兩個 PayNow provider：

1. **PayNow 物流**（`paynow_logistics`）— 實作 `ILogisticsProvider`（10 methods），第三個物流 provider（與 `ecpay_logistics` / `payuni_logistics` 並存）。超商取貨（7-11/全家/HiLife）+ 黑貓宅配（TCAT）；TripleDES DES-EDE3 加密；後台手動出貨。
2. **PayNow 電子發票**（`paynow_invoice`）— 實作 `IInvoiceService` + `ISupportsAllowance` + `ISupportsQuery`，第四個發票 provider（與 Amego / `ecpay` / `ezpay` 並存）。Bearer JWT-Token；issue/cancel/allowance/cancel-allowance/query；能力等級同 ezPay。

兩條線可**平行或序列**實作（無共用程式碼，僅共用 register 慣例）。本計劃將其拆為兩條獨立 cycle 鏈，各自可獨立交付、獨立合併。

---

## 需求重述

- **物流**：顧客結帳選 PayNow 超商/宅配 → 選店（超商）→ 管理員後台手動建單取 LogisticNumber → 查詢/列印/取消。逆物流 throw 尚未實作。
- **發票**：訂單狀態變更（預設 processing）自動開立 → 退款自動折讓 → 後台查詢/作廢。沿用 provider-agnostic 共用層（結帳表單 / order meta / REST / 退款 hook），僅 provider id 不同。
- **驗收主軸**：API_MODE=mock 全綠（sandbox 憑證未申請，GAP）；9 支 feature 對映測試全數通過。

---

## 已知風險（來自研究 — 實讀 woomp / PAYUNi / ezPay 程式碼確認）

| # | 風險 | 嚴重度 | 緩解措施 |
|---|------|--------|---------|
| R1 | **⚠️ 物流貨態 webhook 存在性與 Execution Plan 矛盾** | 高 | Execution Plan 標「PayNow 無 webhook 推送證據 → handle_status_callback 退化為查詢補單」。**但實讀 woomp `class-paynow-shipping-response.php` L34 發現 PayNow 確實會推送貨態到 `wc-api=shipping_status_callback`（`paynow_receive_order_status_update`），payload 含 `orderno` / `PayNowLogisticCode` / `Detail_Status_Description` / `paymentno` / `StoreDate` / `StoreTime`，以 `orderno` 反查訂單**。決策見「架構變更 §物流貨態通知」——首期仍以「查詢補單」為主（feature 對映），但**新增 PaynowLogisticsCallback 接收貨態推送端點**（woomp 有實證，非腦補），兩者並存。`handle_status_callback()` 實作為「解析推送 payload + 更新 meta」，非退化為查詢。 |
| R2 | **TripleDES 有兩種模式，不可混用** | 高 | 實讀 woomp 確認：(a) **Add_Order JsonOrder** = `DES-EDE3`（CBC，預設）+ `OPENSSL_NO_PADDING` + 手動 `\0` zero-pad 到 8 byte 邊界 + `base64`（`class-paynow-shipping-request.php` L716-751）；(b) **選店 apicode** = `DES-EDE3-ECB` + `OPENSSL_RAW_DATA \| OPENSSL_ZERO_PADDING` + 手動 `\0` pad + `base64` + `str_replace(' ','+',...)`（`class-paynow-shipping.php` L243-253）。**兩者 helper 須各提供獨立方法**，測試各自驗 round-trip。 |
| R3 | **固定 key/IV 疑似公開測試值** | 中 | key=`123456789070828783123456`(24B) iv=`12345678`。woomp 寫死，疑似全商家共用測試值。本專案 helper 以常數寫入但**標 GAP**：prod 須向 PayNow 確認是否換鑰；若換鑰，key/IV 須移至 settings。 |
| R4 | **meta-key 前綴策略：PAYUNi 復用 `_pc_logistics_*` vs PayNow 規格要 `_pc_paynow_logistics_*`** | 高 | 實讀確認 `PayuniLogisticsProvider` 復用 shared `LogisticsMetaKeys`（`_pc_logistics_*`），且 `LogisticsApiService::PROVIDER_IDS` resolver 假設所有 provider 共用同一組 meta。**但 PayNow 語意不同**（LogisticNumber/sno/paymentno/validationno vs ECPay TempLogisticsID/CVSPaymentNo），且 9 支 feature 顯式斷言 `_pc_paynow_logistics_*`。決策見「架構變更 §meta key」——**PayNow 自建 `PaynowLogisticsMetaKeys`（前綴 `_pc_paynow_logistics_`），並在 `LogisticsApiService::PROVIDER_IDS` 加入 `paynow_logistics`**；但因 PayNow 用獨立 meta，`get_order_by_ref` 反查須在 PayNow 自己的 callback 內處理（不依賴 shared `LogisticsMetaKeys::get_order_by_ref`）。 |
| R5 | **發票 provider ID 與金流 gateway 同名 `paynow`** | 中（已裁決） | orchestrator 已裁決：發票用 `paynow_invoice` + option `woocommerce_paynow_invoice_settings`。⚠️ 但 9 支 feature 與 activity 內仍以 `"paynow"` 表示發票 provider（CON 未回寫）。**實作 const ID='paynow_invoice'**；測試斷言 `_pc_invoice_provider_id === 'paynow_invoice'`（非 feature 寫的 `paynow`）。feature/activity 文字待 Phase 03 reconcile 修正（標記，非阻塞）。 |
| R6 | **PassCode 規則來源** | 低 | woomp L780 實證：`strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))`。`TotalAmount` 用 `$order->get_total()` 原值（含小數，如 "1000" 或 "1000.00"）。⚠️ sha1 對字串敏感——測試須鎖定 `get_total()` 的字串格式（WC 回傳 string），與 feature 場景的 `1000` 對齊（須確認是否帶小數）。 |
| R7 | **OrderNo 前綴** | 低 | woomp 用 `apply_filters('paynow_shipping_order_prefix','')` + `$order->get_order_number()`（預設無前綴）。本專案用 `$order->get_order_number()`；PassCode 與 Add_Order 的 OrderNo 須一致。 |
| R8 | **api_url 測試/正式網域** | 低（已解 GAP） | woomp L154 實證：test=`https://testlogistic.paynow.com.tw` / prod=`https://logistic.paynow.com.tw`。解決 feature 中「test 環境 api_url 待確認」的 GAP。⚠️ 與金流 `sandboxapi.paynow.com.tw` / 發票 `invoiceapi-*.paynow.com.tw` 三者網域完全不同。 |
| R9 | **超取/宅配金額上限** | 低 | woomp 註解：超取 ≤ 20000、宅配 ≤ 100000。feature 要求超限回明確錯誤（前端擋 or 建單前擋）。實作於 `create_shipment` 前置驗證（依 service 類型判上限）。 |
| R10 | **發票稅額計算（tax_amount）** | 中 | feature：非統編 `tax_amount=0`（國稅局算）；統編帶實際稅額（自行算）。零稅率必填 `is_pass_customs` + `zero_tax_rate_reason`。載具與捐贈互斥。混稅須與財會確認（GAP，首期支援 SaleTax 為主）。 |

> 其餘未發現額外已知風險。

---

## 架構變更

### 物流 domain：`inc/classes/Domains/Logistics/Paynow/`（鏡像 `Logistics/Payuni/`）

```
Domains/Logistics/Paynow/
  Services/PaynowLogisticsProvider.php       # implements ILogisticsProvider；const ID='paynow_logistics'
  Services/WC_PaynowLogisticsShipping.php    # extends WC_Shipping_Method（classic 結帳；per-service 多運送方式）
  Http/LogisticsApiClient.php                # Add_Order / ReNewOrder / CancelOrder / Get_Order_Info / print（含 is_mock fixture）
  Http/LogisticsCallback.php                 # 選店 returnUrl callback + 貨態推送 callback（見 §貨態通知）
  DTOs/PaynowLogisticsSettingsDTO.php        # extends BaseSettingsDTO
  DTOs/CreateShipmentParams.php              # build_add_order_args 對應（DTO::parse）
  DTOs/StoreSelectionParams.php              # 選店導轉表單參數
  Shared/Helpers/TripleDesCrypto.php         # R2：兩方法 encrypt_order_json() + encrypt_apicode()
  Shared/Helpers/PassCodeService.php         # R6：sha1 PassCode
  Shared/Helpers/PaynowLogisticsMetaKeys.php # R4：前綴 _pc_paynow_logistics_
  Shared/Helpers/ItemName.php                # 商品名稱組裝（25 字截斷 + 特殊字元過濾，對齊 woomp）
  Shared/Enums/PaynowLogisticService.php     # 服務代碼 01-06 / 21-24
  Shared/Enums/PaynowDeliverMode.php         # 01=COD / 02=取貨不付款
  Shared/Enums/PaynowLogisticsStatus.php     # 0=成立中 / 1=無效 + 貨態碼（0101/5000/5201/5202/8000/8520）
```

### 發票 domain：`inc/classes/Domains/Invoice/Paynow/`（鏡像 `Invoice/Ezpay/`）

```
Domains/Invoice/Paynow/
  Services/PaynowInvoiceProvider.php   # implements IInvoiceService + ISupportsAllowance + ISupportsQuery；const ID='paynow_invoice'
  Http/InvoiceApiClient.php            # issue/cancel/allowance/cancel-allowance/query（Bearer JWT-Token；含 is_mock fixture）
  DTOs/PaynowInvoiceSettingsDTO.php    # extends BaseSettingsDTO（jwt_token / seller 設定 / mode / auto_issue_order_statuses / auto_allowance_on_refund）
  DTOs/IssueParams.php                 # 結帳發票參數 → PayNow issue body（carrier_type / tax_type / tax_amount）
  DTOs/AllowanceParams.php             # 折讓參數
  DTOs/QueryParams.php                 # 查詢參數
  DTOs/IssueResponse.php / AllowanceResponse.php / QueryResponse.php  # 回應 DTO
  Shared/Enums/ECarrierType.php        # None/PhoneBarCodeCarrier/EasyCardCarrier/CitizenDigitalCardNo/BuyerSno
  Shared/Enums/ETaxType.php            # SaleTax/FreeTax/ZeroTax/MixTax
  Shared/Enums/EZeroTaxReason.php      # 零稅率原因（ExportGoods 等）
```

> ⚠️ 發票**復用** shared `Invoice/Shared/Helpers/MetaKeys.php`（`_pc_issued_invoice_data` / `_pc_invoice_provider_id` / `_pc_allowance_data` / `_pc_cancelled_invoice_data`）—— provider-agnostic，無需新增 meta helper。

### 註冊變更

| 檔案 | 變更 |
|------|------|
| `Domains/Logistics/ProviderRegister.php` | `$logistics_providers` 加 `PaynowLogisticsProvider::ID => ::class`；`add_shipping_method()` 加 `WC_PaynowLogisticsShipping`；`save_checkout_meta()` 委派；callback 註冊段加 `if (is_enabled(paynow_logistics)) PaynowLogisticsCallback::register_hooks()` |
| `Domains/Logistics/Shared/Services/LogisticsApiService.php` | `PROVIDER_IDS` 加 `'paynow_logistics'`（REST 委派可解析；⚠️ PayNow 用獨立 meta，print/cancel/query 委派正常，但 callback 反查走 PayNow 自己的 callback class） |
| `Domains/Invoice/ProviderRegister.php` | `$invoice_providers` 加 `PaynowInvoiceProvider::ID => ::class`（auto-issue / auto-cancel / 退款折讓 hook 自動套用，無需改 hook 邏輯） |
| `js/src/router/index.ts` + `ROUTER_MAPPER` | 加 `/logistics/paynow_logistics` + `/invoices/paynow_invoice` route |
| `js/src/pages/Logistics/Paynow/index.vue` | 物流設定頁（鏡像 `Logistics/Ecpay/index.vue`） |
| `js/src/pages/Invoices/Paynow/index.vue` | 發票設定頁（鏡像 `Invoices/Ezpay/index.vue`） |

### §物流貨態通知（R1 決策）

實讀 woomp 後修正 Execution Plan 的「無 webhook」假設：

- **PayNow 確實推送貨態**到 `wc-api=shipping_status_callback`（woomp 證據）。本專案改用 REST 端點 `POST /wp-json/power-checkout/paynow/logistics/status-callback`（permission `__return_true`，鏡像 ECPay/PAYUNi callback namespace 慣例）。
- `handle_status_callback()` 實作為**解析推送 payload**（`orderno` / `PayNowLogisticCode` / `Detail_Status_Description` / `paymentno` / `StoreDate` / `StoreTime`）→ 以 `orderno` 反查訂單（PayNow 自己的 OrderNo→order 反查，非 LogisticNumber）→ 更新 `_pc_paynow_logistics_*` meta + 冪等防重 → 回 HTTP 200（PayNow 確認收到）。
- `query_shipment()`（主動查詢）仍保留，作為補單手段（feature `paynow-logistics-query` 對映）。兩者並存：webhook 為主、查詢為輔。
- ⚠️ **GAP**：webhook payload 簽章驗證（是否帶 PassCode / user_account 防偽）woomp 無證據 → 比照 ECPay selection-callback（permission `__return_true`，內部解資料），**並評估以 orderno 存在性 + meta 一致性作為弱驗證**；強驗證待官方文件。
- ⚠️ **與 feature 對映調整**：原 `paynow-logistics-query.feature` 的「handle_status_callback 退化為查詢補單」斷言，改為「query 為補單手段；另有貨態推送 callback」。Phase 03 reconcile feature（標記，非阻塞 TDD）。

### §meta key（R4 決策）

PayNow 物流**自建** `PaynowLogisticsMetaKeys`（前綴 `_pc_paynow_logistics_`），不復用 shared `LogisticsMetaKeys`（`_pc_logistics_*`）：

| PayNow meta key | 對應 woomp / 用途 |
|-----------------|------------------|
| `_pc_paynow_logistics_provider_id` | `paynow_logistics` |
| `_pc_paynow_logistics_service_id` | Logistic_serviceID（01-06/21-24，結帳寫入） |
| `_pc_paynow_logistics_store_id` / `_store_name` / `_store_addr` | 選店門市（callback 寫入） |
| `_pc_paynow_logistics_ref` | LogisticNumber（PayNow 物流單號；下游主鍵） |
| `_pc_paynow_logistics_sno` | sno（物流單序號，預設 "1"） |
| `_pc_paynow_logistics_payment_no` | paymentno（物流商託運單號） |
| `_pc_paynow_logistics_validation_no` | validationno（物流商驗證碼） |
| `_pc_paynow_logistics_renew_order_no` | RenewOrderNo（重新取號後 PayNow 訂單編號；列印用） |
| `_pc_paynow_logistics_status` | 0=成立中 / 1=無效 |
| `_pc_paynow_logistics_delivery_status` | Delivery_Status（描述） |
| `_pc_paynow_logistics_logistic_code` | PayNowLogisticCode（貨態碼） |
| `_pc_paynow_logistics_delivery_type` | DeliveryType（黑貓溫層 0003 等） |
| `_pc_paynow_logistics_collection_paid` | COD 取貨付款完成（yes） |
| `_pc_paynow_logistics_processed_status` | 冪等防重陣列（"{OrderNo}:{LogisticCode}"） |

> ⚠️ 因 PayNow 用獨立 meta，`PaynowLogisticsMetaKeys` 須自帶 `get_order_by_order_no(string $order_no)`（貨態 callback 用 OrderNo 反查）與 `get_order_by_ref(string $logistic_number)`（保留）。

---

## 資料流分析

### 流程 1：物流選店（get_store_selection → parse_store_selection）

```
顧客選運送方式 ──▶ 點「選擇超商」 ──▶ get_store_selection ──▶ 組裝導轉表單 ──▶ 瀏覽器 form-POST PayNow 地圖
      │                 │                    │                      │                       │
      ▼                 ▼                    ▼                      ▼                       ▼
 [非PayNow物流?]    [TCAT黑貓?跳過]    [provider未啟用?]      [apicode加密失敗?]        [選店後 returnUrl 回呼]
 [未選運送方式?]                     [訂單不存在?]          [sub_type非啟用?]               │
                                                                                            ▼
                                                                              parse_store_selection
                                                                                            │
                                                                          ┌─────────────────┼─────────────────┐
                                                                          ▼                 ▼                 ▼
                                                                   [缺 storeid?]      [cid對不到訂單?]    寫 store meta
                                                                   錯誤:缺門市資訊    （fallback?）       (_pc_paynow_logistics_store_*)
```

### 流程 2：物流建單（create_shipment）

```
管理員後台出貨 ──▶ create_shipment ──▶ build_add_order_args ──▶ TripleDES加密 ──▶ POST Add_Order ──▶ 解析回應
      │                  │                    │                     │                  │              │
      ▼                  ▼                    ▼                     ▼                  ▼              ▼
 [非PayNow物流?]   [超商無門市meta?]      [PassCode組裝]        [加密失敗?]        [傳輸層失敗?]   [Status=F?]
                  錯誤:尚未選店         [金額超上限?R9]                          throw          throw ErrorMsg
                  [已有有效單?冪等]      錯誤:超商>20000                                        + order note
                  → ReNewOrder                                                                       │
                                                                                                     ▼
                                                                                         [Status=S] 寫 LogisticNumber
                                                                                         + paymentno + validationno
                                                                                         + order note
```

### 流程 3：發票開立（issue，沿用 provider-agnostic）

```
訂單狀態變更 ──▶ auto issue hook ──▶ PaynowInvoiceProvider::issue ──▶ build IssueParams ──▶ Bearer POST issue ──▶ 解析
     │                │                       │                          │                     │            │
     ▼                ▼                        ▼                          ▼                     ▼            ▼
[非auto狀態?]    [已開立?冪等]          [載具+捐贈互斥?]            [tax_amount計算]      [傳輸層失敗?]  [type≠success?]
跳過             直接回 issued_data    錯誤:互斥                 [零稅率缺reason?]      catch→[]      不寫issued_data
                                       [統編→實際稅額]            錯誤:缺零稅率原因                    + order note
                                       [非統編→tax_amount=0]                                              │
                                                                                                          ▼
                                                                                            [type=success] 寫 issued_data
                                                                                            + provider_id=paynow_invoice
```

### 流程 4：物流貨態推送 callback（handle_status_callback，R1）

```
PayNow 推送貨態 ──▶ status-callback REST ──▶ 解析 payload ──▶ orderno 反查訂單 ──▶ 冪等防重 ──▶ 更新 meta ──▶ HTTP 200
      │                    │                      │                 │                │             │           │
      ▼                    ▼                       ▼                 ▼                ▼             ▼           ▼
 [payload空?]         [permission開放]        [缺 orderno?]    [查無訂單?]      [已處理?跳過]   [COD取貨完成?]  恆回200
 仍回200              （內部弱驗證）           仍回200          仍回200                         標記collection_paid (防重送)
```

---

## 錯誤處理登記表

### 物流

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|-------------|---------|---------|-----------|
| get_store_selection | provider 未啟用 | 業務 | throw → REST 403 | 是（錯誤訊息） |
| get_store_selection | 訂單不存在 | 業務 | throw → REST 404 | 是 |
| get_store_selection | sub_type 非啟用子類型 | 參數 | throw → REST 400 | 是 |
| get_store_selection | apicode TripleDES 加密失敗 | 傳輸 | throw + log openssl error | 是（通用訊息） |
| parse_store_selection | 缺 storeid | 參數 | throw「選店回呼缺少門市資訊」 | 是 |
| parse_store_selection | cid 對不到訂單 | 業務 | log + 回空（不 throw，避免回呼風暴） | 否（log） |
| create_shipment | 超商無門市 meta | 業務 | throw「尚未選店，無門市資訊」 | 是 |
| create_shipment | 金額超上限（R9） | 參數 | throw「超商取貨金額不得大於 20000」 | 是 |
| create_shipment | TripleDES 加密失敗 | 傳輸 | throw + log | 是（通用） |
| create_shipment | Status=F | 業務 | throw ErrorMsg + order note | 是 |
| create_shipment | 傳輸層失敗 | 傳輸 | throw + order note | 是（通用） |
| query_shipment | 無 ref | 業務 | throw「尚無物流單，無法查詢」 | 是 |
| print_document | 無 ref | 業務 | throw「尚無物流單，無法列印」 | 是 |
| cancel_shipment | 無 ref | 業務 | throw「尚無物流單，無法取消」 | 是 |
| cancel_shipment | 回應不含 'S' | 業務 | throw + order note 提示手動 | 是 |
| create_return | 任何呼叫 | 業務 | **throw `\Exception('尚未實作')`** | 是 |
| handle_status_callback | 任何例外 | 全部 | **catch → 仍回 HTTP 200**（防重送） | 否（log + order note） |

### 發票

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|-------------|---------|---------|-----------|
| issue | 訂單不存在 | 業務 | throw → REST 500「找不到訂單」 | 是 |
| issue | 已開立（冪等） | — | 直接回 issued_data，不打 API | 否 |
| issue | 載具+捐贈互斥 | 參數 | throw「載具與捐贈不可同時指定」 | 是 |
| issue | 零稅率缺 reason | 參數 | throw「零稅率發票必填零稅率原因」 | 是 |
| issue | type≠success | 業務 | catch → 回 []，不寫 issued_data + order note | 否（order note） |
| issue | 傳輸層 / \Throwable | 全部 | catch → log(error, order note) → 回 [] | 否（order note） |
| cancel | 已作廢（冪等） | — | 回 cancelled_data | 否 |
| issue_allowance | 未開立發票 | 業務 | log warning → 回 [] | 否 |
| issue_allowance | 金額不合法 | 參數 | log warning → 回 [] | 否 |
| issue_allowance | 全額退款 | — | hook 層判 remaining≤0 → 不開折讓（走作廢） | 否 |
| query_invoice | 未開立 | — | 回 []（不打 API） | 否 |
| query_invoice | type≠success | 業務 | 回 [] | 否 |

> **CRITICAL GAP 檢查**：無「處理方式=無 + 靜默」項目。所有靜默路徑皆有 log / order note。✅

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|--------|-----------|---------|
| TripleDES encrypt_order_json | NO_PADDING 未補滿 8 byte → openssl 回 false | 是（手動 `\0` pad） | 是（round-trip 測試） | 否 | log openssl error |
| TripleDES encrypt_apicode | ECB vs CBC 模式混用 | 是（獨立方法） | 是（兩方法各測） | 否 | — |
| PassCode | get_total() 小數格式不一致 → sha1 不符（R6） | 須鎖定 | 是（固定 total 測試） | 否（API 回錯） | 補單重送 |
| create_shipment 冪等 | 重複建單 → ReNewOrder | 是（status≠1 判斷） | 是 | 否 | — |
| status-callback | PayNow 重送同一貨態 | 是（processed_status 防重） | 是 | 否 | 冪等跳過 |
| status-callback | orderno 前綴不一致反查失敗（R7） | 須注意 | 是（反查測試） | 否 | log + 回 200 |
| issue 自動開立 | hook 重複觸發 | 是（issued_data 冪等） | 是 | 否 | — |
| issue tax_amount | 統編/非統編稅額算錯（R10） | 須驗 | 是（B2C/B2B 各測） | 是（發票錯） | 作廢重開 |
| 退款折讓 hook | 全額退款誤開折讓 | 是（remaining≤0 判斷） | 是（既有 hook 復用） | 否 | — |
| provider ID 衝突（R5） | paynow_invoice vs paynow gateway | 是（獨立 option key） | 是（register 測試） | 否 | — |

---

## 實作步驟

> 兩條線獨立。建議**先物流後發票**（物流複雜度高、風險集中；先解掉 TripleDES/webhook 不確定性）。每條線內部以 TDD cycle 推進（Red→Green→Refactor），每個 cycle 可獨立合併。

### 線 A：PayNow 物流（`paynow_logistics`）

#### A-Cycle 0：加密 + PassCode + Enums + MetaKeys 基礎（無外部依賴，純單元）

1. **TripleDesCrypto**（檔案：`Domains/Logistics/Paynow/Shared/Helpers/TripleDesCrypto.php`）
   - 行動：`encrypt_order_json(string $json): string`（~~DES-EDE3 CBC~~ → **R2 實證更正：DES-EDE3 ECB**，OpenSSL 無 -CBC 後綴時 IV 被忽略，實為 ECB 變體；NO_PADDING + 手動 `\0` pad 8B + base64）；`encrypt_apicode(string $apicode): string`（DES-EDE3-ECB + RAW_DATA|ZERO_PADDING + 手動 pad + base64 + `' '→'+'`）；常數 key/IV（標 GAP prod 換鑰）。
   - 原因：R2 兩模式不可混用，核心加密須先鎖死並驗 round-trip。
   - 依賴：無。風險：**高**（R2/R3）。
   - 測試：`PaynowTripleDesCryptoTest`（@group smoke @group security）— 兩方法各驗已知向量加密輸出 + 邊界（空字串 / 非 8B 倍數 / UTF-8 中文）。

2. **PassCodeService**（檔案：`.../Shared/Helpers/PassCodeService.php`）
   - 行動：`build(string $user_account, string $order_no, string $total, string $apicode): string` = `strtoupper(sha1(...))`。
   - 依賴：無。風險：**中**（R6 total 格式）。
   - 測試：`PaynowPassCodeTest`（@group happy）— 固定輸入驗固定 sha1 輸出。

3. **Enums**（`PaynowLogisticService` / `PaynowDeliverMode` / `PaynowLogisticsStatus`）
   - 行動：backed enum；service 代碼 01-06/21-24 + `is_cvs()` / `label()`；DeliverMode 01/02；Status 0/1 + 貨態碼常數。
   - 依賴：無。風險：低。測試：`PaynowLogisticsEnumTest`（@group happy）。

4. **PaynowLogisticsMetaKeys**（`.../Shared/Helpers/PaynowLogisticsMetaKeys.php`）
   - 行動：前綴 `_pc_paynow_logistics_` 全 getter/setter（R4 表）+ `get_order_by_order_no()` + `get_order_by_ref()` + 冪等 `is_processed()`/`mark_processed()`。
   - 依賴：無（HPOS 用 `$order->get_meta`）。風險：中（R4）。
   - 測試：`PaynowLogisticsMetaKeysTest`（@group happy @group edge）。

#### A-Cycle 1：Settings DTO + ApiClient（mock）

5. **PaynowLogisticsSettingsDTO**（`DTOs/PaynowLogisticsSettingsDTO.php`）
   - 行動：extends `BaseSettingsDTO`；欄位 user_account / apicode / mode / enabled_methods / sender_*（name/phone/address/email）/ api_url getter（R8 test/prod 切換）。
   - 依賴：Cycle 0。風險：低。測試：`PaynowLogisticsSettingsDTOTest`（@group happy）。

6. **CreateShipmentParams / StoreSelectionParams**（`DTOs/`）
   - 行動：`DTO::parse()` 組裝 Add_Order args（Description/DeliverMode/Logistic_service/user_account/apicode/OrderNo/Receiver_*/Sender_*/receiver_storeid/receiver_storename/PassCode/TotalAmount/EC + 黑貓 DeliveryType/Weight/Length/Width/Height）。
   - 依賴：Cycle 0。風險：中。測試：`PaynowCreateShipmentParamsTest`（@group happy @group edge）。

7. **LogisticsApiClient**（`Http/LogisticsApiClient.php`）
   - 行動：`add_order()` / `renew_order()` / `cancel_order()` / `query_order()` / `print_label()`；`is_mock()`（getenv API_MODE）回 fixture；真 API 用 `wp_remote_post/get/request`。
   - 原因：mock 為驗收主軸（sandbox 憑證 GAP）。
   - 依賴：Cycle 0 + DTOs。風險：中。
   - 測試：`PaynowLogisticsApiClientTest`（@group happy @group integration）— mock 模式驗請求組裝（JsonOrder base64 / DELETE method / query URL）+ fixture 回應解析。

#### A-Cycle 2：Provider（10 methods）+ Callback

8. **PaynowLogisticsProvider**（`Services/PaynowLogisticsProvider.php` implements ILogisticsProvider）
   - 行動：依序實作 `get_settings` / `get_store_selection` / `parse_store_selection` / `create_shipment`（含冪等 ReNewOrder + 金額上限 R9）/ `query_shipment` / `print_document`（per-service 端點 + RenewOrderNo）/ `cancel_shipment`（不限帳號類型）/ `create_return`（throw 尚未實作）/ `handle_status_callback`（解析推送 R1）/ `get_supported_methods`；`logger()`（同 ezPay/ECPay 慣例）。
   - 依賴：Cycle 0+1。風險：**高**（R1/R4/R9）。
   - 測試：`PaynowLogisticsProviderTest`（@group happy @group error @group edge @group integration）— 對映 6 支物流 feature 全場景（見測試策略）。

9. **LogisticsCallback**（`Http/LogisticsCallback.php`）
   - 行動：選店 returnUrl callback（`POST /paynow/logistics/selection-callback`，permission `__return_true`，cid 反查 + 寫 store meta）+ 貨態推送 callback（`POST /paynow/logistics/status-callback`，R1，orderno 反查 + 冪等 + 回 200）。
   - 依賴：Cycle 0+2。風險：**高**（R1）。
   - 測試：`PaynowLogisticsSelectionCallbackTest` + `PaynowLogisticsStatusCallbackTest`（@group happy @group security @group edge）。

#### A-Cycle 3：WC_Shipping_Method + 註冊 + Vue

10. **WC_PaynowLogisticsShipping**（`Services/WC_PaynowLogisticsShipping.php` extends WC_Shipping_Method）
    - 行動：per-service 運送方式（SEVEN/FAMI/HILIFE/TCAT）；classic 結帳「選擇超商」按鈕 enqueue；`save_checkout_meta()`（寫 service_id / sub_type）；`is_chosen()` 把關。
    - 依賴：Cycle 0-2。風險：中。測試：`WC_PaynowLogisticsShippingTest`（@group happy）。

11. **ProviderRegister + LogisticsApiService 註冊**（修改既有）
    - 行動：見「架構變更 §註冊」。
    - 依賴：Cycle 0-3。風險：中。測試：`PaynowLogisticsRegisterTest`（@group integration）+ `LogisticsApiServiceTest` 擴充（paynow 委派）。

12. **Vue 設定頁 + router**（`js/src/pages/Logistics/Paynow/index.vue` + router + ROUTER_MAPPER）
    - 行動：鏡像 `Logistics/Ecpay/index.vue`；表單欄位對應 SettingsDTO。
    - 依賴：Cycle 0-3。風險：低。測試：手動 / 既有 lint（前端無 PHPUnit）。

### 線 B：PayNow 發票（`paynow_invoice`）

#### B-Cycle 0：Enums + Settings DTO + IssueParams

13. **Enums**（`ECarrierType` / `ETaxType` / `EZeroTaxReason`）
    - 行動：backed enum + 結帳 individual/company/donate → carrier_type 映射（activity §載具映射）。
    - 依賴：無。風險：低。測試：`PaynowInvoiceEnumTest`（@group happy）。

14. **PaynowInvoiceSettingsDTO**（`DTOs/PaynowInvoiceSettingsDTO.php`）
    - 行動：extends `BaseSettingsDTO`；jwt_token / mode / api_url getter（dev/prod）/ seller 設定 / auto_issue_order_statuses / auto_cancel_order_statuses / auto_allowance_on_refund。
    - 依賴：無。風險：低（⚠️ R5 option key `woocommerce_paynow_invoice_settings`）。測試：`PaynowInvoiceSettingsDTOTest`（@group happy）。

15. **IssueParams**（`DTOs/IssueParams.php`）
    - 行動：從 order + `_pc_issue_invoice_params` 組 PayNow issue body；carrier_type 映射；tax_amount 計算（R10：非統編=0 / 統編=實際稅額）；載具捐贈互斥驗證；零稅率必填驗證；`build_merchant_order_no()`。
    - 依賴：Cycle 0 enums。風險：**中**（R10）。測試：`PaynowIssueParamsTest`（@group happy @group edge @group error）。

#### B-Cycle 1：ApiClient（mock）+ Response DTOs

16. **InvoiceApiClient**（`Http/InvoiceApiClient.php`）
    - 行動：`issue()` / `cancel()` / `issue_allowance()` / `invalid_allowance()` / `query()`；Bearer JWT-Token header；`is_mock()` 回 fixture；外層回應 `{status,type,message,result,request_id}` 解析（type=success 判斷）。
    - 依賴：Cycle 0。風險：中。測試：`PaynowInvoiceApiClientMockTest`（@group happy @group integration）— 驗 Authorization header + body 組裝 + fixture 解析。

17. **Response DTOs**（`IssueResponse` / `AllowanceResponse` / `QueryResponse`）
    - 行動：`::parse()` 從 result 取 invoice_number / allowance_number / 明細。
    - 依賴：Cycle 1。風險：低。測試：併入 ApiClient 測試。

#### B-Cycle 2：Provider（三介面）+ 註冊 + Vue

18. **PaynowInvoiceProvider**（`Services/PaynowInvoiceProvider.php` implements IInvoiceService + ISupportsAllowance + ISupportsQuery）
    - 行動：鏡像 `EzpayInvoiceProvider`；`issue`（冪等 + 互斥/零稅率驗證）/ `cancel`（冪等 + 已開折讓擋）/ `issue_allowance`（冪等 + 金額驗證）/ `invalid_allowance` / `query_invoice`（唯讀）/ `get_invoice_number` / `get_settings`；const ID='paynow_invoice'；catch \Throwable → log + order note → 回 []。
    - 依賴：Cycle 0+1。風險：**中**（R5/R10）。
    - 測試：`PaynowInvoiceProviderTest`（@group happy @group error @group edge @group invoice）— 對映 3 支發票 feature 全場景。

19. **ProviderRegister 註冊**（修改 `Invoice/ProviderRegister.php`）
    - 行動：`$invoice_providers` 加一行（auto-issue / 退款折讓 hook 自動套用）。
    - 依賴：Cycle 2。風險：低。測試：`PaynowInvoiceRegisterTest`（@group integration）— 驗 auto-issue hook 掛載 + 退款折讓路由。

20. **Vue 設定頁 + router**（`js/src/pages/Invoices/Paynow/index.vue` + router + ROUTER_MAPPER）
    - 行動：鏡像 `Invoices/Ezpay/index.vue`。
    - 依賴：Cycle 2。風險：低。測試：手動 / lint。

---

## 測試策略

### 測試對映（9 支 feature → 測試類別）

| Feature | 測試類別 | group | 關鍵場景 |
|---------|---------|-------|---------|
| `paynow-logistics-store-selection` | PaynowLogisticsProviderTest | happy/error/edge | provider未啟用→fail / 訂單不存在→fail / 非啟用子類型→fail / SEVEN組裝(action/Logistic_serviceID=01/returnUrl/TripleDES apicode) / TCAT跳過選店 / 回 redirect_target |
| `paynow-logistics-selection-callback` | PaynowLogisticsSelectionCallbackTest | happy/security/edge | 缺storeid→fail / 寫 store meta / cid反查訂單 / 回呼來源驗證（弱驗證） |
| `paynow-logistics-create-shipment` | PaynowLogisticsProviderTest | happy/error/edge | 無門市→fail / SEVEN取貨不付款(DeliverMode=02/OrderNo/TotalAmount/PassCode/JsonOrder base64) / COD(DeliverMode=01) / TCAT(收件地址+DeliveryType=0003) / 金額>20000→fail / Status=F→fail+note / Status=S寫LogisticNumber+paymentno+validationno / 冪等→ReNewOrder |
| `paynow-logistics-query` | PaynowLogisticsProviderTest | happy/error/edge | 無ref→fail / 請求帶LogisticNumber+sno=1 / 寫status+Delivery_Status+LogisticCode+更新時間 / COD取貨完成→collection_paid |
| `paynow-logistics-print-document` | PaynowLogisticsProviderTest | happy/error/edge | 無ref→fail / SEVEN→Order711端點 / TCAT→PrintBlackCatLabel回PDF / 回標籤連結 / 已ReNewOrder→用RenewOrderNo |
| `paynow-logistics-cancel` | PaynowLogisticsProviderTest | happy/error | 無ref→fail / DELETE+LogisticNumber+sno=1+PassCode / 回應含'S'→status=1+note / 不含'S'→fail+提示手動 / create_return→throw尚未實作 |
| `paynow-invoice-issue` | PaynowInvoiceProviderTest + InvoiceApiService(既有擴充) | happy/error/edge/invoice | 訂單不存在→500 / 冪等(已開立不重打) / Bearer header / B2C手機條碼(carrier_type=PhoneBarCodeCarrier/tax_amount=0) / B2B統編(buyer.identifier/tax_amount實際) / 捐贈(npoban/carrier_type空) / 載具+捐贈互斥→fail / 零稅率缺reason→fail / type≠success不寫data+note / 作廢帶invoice_number / 自動開立hook |
| `paynow-invoice-allowance` | PaynowInvoiceProviderTest + RefundAllowanceHook(既有) | happy/edge/invoice | 未開立不折讓 / 部分退款→allowance API+寫allowance_data / 全額退款→不折讓走作廢 / 作廢折讓帶allowance_number+清資料 |
| `paynow-invoice-query` | PaynowInvoiceProviderTest | happy/edge/invoice | 未開立→回空 / 帶InvoiceNumber / type=success回明細不改狀態 / type≠success回空 |

> ⚠️ 額外 webhook 測試（R1，非原 feature）：`PaynowLogisticsStatusCallbackTest`（@group security @group edge）— 推送解析 + orderno反查 + 冪等防重 + 恆回200。

### 單元 / 整合 / 設定測試

- **單元**：TripleDesCrypto（兩模式 round-trip）、PassCode（sha1 固定向量）、Enums、MetaKeys、IssueParams（tax_amount/載具映射）、SettingsDTO。
- **整合**：ApiClient（mock 請求組裝 + fixture 解析）、Provider（全 feature 場景）、Callback、Register（hook 掛載 + REST 委派）。
- **E2E**：本期不新增 Playwright（sandbox 憑證 GAP）；列第二期憑證到位後補。

### 測試執行指令（本地 LocalWP DB 限制 → wp-env）

```bash
# 全套（mock）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit"

# 單一類別（須帶路徑 tests/Integration/）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit --filter PaynowLogisticsProviderTest tests/Integration/Logistics/"

# PHPStan level 9（-d 為 PHP flag）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "php -d memory_limit=2G vendor/bin/phpstan analyse"
```

> ⚠️ **group 白名單**（phpunit.xml.dist）：每個測試方法**必掛** smoke/happy/error/edge/security 至少一個，否則不被收集（見 MEMORY active-test-suite-location）。可併掛 integration/logistics/invoice/paynow 分類 group。
> ⚠️ 既有兩個 pre-existing 失敗（ezpay edge + RedirectSettingsDTO），非本次引入，不阻塞。
> ⚠️ store 幣別預設 USD，物流/發票測試須顯式 `update_option('woocommerce_currency','TWD')`（見 MEMORY paynow-status 踩雷）。

### 關鍵邊界情況

- TripleDES：空字串 / 非 8B 倍數 / UTF-8 中文 / 兩模式不互換
- PassCode：get_total() 小數格式（"1000" vs "1000.00"）
- 建單冪等：已有有效單 → ReNewOrder 而非重複
- 貨態推送：重複推送同貨態 → 冪等跳過 / orderno 前綴反查
- 發票：載具捐贈互斥 / 零稅率必填 / 統編稅額 / 全額 vs 部分退款分流
- 自動開立 hook 重複觸發 → issued_data 冪等

---

## 依賴項目

- **無新增第三方 library**：TripleDES 用 PHP `openssl_*` 內建；Bearer 用 `wp_remote_*`；不觸發 lib-skill-creator。
- 既有 `j7-dev/wp-utils`（DTO / ApiBase / SingletonTrait / WC logger）。
- 既有 `BaseSettingsDTO` / `BaseService` / `ProviderUtils` / `OrderUtils`。
- 既有 shared `Invoice/Shared/Helpers/MetaKeys`（發票復用）+ `LogisticsApiService`（物流 REST 委派）。
- `paynow` skill（發票 API contract grounding）+ woomp `includes/paynow-shipping/`（物流 API contract grounding）。

---

## 風險與緩解措施

- **高**：物流貨態 webhook 與 Execution Plan 矛盾（R1）— 已實讀 woomp 修正，新增 status-callback 端點 + 保留查詢補單；feature 標記待 Phase 03 reconcile。
- **高**：TripleDES 兩模式混用（R2）— 獨立方法 + 各自 round-trip 測試先行（A-Cycle 0）。
- **高**：meta-key 前綴策略分歧（R4）— PayNow 自建 `PaynowLogisticsMetaKeys`，PROVIDER_IDS 加 paynow，callback 反查走自己的 class。
- **中**：固定 key/IV 疑似測試值（R3）— 常數寫入 + 標 GAP prod 換鑰。
- **中**：發票稅額計算（R10）— B2C/B2B 各自測試鎖定。
- **中**：provider ID 衝突（R5，已裁決）— const ID='paynow_invoice' + 獨立 option key。
- **低**：PassCode sha1 對 total 字串敏感（R6）— 固定 total 測試。

---

## 錯誤處理策略

採「**catch \Throwable → logger(error, 同步 order note) → 回 [] / WP_Error / HTTP 200**」既有慣例（ezPay/ECPay/PAYUNi 一致）：

- **物流 provider 方法**：失敗一律 `throw`，由 `LogisticsApiService` catch 轉 HTTP code（403/404/400/500）。
- **物流 callback**（選店 / 貨態）：所有路徑（含例外）catch → 回 HTTP 200，防 PayNow 重送風暴。
- **發票 provider 方法**：catch \Throwable → log + order note → 回 []，絕不破壞 WooCommerce 主流程（退款 / 狀態變更）。
- **絕不外洩內部錯誤**到前端（通用訊息 + log 細節）。

---

## 限制條件（此計劃不會做的事）

- ❌ 不破壞任何既有介面（`ILogisticsProvider` / `IInvoiceService` / `ISupportsAllowance` / `ISupportsQuery` 簽章不動）。
- ❌ 不實作逆物流（`create_return` → throw 尚未實作；woomp 無證據）。
- ❌ **首期不實作冷凍交貨便**（SEVENFROZEN/FAMIFROZEN 等 service 21-24）— 先做常溫超商（01/03/05）+ 黑貓宅配（06）；冷凍列第二期（woomp 有實作可參考）。
- ❌ 不實作發票 POS 取號 / POS 開立（一般電商不走 POS，GAP）。
- ❌ 不實作 block checkout 物流選店 UI（classic-first，對齊 ECPay 慣例；block 第二期）。
- ❌ 不做 sandbox / prod 端到端驗證（憑證未申請，GAP）— 驗收以 API_MODE=mock 全綠為主軸。
- ❌ 混稅（MixTax）首期僅佔位，實際組合須與財會確認（GAP）。
- ❌ 不新增第三方 library。

---

## 成功標準

- [ ] 線 A：`paynow_logistics` 落 `ILogisticsProvider` 10 methods；6 支物流 feature 對映測試 mock 全綠。
- [ ] 線 B：`paynow_invoice` 落 `IInvoiceService` + 兩選配介面；3 支發票 feature 對映測試 mock 全綠。
- [ ] TripleDES 兩模式 round-trip 測試通過（R2）；PassCode 固定向量通過（R6）。
- [ ] 物流貨態 status-callback 端點解析 + 冪等 + 恆回 200 測試通過（R1）。
- [ ] 發票自動開立 / 退款自動折讓 hook 復用既有機制，無需改 hook 邏輯，測試驗證掛載正確。
- [ ] `paynow_invoice` option key 為 `woocommerce_paynow_invoice_settings`，與金流 `woocommerce_paynow_settings` 不衝突（R5）。
- [ ] PHPStan level 9 無新增錯誤；PHPCS 通過；既有 2 個 pre-existing 失敗外全綠。
- [ ] Vue 設定頁 `/logistics/paynow_logistics` + `/invoices/paynow_invoice` 可達；lint 通過。
- [ ] CLAUDE.md / provider-guide.rule / actor / feature 文字（R5 provider id、R1 webhook）標記待 reconcile（非阻塞 TDD）。

---

## 預估複雜度：高

兩個全新 provider（~40 檔案，含測試），但結構高度模板化（鏡像 PAYUNi 物流 + ezPay 發票）。風險集中於 TripleDES 雙模式（R2）、貨態 webhook 修正（R1）、meta-key 策略（R4）三點，已於 A-Cycle 0 / A-Cycle 2 前置處理。發票線（線 B）複雜度中，可與物流線（線 A）平行。

> ⚠️ 影響檔案數 >30（含測試約 40+）。因高度模板化 + 拆為 6 個獨立 cycle（線 A 4 個 + 線 B 3 個），每 cycle 可獨立交付合併，不觸發 REDUCTION。若 tdd-coordinator 評估單一 cycle 過大，可進一步拆分。

---

## ⚠️ 待覆核 / reconcile 標記（交接 tdd-coordinator 同步告知）

| 項目 | 性質 | 處理時機 |
|------|------|---------|
| R1 物流貨態 webhook（woomp 有證據，Execution Plan 標無）→ 新增 status-callback 端點 | 規格修正 | 已納入本計劃；feature `paynow-logistics-query` 的「退化為查詢補單」描述待 Phase 03 reconcile |
| R5 發票 provider id：feature/activity 寫 `paynow`，實作用 `paynow_invoice` | 文字不一致 | 實作以 `paynow_invoice` 為準；feature/activity 文字待 reconcile（標記，非阻塞） |
| R3 固定 key/IV prod 換鑰 | GAP | 待 PayNow 官方文件；prod 上線前確認 |
| sandbox 憑證（物流 user_account/apicode + 發票 jwt_token） | GAP | 用戶申請後補 sandbox 端到端 |
| 官方物流 API 文件核對（端點/欄位/錯誤碼） | GAP | 待 PayNow 提供；現以 woomp 反推（ASM） |
