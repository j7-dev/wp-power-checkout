# TDD 協調藍圖：PayNow 物流 + 電子發票

> tdd-coordinator 產出（2026-06-10）。基於 planner 實作計劃 `paynow-logistics-invoice-implementation-plan.md`。
> **派發模式**：純 sub-agent 鏈式委派——主窗口逐 cycle spawn `@zenbu-powers:test-creator`（Red）→ 親跑 Red Gate → spawn `@wordpress-master`（Green）→ 親跑 Green Gate。
> **核心鐵律**：沒有 Red 不准 Green。每個 Gate 主窗口必須親跑命令並貼 EXIT_CODE，不接受 sub-agent 口頭「完成」。
> **執行順序**：先線 A 物流（A-Cycle 0→1→2→3），後線 B 發票（B-Cycle 0→1→2）。

---

## 第 1 節：環境確認

| 項目 | 確認結果 |
|------|---------|
| 執行模式 | **本地**（`printenv GITHUB_ACTIONS` → exit 1，非 CI）。收尾保留變更等使用者驗收，不自動 commit/PR。 |
| specs 完整性 | ✅ 9 支 feature（6 logistics + 3 invoice）、2 activity、actor `PayNow.md`、ui `PayNow物流選店頁面.md`、clarify `2026-06-10-1136.md` 全到位。實作計劃 + execution plan 齊備。 |
| 技術棧 | PHP 8.1+ DDD（`J7\PowerCheckout` → `inc/classes/`）+ Vue 3 前端。本任務 99% 為 PHP 後端（兩個 provider）；Vue 設定頁為收尾末步。 |
| 派哪些 master | **僅 `@wordpress-master`**（全為 PHP/WP 後端 + WC_Shipping_Method + REST callback + DTO + Enum + Crypto helper）。Vue 設定頁（A-Cycle 3 / B-Cycle 2 末步）亦由 wordpress-master 鏡像既有頁面處理（前端無 PHPUnit，走 lint）。**不需 react-master**（WC Blocks 物流選店 block UI 明確排除，classic-first）。 |
| 測試 harness | `tests/Integration/{Logistics,Invoice}/`；namespace `Tests\Integration\`；base `Tests\Integration\TestCase`。group 白名單 `smoke/happy/error/edge/security`（phpunit.xml.dist 已確認），每個測試方法必掛至少一個，否則不被收集。`integration/logistics/invoice/paynow` 僅為分類 group，可併掛但不能單獨依賴。 |
| Grounding 來源 | 物流 = woomp `../woomp/includes/paynow-shipping/`（已確認存在）反推 API contract；發票 = `paynow` skill（Bearer JWT / `/api/invoices/*`）。 |
| 安全敏感 | ⚠️ 涉及 payment-adjacent（物流 COD 代收 + 發票稅務）+ external-api + TripleDES 加密 + webhook callback（`permission __return_true`）。**收尾建議用戶 opt-in 補派 `@zenbu-powers:security-reviewer`**（見第 6 節）。 |

---

## Cycle 全覽與依賴圖

```
線 A（物流，序列）                          線 B（發票，序列；可在 A 完成後開，或與 A 平行另開分支）
A-Cycle 0  加密/PassCode/Enum/MetaKeys  ──┐
   │ (無外部依賴，純單元)                  │
A-Cycle 1  SettingsDTO + Params + ApiClient│
   │                                       │
A-Cycle 2  Provider(10) + Callback         │   B-Cycle 0  Enum + SettingsDTO + IssueParams
   │                                       │      │ (無外部依賴)
A-Cycle 3  WC_Shipping + 註冊 + Vue        │   B-Cycle 1  ApiClient(mock) + Response DTOs
                                           │      │
                                           └───►  B-Cycle 2  Provider(三介面) + 註冊 + Vue
```

- **線 A 內部嚴格序列**（A-Cycle N+1 依賴 N 的產物）。
- **線 B 內部嚴格序列**。
- **線 A 與線 B 無共用程式碼**（僅共用 register 慣例），技術上可平行；但本藍圖建議**先 A 後 B**（planner 裁決：先解 TripleDES/webhook 不確定性，避免兩線同時除錯）。主窗口若要平行，須在獨立分支各跑一條鏈，最後合併。
- 每個 cycle 結束 = 一次完整 Red→Green→（Refactor 檢查點）；可獨立交付。

---

## 第 2-5 節：逐 Cycle 藍圖（線 A 物流）

> 每個 cycle 的結構：🔴 Red（test-creator 任務）→ 🚨 Red Gate（主窗口親跑）→ 🟢 Green（wordpress-master 任務）→ 🚨 Green Gate（主窗口親跑）→ 🔵 Refactor 檢查點。

---

### ▍A-Cycle 0：加密 + PassCode + Enums + MetaKeys（純單元，風險最高先打）

對應計劃實作步驟 1-4。**這是整條線的地基，TripleDES 雙模式（R2）+ meta 策略（R4）在此鎖死。**

#### 🔴 Red — spawn `@zenbu-powers:test-creator`

**派發 prompt 要素：**
- 任務：為 PayNow 物流加密層 + 基礎 helper 產生失敗的 PHPUnit 測試骨架（4 支測試類）。
- 傳入檔案：
  - 實作計劃 `specs/open-issue/paynow-logistics-invoice-implementation-plan.md`（讀「線 A A-Cycle 0」+「§meta key R4 決策」+「失敗模式登記表」TripleDES/PassCode 列）
  - 鏡像參考 src：`inc/classes/Domains/Logistics/Payuni/Shared/Helpers/PayuniCrypto.php`、`inc/classes/Domains/Payment/Paynow/Shared/Helpers/`（命名慣例）
  - 既有測試命名/mock 範本：`tests/Integration/Payment/PaynowEnumTest.php`、`tests/Integration/Payment/PaynowMetaKeysTest.php`、`tests/Integration/Logistics/PayuniCryptoTest.php`
  - woomp grounding：`../woomp/includes/paynow-shipping/includes/class-paynow-shipping-request.php`（L716-751 order json 加密）、`class-paynow-shipping.php`（L243-253 apicode 加密、L780 PassCode）
- 裁決傳達：
  - R2：TripleDES **兩方法獨立不可混用**——`encrypt_order_json()` = DES-EDE3 CBC + `OPENSSL_NO_PADDING` + 手動 `\0` pad 到 8B + base64；`encrypt_apicode()` = DES-EDE3-ECB + `OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING` + 手動 pad + base64 + `str_replace(' ','+',...)`。**各驗 round-trip**。
  - R3：固定 key=`123456789070828783123456`(24B) iv=`12345678`，常數寫入（測試用此向量）。
  - R4：MetaKeys 前綴 `_pc_paynow_logistics_`，**自建** `PaynowLogisticsMetaKeys`（不復用 shared `LogisticsMetaKeys`），須含 `get_order_by_order_no()` + `get_order_by_ref()` + `is_processed()`/`mark_processed()`。
  - R6：PassCode = `strtoupper(sha1(user_account + OrderNo + TotalAmount + apicode))`；測試鎖定固定向量 + 鎖定 `get_total()` 字串格式（"1000" 含小數議題）。
- **group 約束**：每個測試方法必掛 `smoke/happy/error/edge/security` 至少一，可併掛 `logistics/paynow`。
- ⚠️ 幣別踩雷：涉及金額/total 的測試須顯式 `update_option('woocommerce_currency','TWD')`。

**預期產出測試檔（4 支，放 `tests/Integration/Logistics/`）：**
| 測試類 | group | 關鍵斷言 |
|--------|-------|---------|
| `PaynowTripleDesCryptoTest` | `@group smoke @group security` | 兩方法各驗已知向量加密輸出 + round-trip；邊界：空字串 / 非 8B 倍數 / UTF-8 中文；兩模式輸出**不互換** |
| `PaynowPassCodeTest` | `@group happy` | 固定 user_account+OrderNo+total+apicode → 固定 sha1 大寫；total "1000" vs "1000.00" 格式敏感 |
| `PaynowLogisticsEnumTest` | `@group happy` | `PaynowLogisticService` 01-06/21-24 + `is_cvs()`；`PaynowDeliverMode` 01/02；`PaynowLogisticsStatus` 0/1 + 貨態碼 |
| `PaynowLogisticsMetaKeysTest` | `@group happy @group edge` | 前綴全 getter/setter；`get_order_by_order_no()`/`get_order_by_ref()` 反查；冪等 `is_processed()`/`mark_processed()` |

#### 🚨 A-Cycle 0 Red Gate（主窗口親跑）

```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ --filter 'Paynow(TripleDesCrypto|PassCode|LogisticsEnum|LogisticsMetaKeys)'" 2>&1; echo "EXIT_CODE=$?"
```

**綠燈標準（Red）**：4 測試類檔案存在 + EXIT_CODE ≠ 0 + 失敗原因為「class/method 不存在」或斷言失敗（非語法/環境錯）。
**失敗處理**：無測試檔→重派 test-creator；全綠（斷言空）→退回修正；環境錯→修環境重試（≤2 次）。

#### 🟢 Green — spawn `@wordpress-master`

**派發 prompt 要素：**
- 任務：實作 A-Cycle 0 四個 helper/enum 讓上述 4 測試類全綠（最小實作）。
- 實作檔案：
  - `inc/classes/Domains/Logistics/Paynow/Shared/Helpers/TripleDesCrypto.php`
  - `inc/classes/Domains/Logistics/Paynow/Shared/Helpers/PassCodeService.php`
  - `inc/classes/Domains/Logistics/Paynow/Shared/Helpers/PaynowLogisticsMetaKeys.php`
  - `inc/classes/Domains/Logistics/Paynow/Shared/Helpers/ItemName.php`（商品名 25 字截斷，對齊 woomp，併入此 cycle）
  - `inc/classes/Domains/Logistics/Paynow/Shared/Enums/PaynowLogisticService.php` / `PaynowDeliverMode.php` / `PaynowLogisticsStatus.php`
- 約束：`declare(strict_types=1)` + `final class` + PHPStan level 9 + 文字域 `power_checkout`；HPOS 用 `$order->get_meta()`。R2/R3/R4/R6 裁決同 Red。
- 傳入：同 Red 的 woomp grounding 檔案路徑 + 鏡像 src。

#### 🚨 A-Cycle 0 Green Gate（主窗口親跑）

同 Red Gate 命令，**預期 EXIT_CODE = 0**，貼「N passed」摘要。
**失敗處理**：測試仍紅→重派 wordpress-master 修（≤3 次）；測試本身崩潰→重派 test-creator 檢查設計。

#### 🔵 A-Cycle 0 Refactor 檢查點
- TripleDES 兩方法是否有重複 pad 邏輯可抽共用 private？（保持兩 public 方法獨立）
- PHPStan level 9 跑一次：`php -d memory_limit=2G vendor/bin/phpstan analyse`（無新增錯誤）。
- 不派 reviewer（opt-in）。

---

### ▍A-Cycle 1：Settings DTO + Params + ApiClient（mock）

對應計劃實作步驟 5-7。依賴 A-Cycle 0。

#### 🔴 Red — spawn `@zenbu-powers:test-creator`

**派發 prompt 要素：**
- 任務：為 PayNow 物流 SettingsDTO + 兩個 request Params DTO + ApiClient（mock 模式）產生失敗測試（3 支測試類）。
- 傳入：實作計劃「A-Cycle 1」段 + R8（test/prod 網域 `testlogistic.paynow.com.tw` / `logistic.paynow.com.tw`）+ R9（金額上限）；鏡像 `inc/classes/Domains/Logistics/Payuni/DTOs/PayuniLogisticsSettingsDTO.php`、`inc/classes/Domains/Logistics/Payuni/Http/PayuniLogisticsApiClient.php`；既有 mock 範本 `tests/Integration/Logistics/LogisticsApiClientTest.php`、`tests/Integration/Payment/PaynowRestClientTest.php`（`is_mock()` 模式）。
- 裁決：ApiClient `is_mock()`（讀 `getenv('API_MODE')`）回 fixture；`add_order/renew_order/cancel_order/query_order/print_label`；JsonOrder = base64(TripleDES order_json)；cancel 走 DELETE。

**預期產出測試檔（`tests/Integration/Logistics/`）：**
| 測試類 | group | 關鍵斷言 |
|--------|-------|---------|
| `PaynowLogisticsSettingsDTOTest` | `@group happy` | user_account/apicode/mode/enabled_methods/sender_*；`api_url()` test/prod 切換（R8） |
| `PaynowCreateShipmentParamsTest` | `@group happy @group edge` | `DTO::parse()` 組 Add_Order args（Description/DeliverMode/Logistic_service/OrderNo/Receiver_*/Sender_*/PassCode/TotalAmount/EC + 黑貓 DeliveryType/Weight/L/W/H） |
| `PaynowLogisticsApiClientTest` | `@group happy @group integration` | mock 模式驗請求組裝（JsonOrder base64 / DELETE method / query URL）+ fixture 回應解析 |

