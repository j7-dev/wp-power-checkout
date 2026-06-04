# 實作計劃：Logistics Domain — 綠界全方位物流 v2 + 統一物流抽象

> Planner 產出（工程規劃）。輸入：`logistics-execution-plan.md`（定案版 scope）+ specs（erm.dbml v1.3.0 / api.yml v1.3.0 / 7 features / guide 07）。
> 範圍模式：**HOLD SCOPE** — Execution Plan 已凍結 scope（C4/C5a-f/C7/C-cred 全拍板），本計畫只負責「拆成防彈、可獨立交付的分階段步驟」，不擴張不縮減。
> 下游：交接 `@zenbu-powers:tdd-coordinator`，鏈路 planner → tdd-coordinator → test-creator → wordpress-master。

---

## 概述

在 Power Checkout 新增第 4 個 domain `Logistics`，串接綠界全方位物流 v2（AllInOne，AES-128-CBC + JSON，`/Express/v2/`），並設計加密無關的統一抽象 `ILogisticsProvider`（鏡像 `IInvoiceService`，可容未來 PAYUNi）。本次只實作 `ecpay_logistics` provider：選店（暫存單三段）/ 建單 / 查詢 / 列印 / 貨態 callback / C2C 取消，搭配 `WC_Shipping_Method`（超商+宅配，classic 結帳「選擇門市」按鈕）、COD 代收貨款、B2C/C2C 帳號切換、Vue 設定頁。退貨、block checkout、PAYUNi 骨架延後第二期。

## 需求重述

- **統一抽象**：`ILogisticsProvider`（~10 方法）吸收兩階段選店（ECPay `get_store_selection`→`parse_store_selection`→`create_shipment` 三步）、callback 回應格式差異（ECPay AES-JSON 三層 vs PAYUNi 200-OK，差異收進 provider）。
- **ECPay provider**：复用既有 `Payment/Ecpg/Shared/Helpers/AesCrypto`（AES-128-CBC，經 guide 07 確認規則一致）；RqHeader 帶 `Revision: "1.0.0"` + **即時** `time()`（5 分鐘視窗）；雙層檢查（外層 `TransCode===1`（int）→ 解密 → 內層 `RtnCode===1`（int））。
- **WC 整合**：`WC_Shipping_Method` 子類使超商/宅配出現在 classic 結帳運送方式；選店按鈕導 ECPay RWD 選店頁。
- **COD**：建單帶 `IsCollection=Y` + `CollectionAmount=訂單金額`；取件完成貨態標 `_pc_logistics_collection_paid=yes`；baseline 不自動改 WC 訂單狀態。
- **後台手動建單**（CreateByTempTrade），非付款自動。
- **憑證**：test 模式套綠界公開帳號（B2C 2000132 / C2C 2000933），prod 由 Vue 設定頁填，禁寫死。

## 已知風險（來自研究 — guide 07 / 14 / 19 / 21）

- **風險 R1：Timestamp 5 分鐘視窗**（比 ECPG/跨境 10 分鐘短）— 緩解：ApiClient 每次 `build_envelope` 內即時 `\time()`，禁止快取/預算/複製，與 EcpgApiClient 同 pattern。
- **風險 R2：callback 回應必須 AES-JSON 三層，非 `1|OK`** — 回錯誤格式綠界隔 60 分重送最多 3 次。緩解：不可複用 EcpgCallback 的純文字回應；provider 自組 AES-JSON 回應（`make_aes_json_response`），透過 `rest_pre_echo_response` 直接 echo JSON。失敗模式登記表已列。
- **風險 R3：RqHeader 跨服務差異** — 全方位物流要 `Revision:"1.0.0"`；站內付 2.0 不可帶 Revision（帶了報錯）。緩解：Logistics 自有 ApiClient，`build_envelope` 寫死 `Revision => '1.0.0'`，不共用 Ecpg 的 envelope。
- **風險 R4：型別比對陷阱** — AES-JSON 解密後 `RtnCode`/`TransCode` 為 **整數**（`=== 1`），與 AIO Form POST 的字串 `"1"` 不同。緩解：一律 `(int)` 後 `=== 1`，鏡像 EcpgApiClient::parse_response。
- **風險 R5：B2C/C2C 憑證錯置** — 兩組 MerchantID/HashKey/HashIV 不同，用錯帳號 AES 解密直接失敗。緩解：SettingsDTO 提供 `get_active_merchant_id/hash_key/hash_iv()`（依 account_type 回傳對應憑證），ApiClient/Callback 只透過此 accessor 取憑證，不直接讀 b2c_*/c2c_*。
- **風險 R6：ServerReplyURL/ClientReplyURL 僅 80/443、須公開** — localhost 無效。緩解：`get_store_selection` 前置驗證 reply URL 非 localhost（feature 已要求「ClientReplyURL 必須為公開可訪問的 URL」）。
- **風險 R7：WC_Shipping_Method 為專案全新領域**（既有 codebase 無 shipping method）— 緩解：第二階段獨立切片，僅做「運送方式出現 + 選店按鈕 + 寫 order meta」最小切片，不碰運費計算複雜邏輯（運費 0 或固定值，可後台設定延後）。
- **風險 R8：COD 與金流 StatusManager 衝突** — 緩解（trade-off 已決，見下）：baseline callback 只寫 `_pc_logistics_status` meta + `_pc_logistics_collection_paid` + order note，**不呼叫 `$order->update_status()`**，與 Payment 各自 StatusManager 完全解耦。
- **風險 R9：PHPStan level 9** — 既有 `RedirectSettingsDTO.php:148` 錯誤非本次引入，不理；新檔須零新增錯誤（`--memory-limit=2G`）。
- 未發現其他額外已知風險。

