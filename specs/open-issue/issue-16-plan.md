# 實作計劃：Issue #16 — API KEY 自動修剪前後不可見字元

## 概述

管理員從 Shopline / Amego 後台複製金鑰時，常不小心連同肉眼看不見的前後空白、全形空白、零寬字元一起貼入 WooCommerce 後台設定欄位，導致儲存後 API 認證失敗、開發票失敗，且管理員與客服都很難自助發現。

本計劃透過**三層防線**徹底解決：
1. **後端寫入時 trim**（`ProviderUtils::update_option` 統一處理，所有 provider 自動受惠）
2. **後端讀取時 trim**（DTO `before_init()` / `after_init()` hooks，既有資料無感修復）
3. **前端失焦時 trim**（`<TrimmedInput>` 共用元件，UX 加分）

修剪字元集涵蓋：半形空白、Tab、CR、LF、全形空白（U+3000）、不換行空白（U+00A0）、零寬空白／非連接／連接（U+200B / U+200C / U+200D）、BOM（U+FEFF）。**僅修剪「前後」，欄位中間的不可見字元不動**。

## 範圍模式

**HOLD SCOPE** — 行為層的精準調整。範圍由 `specs/clarify/2026-04-27-1156.md` 的 9 個 Q&A 完全鎖定，不擴大、不改既有 sanitize 邏輯、不擴及 migration。

---

## 已知風險（來自研究）

- **風險 1：DTO `before_init()` / `after_init()` 介面差異**
  `RedirectSettingsDTO::before_init()` 操作 `$this->dto_data[ $key ]`（屬性還沒被指派），`after_init()` 直接操作 `$this->{$prop}`（屬性已指派）。trim 寫在哪一個 hook 影響很大：
  - 若寫在 `before_init`：可在屬性指派前修剪，避免型別轉換後又 cast 回污染值。但 `RedirectSettingsDTO::before_init` 已處理 `int` 轉換 + 預設值塞入；新邏輯要插入但不能干擾 int cast 路徑（`expire_min` / `min_amount` / `max_amount` 不能 trim）。
  - 若寫在 `after_init`：屬性已指派且型別已轉換，只 trim 字串屬性最直觀；但 `RedirectSettingsDTO::after_init` 已處理「測試模式覆寫 + signKey 編碼轉換」，trim 必須在這些邏輯之後執行，否則覆寫的 hard-coded 值是乾淨的、卻被 trim 之前的 raw 值污染。
  **緩解**：選擇 `after_init` 並排在最後（測試模式覆寫之後、signKey 編碼轉換之後）；同時在 `before_init` 對 `dto_data` 中 **string 型別** 的 key 預先 trim，避免 dto_data 殘留污染（雙保險）。
  **決策**：採用「`before_init` 對 `dto_data` 全量 trim string 值（跳過 array、int、float、bool、enum） + `after_init` 對 `signKey` 編碼轉換後再 trim 一次」。

- **風險 2：`ProviderUtils::update_option` 的 `wp_parse_args` 淺層合併**
  現有寫入路徑為：
  ```php
  return \update_option( ..., \wp_parse_args( $values, $settings_array) );
  ```
  `wp_parse_args` 是淺層（top-level）合併。**若我們直接對 `$values` 做 `trim_invisible_deep()` 後再傳給 `wp_parse_args`，是安全的**（merge 後的值仍然是已 trim 的）。但若先 merge 後再 trim，會多 trim 到既有的 `$settings_array` 部分（無害，但效能差且職責混亂）。
  **緩解**：在 merge **之前** 對 `$values` 做 `trim_invisible_deep()`。

- **風險 3：`enabled` 等可能被誤 trim 的非語意字串欄位**
  `enabled` 欄位 `'yes'` / `'no'` 是字串、不是合法的「不應 trim 範圍」之外，但其實 trim 對它們無害（`'yes'` trim 後還是 `'yes'`）。**實際風險來自 `signKey` 之類「已被 `mb_convert_encoding` 處理過的 UTF-8 字串」**：若編碼轉換產生不可見前後字元（罕見但可能），讀取時 trim 可解、但寫入時 trim 是在 `mb_convert_encoding` 之前。
  **緩解**：`signKey` 不在前端／後端寫入路徑做特例處理，`mb_convert_encoding` 仍保留在 `after_init`，trim 在編碼之後再執行。