#### 🚨 A-Cycle 1 Red Gate（主窗口親跑）
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ --filter 'Paynow(LogisticsSettingsDTO|CreateShipmentParams|LogisticsApiClient)'" 2>&1; echo "EXIT_CODE=$?"
```
綠燈標準（Red）：3 檔存在 + EXIT_CODE ≠ 0。

#### 🟢 Green — spawn `@wordpress-master`
- 實作檔案：
  - `inc/classes/Domains/Logistics/Paynow/DTOs/PaynowLogisticsSettingsDTO.php`（extends `BaseSettingsDTO`）
  - `inc/classes/Domains/Logistics/Paynow/DTOs/CreateShipmentParams.php`
  - `inc/classes/Domains/Logistics/Paynow/DTOs/StoreSelectionParams.php`
  - `inc/classes/Domains/Logistics/Paynow/Http/LogisticsApiClient.php`
- 依賴 A-Cycle 0 helper（TripleDesCrypto/PassCodeService）。

#### 🚨 A-Cycle 1 Green Gate（主窗口親跑）
同 Red Gate，EXIT_CODE = 0。

#### 🔵 A-Cycle 1 Refactor 檢查點
- ApiClient 的 mock fixture 是否集中可維護；真 API 路徑用 `wp_remote_*`。PHPStan 過。

---

### ▍A-Cycle 2：Provider（10 methods）+ Callback（風險集中：R1 webhook + R4 反查 + R9 上限）

對應計劃實作步驟 8-9。**對映 6 支物流 feature 全場景。** 依賴 A-Cycle 0+1。

#### 🔴 Red — spawn `@zenbu-powers:test-creator`

**派發 prompt 要素：**
- 任務：為 `PaynowLogisticsProvider`（10 methods）+ 兩個 callback 產生失敗測試，**逐場景對映 6 支 feature**。
- 傳入 feature（逐檔對映）：
  - `specs/features/logistics/paynow-logistics-store-selection.feature`
  - `specs/features/logistics/paynow-logistics-selection-callback.feature`
  - `specs/features/logistics/paynow-logistics-create-shipment.feature`
  - `specs/features/logistics/paynow-logistics-query.feature`
  - `specs/features/logistics/paynow-logistics-print-document.feature`
  - `specs/features/logistics/paynow-logistics-cancel.feature`
- 傳入：實作計劃「A-Cycle 2」+「§物流貨態通知 R1 決策」+「資料流分析 流程 1/2/4」+「錯誤處理登記表 物流」；介面 `inc/classes/Domains/Logistics/Shared/Interfaces/ILogisticsProvider.php`（10 methods 簽章）；鏡像 `inc/classes/Domains/Logistics/Payuni/Services/PayuniLogisticsProvider.php`、`Http/PayuniLogisticsCallback.php`；既有測試範本 `tests/Integration/Logistics/PayuniLogisticsProviderTest.php`、`PayuniLogisticsCallbackTest.php`。
- 裁決傳達：
  - R1：`handle_status_callback()` **不退化為查詢**——實作為「解析推送 payload（`orderno`/`PayNowLogisticCode`/`Detail_Status_Description`/`paymentno`/`StoreDate`/`StoreTime`）→ 以 `orderno` 反查訂單 → 冪等 → 更新 meta → 恆回 HTTP 200」。**另有 status-callback REST 端點**（woomp 有證據）。`query_shipment()` 為補單手段並存。
  - R4：callback 反查走 PayNow 自己的 `PaynowLogisticsMetaKeys::get_order_by_order_no()`（用 OrderNo，非 LogisticNumber），不依賴 shared。
  - R9：`create_shipment` 前置驗證金額上限（超商 ≤20000、宅配 ≤100000）→ 超限 throw。
  - `create_return()` → throw `\Exception('尚未實作')`。
  - 冪等：已有 ref 且 status≠"1" → ReNewOrder 而非重複 Add_Order。
- **callback 安全**：`permission __return_true`，內部弱驗證（orderno 存在性 + meta 一致性）；強驗證待官方文件（GAP）。測試掛 `@group security`。

**預期產出測試檔（`tests/Integration/Logistics/`）：**
| 測試類 | group | 對映 feature / 關鍵場景 |
|--------|-------|------------------------|
| `PaynowLogisticsProviderTest` | `@group happy @group error @group edge @group integration` | store-selection（provider未啟用/訂單不存在/非啟用子類型/SEVEN組裝 serviceID=01+returnUrl+TripleDES apicode/TCAT跳過/回 redirect_target）；create-shipment（無門市→fail/SEVEN DeliverMode=02/COD=01/TCAT DeliveryType=0003/>20000→fail/Status=F→fail+note/Status=S寫 ref+payment_no+validation_no/冪等→ReNewOrder）；query（無ref→fail/帶 LogisticNumber+sno=1/寫 status+Delivery_Status+LogisticCode/COD取貨完成→collection_paid）；print（無ref→fail/SEVEN→Order711/TCAT→PrintBlackCatLabel回PDF/RenewOrderNo）；cancel（無ref→fail/DELETE+PassCode/含'S'→status=1+note/不含'S'→fail+手動提示/create_return→throw 尚未實作） |
| `PaynowLogisticsSelectionCallbackTest` | `@group happy @group security @group edge` | 缺 storeid→fail「選店回呼缺少門市資訊」/寫 store_id/name/addr meta/cid 反查訂單/來源弱驗證 |
| `PaynowLogisticsStatusCallbackTest` | `@group happy @group security @group edge` | （R1 額外）推送解析/orderno 反查/冪等防重（"{OrderNo}:{LogisticCode}"）/COD取貨完成標記/恆回 HTTP 200（含例外路徑） |

#### 🚨 A-Cycle 2 Red Gate（主窗口親跑）
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ --filter 'PaynowLogistics(Provider|SelectionCallback|StatusCallback)'" 2>&1; echo "EXIT_CODE=$?"
```
綠燈標準（Red）：3 檔存在 + EXIT_CODE ≠ 0 + 失敗為 class/method 不存在或斷言。