---

## 純技術決策（自行決策，標 trade-off；對應 clarifier 殘留待決）

| # | 議題 | 決策 | 理由 |
|---|------|------|------|
| T1 | 貨態碼對應表 | 以 guide 07/21 官方碼為準；spec 的 `"300"`（已出貨）/`"2067"`（取件完成）為代表性碼。實作以 `LogisticsStatusEnum` 承載原始字串（不強映射 WC 狀態），「取件完成」判定碼集中於 `Enums::is_pickup_completed(string)`，實作期以 guide 21 官方碼填充常數，測試用代表碼。 | 貨態碼眾多且依子類型而異，硬編完整表易過時；只需「是否取件完成」單一語意判定 |
| T2 | COD 是否自動轉 WC completed | **不自動轉**（baseline）。取件完成只寫 `_pc_logistics_collection_paid=yes` + `_pc_logistics_status` + order note | Execution Plan §8 已指示 baseline 不自動改單避免與金流 StatusManager 衝突；自動轉 completed 屬商業策略，留後台手動或第二期 |
| T3 | block checkout 選店 | 延後第二期（Execution Plan C4） | 本次 classic-first |
| T4 | 運費計算 | `WC_Shipping_Method` 本次回固定運費（後台可設 `cost`，預設 0），不做依重量/地區的級距運費 | 風險 R7：shipping 為新領域，先做選店最小切片；級距運費非 spec 範圍 |
| T5 | AesCrypto 複用方式 | 直接 `use` 既有 `Payment\Ecpg\Shared\Helpers\AesCrypto`（不提取共用、不複製）；guide 07 確認加密規則與 Ecpg 完全一致（JSON→urlencode→AES-128-CBC→base64） | 提取跨 domain Helper 屬重構，超出本次 scope；直接複用最小變更（CLAUDE.md 已記載 Ecpg AesCrypto 日後可 refactor 提取，但非本次） |
| T6 | callback 反查訂單主鍵 | 新增 `LogisticsMetaKeys::get_order_by_ref(string $logistics_id)`（鏡像 `EcpayMetaKeys::get_order_by_trade_no`，用 `wc_get_orders` meta query） | 貨態 callback 只帶 LogisticsID，須反查 |
| T7 | 防重 key | 以 `LogisticsID + LogisticsStatus` 組合為已處理 key，存 order meta 陣列 `_pc_logistics_processed_status`（feature「記錄已處理該貨態」） | 同一物流單會收多次不同貨態，須以「單號+貨態」防重而非僅單號 |

---

## 架構變更

### 新增（PHP — `inc/classes/Domains/Logistics/`）

```
Domains/Logistics/
├── ProviderRegister.php                          # $logistics_providers + register_hooks + 進 ProviderUtils 容器 + WC_Shipping_Method 註冊
├── Shared/
│   ├── Interfaces/ILogisticsProvider.php         # 統一抽象（~10 方法）
│   ├── Helpers/LogisticsMetaKeys.php             # order meta 存取（HPOS 相容）+ get_order_by_ref
│   ├── Enums/LogisticsSubType.php                # FAMI/UNIMART/HILIFE/HOME
│   ├── Enums/LogisticsAccountType.php            # b2c/c2c
│   ├── Enums/LogisticsTemperature.php            # 0001/0002/0003
│   ├── Enums/LogisticsPaymentScenario.php        # online/cod
│   ├── Enums/LogisticsStatus.php                 # 貨態碼（含 is_pickup_completed 判定）
│   └── Services/LogisticsApiService.php          # REST endpoints（extends ApiBase，power-checkout/v1）
└── Ecpay/
    ├── Services/EcpayLogisticsProvider.php       # implements ILogisticsProvider；account_type 切兩組憑證
    ├── Http/LogisticsApiClient.php               # AES-128-CBC（复用 Ecpg AesCrypto）；RqHeader Revision+即時 time；雙層檢查；MOCK 模式
    ├── Http/LogisticsCallback.php                # selection-callback + status-callback（power-checkout/ecpay；AES-JSON 三層回應）
    ├── DTOs/EcpayLogisticsSettingsDTO.php        # extends BaseSettingsDTO；get_active_* 憑證 accessor
    ├── DTOs/StoreSelectionParams.php             # RedirectToLogisticsSelection Data（含 IsCollection/Temperature）
    ├── DTOs/CreateShipmentParams.php             # CreateByTempTrade Data
    └── Services/WC_EcpayLogisticsShipping.php    # WC_Shipping_Method 子類（運送方式 + 選店按鈕）
```