- **風險 4：`sanitize_text_field_deep` 已存在於 `SettingApiService::post_providers_with_id_callback`**
  `WP::sanitize_text_field_deep` 內部會做 `\sanitize_text_field()`，而 `\sanitize_text_field()` 本身會「移除 \r\n 與多餘空白、收斂連續空白為單一空白」，但**對全形空白、零寬字元、不換行空白通常不處理**。我們的 trim_invisible 必須在 sanitize 之後仍然能處理這些字元，且 trim 不可破壞 sanitize 對 HTML / `<script>` 的剝除（規則 4 場景已在 feature 中明確要求）。
  **緩解**：trim 邏輯放在 `ProviderUtils::update_option`，**位於 `sanitize_text_field_deep` 之後**，保證 sanitize 先跑、再 trim 不可見字元。

- **風險 5：陣列欄位（`allowPaymentMethodList`）的 trim 行為**
  Feature 場景明確要求陣列內的字串元素也要 trim 前後空白（`["  CreditCard  ", " LinePay "]` → `["CreditCard", "LinePay"]`），但**不可遞迴進入物件型別 / DTO 子陣列**亂改值。
  **緩解**：`StrHelper::trim_invisible_deep()` 採「遞迴判斷型別」：
  - `string` → `trim_invisible($value)`
  - `array` → 遞迴
  - 其他型別（int / float / bool / object / null）→ 原值返回

- **風險 6：DTO 屬性的 `mode` 是 enum / 布林**
  `BaseSettingsDTO::before_init` 已將 `mode` 從 string 轉成 `Mode` enum；若 trim 在 base 的 `before_init` 之前對 dto_data 動手時，`mode` 還是 string，trim 不會壞事。但若 trim 在 child 的 `before_init` 之後執行（`parent::before_init()` 已轉 enum），對 enum 物件呼叫 trim 會出錯。
  **緩解**：trim 邏輯只處理 `is_string($value) === true` 的 dto_data 條目，跳過所有其他型別。

- **風險 7：純不可見字元的欄位 trim 後變成空字串**
  Feature 場景「`apiKey: '    '` → `''`」。若該欄位（如 `apiKey`）在 `validate()` 期間有 required 檢查，trim 後變成空字串會觸發 validation error；但目前 `RedirectSettingsDTO` 預設值就是 `''`，validate 沒有 required 檢查，PHP 端不會擋。前端 `el-form` rules 才有 required 檢查，若使用者貼了純空白並失焦，trim 後變成空字串會立即觸發 form validator 紅字 ── 這正是預期 UX。
  **緩解**：無需特殊處理；單純 trim 後空字串即正確行為。

- **風險 8：前端 `<TrimmedInput>` 與 `el-input` 的 v-model 雙向綁定**
  Element Plus 的 `el-input` 用 `modelValue` + `update:modelValue` 雙向綁定。`<TrimmedInput>` 必須：
  - 透過 `defineModel()` 或 props/emit 包裝完整轉發 `modelValue`、`update:modelValue`
  - 在 `@blur` 時取得當前 input 值、執行 trim_invisible、emit 新值
  - 透過 `v-bind="$attrs"` 透傳所有 prop（`clearable` / `disabled` / `placeholder` / `class` ...）
  - Slot 透傳（`#prefix` / `#suffix` / `#prepend` / `#append`）
  **緩解**：使用 Vue 3.4+ 的 `defineModel()` 巨集 + `inheritAttrs: false` + `v-bind="$attrs"` 模式。

- **風險 9：前端與後端 trim 字元集不同步**
  Issue 風險章節明確要求兩端等價。
  **緩解**：定義「字元集 single source of truth」於文件化的 regex pattern，PHP 與 TypeScript 各自實作但**共用同一份字元清單**：`\x09\x0A\x0D\x20\xA0　​‌‍﻿`。在程式碼註解中明確標示「若擴增此清單，PHP / TS 兩邊必須同步」。

- **風險 10：DTO 讀取時 trim 不寫回 DB**
  Feature 場景明確要求「讀取時 trim 不會主動寫回資料庫」。
  **緩解**：`before_init` 只改 `$this->dto_data` 與屬性，**不呼叫** `update_option`。等到管理員下次點儲存才寫回。

---

## 架構變更

### 後端

1. **`inc/classes/Shared/Utils/StrHelper.php`** — 新增 trim 工具函式
   - 新增 `public static function trim_invisible(string $value): string`
     - 用 PCRE `u` flag regex 處理：`/^[\x{0009}\x{000A}\x{000D}\x{0020}\x{00A0}\x{3000}\x{200B}\x{200C}\x{200D}\x{FEFF}]+|[\x{0009}\x{000A}\x{000D}\x{0020}\x{00A0}\x{3000}\x{200B}\x{200C}\x{200D}\x{FEFF}]+$/u`
     - 也涵蓋 `\x{000B}` / `\x{000C}`（垂直 tab / form feed）以對齊 PHP `trim()` 的傳統字元集
   - 新增 `public static function trim_invisible_deep(mixed $value): mixed`
     - 遞迴：`is_string` → `trim_invisible`；`is_array` → 遞迴每個元素；其他原值返回