#### 🟢 Green — spawn `@wordpress-master`
- 實作檔案：
  - `inc/classes/Domains/Logistics/Paynow/Services/PaynowLogisticsProvider.php`（implements `ILogisticsProvider` 10 methods；const ID='paynow_logistics'；`logger()` 慣例）
  - `inc/classes/Domains/Logistics/Paynow/Http/LogisticsCallback.php`（selection-callback + status-callback 兩端點，`register_hooks()` 靜態）
- 依賴 A-Cycle 0+1 全部產物。R1/R4/R9 裁決同 Red。
- callback 所有路徑（含 `\Throwable`）catch → 回 HTTP 200。

#### 🚨 A-Cycle 2 Green Gate（主窗口親跑）
同 Red Gate，EXIT_CODE = 0。**這是線 A 風險最高的 Gate**，失敗重派 wordpress-master 並指明哪支 feature 場景紅（≤3 次）。

#### 🔵 A-Cycle 2 Refactor 檢查點
- Provider 10 方法是否過長可抽 private helper；callback 反查邏輯是否與 Provider 重複。
- ⚠️ **安全敏感**：callback `permission __return_true` + TripleDES + COD 代收——記錄到收尾，建議 opt-in `security-reviewer`。PHPStan 過。

---

### ▍A-Cycle 3：WC_Shipping_Method + 註冊 + Vue（整合收口）

