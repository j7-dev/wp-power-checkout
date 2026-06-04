---
name: wp-testing
description: >
  WordPress Plugin/Theme 測試統一參考——涵蓋 E2E（Playwright）與 Integration
  Test（PHPUnit + WP_UnitTestCase + wp-env）兩大策略。
  依測試目的載入對應 reference：
    - 端到端用戶流程 / 跨頁面互動 → references/e2e-playwright.md
    - 單元邏輯 + plugin hooks + DB 操作 → references/integration-phpunit.md
  共用測試準則（繁中、命名規範、wp-env 共用設定）見主檔下方。
---

# WordPress Plugin Testing 統一參考

從測試目的決定走哪條路，再 Read 對應 reference 載入詳細範例與環境設定。

## E2E vs IT 決策樹

| 測試層級 | 關注點 | 對應 Reference |
|---------|--------|---------------|
| **E2E（Playwright）** | **核心業務流程** — 使用者最關鍵的操作路徑（購買、註冊、登入、發布） | `references/e2e-playwright.md` |
| **Integration（PHPUnit + WP_UnitTestCase）** | Hooks / Filters / REST API / 權限矩陣 / DB 約束 / Service 異常 / 邊緣案例 | `references/integration-phpunit.md` |
| Static Analysis | 型別安全、邏輯一致性 | （PHPStan，本 skill 不涵蓋） |

**E2E 收錄**（任一即可）：收入相關（購買 / 訂閱 / 退款）、認證授權主流程、產品核心價值交付（觀看課程 / 發布內容）、跨系統整合 happy path。

**E2E 排除（一律改寫 Integration）**：權限矩陣、資料 / 狀態邊界、API 參數驗證（401 / 404 / SQLi / XSS）、Hook / Filter 交互邏輯、並發 / race condition。

> **判準**：若這個情境失敗時，**不會直接讓使用者拿不到產品價值或公司收不到錢**，那它就不是 E2E 該測的。

---

## 共用測試準則

### 繁體中文輸出規範

`describe` / `it` / `test` / `public function test_*` 的描述、註解、錯誤訊息一律使用**繁體中文**。英文僅保留技術識別碼、函式名稱、程式碼本身。

```typescript
// ✅ test('@smoke 使用者可以完成課程購買並進入教室', ...)
// ❌ test('user can purchase course and access classroom', ...)
```

PHP 端可採函式名繁中（`test_管理員可以新增課程`）或保留英文函式名 + 繁中 docblock / 註解。

### wp-env 共用設定

- `.wp-env.json` 放專案根目錄，`"plugins": ["."]` 掛載當前目錄為 plugin。
- E2E 與 Integration **共用同一份 wp-env**；不要為兩者各起一份 Docker。
- wp-env 指令一律從**專案根目錄**執行；`cd` 進子目錄會找不到 `.wp-env.json`。
- 環境變數 `WP_TESTS_DIR` 指向 WP test suite，bootstrap 與 CI 皆需設定。

### 測試命名規範

- E2E spec 檔：`tests/e2e/{group}/{flow}.spec.ts`（group = `smoke` / `happy`）
- Integration 測試檔：`tests/integration/{Feature}Test.php`（PSR-4 命名 + `Test.php` 結尾）
- 共用測試資料命名前綴 `[E2E]` / `[IT]` 便於識別與清除。

### 不寫測試的義務

若此次變更未產出測試檔，必須說明原因（純樣式 / 文案、已有既存測試覆蓋、不屬於對應策略職責等）。

---

## References 索引

| 需要 | 檔案 |
|------|------|
| Playwright E2E 範本（冒煙 / 快樂路徑）+ wp-env Global Setup + REST API Client + LC Bypass | `references/e2e-playwright.md` |
| PHPUnit 9 配置 + WP_UnitTestCase API + 13 種測試 pattern + GitHub Actions CI YAML | `references/integration-phpunit.md` |

---

## References

- 本 skill 含 `references/e2e-playwright.md`（E2E）與 `references/integration-phpunit.md`（Integration Test）。
