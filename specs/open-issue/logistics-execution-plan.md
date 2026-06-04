# Execution Plan — Logistics Domain（綠界全方位物流 v2 + 統一物流抽象）

> Phase 01 Discovery 產出。本計畫為後續 Phase 02-08 的 scope 依據。
> 狀態：**定案版**（所有窄門 C4/C5a-f/C7 已拍板，7 張 CiC 全數清零，Examples 已補齊，erm.dbml/api.yml 已產出）。

## 需求摘要

在 Power Checkout 新增**第 4 個 domain `Logistics`**，串接**綠界全方位物流 v2（AllInOne）**，
並設計一個**加密無關、流程細節不外洩**的統一抽象 `ILogisticsProvider`，
使未來的 **PAYUNi 物流**能共用同一抽象（硬性架構要求）。本次只實作 ECPay provider。

## 概覽

| 類型 | 數量 |
|------|------|
| Create（spec） | Activity ×1、Feature ×6、UI ×1、ERM（4 enum + 1 settings table + 1 order-meta table + ref）、API endpoints ×7 |
| Modify（spec） | `actors/綠界ECPay.md`（物流段）、`erm.dbml`（v1.3.0）、`api.yml`（v1.3.0） |
| Create（impl，Phase 05+） | Logistics domain PHP（interface/provider/register/http/dto/service）、Vue 設定頁、WC_Shipping_Method |
| Modify（impl，Phase 05+） | `Bootstrap.php`、`js/src/router/index.ts`、`CLAUDE.md` |
| Delete | logistics-create-return.feature（退貨延後第二期） |

---

## 窄門定案總表

| # | 窄門 | 拍板 |
|---|------|------|
| C4 | WC 整合方式 | **WC_Shipping_Method + 結帳頁「選擇門市」按鈕**，classic 先、block 第二期 |
| C5a/b | 物流範圍 | **超商三家（FAMI/UNIMART/HILIFE）+ 宅配 HOME（含溫層）** |
| C5c | B2C/C2C | **兩者並存**，同 provider 以 account_type 切換兩組憑證 |
| C5d | 退貨 | **延後第二期**，interface 預留 create_return 但本次不實作 |
| C5e | COD | **線上付款 + COD 取貨付款 兩者並存**（IsCollection=Y + CollectionAmount=訂單金額） |
| C5f | 成立時機 | **後台手動**（管理員於訂單頁觸發 CreateByTempTrade） |
| C7 | PAYUNi | **本次只實作 ECPay provider**；抽象設計到能容 PAYUNi |
| C-cred | 憑證 | 測試用綠界公開帳號（B2C 2000132 / C2C 2000933）；正式存後台 Vue 設定頁，禁寫死 |

---

## 統一抽象設計（定案）

### `ILogisticsProvider` interface（鏡像 `Invoice\Shared\Interfaces\IInvoiceService`）

| 方法 | 用途 | ECPay v2 對應 | PAYUNi 未來對應 |
|------|------|--------------|------------|
| `static get_settings(bool): array` | 設定 | settings DTO | settings DTO |
| `get_store_selection(order, ctx): array` | 階段 A：取得選店導轉目標 | `RedirectToLogisticsSelection`（建暫存 + RWD HTML） | `ship_map`（form POST 導轉地圖頁） |
| `parse_store_selection(raw): array` | 解析選店回呼 → 統一 StoreSelectionResult | 解 `ClientReplyURL` ResultData → `TempLogisticsID` + 門市 | 解 callback → `MapJson`（StoreID/Name/Addr） |
| `create_shipment(order, selection): array` | 階段 B：成立正式物流單 | `CreateByTempTrade`（憑 TempLogisticsID） | `trade`（商店組完整收件人） |
| `query_shipment(order): array` | 查詢 | `QueryLogisticsTradeInfo` | `query` |
| `print_document(order): string` | 列印託運單 → HTML/PDF | `PrintTradeDocument` | `print_label` / `get_obt_number_pdf` |
| `cancel_shipment(order): array` | 取消物流單（C2C） | `CancelC2COrder`（C2C，需 CVSPaymentNo/ValidationNo） | 對等取消（視支援度） |
| `create_return(order, ctx): array` | 退貨（**本次不實作，方法位置預留**） | ReturnCVS/ReturnUniMartCVS/ReturnHilifeCVS/ReturnHome | refund / home_delivery/refund |
| `handle_status_callback(req): \WP_REST_Response` | 貨態 callback（解密/驗簽/防重/回正確格式） | ServerReplyURL，回 **AES-JSON 三層** | 貨態 Notify，回 **200 "OK"** |
| `get_supported_methods(): array` | 該 provider 支援的物流方式 → 結帳頁選項 | 超商(FAMI/UNIMART/HILIFE)+宅配, B2C/C2C | 7-ELEVEN + 黑貓, B2C/C2C/C2B |