對應計劃實作步驟 10-12。依賴 A-Cycle 0-2。

#### 🔴 Red — spawn `@zenbu-powers:test-creator`
**派發 prompt 要素：**
- 任務：為 `WC_PaynowLogisticsShipping` + ProviderRegister/LogisticsApiService 註冊變更產生失敗測試（2 支測試類）。
- 傳入：實作計劃「A-Cycle 3」+「§註冊變更」表；鏡像 `inc/classes/Domains/Logistics/Ecpay/Services/WC_EcpayLogisticsShipping.php`、`Domains/Logistics/ProviderRegister.php`、`Shared/Services/LogisticsApiService.php`（`PROVIDER_IDS`）；既有測試 `tests/Integration/Logistics/WC_EcpayLogisticsShippingTest.php`、`LogisticsProviderRegisterTest.php`、`PayuniLogisticsRegisterTest.php`、`LogisticsApiServiceTest.php`。
- 裁決：`PROVIDER_IDS` 加 `paynow_logistics`（REST 委派可解析）；callback 註冊段加 `if is_enabled(paynow_logistics)`；per-service 運送方式（SEVEN/FAMI/HILIFE/TCAT）；`save_checkout_meta()` 寫 service_id/sub_type。

**預期產出測試檔：**
| 測試類 | group | 關鍵斷言 |
|--------|-------|---------|
| `WC_PaynowLogisticsShippingTest` | `@group happy` | per-service 運送方式註冊；`is_chosen()`；`save_checkout_meta()` 寫 `_pc_paynow_logistics_service_id` |
| `PaynowLogisticsRegisterTest` | `@group integration @group happy` | `$logistics_providers` 含 paynow_logistics；`LogisticsApiService::PROVIDER_IDS` 含 paynow_logistics（REST 委派）；callback 條件註冊 |