### 新增（前端 Vue）

```
js/src/pages/Logistics/Ecpay/index.vue            # 設定頁（account_type + 兩組憑證 + enabled_methods + sender + reply URLs）
js/src/pages/Logistics/index.vue（或改 Logistics.vue）# provider 列表（鏡像 Invoices/index.vue）
js/src/pages/Logistics/Ecpay/Shared/{types.ts,enums.ts}  # 頁面型別/enum
```

### 修改

| 檔案 | 變更 |
|------|------|
| `inc/classes/Bootstrap.php` | `Domains\Logistics\ProviderRegister::register_hooks()` |
| `inc/classes/Domains/Settings/Services/SettingApiService.php` | `'logistics' => []` → `Logistics\ProviderRegister::get_registered_provider_dtos()` |
| `js/src/router/index.ts` | `/logistics/ecpay_logistics` route + `ROUTER_MAPPER.ecpay_logistics` + `/logistics` 列表頁實作（取代 placeholder） |
| `CLAUDE.md` | 記錄第 4 個 domain Logistics + meta key + REST + hooks |

### 不變更（明確排除）

- 既有 Payment/Invoice/Settings domain 邏輯（零侵入）
- `Payment/Ecpg/Shared/Helpers/AesCrypto.php`（只 `use`，不改）

---

## ILogisticsProvider 介面契約（鏡像 IInvoiceService）

```php
interface ILogisticsProvider {
    public static function get_settings( bool $with_default = true ): array;
    public function get_store_selection( \WC_Order $order, array $ctx = [] ): array;      // 階段A：回 redirect_target（RWD HTML）
    public function parse_store_selection( array $raw ): array;                            // 解 ResultData → TempLogisticsID + 門市
    public function create_shipment( \WC_Order $order ): array;                            // 階段B：CreateByTempTrade → LogisticsID
    public function query_shipment( \WC_Order $order ): array;                             // QueryLogisticsTradeInfo
    public function print_document( \WC_Order $order ): string;                            // PrintTradeDocument → HTML
    public function cancel_shipment( \WC_Order $order ): array;                            // C2C CancelC2COrder
    public function create_return( \WC_Order $order, array $ctx = [] ): array;             // 預留，本次 throw "尚未實作"
    public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response;// 貨態 callback（AES-JSON 三層回應）
    public function get_supported_methods(): array;                                        // 結帳頁選項（enabled_methods 子集）
}
```

> 回傳統一 `array<string,mixed>`（鏡像 IInvoiceService，不在 interface 邊界引入 DTO）。各方法失敗一律 throw（REST 層 catch 轉 HTTP code）；callback 例外吞掉仍回 AES-JSON（防重送風暴）。

---

## 資料流分析

### 流程 1：選店（階段 A，get_store_selection）

```
顧客(結帳選運送方式+點選店) ─▶ REST POST /logistics/{id}/store-selection
   │
   ▼ 前置驗證
[provider 未啟用?]──403 "綠界全方位物流未啟用"
[訂單不存在?]──404 "找不到訂單"
[reply URL=localhost?]──400 "ClientReplyURL 必須為公開可訪問的 URL"
[sub_type 不在 enabled_methods?]──400 "運送方式必須為已啟用的綠界物流子類型"
   │
   ▼ 組裝 RedirectToLogisticsSelection（StoreSelectionParams）
   ├─ RqHeader: Revision="1.0.0" + 即時 time()           （R1/R3）
   ├─ MerchantID = get_active_merchant_id()（account_type）（R5）
   ├─ Data.TempLogisticsID="0"
   ├─ COD? → IsCollection="Y" + CollectionAmount=total   ; online → IsCollection="N"
   ├─ HOME+冷凍? → Temperature="0003"
   └─ AES 加密（get_active_hash_key/iv）
   │
   ▼ PostWithAesStrResponseService（回 HTML body）
[HTTP error?]──throw→500 ; [TransCode≠1?]──throw "傳輸層錯誤"
   │
   ▼ 回 { code:success, data:{ redirect_target: RWD HTML } }
   前端導轉 ECPay RWD 選店頁
```

### 流程 2：選店回呼（parse_store_selection，ClientReplyURL）

```
ECPay 瀏覽器 Form POST ResultData ─▶ /ecpay/logistics/selection-callback (__return_true)
   │
   ▼
[ResultData 空?]──操作失敗 "選店結果為空"（記 log；仍回應）
   │
   ▼ AES 解密（get_active_hash_key/iv）
[解密失敗?]──"選店結果解密失敗"（記 log）
   │
   ▼ 取 TempLogisticsID + CVSStoreID/Name/Address
   ▼ 反查訂單（ctx 帶 order_id，或 ResultData 內 MerchantTradeNo→order）
   ▼ 寫 order meta: _pc_logistics_temp_id / store_id / store_name / store_addr
   回應（成功處理）
```