### 設計 trade-off（已決策）

1. **加密隔離**：interface 完全加密無關。ECPay 復用既有 `Domains/Payment/Ecpg/Shared/Helpers/AesCrypto.php`（AES-128-CBC，與 logistics guide 14 規則一致）；PAYUNi 未來自帶 AES-256-GCM + SHA256。
2. **兩階段選店抽象**（核心 trade-off）：選店拆 `get_store_selection`（導轉）→ `parse_store_selection`（回呼）→ `create_shipment`（成立）三步，吸收「ECPay 暫存單三段 vs PAYUNi ship_map 單段 + trade」差異。selection_result 用字典承載 provider 專屬 token（TempLogisticsID vs StoreID + 收件人），犧牲型別強度換抽象通用性。
3. **統一回傳 `array<string,mixed>`**：鏡像 IInvoiceService，不在 interface 邊界引入新 DTO 階層；各 provider 內部仍可用 DTO。
4. **統一主鍵 `_pc_logistics_ref`**（= ECPay LogisticsID / 未來 PAYUNi ShipTradeNo），對齊 PAYUNi `order.shippingRef`，下游不需知 provider。
5. **callback 回應差異收進 provider**：`handle_status_callback` 回 `\WP_REST_Response`，REST 路由層統一，AES-JSON 三層 vs 200-OK 差異不外洩。
6. **B2C/C2C 帳號切換**（定案）：同一 `ecpay_logistics` provider，設定內以 `account_type`（b2c/c2c）切換，對應兩組憑證欄位（b2c_* / c2c_*）。C2C 才有 CancelC2COrder/UpdateStoreInfo 與 CVSPaymentNo/CVSValidationNo 流程。
7. **provider 註冊**：鏡像 `Invoice\ProviderRegister` — `$logistics_providers` 靜態陣列、`is_enabled` 才進 `ProviderUtils::$container`、設定存 `woocommerce_ecpay_logistics_settings`。既有 `provider-settings.feature` 已預留 `回應 data 包含 "logistics" 陣列`，settings API 可直接容納。
8. **COD 付款狀態協調**（定案）：COD 與既有線上金流 gateway 解耦——結帳時消費者選「線上付款」或「COD 取貨付款」。COD 訂單建物流單帶 IsCollection=Y + CollectionAmount=訂單金額；WC 訂單狀態機：下單→處理中/待出貨→（管理員成立物流單→已出貨）→取件完成貨態觸發 _pc_logistics_collection_paid=yes。是否將「取貨付款完成」進一步轉 WC completed 由 Phase 05 實作決定（baseline 僅標記 meta + order note，不自動改單，避免與金流 StatusManager 衝突）。

---

## Phase 02: Entity Modeling（已產出 erm.dbml v1.3.0）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | enum logistics_provider / logistics_sub_type / logistics_account_type / logistics_temperature / logistics_payment_scenario / logistics_status | 物流相關值域 |
| create | wp_options_ecpay_logistics_settings | provider 設定表（account_type + 兩組憑證 + enabled_methods + sender + reply URLs） |
| create | wc_order_meta_logistics | 物流 order meta（temp_id / ref / store_* / status / cvs_payment_no / cvs_validation_no / collection_paid） |
| create | ref wc_order_meta_logistics.order_id > wc_orders.id | 關聯 |

## Phase 03: BDD Analysis（已產出 features/logistics/，無 CiC）

| Feature | 類型 | 涵蓋 |
|------|------|------|
| `logistics-store-selection.feature` | @command | 階段 A 建暫存單 + 回選店頁；account_type 切換、COD 代收、宅配溫層、子類型驗證 |
| `logistics-selection-callback.feature` | @command | ClientReplyURL 解析 TempLogisticsID + 門市暫存 |
| `logistics-create-shipment.feature` | @command | 階段 B CreateByTempTrade；雙層檢查；C2C 寄貨編號/驗證碼 |
| `logistics-status-callback.feature` | @command | ServerReplyURL 貨態（AES-JSON 三層、驗 MerchantID、防重、COD 取件完成標記） |
| `logistics-query.feature` | @query | 查詢物流單狀態 |
| `logistics-print-document.feature` | @command | 列印託運單（子類型） |
| `logistics-cancel-c2c.feature` | @command | C2C 取消物流單 |