> ⚠️ `LogisticsApiServiceTest` 既有檔擴充（paynow 委派）由 wordpress-master 在 Green 時併入，test-creator 在此新增對應斷言。

#### 🚨 A-Cycle 3 Red Gate（主窗口親跑）
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ --filter 'WC_PaynowLogisticsShipping|PaynowLogisticsRegister'" 2>&1; echo "EXIT_CODE=$?"
```

#### 🟢 Green — spawn `@wordpress-master`
- 實作/修改檔案：
  - 建 `inc/classes/Domains/Logistics/Paynow/Services/WC_PaynowLogisticsShipping.php`
  - 改 `inc/classes/Domains/Logistics/ProviderRegister.php`（加 provider/shipping method/callback 註冊/save_checkout_meta 委派）
  - 改 `inc/classes/Domains/Logistics/Shared/Services/LogisticsApiService.php`（`PROVIDER_IDS` 加 paynow_logistics）
  - 建 Vue：`js/src/pages/Logistics/Paynow/index.vue` + 改 `js/src/router/index.ts`（route + ROUTER_MAPPER）。鏡像 `js/src/pages/Logistics/Ecpay/index.vue`。
- Vue 無 PHPUnit，走 `pnpm lint`（收尾統一驗）。

#### 🚨 A-Cycle 3 Green Gate（主窗口親跑）
1. 上述 filter 命令 EXIT_CODE = 0。
2. **線 A 全套回歸**（確認未破壞既有 logistics 測試）：
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/" 2>&1; echo "EXIT_CODE=$?"
```
綠燈標準：EXIT_CODE = 0（既有 2 個 pre-existing 失敗 ezpay edge + RedirectSettingsDTO **不在 Logistics 目錄**，此 Gate 應全綠）。

#### 🔵 A-Cycle 3 Refactor 檢查點
- 前端 `pnpm lint` 通過；PHPStan level 9 無新增。線 A 完成，可獨立合併。

---

## 第 2-5 節：逐 Cycle 藍圖（線 B 發票）

> 線 A 完成後開始（或另開分支平行）。鏡像 `Invoice/Ezpay/`。

---

### ▍B-Cycle 0：Enums + Settings DTO + IssueParams

對應計劃實作步驟 13-15。無外部依賴。

#### 🔴 Red — spawn `@zenbu-powers:test-creator`
**派發 prompt 要素：**
- 任務：為發票 3 個 Enum + SettingsDTO + IssueParams 產生失敗測試（3 支）。
- 傳入：實作計劃「B-Cycle 0」+ R5（const ID='paynow_invoice' + option `woocommerce_paynow_invoice_settings`）+ R10（tax_amount：非統編=0、統編=實際稅額；零稅率必填 reason；載具捐贈互斥）；feature `specs/features/invoice/paynow-invoice-issue.feature`（載具/捐贈/統編/零稅率場景）；鏡像 `inc/classes/Domains/Invoice/Ezpay/Shared/Enums/`（ECarrierType/ETaxType）、`Ezpay/DTOs/EzpaySettingsDTO.php`、`Ezpay/DTOs/IssueParams.php`；既有測試 `tests/Integration/Invoice/EzpayIssueParamsTest.php`、`EzpaySettingsDTOTrimTest.php`。
- ⚠️ R5 裁決：**測試斷言 provider id = `paynow_invoice`**（非 feature 文字寫的 `paynow`）；option key `woocommerce_paynow_invoice_settings`。