2. **`inc/classes/Shared/Utils/ProviderUtils.php`** — 寫入時統一 trim
   - 修改 `update_option(string $provider_id, string|array $key_or_values, mixed $value = ''): bool`
     - 若 `$key_or_values` 是 array：`$key_or_values = StrHelper::trim_invisible_deep($key_or_values);` 再做 wp_parse_args
     - 若是 string + value：`if (is_string($value)) { $value = StrHelper::trim_invisible($value); }`；array 則 `trim_invisible_deep`
   - **不修改** `get_option`（讀取由 DTO 接手，避免重複 trim 影響其他呼叫者）

3. **`inc/classes/Domains/Payment/ShoplinePayment/DTOs/RedirectSettingsDTO.php`** — DTO 讀取時 trim
   - 在 `before_init()` 開頭新增：對 `$this->dto_data` 中 **每個 string 值** 套用 `StrHelper::trim_invisible()`（跳過 array / int / float / bool / enum 物件）。
     - 排除清單（純粹保險）：以 `is_string` 判斷即足夠，無須額外白名單
   - `after_init()` 末尾：在 `mb_convert_encoding(signKey)` 之後再做 `$this->signKey = StrHelper::trim_invisible($this->signKey);`（雙保險，編碼轉換可能引入頭尾不可見字元）

4. **`inc/classes/Domains/Invoice/Amego/DTOs/AmegoSettingsDTO.php`** — DTO 讀取時 trim
   - 由於 `AmegoSettingsDTO` 採單例 + `instance` 緩存，需注意 trim 在第一次實例化時就執行
   - 新增 `protected function before_init(): void` 覆寫 base 的方法：先呼叫 `parent::before_init()`，再對 `$this->dto_data` 中 string 值套用 `StrHelper::trim_invisible()`
   - 不需動 `after_init`（測試模式覆寫的是乾淨字串）

5. **`inc/classes/Shared/DTOs/BaseSettingsDTO.php`**（**評估後選用**）
   - **方案 A**：在 base 直接做 trim，所有未來新 provider 自動受惠
     - 優點：未來新 SettingsDTO 不必各自實作；對齊 issue 中「未來 provider 自動受惠」的場景
     - 風險：base 已有 `before_init` 處理 mode enum 轉換，需確保 trim 在 enum 轉換之前
   - **方案 B**：只在具體 DTO 各自加 trim（RedirectSettingsDTO、AmegoSettingsDTO）
     - 優點：影響範圍可控
     - 缺點：未來新 provider 需重複加
   - **決策**：採方案 A — 在 `BaseSettingsDTO::before_init()` 中先 trim 所有 string 型 dto_data，再呼叫原本的 mode enum 轉換。`RedirectSettingsDTO` 不繼承 base 但 `AmegoSettingsDTO` 繼承，所以：
     - `BaseSettingsDTO::before_init` 加 trim 邏輯（為 Amego 與所有未來 provider 服務）
     - `RedirectSettingsDTO::before_init` 也獨立加 trim（因為沒繼承 BaseSettingsDTO）
     - 兩處共用 `StrHelper::trim_invisible_deep` helper

### 前端

6. **`js/src/utils/trim.ts`**（新檔）
   - export `function trimInvisible(value: string): string` — TS 版等價邏輯
     - regex: `/^[	
  　​‌‍﻿]+|[	
  　​‌‍﻿]+$/g`
   - 註解明示「字元集需與 PHP `StrHelper::trim_invisible` 同步」

7. **`js/src/components/TrimmedInput.vue`**（新檔）
   - 共用元件，使用 `defineModel()` 與 `defineOptions({ inheritAttrs: false })`
   - 模板包 `<el-input v-bind="$attrs" :model-value="modelValue" @update:model-value="onInput" @blur="onBlur"><template v-for="..." #...><slot :name="..."/></template></el-input>`
   - `onInput(val)`：直接 update modelValue（不修剪）
   - `onBlur()`：取得 modelValue → `trimInvisible` → emit `update:modelValue` 新值；emit `blur` event 讓父層 form validator 跑