### 流程 3：成立物流單（階段 B，create_shipment）

```
管理員後台訂單頁觸發 ─▶ REST POST /logistics/{id}/create-shipment（Nonce）
   │
   ▼
[訂單不存在?]──404 ; [無 _pc_logistics_temp_id?]──403 "尚未選店，無暫存物流單"
   │
   ▼ CreateByTempTrade（CreateShipmentParams: TempLogisticsID + RqHeader Revision+即時time）
   ▼ AES-JSON 請求 → 雙層檢查
[TransCode≠1(int)?]──400 "傳輸層錯誤（TransCode）"
[RtnCode≠1(int)?]────400 "業務層錯誤（RtnCode）"
   │
   ▼ 寫 _pc_logistics_ref = LogisticsID + order note
   ▼ C2C? → 額外寫 _pc_logistics_cvs_payment_no / cvs_validation_no
   回 { data:{ logistics_id } }
```

### 流程 4：貨態 callback（handle_status_callback，ServerReplyURL）★最高風險

```
ECPay JSON body POST（AES-JSON 三層）─▶ /ecpay/logistics/status-callback (__return_true)
   │
   ▼ 一律最終回 AES-JSON 三層（即使任一步失敗）              （R2）
[TransCode≠1(int)?]──不更新；回 AES-JSON(Data.RtnCode=0)
   │
   ▼ 解密 Data（get_active_hash_key/iv）
[解密失敗?]──記 log；回 AES-JSON(Data.RtnCode=0)
[MerchantID≠本商店?]──記安全警告(遮蔽HashKey/IV)；不更新；回 AES-JSON   （安全清單1）
   │
   ▼ get_order_by_ref(LogisticsID)                          （安全清單2 / T6）
[找不到訂單?]──不更新；回 AES-JSON（避免重送風暴）
   │
   ▼ 防重：已處理過 (LogisticsID+LogisticsStatus)?            （安全清單3 / T7）
[已處理?]──不重複更新；回 AES-JSON(RtnCode=1)
   │
   ▼ 寫 _pc_logistics_status=LogisticsStatus + 記已處理碼
   ▼ COD 且 is_pickup_completed(status)? → _pc_logistics_collection_paid="yes"  （T1/T2 不改單）
   ▼ order note
   回 AES-JSON 三層(Data.RtnCode=1)
```

### 流程 5/6/7：查詢 / 列印 / 取消（管理端，Nonce）

```
query:  [無 _pc_logistics_ref?]──403 "尚未成立物流單" → QueryLogisticsTradeInfo → 回 {logistics_id,status,store_info}
print:  [無 _pc_logistics_ref?]──403 "尚未成立物流單" → PrintTradeDocument(LogisticsID[],SubType) → 回 HTML
cancel: [account_type≠c2c?]──403 "取消物流單僅支援 C2C 帳號"
        [無 cvs_payment_no?]──400 "缺少 C2C 寄貨編號，無法取消"
        → CancelC2COrder(LogisticsID,CVSPaymentNo,CVSValidationNo) → 雙層檢查 → _pc_logistics_status="cancelled" + order note
```

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
|-----------|------------|---------|---------|-----------|
| get_store_selection | provider 未啟用 | 前置(狀態) | throw → REST 403 | 是（錯誤訊息） |
| get_store_selection | 訂單不存在 | 前置(狀態) | throw → 404 | 是 |
| get_store_selection | reply URL=localhost | 前置(參數) | throw → 400 | 是（後台設定錯誤） |
| get_store_selection | sub_type 未啟用 | 前置(參數) | throw → 400 | 是 |
| get_store_selection | HTTP error / TransCode≠1 | 外部API | throw → 500 + order note | 是（通用訊息） |
| parse_store_selection | ResultData 空 | 前置(參數) | log warning，回應處理完成 | 否（瀏覽器導轉，記 log） |
| parse_store_selection | AES 解密失敗 | 加密 | log warning，回應 | 否 |
| create_shipment | 無 temp_id | 前置(狀態) | throw → 403 | 是 |
| create_shipment | TransCode≠1 / RtnCode≠1 | 雙層檢查 | throw → 400 + order note | 是 |
| handle_status_callback | TransCode≠1 | 傳輸層 | 不更新；回 AES-JSON(RtnCode=0) | 否（綠界端） |
| handle_status_callback | 解密失敗 | 加密 | log；回 AES-JSON(RtnCode=0) | 否 |
| handle_status_callback | MerchantID 不符 | 安全 | 安全警告 log(遮蔽憑證)；回 AES-JSON | 否 |
| handle_status_callback | LogisticsID 無對應訂單 | 反查 | 不更新；回 AES-JSON | 否 |
| handle_status_callback | 重複貨態 | 冪等 | 不重複處理；回 AES-JSON(RtnCode=1) | 否 |
| handle_status_callback | **任何未預期 \Throwable** | 例外 | **catch；仍回 AES-JSON** 避免 60 分重送×3 | 否 |
| query/print | 無 _pc_logistics_ref | 前置(狀態) | throw → 403 | 是 |
| cancel_shipment | account_type≠c2c | 前置(狀態) | throw → 403 | 是 |
| cancel_shipment | 缺 cvs_payment_no | 前置(狀態) | throw → 400 | 是 |
| LogisticsApiClient.request | wp_remote_post 連線失敗 | 網路 | throw + order note | 是（通用） |