**預期產出測試檔（`tests/Integration/Invoice/`）：**
| 測試類 | group | 關鍵斷言 |
|--------|-------|---------|
| `PaynowInvoiceEnumTest` | `@group happy` | `ECarrierType`（None/PhoneBarCodeCarrier/EasyCardCarrier/CitizenDigitalCardNo/BuyerSno）；`ETaxType`（SaleTax/FreeTax/ZeroTax/MixTax）；`EZeroTaxReason`；結帳 individual/company/donate → carrier_type 映射 |
| `PaynowInvoiceSettingsDTOTest` | `@group happy` | jwt_token/mode/api_url(dev/prod)/seller/auto_issue_order_statuses/auto_allowance_on_refund；option key `woocommerce_paynow_invoice_settings`（R5） |
| `PaynowIssueParamsTest` | `@group happy @group edge @group error` | carrier_type 映射；tax_amount（非統編=0 / 統編=實際稅額 R10）；載具捐贈互斥→throw；零稅率缺 reason→throw；`build_merchant_order_no()` |

#### 🚨 B-Cycle 0 Red Gate（主窗口親跑）
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/ --filter 'PaynowInvoice(Enum|SettingsDTO)|PaynowIssueParams'" 2>&1; echo "EXIT_CODE=$?"
```

#### 🟢 Green — spawn `@wordpress-master`
- 實作檔案：
  - `inc/classes/Domains/Invoice/Paynow/Shared/Enums/ECarrierType.php` / `ETaxType.php` / `EZeroTaxReason.php`
  - `inc/classes/Domains/Invoice/Paynow/DTOs/PaynowInvoiceSettingsDTO.php`（extends `BaseSettingsDTO`）
  - `inc/classes/Domains/Invoice/Paynow/DTOs/IssueParams.php`
- grounding：`paynow` skill 發票 API（carrier_type/tax_type/tax_amount/npoban/zero_tax_rate_reason）。

#### 🚨 B-Cycle 0 Green Gate（主窗口親跑）
同 Red Gate，EXIT_CODE = 0。

#### 🔵 B-Cycle 0 Refactor 檢查點
- tax_amount 計算邏輯集中可測；PHPStan 過。

---

### ▍B-Cycle 1：ApiClient（mock）+ Response DTOs

對應計劃實作步驟 16-17。依賴 B-Cycle 0。

#### 🔴 Red — spawn `@zenbu-powers:test-creator`
**派發 prompt 要素：**
- 任務：為發票 `InvoiceApiClient`（mock）+ Response DTOs 產生失敗測試（1 支主測試類，Response DTO 斷言併入）。
- 傳入：實作計劃「B-Cycle 1」；`paynow` skill（Bearer JWT-Token header；端點 `/api/invoices/issue|cancel|allowance|cancel-allowance`；GET `/api/invoices`；外層回應 `{status,type,message,result,request_id}`，type=success 判斷）；鏡像 `inc/classes/Domains/Invoice/Ezpay/Http/InvoiceApiClient.php`、`Ezpay/DTOs/IssueResponse.php`；既有測試 `tests/Integration/Invoice/EzpayInvoiceApiClientMockTest.php`、`AmegoApiClientMockTest.php`。

**預期產出測試檔：**
| 測試類 | group | 關鍵斷言 |
|--------|-------|---------|
| `PaynowInvoiceApiClientMockTest` | `@group happy @group integration` | `Authorization: Bearer {jwt_token}` header；issue/cancel/allowance/invalid_allowance/query body 組裝；fixture 解析（type=success → IssueResponse/AllowanceResponse/QueryResponse；`invoice_number`/`allowance_number` 取值） |

#### 🚨 B-Cycle 1 Red Gate（主窗口親跑）
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/ --filter 'PaynowInvoiceApiClientMock'" 2>&1; echo "EXIT_CODE=$?"
```

#### 🟢 Green — spawn `@wordpress-master`
- 實作檔案：
  - `inc/classes/Domains/Invoice/Paynow/Http/InvoiceApiClient.php`（`is_mock()` fixture）
  - `inc/classes/Domains/Invoice/Paynow/DTOs/IssueResponse.php` / `AllowanceResponse.php` / `QueryResponse.php`
  - `inc/classes/Domains/Invoice/Paynow/DTOs/AllowanceParams.php` / `QueryParams.php`（請求 DTO，併入此 cycle）

#### 🚨 B-Cycle 1 Green Gate（主窗口親跑）
同 Red Gate，EXIT_CODE = 0。