8. **`js/src/pages/Payments/SLP/index.vue`** — 將金鑰類欄位改用 `<TrimmedInput>`
   - 改動範圍：`platformId`（雖然 commented out 也順便改）/ `merchantId` / `apiKey` / `clientKey` / `signKey` 共 5 個欄位
   - 不改：`title` / `description` / `order_button_text`（後端會 trim，前端 UX 不需要對純文字欄位失焦立即修剪）
     - **備註**：feature 場景大綱明確要求 `title` / `description` 也 trim，但這是「儲存時 trim」由後端負責；前端 UX 即時修剪僅針對金鑰類欄位（避免使用者打字到一半看到欄位被改）

9. **`js/src/pages/Invoices/Amego/index.vue`** — 將 `invoice` / `app_key` 改用 `<TrimmedInput>`

### 測試

10. **`inc/tests/Shared/Utils/StrHelperTrimInvisibleTest.php`**（新檔，單元測試）
    - 涵蓋 `trim_invisible` / `trim_invisible_deep` 的字元集 ── 對應 feature 「多種不可見字元都會被修剪」的 11 個案例
    - 涵蓋「中間不可見字元保留」、「純不可見字元變空字串」、「空字串／null／非字串」邊界案例
    - 涵蓋陣列遞迴：`['  a ', ['  b  ', 1, true]]` → `['a', ['b', 1, true]]`

11. **`inc/tests/Domains/Settings/SettingApiTrimWhitespaceTest.php`**（新檔，整合測試）
    - 對應 feature 規則 1「後端儲存時對所有設定欄位修剪前後不可見字元」
    - 測試 case：
      - `POST /settings/shopline_payment_redirect` 帶 `apiKey: '  sk_live_abc123  '` → 讀回 `'sk_live_abc123'`
      - `POST /settings/amego` 帶 `app_key: '　amego_key　'`（全形空白）→ 讀回 `'amego_key'`
      - `POST /settings/...` 帶 `title: ' 我的金流標題 '` → 讀回 `'我的金流標題'`（驗證所有欄位都 trim）
      - `POST /settings/...` 帶 `apiKey: 'sk_live abc 123'` → 讀回 `'sk_live abc 123'`（中間空白保留）
      - `POST /settings/...` 帶 `apiKey: '    '` → 讀回 `''`
      - `POST /settings/...` 帶 `allowPaymentMethodList: ['  CreditCard  ', ' LinePay ']` → 讀回 `['CreditCard', 'LinePay']`
      - `POST /settings/...` 帶 `min_amount: '  5  '` → 讀回 `5` (整數，sanitize 處理)
      - `POST /settings/...` 帶 `title: ' <script>alert(1)</script> '` → 讀回不含 `<script>` 且無前後空白
      - `POST /settings/.../toggle` → `enabled` 由 'yes' 變 'no'，未受 trim 影響
      - 透過 `ProviderUtils::update_option('future_provider', ['some_key' => '  value  '])` 直接呼叫 → DB 寫入 `'value'`（驗證未來 provider 自動受惠）

12. **`inc/tests/Domains/Payment/ShoplinePayment/RedirectSettingsDTOTrimTest.php`**（新檔，DTO 讀取單元測試）
    - 對應 feature 規則 2「讀取設定時對既有資料即時 trim」
    - 測試 case：
      - 直接寫入 wp_options：`update_option('woocommerce_shopline_payment_redirect_settings', ['apiKey' => '  sk_live_legacy  ', ...])`
      - 呼叫 `RedirectSettingsDTO::instance()` → `$dto->apiKey === 'sk_live_legacy'`
      - 驗證 wp_options 原始值仍為 `'  sk_live_legacy  '`（讀取不會主動寫回）

13. **`inc/tests/Domains/Invoice/Amego/AmegoSettingsDTOTrimTest.php`**（新檔，DTO 讀取單元測試）
    - 直接寫入 wp_options：`update_option('woocommerce_amego_settings', ['app_key' => ' amego_legacy_key ', 'invoice' => ' 12345678 '])`
    - 呼叫 `AmegoSettingsDTO::instance()` → `$dto->app_key === 'amego_legacy_key'`、`$dto->invoice === '12345678'`
    - 注意：因 `AmegoSettingsDTO` 用 static cache，每個 test method 之間需 reset（`AmegoSettingsDTO::$instance = null` 透過 reflection）

---

## 資料流分析

### 寫入流程（Admin 設定儲存）

```
Vue Form 欄位失焦 ──▶ TrimmedInput.onBlur ──▶ trimInvisible(value) ──▶ form 更新
                                                                          │
                                                                          ▼
              Vue 提交 ──▶ POST /settings/{id}（payload 已乾淨）──▶ SettingApiService::post_providers_with_id_callback
                                                                          │
                                                                          ▼
                                                          WP::sanitize_text_field_deep（剝 HTML、收斂空白）
                                                                          │
                                                                          ▼
                                                       ProviderUtils::update_option(provider_id, $params)
                                                                          │
                                                                          ▼
                                              StrHelper::trim_invisible_deep($params) ◀── 新增
                                                                          │
                                                                          ▼
                                                       wp_parse_args($params, $existing) → \update_option
```