> 無「處理方式=無 + 靜默」組合 → 無 CRITICAL GAP。callback 所有路徑均「最終回 AES-JSON」，無靜默漏洞。

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
|-----------|---------|--------|--------|-----------|---------|
| 貨態 callback 回錯格式 | 綠界 60 分重送×3 | 是（強制 AES-JSON） | 是（IT：驗回應結構） | 否 | provider 自組 AES-JSON 回應 |
| Timestamp 過期(>5min) | TransCode≠1 | 是（即時 time） | 是（IT：驗 RqHeader 即時） | 是（建單失敗） | 重新觸發建單 |
| B2C/C2C 憑證錯置 | AES 解密失敗 | 是（get_active_* accessor） | 是（IT：account_type 切換驗 MerchantID） | 是 | 後台改 account_type |
| 重複貨態通知 | 重複改 meta | 是（LogisticsID+status 防重） | 是（IT：重送不重複） | 否 | 防重 key 短路 |
| COD 取件完成 | 漏標 collection_paid | 是（is_pickup_completed） | 是（IT：cod+2067→paid=yes） | 否（後台 meta） | 重送貨態觸發 |
| 選店未完成就建單 | 無 temp_id | 是（403 前置） | 是（IT：無 temp_id→失敗） | 是 | 先完成選店 |
| RqHeader 帶錯 Revision | TransCode≠1 | 是（寫死 1.0.0） | 是（IT：驗 Revision=1.0.0） | 是 | — |
| 解密後 RtnCode 字串"1"誤判 | 業務層誤判 | 是（(int)===1） | 是（IT：int 比對） | 是 | — |

---

## 實作步驟

> TDD：每階段先 test-creator 寫整合測試（紅），再 wordpress-master 實作（綠）。測試指令：`npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout composer run test`（API_MODE=mock），新測試放 `tests/Integration/Logistics/`，基類 `Tests\Integration\TestCase`。每階段結束跑 `vendor/bin/phpstan analyse --memory-limit=2G` 確保零新增錯誤。

### 第一階段：抽象骨架 + Enums + MetaKeys + SettingsDTO（地基，可獨立合併）

1. **ILogisticsProvider 介面**（`Domains/Logistics/Shared/Interfaces/ILogisticsProvider.php`）
   - 行動：定義上述 ~10 方法簽章。原因：所有後續 provider 的契約。依賴：無。風險：低。
2. **Enums ×5**（`Shared/Enums/`）
   - 行動：`LogisticsSubType`(FAMI/UNIMART/HILIFE/HOME)、`LogisticsAccountType`(b2c/c2c)、`LogisticsTemperature`(0001/0002/0003)、`LogisticsPaymentScenario`(online/cod)、`LogisticsStatus`（含 `is_pickup_completed(string):bool`，代表碼 2067）。皆 backed enum（鏡像 EcpayPaymentMethod）。依賴：無。風險：低。
3. **LogisticsMetaKeys**（`Shared/Helpers/LogisticsMetaKeys.php`）
   - 行動：鏡像 `EcpayMetaKeys`，get/update：provider_id / sub_type / payment_scenario / temp_id / ref / store_id / store_name / store_addr / status / cvs_payment_no / cvs_validation_no / collection_paid / processed_status(陣列)；static `get_order_by_ref(string)`（wc_get_orders meta query）；`is_processed(id,status)` / `mark_processed(id,status)`。一律 `$order->get_meta/update_meta_data`（HPOS）。依賴：無。風險：低。
4. **EcpayLogisticsSettingsDTO**（`Ecpay/DTOs/EcpayLogisticsSettingsDTO.php`）
   - 行動：extends BaseSettingsDTO；欄位對齊 erm.dbml `wp_options_ecpay_logistics_settings`（account_type / b2c_* / c2c_* / enabled_methods / sender_* / *_reply_url）；`instance()`+`reset()` 單例（鏡像 EcpgSettingsDTO）；`after_init` test 模式套公開帳號（B2C 2000132/5294y06JbISpM5x9/v77hoKGq4kWxNNIS、C2C 2000933/XBERn1YOvpM9nfZc/h1ONHk4P4yqbl5LK）+ 依 mode 設 stage/prod 端點；**`get_active_merchant_id()/get_active_hash_key()/get_active_hash_iv()`** 依 account_type 回傳（R5）。依賴：無。風險：中（憑證 accessor 是 R5 核心，須測試覆蓋）。
   - 測試：`EcpayLogisticsSettingsDTOTest`（account_type=b2c→2000132；=c2c→2000933；trim；test 預設憑證）。
   - 成功標準：account_type 切換正確回傳憑證；PHPStan 9 過。

### 第二階段：ApiClient（AES + 雙層檢查 + MOCK）+ 參數 DTO