> provider 啟用/設定 CRUD 由既有 `settings/provider-settings.feature` 涵蓋（已預留 logistics 陣列），不重複生成。

## Phase 04: API Contract（已產出 api.yml v1.3.0）

| 操作 | endpoint | namespace | Auth |
|------|------|------|------|
| create | POST `/logistics/{order_id}/store-selection` | power-checkout/v1 | Nonce |
| create | POST `/logistics/selection-callback` | power-checkout/ecpay | AES 解密把關（`__return_true`） |
| create | POST `/logistics/{order_id}/create-shipment` | power-checkout/v1 | Nonce |
| create | GET `/logistics/{order_id}` | power-checkout/v1 | Nonce |
| create | POST `/logistics/{order_id}/print` | power-checkout/v1 | Nonce |
| create | POST `/logistics/{order_id}/cancel` | power-checkout/v1 | Nonce |
| create | POST `/logistics/status-callback` | power-checkout/ecpay | MerchantID 驗證 + LogisticsID 比對 + 防重（`__return_true`），回 AES-JSON 三層 |

## Phase 05-08: Implementation（鏡像 provider-guide.rule.md）

| 操作 | 目標 | 說明 |
|------|------|------|
| create | `Domains/Logistics/Shared/Interfaces/ILogisticsProvider.php` | 統一抽象 interface |
| create | `Domains/Logistics/ProviderRegister.php` | `$logistics_providers` + register_hooks + 進 ProviderUtils 容器 |
| create | `Domains/Logistics/Ecpay/Services/EcpayLogisticsProvider.php` | implements ILogisticsProvider；account_type 切換 B2C/C2C 憑證 |
| create | `Domains/Logistics/Ecpay/Http/{ApiClient, LogisticsCallback}.php` | 復用 Payment\Ecpg AES-128-CBC AesCrypto；RqHeader Revision=1.0.0 + 即時 Timestamp（5 分視窗）；雙層檢查 |
| create | `Domains/Logistics/Ecpay/DTOs/EcpayLogisticsSettingsDTO.php` + 暫存單/建單參數 DTO | extends BaseSettingsDTO |
| create | `Domains/Logistics/Shared/Helpers/{LogisticsMetaKeys, Enums}.php` | order meta key + 子類型/帳號/溫層/貨態 enum |
| create | `Domains/Logistics/Shared/Services/LogisticsApiService.php` | REST endpoints（extends ApiBase） |
| create | `Domains/Logistics/Ecpay/WC_EcpayLogisticsShipping.php`（WC_Shipping_Method 子類） | 結帳頁運送選項 + 選店按鈕（classic 先） |
| modify | `Bootstrap.php` | wire Logistics\ProviderRegister::register_hooks() |
| create | `js/src/pages/Logistics/Ecpay/index.vue` + 改 `Logistics.vue` 列表 | Vue 設定頁（鏡像 Invoices/Ecpay；account_type 選擇 + 兩組憑證欄位） |
| modify | `js/src/router/index.ts` | `/logistics/ecpay_logistics` route + ROUTER_MAPPER |
| modify | `CLAUDE.md` | 記錄第 4 個 domain Logistics |
| defer | block checkout 選店 UI、退貨（ReturnCVS/Home）、PAYUNi provider 骨架 | 第二期 |

### 注意事項（傳遞給 planner / tdd-coordinator）

- COD 牽涉付款狀態：WC 訂單狀態機（下單→處理中/待出貨→已出貨→取件完成）、CollectionAmount=訂單金額、貨態 callback 標記取貨付款完成；是否自動轉 WC completed 由實作決定（baseline 不自動改單）。
- 全方位物流 v2 Timestamp 視窗僅 **5 分鐘**（比 ECPG/跨境物流的 10 分鐘短），每次送出前必須即時 time()，不可快取。
- callback 回應**必須 AES-JSON 三層**（非 1|OK），否則綠界隔 60 分重送最多 3 次。
- callback 安全必做：驗 MerchantID、比對 LogisticsID 與訂單、防重複（記已處理 LogisticsID/貨態碼）、遮蔽 HashKey/HashIV。
- 生成綠界 API 程式碼前必須以 ECPay-API-Skill（guides/07、14 AES、19 HTTP、21 webhook）查最新規格；貨態碼對應表須以官方文件確認（spec 中 "300"/"2067" 為代表性碼值）。

## CiC 便條紙

全數清零（7 → 0）。Grep `CiC\(` 於 activities/ + features/ 結果為空。

## Hand-off

specs 定案 → 交接 @zenbu-powers:planner 進行工程規劃。
</content>