**檢查點**：
- Red 階段斷言：DB 中的值不含前後不可見字元、保留中間字元、所有 string 型欄位都被 trim、enabled / 數值型 / 陣列元素都符合預期。

### 讀取流程（DTO 實例化）

```
ProviderUtils::get_option(id) ──▶ wp_options 原始值（可能殘留前後空白）──▶ new SettingsDTO($settings_array)
                                                                              │
                                                                              ▼
                                                        before_init() ──▶ trim string in dto_data ◀── 新增
                                                                              │
                                                                              ▼
                                                  parent::before_init()（mode enum 轉換）
                                                                              │
                                                                              ▼
                                                        屬性指派（$this->apiKey = trimmed value）
                                                                              │
                                                                              ▼
                                                        after_init()（測試模式覆寫 + signKey 編碼轉換 + trim signKey）
                                                                              │
                                                                              ▼
                                                                    DTO 物件（屬性乾淨）
                                                                              │
                                                                              ▼
                                                                    交給 ApiClient 發送
```

**檢查點**：
- 讀取後 wp_options 原始值未被改寫（讀取無副作用）
- DTO 屬性已乾淨

### 前端失焦流程

```
管理員貼上 "  sk_live_abc  " ──▶ <TrimmedInput> @input ──▶ form.apiKey = "  sk_live_abc  "
管理員點擊其他位置 ──▶ <TrimmedInput> @blur ──▶ trimInvisible("  sk_live_abc  ") ──▶ form.apiKey = "sk_live_abc"
                                                                ▼
                                                Element Plus form validator 跑（若有 required 規則）
```

**檢查點**：
- 失焦後欄位顯示乾淨
- 不顯示通知（靜默）
- v-model 綁定值與顯示值一致

---

## 錯誤處理登記表

| 方法/路徑 | 可能失敗原因 | 錯誤類型 | 處理方式 | 使用者可見? |
| --------- | ------------ | -------- | -------- | ----------- |
| `StrHelper::trim_invisible` | 傳入非字串（誤呼叫） | 型別錯誤 | 簽章嚴格 `string` 型別，PHP 8.1 直接 throw `TypeError` | 否（開發期錯誤） |
| `StrHelper::trim_invisible_deep` | array 內含 object/resource | 已處理 | 該 key 原值返回（不修剪、不報錯） | 否 |
| `ProviderUtils::update_option` | trim 後變空字串導致 required 欄位空 | 業務邏輯 | 不阻擋（前端 form validator 負責），DB 仍寫入空字串 | 是（下次讀取時 form 顯示空、validator 提示） |
| `RedirectSettingsDTO::before_init` | dto_data 含 array key（如 `allowPaymentMethodList`） | 型別判斷 | `is_string` 判斷跳過 array | 否 |
| `RedirectSettingsDTO::after_init` | `signKey` `mb_convert_encoding` 失敗 | 編碼錯誤 | 現有邏輯保留原值，trim 在後仍會跑 | 否 |
| `AmegoSettingsDTO::instance` | static cache 未重置造成測試污染 | 測試副作用 | 測試 setUp 用 reflection 重置 `$instance = null` | 否（測試環境） |
| `<TrimmedInput>` `@blur` | modelValue 為 `null` / `undefined` | TS 型別 | `value ?? ''` 後再 trim | 否 |
| `<TrimmedInput>` v-bind 透傳 | 父層傳了非預期 attr | Vue warn | `inheritAttrs: false` + `$attrs` 透傳避免 root 元素污染 | 否 |
| 前端純空白欄位 trim 後變空 | required validator 觸發 | 驗證失敗 | el-form rules 自然顯示紅字（已存在邏輯） | 是（紅字提示） |

**GAP 評估**：
- 既有 `update_option` 寫入失敗（回 false）的靜默問題仍存在 → 不在本次處理範圍（沿用 issue-12-plan 同樣決策）
- 純不可見字元欄位 trim 後成為空字串：feature 已明確要求此行為，**不視為 GAP**

---

## 失敗模式登記表