5. **StoreSelectionParams / CreateShipmentParams**（`Ecpay/DTOs/`）
   - 行動：組 RedirectToLogisticsSelection / CreateByTempTrade 的 Data；前者含 TempLogisticsID="0"/GoodsAmount/GoodsName/Sender*/IsCollection/CollectionAmount/Temperature/ServerReplyURL/ClientReplyURL/LogisticsSubType；後者含 TempLogisticsID。依賴：SettingsDTO。風險：低。
6. **LogisticsApiClient**（`Ecpay/Http/LogisticsApiClient.php`）
   - 行動：鏡像 EcpgApiClient。`use Payment\Ecpg\Shared\Helpers\AesCrypto`（T5）；`build_envelope` 寫 `RqHeader => ['Timestamp'=>\time(),'Revision'=>'1.0.0']`（R1/R3，即時不快取）；方法：`redirect_to_logistics_selection`(回 HTML body)、`create_by_temp_trade`、`query`、`print_trade_document`(回 HTML)、`cancel_c2c`；`parse_response`：`(int)TransCode===1`→解密→`(int)RtnCode===1`（R4），失敗 throw + order note；MOCK 模式（API_MODE=mock）回固定 fixture（含 LogisticsID/TempLogisticsID/CVSPaymentNo）。依賴：步驟 4/5。風險：高（AES + 雙層 + MOCK，核心）。
   - 測試：`LogisticsApiClientTest`（parse_response 雙層：TransCode=0→throw；RtnCode 整數 0→throw；皆 1→回解密 Data；MOCK fixture）。
   - 成功標準：雙層檢查正確、RqHeader 含 Revision、MOCK 不打真 API。

### 第三階段：EcpayLogisticsProvider（選店/建單/查詢/列印/取消/貨態，核心業務）

7. **EcpayLogisticsProvider**（`Ecpay/Services/EcpayLogisticsProvider.php`）
   - 行動：`extends BaseService implements ILogisticsProvider`，`const ID='ecpay_logistics'`，SingletonTrait，`logger`（鏡像 EcpayInvoiceProvider，寫 order note）。實作各方法對應流程 1-7；`get_store_selection` 前置驗證（provider 啟用 / 訂單 / reply URL 非 localhost / sub_type ∈ enabled_methods）；`create_return` throw "退貨尚未實作"；`handle_status_callback` 委派 LogisticsCallback 的處理邏輯（或自身組 AES-JSON 回應）。依賴：步驟 1-6。風險：高。
   - 測試：`EcpayLogisticsProviderTest`（涵蓋 store-selection / create-shipment / query / print / cancel-c2c 七 feature 的場景；含 COD IsCollection=Y、宅配 Temperature、C2C 寄貨編號保存、B2C 呼叫 cancel 失敗、無 temp_id 失敗、無 ref 失敗）。
   - 成功標準：7 個 feature 全部場景綠燈。

8. **LogisticsCallback**（`Ecpay/Http/LogisticsCallback.php`）★R2 核心
   - 行動：extends ApiBase + SingletonTrait，namespace `power-checkout/ecpay`，apis: `logistics/selection-callback`(post,__return_true)、`logistics/status-callback`(post,__return_true)。`handle_selection`：解 ResultData→寫門市 meta（流程 2）。`handle_status`：流程 4（驗 MerchantID / get_order_by_ref / 防重 / COD 標記）。`make_aes_json_response(int $rtn_code)`：組 AES-JSON 三層（MerchantID + RqHeader[Timestamp] + TransCode=1 + Data=AES({RtnCode,RtnMsg})），透過 `rest_pre_echo_response` 直接 echo JSON（鏡像 EcpgCallback 純文字技巧，但輸出 JSON）。所有路徑 catch \Throwable 仍回 AES-JSON。`get_server_reply_url()/get_client_reply_url()`。依賴：步驟 4/6/7。風險：高（R2 回應格式錯誤致重送風暴）。
   - 測試：`LogisticsStatusCallbackTest`（TransCode=0→回 AES-JSON RtnCode=0；MerchantID 不符→不更新+安全 log；LogisticsID 無訂單→回 AES-JSON；已出貨 300→寫 status+回 RtnCode=1；COD+2067→collection_paid=yes；重送→不重複；例外→仍回 AES-JSON）；`LogisticsSelectionCallbackTest`（空 ResultData→失敗；解密失敗→失敗；成功→寫門市 meta）。
   - 成功標準：回應一律 AES-JSON 三層、可被解密還原；防重/驗 MerchantID 正確。

### 第四階段：REST Service + Provider 註冊 + Bootstrap 接入（對外可用）