#### 🔵 B-Cycle 1 Refactor 檢查點
- fixture 集中；外層 `{status,type,...}` 解析抽共用。PHPStan 過。

---

### ▍B-Cycle 2：Provider（三介面）+ 註冊 + Vue（對映 3 支發票 feature）

對應計劃實作步驟 18-20。依賴 B-Cycle 0+1。

#### 🔴 Red — spawn `@zenbu-powers:test-creator`
**派發 prompt 要素：**
- 任務：為 `PaynowInvoiceProvider`（IInvoiceService + ISupportsAllowance + ISupportsQuery）+ 註冊產生失敗測試，**逐場景對映 3 支 feature**。
- 傳入 feature：
  - `specs/features/invoice/paynow-invoice-issue.feature`
  - `specs/features/invoice/paynow-invoice-allowance.feature`
  - `specs/features/invoice/paynow-invoice-query.feature`
- 傳入：實作計劃「B-Cycle 2」+「資料流分析 流程 3」+「錯誤處理登記表 發票」；介面 `inc/classes/Domains/Invoice/Shared/Interfaces/IInvoiceService.php`/`ISupportsAllowance.php`/`ISupportsQuery.php`；鏡像 `inc/classes/Domains/Invoice/Ezpay/Services/EzpayInvoiceProvider.php`、`Domains/Invoice/ProviderRegister.php`；既有測試 `tests/Integration/Invoice/EzpayInvoiceProviderTest.php`、`EzpayAllowanceTest.php`、`EzpayQueryTest.php`、`RefundAllowanceHookTest.php`。
- 裁決：const ID='paynow_invoice'（R5）；catch `\Throwable` → log + order note → 回 []；冪等（issued_data 已存在不重打）；全額退款走作廢非折讓（remaining≤0）；query 唯讀不改狀態。

**預期產出測試檔（`tests/Integration/Invoice/`）：**
| 測試類 | group | 對映 feature / 關鍵場景 |
|--------|-------|------------------------|
| `PaynowInvoiceProviderTest` | `@group happy @group error @group edge @group invoice` | issue（訂單不存在→500/冪等不重打/Bearer header/B2C手機條碼 carrier_type=PhoneBarCodeCarrier+tax_amount=0/B2B統編 buyer.identifier+實際稅額/捐贈 npoban+carrier_type空/載具捐贈互斥→fail/零稅率缺reason→fail/type≠success不寫data+note/作廢帶invoice_number/自動開立hook）；allowance（未開立不折讓/部分退款→allowance API+寫allowance_data/全額退款→走作廢/作廢折讓帶allowance_number+清資料）；query（未開立→空/帶 InvoiceNumber/type=success回明細不改狀態/type≠success回空） |
| `PaynowInvoiceRegisterTest` | `@group integration @group happy` | `$invoice_providers` 含 paynow_invoice；auto-issue hook 掛載；退款折讓路由；option key `woocommerce_paynow_invoice_settings` 不撞金流 `woocommerce_paynow_settings`（R5） |

> ⚠️ 既有 `InvoiceApiService`/`RefundAllowanceHook` 為 provider-agnostic，test-creator 視需要新增 paynow 委派斷言（多半零改動，hook 自動套用）。

#### 🚨 B-Cycle 2 Red Gate（主窗口親跑）
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/ --filter 'PaynowInvoice(Provider|Register)'" 2>&1; echo "EXIT_CODE=$?"
```

#### 🟢 Green — spawn `@wordpress-master`
- 實作/修改檔案：
  - 建 `inc/classes/Domains/Invoice/Paynow/Services/PaynowInvoiceProvider.php`（implements 三介面；const ID='paynow_invoice'）
  - 改 `inc/classes/Domains/Invoice/ProviderRegister.php`（`$invoice_providers` 加一行）
  - 建 Vue：`js/src/pages/Invoices/Paynow/index.vue` + 改 `js/src/router/index.ts`（route + ROUTER_MAPPER）。鏡像 `js/src/pages/Invoices/Ezpay/index.vue`。

#### 🚨 B-Cycle 2 Green Gate（主窗口親跑）
1. 上述 filter 命令 EXIT_CODE = 0。
2. **線 B 全套回歸**：
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
  bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/" 2>&1; echo "EXIT_CODE=$?"
```
綠燈標準：**僅 1 個 pre-existing 失敗（ezpay edge）**，其餘全綠。若 paynow 相關全綠且失敗數未增加 → 通過。

#### 🔵 B-Cycle 2 Refactor 檢查點
- Provider 三介面方法是否與 EzpayProvider 重複可抽（保持鏡像獨立）；前端 lint 過；PHPStan 過。

---

## 第 6 節：🔵 Refactor 階段（Green Gate 通過後直接收尾）

**Green Gate 通過後不強制派 reviewer。**

**Optional Manual Quality Pass（opt-in，由用戶決定）：**

本任務涉及多項安全敏感領域，**強烈建議用戶 opt-in 補派**：