| 程式碼路徑 | 失敗模式 | 已處理? | 有測試? | 使用者可見? | 恢復路徑 |
| ---------- | -------- | ------- | ------- | ----------- | -------- |
| 後端 trim 漏處理某字元 | API 仍因隱形字元失敗 | ✅（regex 字元清單） | ✅（StrHelperTrimInvisibleTest 11 字元） | 是 | 補 regex 字元清單 |
| 前端 trim 與後端不一致 | 前端顯示乾淨但 DB 不一致 | ✅（共用字元清單） | ⚠️（無 Vitest，靠 PR review） | 否（最終 DB 仍正確） | 後端為最終防線、自動補正 |
| DTO trim 寫回 DB | 違反 feature「不主動寫回」要求 | ✅（只改 in-memory） | ✅（DTO trim test 驗證 wp_options 原值不變） | 否 | 不會發生 |
| Amego DTO static cache | 測試之間污染 | ✅（reflection reset） | ✅ | 否 | 測試 setUp |
| 中間空白被誤 trim | 破壞合法資料 | ✅（regex 限定首尾） | ✅（feature 場景） | 是（資料錯誤） | regex 用 `^...|...$` 而非全域替換 |
| 數值型欄位被 trim 成空字串 | int 0 vs 空字串歧義 | ✅（trim 在 sanitize 之後，sanitize 已 cast 字串） | ✅（feature 場景）| 否 | 後端再 cast 一次 |
| 陣列欄位 trim 遞迴過深 | 改到不該改的巢狀資料 | ⚠️（目前 paymentMethodOptions 含巢狀字串） | ✅（測試覆蓋） | 看內容 | 巢狀字串前後 trim 不應有破壞性 |
| `signKey` `mb_convert_encoding` 引入頭尾字元 | 編碼轉換產生不可見字元 | ✅（after_init trim signKey） | ⚠️（罕見，建議手動驗） | 是（API 失敗） | after_init trim 兜底 |

---

## 資料遷移

**結論：不需 migration**，由「DTO 讀取時 in-memory trim」+「下次儲存自動寫回乾淨值」雙策略無感修復既有資料：

- **狀態 A — DB 中已是乾淨值**：升級後行為不變
- **狀態 B — DB 中前後有不可見字元**：升級後 DTO 讀取時即時 trim，API 立即恢復正常；下次管理員手動點儲存時，`ProviderUtils::update_option` 寫入會永久清理
- **客服 SOP 連動**：feature 已明確「請按一次儲存」即可清理，不需公告

---

## 實作步驟

### 第一階段：後端 helper 與測試先行（Red）

1. **新增 `StrHelper::trim_invisible` / `trim_invisible_deep`**（檔案：`inc/classes/Shared/Utils/StrHelper.php`）
   - 行動：實作 helper + PHPDoc
   - 依賴：無
   - 風險：低

2. **撰寫 `StrHelperTrimInvisibleTest`**（檔案：`inc/tests/Shared/Utils/StrHelperTrimInvisibleTest.php`）
   - 行動：覆蓋字元集、邊界、遞迴、混合不可見字元 11 個案例
   - 依賴：步驟 1
   - 風險：低
   - **完成條件**：`vendor/bin/phpunit --filter StrHelperTrimInvisibleTest` 全綠

### 第二階段：後端寫入路徑（Red → Green）

3. **整合測試先行：寫入時 trim**（檔案：`inc/tests/Domains/Settings/SettingApiTrimWhitespaceTest.php`）
   - 行動：撰寫 8 ～ 10 個 test method 對應 feature 規則 1 與規則 4 的場景
   - 此時測試**全部失敗**（Red）
   - 依賴：步驟 1
   - 風險：低

4. **實作 `ProviderUtils::update_option` trim**（檔案：`inc/classes/Shared/Utils/ProviderUtils.php`）
   - 行動：在 `wp_parse_args` 之前對 `$values` 做 `StrHelper::trim_invisible_deep`；在 `update_option(string, string|array, mixed)` 簽章下處理 string + array 兩種傳入形式
   - 依賴：步驟 1, 3
   - 風險：低
   - **完成條件**：步驟 3 的整合測試全綠（Green）

### 第三階段：後端讀取路徑（Red → Green）

5. **DTO 讀取單元測試先行**（檔案：`RedirectSettingsDTOTrimTest.php` + `AmegoSettingsDTOTrimTest.php`）
   - 行動：撰寫 4 ～ 6 個 test method 對應 feature 規則 2「讀取時 trim」、「不寫回 DB」、「Amego 讀取 trim」
   - 此時測試**全部失敗**（Red）
   - 依賴：步驟 1
   - 風險：中（Amego static cache 需 reflection reset）

6. **實作 `RedirectSettingsDTO::before_init` trim**（檔案：`inc/classes/Domains/Payment/ShoplinePayment/DTOs/RedirectSettingsDTO.php`）
   - 行動：在 `before_init()` 開頭新增 string 值 trim 迴圈；`after_init()` 末尾對 `signKey` 編碼轉換後再 trim
   - 依賴：步驟 1, 5
   - 風險：低