9. **LogisticsApiService**（`Shared/Services/LogisticsApiService.php`）
   - 行動：extends ApiBase + SingletonTrait，namespace `power-checkout/v1`，apis：`logistics/(?P<id>\d+)/store-selection`(post)、`logistics/(?P<id>\d+)/create-shipment`(post)、`logistics/(?P<id>\d+)`(get)、`logistics/(?P<id>\d+)/print`(post)、`logistics/(?P<id>\d+)/cancel`(post)。callback 取 provider（ProviderUtils）→ 委派對應方法 → 回 `{code,message,data}`（鏡像 InvoiceApiService）；print 回 HTML（`rest_pre_echo_response`）。依賴：步驟 7。風險：中。
   - 測試：`LogisticsApiServiceTest`（各端點 happy + 403/404 前置）。
10. **Logistics\ProviderRegister**（`Domains/Logistics/ProviderRegister.php`）
    - 行動：鏡像 Invoice\ProviderRegister。`$logistics_providers=[EcpayLogisticsProvider::ID=>class]`；`register_hooks`：啟用才進 `ProviderUtils::$container`、註冊 `LogisticsApiService::instance()` + `LogisticsCallback::register_hooks()` + `woocommerce_shipping_methods` filter；`get_registered_provider_dtos()`（給 SettingApiService）。依賴：步驟 7/8/9/11。風險：中。
    - 測試：`LogisticsProviderRegisterTest`（啟用→進容器；未啟用→不進；is_enabled 讀 woocommerce_ecpay_logistics_settings）。
11. **WC_EcpayLogisticsShipping**（`Ecpay/Services/WC_EcpayLogisticsShipping.php`）★R7 新領域
    - 行動：`extends \WC_Shipping_Method`。每個 enabled sub_type 一個運送方式 instance（或單一 method + sub_type 選項）；`calculate_shipping` 加固定運費（T4，後台 cost，預設 0）；結帳頁加「選擇門市」按鈕欄位 + 寫 `_pc_logistics_sub_type` / `_pc_logistics_payment_scenario` 到 order meta（classic：`woocommerce_checkout_create_order` 或 review-order hook）。依賴：步驟 3。風險：高（全新領域，最小切片）。
    - 測試：`WC_EcpayLogisticsShippingTest`（method 註冊；enabled_methods 過濾出對應運送選項；運費）。E2E 補結帳頁互動。
12. **Bootstrap + SettingApiService 接入**
    - 行動：`Bootstrap.php` 加 `Domains\Logistics\ProviderRegister::register_hooks()`；`SettingApiService.php` line 72 `'logistics' => Logistics\ProviderRegister::get_registered_provider_dtos()`。依賴：步驟 10。風險：低。
    - 測試：`SettingsGetAllLogisticsTest`（GET /settings 的 data.logistics 含 ecpay_logistics 摘要）。

### 第五階段：Vue 設定頁 + router（後台可設定）

13. **Vue 設定頁 + 列表**（`js/src/pages/Logistics/`）
    - 行動：`Ecpay/index.vue` 鏡像 `Invoices/Ecpay/index.vue`（Element Plus 表單：account_type radio、b2c_*/c2c_* 憑證、enabled_methods 多選、sender_*、reply URLs、mode、enabled toggle）；`Logistics/index.vue` 列表（鏡像 Invoices/index.vue，讀 settings.data.logistics）；`@/` alias、`<script setup>`、apiClient。依賴：步驟 12。風險：中。
14. **router**（`js/src/router/index.ts`）
    - 行動：`ROUTER_MAPPER.ecpay_logistics='/logistics/ecpay_logistics'`；route `/logistics`→`Logistics/index.vue`（取代 placeholder）、`/logistics/ecpay_logistics`→`Logistics/Ecpay/index.vue`。依賴：步驟 13。風險：低。
    - 驗證：`pnpm build` 無錯；後台設定頁可存取、存讀憑證。

### 第六階段：文件 + E2E + 收尾

15. **CLAUDE.md 更新**：第 4 domain Logistics、12 個 logistics meta key、7 個 REST endpoint、shipping/callback hooks。
16. **E2E（Playwright，`tests/e2e/`）**：admin 設定頁 CRUD（鏡像 ecpay-settings.spec）；status-callback HMAC/AES helper（擴充 `helpers/ecpay-aes.ts`）；create-shipment 流程。
    - 驗證：`composer test`（全 PHPUnit 綠）+ `vendor/bin/phpstan analyse --memory-limit=2G`（零新增）+ `composer lint`。

---

## 測試策略

- **單元/整合測試（PHPUnit，主力）**：`tests/Integration/Logistics/`，基類 `Tests\Integration\TestCase`，API_MODE=mock。
  - `EcpayLogisticsSettingsDTOTest`、`LogisticsApiClientTest`、`EcpayLogisticsProviderTest`、`LogisticsStatusCallbackTest`、`LogisticsSelectionCallbackTest`、`LogisticsApiServiceTest`、`LogisticsProviderRegisterTest`、`LogisticsMetaKeysTest`、`WC_EcpayLogisticsShippingTest`、`SettingsGetAllLogisticsTest`。
  - 每個 PHPUnit 測試對應 7 個 feature 的「規則→場景」（@ignore feature 作為測試案例藍本）。