| Reviewer | 觸發理由 | 喚醒方式 |
|----------|---------|---------|
| `@zenbu-powers:security-reviewer` | **強烈建議**：TripleDES 自實作加密（R2/R3 固定 key/IV）+ 兩個 `permission __return_true` callback（selection + status，弱驗證）+ COD 代收貨款 + 發票稅務 + Bearer JWT-Token 處理 | 用戶顯式喚醒 |
| `@wordpress-reviewer` | opt-in：兩個全新 provider ~40 檔案，PHP/WP 編碼規範深度審查（WordPress 專案需先 `/copy-sets`，複製後無前綴調用） | 用戶顯式喚醒 |

> reviewer 退回意見依嚴重性處理；若進入 reviewer ↔ wordpress-master 修復迴圈，最多 3 輪。

---

## 第 7 節：收尾藍圖（主窗口執行）

> **前置**：cwd `specs/milestones/` 不存在（本專案無 milestone 資料夾）→ **跳過 milestone 同步**步驟 0。

1. **最終全套 Green Gate**（主窗口親跑，確認兩線 + 既有測試）：
   ```bash
   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
     bash -c "API_MODE=mock vendor/bin/phpunit" 2>&1; echo "EXIT_CODE=$?"
   ```
   綠燈標準：**僅 2 個 pre-existing 失敗（ezpay edge + RedirectSettingsDTO）**，paynow 物流/發票全綠，失敗數未增加。

2. **PHPStan level 9**（主窗口親跑）：
   ```bash
   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
     bash -c "php -d memory_limit=2G vendor/bin/phpstan analyse" 2>&1; echo "EXIT_CODE=$?"
   ```
   綠燈標準：無新增錯誤。

3. **前端 lint**（Vue 設定頁）：`pnpm lint`（ESLint + PHPCBF）。

4. **spawn `@zenbu-powers:doc-updater`** 同步專案文件（**必派**）：
   - `.claude/CLAUDE.md`：新增 PayNow 物流（`paynow_logistics`）+ 發票（`paynow_invoice`）domain 說明、order meta keys（`_pc_paynow_logistics_*` 13 個）、REST 端點（selection-callback + status-callback）、WordPress hooks。
   - `.claude/rules/provider-guide.rule.md`：物流/發票 provider 清單更新。
   - **reconcile feature/activity 文字**（plan 末段 5 項待覆核）：
     - R1：`paynow-logistics-query.feature` 的「handle_status_callback 退化為查詢補單」描述 → 改為「query 為補單手段，另有貨態推送 status-callback」。
     - R5：9 支 feature/activity 內發票 provider 寫 `paynow` → reconcile 為 `paynow_invoice`。
     - R3 固定 key/IV prod 換鑰、sandbox 憑證、官方物流 API 文件核對 → 標 GAP（非阻塞）。

5. **commit/收尾**：本地模式 → **保留變更等使用者驗收**（不自動 commit）。使用者要求時用 `/zenbu-powers:git-commit` 按 cycle 拆原子 commit（建議：feat(logistics) 線 A、feat(invoice) 線 B、test、docs 分開）。

6. **彙整摘要回報使用者**：
   - 測試結果：線 A 6 feature + 線 B 3 feature 對映測試 mock 全綠；TripleDES 雙模式 round-trip / PassCode 固定向量 / status-callback 冪等恆回 200 通過。
   - 關鍵變更：~40 檔（2 provider + helper + DTO + enum + callback + 註冊 + 2 Vue 頁）。
   - **建議 opt-in**：`@zenbu-powers:security-reviewer`（加密 + callback + COD + 稅務）。
   - **GAP 提醒**：sandbox 憑證（物流 user_account/apicode + 發票 jwt_token）未申請，僅 mock 驗收；prod key/IV 換鑰待確認；官方物流 API 文件待核對（現以 woomp 反推）。
   - milestone 狀態：N/A（無 milestones 資料夾）。

---

## Hand-off：主窗口照辦清單

> **逐 cycle 執行，不可跳序。每個 Gate 主窗口親跑命令並貼 EXIT_CODE。**

**線 A（物流，序列）：**
1. A-Cycle 0：spawn test-creator → 跑 Red Gate → spawn wordpress-master → 跑 Green Gate
2. A-Cycle 1：同上
3. A-Cycle 2：同上（風險最高，Green Gate 失敗指明哪支 feature 場景紅）
4. A-Cycle 3：同上（Green Gate 含線 A 全套回歸）

**線 B（發票，序列；A 完成後或另分支平行）：**
5. B-Cycle 0：spawn test-creator → Red Gate → wordpress-master → Green Gate
6. B-Cycle 1：同上
7. B-Cycle 2：同上（Green Gate 含線 B 全套回歸）

**收尾：**
8. 最終全套 Green Gate + PHPStan + lint（主窗口親跑）
9. spawn doc-updater（CLAUDE.md + rule + feature/activity reconcile）
10. 彙整回報使用者（提醒 opt-in security-reviewer + GAP）

**阻擋條件**：任何 cycle 的 Green Gate 重試 3 次仍紅 → 中止該 cycle，保留變更，回報失敗的 feature 場景清單供人工介入。