7. **實作 `BaseSettingsDTO::before_init` trim + AmegoSettingsDTO 沿用**（檔案：`inc/classes/Shared/DTOs/BaseSettingsDTO.php` + `inc/classes/Domains/Invoice/Amego/DTOs/AmegoSettingsDTO.php`）
   - 行動：在 `BaseSettingsDTO::before_init` 中先對 `$this->dto_data` 中所有 string 值 trim，再做原本的 mode enum 轉換；`AmegoSettingsDTO` 不需另外覆寫（沿用 base）
   - 依賴：步驟 1, 5
   - 風險：中（影響所有未來繼承 BaseSettingsDTO 的 DTO）
   - **完成條件**：步驟 5 的測試全綠（Green）

### 第四階段：前端

8. **新增 `js/src/utils/trim.ts`**（檔案：新檔）
   - 行動：實作 `trimInvisible` 與 PHP 等價的 regex
   - 依賴：無
   - 風險：低

9. **新增 `<TrimmedInput>` 元件**（檔案：`js/src/components/TrimmedInput.vue`）
   - 行動：使用 `defineModel()` + `inheritAttrs: false` + `v-bind="$attrs"` + slot 透傳
   - 依賴：步驟 8
   - 風險：中（slot 透傳細節需驗證、v-model 雙向綁定不能有延遲）

10. **替換 SLP 設定頁金鑰欄位**（檔案：`js/src/pages/Payments/SLP/index.vue`）
    - 行動：將 `merchantId` / `apiKey` / `clientKey` / `signKey` / `platformId`（commented）的 `<el-input>` 改為 `<TrimmedInput>`
    - 依賴：步驟 9
    - 風險：低

11. **替換 Amego 設定頁金鑰欄位**（檔案：`js/src/pages/Invoices/Amego/index.vue`）
    - 行動：將 `invoice` / `app_key` 的 `<el-input>` 改為 `<TrimmedInput>`
    - 依賴：步驟 9
    - 風險：低

### 第五階段：驗證與清理

12. **跑全套後端測試**：`composer test`（API_MODE=mock）
    - 確認新測試全綠、既有測試無回歸

13. **跑 phpstan + phpcs**：`vendor/bin/phpstan analyse` + `composer lint`
    - 確認 level 9 與 coding standard 無新增違規

14. **前端 build 驗證**：`pnpm build`
    - 確認 `<TrimmedInput>` 引入後 build 無錯

15. **手動驗證腳本**（建議但非必需）：
    - 開啟 SLP 設定頁，貼入帶全形空白的 apiKey，失焦後確認欄位乾淨
    - 開啟瀏覽器 DevTools network panel，儲存後確認 payload 已乾淨
    - DB 中查 `wp_options` 的 `woocommerce_shopline_payment_redirect_settings` 確認儲存值乾淨

---

## 測試策略

| 層級 | 工具 | 範圍 | 預期 test 數 |
| --- | --- | --- | --- |
| 單元測試 | PHPUnit | `StrHelper::trim_invisible` / `trim_invisible_deep` | ~15 |
| 單元測試 | PHPUnit | `RedirectSettingsDTO` / `AmegoSettingsDTO` 讀取 trim | ~6 |
| 整合測試 | PHPUnit + WP_UnitTestCase | `ProviderUtils::update_option` + REST API 端到端 | ~10 |
| E2E | **不納入本次** | （規格決策 Q9） | — |
| 前端元件測試 | **不納入本次** | （規格決策不在範圍） | — |

**單一指令重跑**：
```bash
composer test                                                          # 全部
vendor/bin/phpunit --filter StrHelperTrimInvisibleTest                # helper
vendor/bin/phpunit --filter SettingApiTrimWhitespaceTest              # 整合
vendor/bin/phpunit --filter RedirectSettingsDTOTrimTest               # SLP DTO
vendor/bin/phpunit --filter AmegoSettingsDTOTrimTest                  # Amego DTO
```

---

## 風險評估與注意事項

### 高優先

- **PHP / TS 字元集同步**：在兩處檔案頂部留註解標示「若調整字元清單，請同步更新另一邊」。建議將清單加入 `inc/CLAUDE.md` 或新增 `.claude/rules/trim-invisible.rule.md`（**不在本次必做範圍**，但若 reviewer 提出可加）。

- **DTO before_init 順序**：`BaseSettingsDTO::before_init` 加 trim 後，**RedirectSettingsDTO** 不繼承 BaseSettingsDTO（直接 extend `J7\WpUtils\Classes\DTO`），所以必須在 `RedirectSettingsDTO::before_init` 中**獨立**加 trim 邏輯。**不要假設 base 自動處理 SLP**。