- **E2E（Playwright）**：admin 物流設定 CRUD、status-callback（AES 三層 + 防重）、create-shipment 後台觸發。
- **測試執行指令**：
  - 全套：`npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout composer run test`
  - 單類：`vendor/bin/phpunit --filter EcpayLogisticsProviderTest`
  - 靜態：`vendor/bin/phpstan analyse --memory-limit=2G`
- **關鍵邊界情況**：5 分鐘 Timestamp、AES-JSON 回應結構、B2C/C2C 憑證切換、整數 RtnCode 比對、重複貨態防重、COD IsCollection、宅配 Temperature、無 temp_id/ref 前置、localhost reply URL、MerchantID 不符、callback 例外仍回 AES-JSON。

## 依賴項目

- 既有 `Payment/Ecpg/Shared/Helpers/AesCrypto`（複用）、`ProviderUtils`、`BaseSettingsDTO`、`BaseService`、`ApiBase`(wp-utils)、`OrderUtils`、`SettingTabService`(enqueue Vue)。
- WooCommerce `WC_Shipping_Method`、HPOS（`$order->get_meta`）。
- ECPay-API-Skill guide 07/14/19/21（生成綠界程式碼前必查）；PAYUNi 抽象參考 `payuni-logistics-v3`。
- 測試環境：wp-env tests-cli（已修好）。

## 風險與緩解措施

- **高 R2**：callback 回應格式錯誤致重送風暴 — provider 自組 AES-JSON 三層回應 + 所有路徑 catch 仍回；IT 驗回應可解密還原。
- **高 R7**：WC_Shipping_Method 新領域 — 獨立第四階段最小切片，運費固定，只做選店+寫 meta。
- **高（ApiClient）**：AES + 雙層 + 5min Timestamp — 鏡像 EcpgApiClient 成熟 pattern，即時 time、整數比對、MOCK 模式。
- **中 R5**：B2C/C2C 憑證錯置 — `get_active_*` accessor 集中切換，IT 覆蓋。
- **中 R8**：COD 與金流衝突 — baseline 不改單，只寫 meta + note。
- **低 R9**：PHPStan — 新檔零新增錯誤；既有 RedirectSettingsDTO:148 不理。

## 錯誤處理策略

- 業務操作（provider 方法 / API client）：失敗 `throw \Exception`，REST 層 catch 轉 HTTP code（400 參數 / 403 狀態 / 404 找不到 / 500 例外），order note 記錄，`Plugin::logger`/`self::logger` 寫 log，不外洩內部細節。
- callback（selection / status）：**永不向綠界拋錯**——catch \Throwable，選店回應處理完成、貨態一律回 AES-JSON 三層（防 60 分重送×3）。安全 log 遮蔽 HashKey/HashIV。

## 限制條件（本計畫不做）

- ❌ 退貨（ReturnCVS/ReturnUniMartCVS/ReturnHilifeCVS/ReturnHome）— interface 預留 `create_return`，本次 throw「尚未實作」。
- ❌ block checkout 選店 UI（classic-first）。
- ❌ PAYUNi provider 骨架（抽象設計到能容，但不建檔）。
- ❌ COD 自動轉 WC completed（baseline 不改單）。
- ❌ 依重量/地區級距運費（固定運費）。
- ❌ UpdateTempTrade / UpdateShipmentInfo / UpdateStoreInfo（非本次 feature scope；C2C UpdateStoreInfo 若 cancel 流程不需則不做）。
- ❌ 不改既有 Payment/Invoice/Settings 邏輯與 Ecpg AesCrypto。

## 成功標準

- [ ] `Domains/Logistics/` 全檔案建立，`ILogisticsProvider` + `EcpayLogisticsProvider` 實作 7 個 feature 全場景。
- [ ] AES-128-CBC 複用 Ecpg AesCrypto；RqHeader Revision=1.0.0 + 即時 time；雙層整數檢查。
- [ ] 貨態 callback 回 AES-JSON 三層（可解密還原）；驗 MerchantID + 防重 + COD 標記。
- [ ] B2C/C2C account_type 切換正確憑證；test 模式套公開帳號。
- [ ] WC_Shipping_Method 超商+宅配出現於 classic 結帳 + 選店按鈕 + 寫 order meta。
- [ ] 7 個 REST endpoint 可用；GET /settings data.logistics 含 ecpay_logistics。
- [ ] Vue 設定頁可 CRUD 憑證；router `/logistics/ecpay_logistics` 可達。
- [ ] Bootstrap 接入；`composer test` 全綠；PHPStan 9 零新增錯誤；`composer lint` 過。
- [ ] CLAUDE.md 更新；E2E admin 設定 + callback 通過。

## 預估複雜度：高

（新 domain + 6 大功能 + 全新 WC_Shipping_Method 領域 + 高風險 AES-JSON callback；但 95% 鏡像既有成熟 pattern，風險集中於 R2/R7。）

## Hand-off / Next Agent

→ `@zenbu-powers:tdd-coordinator`（鏈路 planner → tdd-coordinator → test-creator → wordpress-master）。
依第一～六階段順序執行 TDD（每階段 test-creator 先寫紅、wordpress-master 實作綠）。