- **Amego static instance cache**：`AmegoSettingsDTO::$instance` static cache 在測試之間若不 reset 會污染下個 test。測試需用 reflection 強制 reset，或在 `AmegoSettingsDTO` 加 `public static function reset_instance(): void` 測試專用方法。

### 中優先

- **`signKey` 雙重處理**：feature 規則隱含「signKey 也要 trim」，但 `RedirectSettingsDTO::after_init` 已對 signKey 做 `mb_convert_encoding`。若不可見字元在編碼轉換後產生（罕見），讀取時 trim 是兜底。實作時 trim 必須在 mb_convert_encoding **之後** 執行，且要驗證 trim 不破壞合法 UTF-8 字元。

- **前端 `<TrimmedInput>` slot 透傳**：el-input 支援多個 slot（prefix/suffix/prepend/append）。若沒做 slot forwarding，使用方原本的 slot 內容會消失。實作時要用 `<template v-for="(_, name) in $slots" :slot="name">...<slot :name="name"/></template>`（Vue 3.x 寫法）。

- **既有 PHPCS 規則**：`final class` 強制、camelCase property 允許，新增測試類別必須 `final class`。

### 低優先

- **效能**：`trim_invisible_deep` 對大型 settings 陣列的 regex 開銷可忽略（每次儲存才跑一次）。
- **i18n**：本功能無 UI 文案變更（靜默處理），無需新增翻譯。

---

## 不在本次範圍

- **Migration 主動掃描資料庫**：規格已明確不做
- **中間不可見字元的偵測或警告**：規格已明確不做（旅程 4 確認）
- **修剪後的提示通知**：保持靜默（Q5 決策 A）
- **前端 Vitest 元件測試**：規格已明確排除
- **E2E 測試**：規格已明確排除（Q9 決策 B）
- **既有 `update_option` 回 false 的靜默處理**：與 issue-12 相同 GAP，不在本次處理
- **新增 `.claude/rules/trim-invisible.rule.md` 文件**：可在 review 階段補上，非必做

---

## 交付物清單

### 新增檔案

- `inc/tests/Shared/Utils/StrHelperTrimInvisibleTest.php`
- `inc/tests/Domains/Settings/SettingApiTrimWhitespaceTest.php`
- `inc/tests/Domains/Payment/ShoplinePayment/RedirectSettingsDTOTrimTest.php`
- `inc/tests/Domains/Invoice/Amego/AmegoSettingsDTOTrimTest.php`
- `js/src/utils/trim.ts`
- `js/src/components/TrimmedInput.vue`

### 修改檔案

- `inc/classes/Shared/Utils/StrHelper.php`（新增 2 個 static method）
- `inc/classes/Shared/Utils/ProviderUtils.php`（`update_option` 加 trim_invisible_deep）
- `inc/classes/Shared/DTOs/BaseSettingsDTO.php`（`before_init` 加 string trim 迴圈）
- `inc/classes/Domains/Payment/ShoplinePayment/DTOs/RedirectSettingsDTO.php`（`before_init` 加 string trim 迴圈、`after_init` 補 signKey trim）
- `inc/classes/Domains/Invoice/Amego/DTOs/AmegoSettingsDTO.php`（測試專用 reset_instance；如測試決定用 reflection 則此檔不動）
- `js/src/pages/Payments/SLP/index.vue`（5 個 el-input → TrimmedInput）
- `js/src/pages/Invoices/Amego/index.vue`（2 個 el-input → TrimmedInput）

預估改動 ~13 檔案，HOLD SCOPE 範圍內，可控。

---

## 交接給 tdd-coordinator

本計劃已完成澄清（`specs/clarify/2026-04-27-1156.md`）、規格（`specs/features/settings/trim-key-whitespace.feature`），可直接交付給 `@zenbu-powers:tdd-coordinator` 執行 Red → Green → Refactor 循環。

執行順序建議：
1. 第一、二階段（後端 helper + 寫入路徑）：1 個 worktree、1 位 wordpress-master 處理
2. 第三階段（DTO 讀取路徑）：可與第二階段同 worktree 順接
3. 第四階段（前端）：可獨立分派給 react-master / 直接 Vue 開發者
4. 第五階段（驗證）：tdd-coordinator 統合驗收

完成條件：
- 所有新增測試綠燈
- `composer test` 既有測試無回歸
- `vendor/bin/phpstan analyse` level 9 無新增錯誤
- `composer lint` + `pnpm lint` 無新增違規
- `pnpm build` 成功
