# E2E Playwright Reference

> WordPress Plugin/Theme 的 E2E 測試完整參考。E2E 只覆蓋核心業務流程；其他類型的測試請改用 `integration-phpunit.md`。

---

## 目錄

- [核心原則](#核心原則)
- [前置條件：讀取 ./specs](#前置條件讀取-specs)
- [核心工作流程](#核心工作流程)
- [核心業務流程篩選器](#核心業務流程篩選器)
- [測試分組（@smoke / @happy）](#測試分組)
- [Timeout 設定規範](#timeout-設定規範)
- [關鍵技術注意事項](#關鍵技術注意事項)
- [E2E 測試程式碼模板](#e2e-測試程式碼模板)
- [wp-env + Playwright 環境設定](#wp-env--playwright-環境設定)
- [疑難排解](#疑難排解)
- [最佳實踐](#最佳實踐)
- [測試執行指令範例](#測試執行指令範例)

---

## 核心原則

E2E 測試成本高、執行慢、維護負擔重，**只應覆蓋使用者最關鍵的操作路徑**。

| 測試層級 | 關注點 | 對應 reference |
|---------|--------|---------------|
| **E2E 測試** | **核心業務流程** — 使用者最關鍵的操作路徑（購買、註冊、登入、發布等） | 本檔 |
| **整合測試** | **邊緣案例** — Hook/Filter 交互、REST API 參數驗證、權限矩陣、資料庫約束、Service 異常處理 | `integration-phpunit.md` |

> 如果你正在思考「要不要用 E2E 多測一個權限角色 / 一個錯誤輸入 / 一個並發情境」——
> **答案是：不要。** 那些是整合測試的工作。

---

## 前置條件：讀取 ./specs

> 若 `./specs/` 目錄不存在或其中無任何檔案，立即中止任務。
> 提示使用者先執行需求訪談（`@zenbu-powers:clarifier`），產生 `./specs/` 規格文件後再繼續。

**`./specs/` 檔案為所有功能規格與使用者情境的唯一依據，必須優先完整閱讀。**

從 `./specs/` 中提取：
- **使用者角色清單**（所有角色及其權限）
- **使用者情境清單**（user story / 使用流程）
- **業務規則**（驗證規則、狀態機）
- **商業價值產生點**（哪些操作直接創造或交付價值）

---

## 核心工作流程

```
Step 1: 讀取 ./specs/，列出所有使用者情境
  └── 完整提取，不過濾

Step 2: 識別「核心業務流程」
  └── 用「核心業務流程篩選器」過濾出 E2E 候選

Step 3: 分組規劃（冒煙 / 快樂路徑）
  └── 不展開角色矩陣、不展開邊緣案例

Step 4: 生成 Playwright 測試
  └── 每個流程一個獨立 spec 檔案
```

---

## 核心業務流程篩選器

### 收錄規則（屬於 E2E）

符合**任一**條件即為核心業務流程：

1. **收入直接相關** — 購買、訂閱、續費、退款
2. **認證授權主流程** — 註冊、登入、登出、密碼重設
3. **產品核心價值交付** — 使用者完成「為什麼要用這個產品」的關鍵動作（如：觀看已購買課程、下單、發布內容、上傳檔案）
4. **跨系統整合的 happy path** — 涉及多個 plugin / 第三方服務的串接結果（如：WooCommerce → 課程授權）

### 排除規則（屬於整合測試）

下列情境**一律不寫 E2E**，請交給 `integration-phpunit.md`：

- 權限矩陣覆蓋（guest / subscriber / admin / expired ...）
- 資料邊界（空狀態、大量資料、特殊字元、Unicode、邊界值）
- 狀態邊界（draft / pending / cancelled / refunded ...）
- API 參數驗證（401、404、SQL injection、XSS）
- Hook / Filter 交互邏輯
- 並發情境（同時兩個 tab 結帳、race condition）
- 資源刪除後的殘留行為
- 業務規則的所有分支組合

> **判斷準則**：若這個情境失敗時，**不會直接讓使用者拿不到產品價值或公司收不到錢**，那它就不是 E2E 該測的東西。

---

## 測試分組

E2E 測試只寫**兩個分組**，其餘交給整合測試：

| 分組 | 標籤 | 說明 |
|------|------|------|
| 冒煙測試 | `@smoke` | 1 分鐘內跑完，全過代表環境沒炸。只放最關鍵的 3~5 條路徑 |
| 快樂路徑 | `@happy` | 標準使用者的完整正常流程，每個核心業務流程一條 |

**禁止在 E2E 中寫的分組**（請改寫整合測試）：

- 錯誤處理（Error Handling）
- 邊緣案例（Edge Cases）
- 安全性（Security）

---

## Timeout 設定規範

本地 WordPress 環境（如 LocalWP、wp-env）比 CI 慢很多，cold start 時 API 回應時間可能超過 10s。**Timeout 設得太短是 flaky test 的頭號元兇。**

### 強制規則

1. **`test.setTimeout(60_000)`**：每個 E2E test file 開頭必須設定足夠的測試超時時間（建議 60s 以上）
2. **API context timeout**：若使用 `browser.newContext()` 建立 API 請求用的 context，**必須**明確設定 `context.setDefaultTimeout(60_000)`，不可依賴 Playwright config 的 `actionTimeout`（通常只有 10s）
3. **`page.goto` timeout**：導航到 wp-admin 頁面時，使用 `{ timeout: 30_000 }` 以上
4. **`waitForSelector` / `waitForFunction`**：等待 React SPA 渲染、Ant Design Spin 消失等操作，建議 15_000 ~ 30_000ms
5. **`toBeVisible` assertions**：`expect(locator).toBeVisible({ timeout: 10_000 })` 作為最低標準

### 已知地雷

- `setupApiFromBrowser()` 繼承 Playwright config 的 `actionTimeout: 10s`，在 `beforeAll` 中做課程 / 章節建立時很容易超時 → **改用獨立的 `setupApiWithLongTimeout()` 模式**
- 第一個測試案例可能遇到 cold start（WordPress 載入快取尚未建立），需要更寬鬆的 timeout
- 多個測試重複導航到同一頁面時，合併測試案例以減少導航開銷

### 參考模式

```typescript
// ✅ 正確：獨立 context + 明確 timeout
async function setupApiWithLongTimeout(browser: Browser) {
  const context = await browser.newContext({
    storageState: STORAGE_STATE_PATH,
    ignoreHTTPSErrors: true,
    serviceWorkers: 'block',
  })
  context.setDefaultTimeout(60_000)
  // ...
}

// ❌ 錯誤：繼承 config 的 10s actionTimeout
const { api } = await setupApiFromBrowser(browser)
```

---

## 關鍵技術注意事項

### Workers 必須設為 1

```typescript
export default defineConfig({
  workers: 1,          // WordPress 共用 DB session，不能平行
  fullyParallel: false,
})
```

### 使用獨立 npm（非 pnpm）

Windows NTFS junction 在 pnpm 中會造成「untrusted mount point」錯誤。E2E 相依套件必須使用 npm。

### 不使用 WP CLI — 改用 REST API

```typescript
// ✅ 使用 REST API（跨平台，無 Docker PATH 問題）
const res = await request.get(`${BASE}/wp-json/wp/v2/posts`)

// ❌ 不要用 execSync（Windows 上 Docker PATH 問題）
execSync('npx wp-env run cli wp post list')
```

### wp-env 必須從專案根目錄執行

```bash
# ✅ 正確
./tests/e2e/node_modules/.bin/wp-env start

# ❌ 錯誤（找不到 .wp-env.json）
cd tests/e2e && npx wp-env start
```

---

## E2E 測試程式碼模板

E2E 測試只覆蓋兩個分組：

- **冒煙測試（`@smoke`）** — 1 分鐘內，最關鍵路徑
- **快樂路徑（`@happy`）** — 標準使用者完整流程

### 冒煙測試模板

冒煙測試只驗證「環境沒炸」與「最關鍵的價值交付」。每個 spec 的冒煙測試應該在數秒內完成。

```typescript
// tests/e2e/smoke/smoke.spec.ts
import { test, expect } from '@playwright/test'

test.setTimeout(60_000)

test.describe('冒煙測試', () => {

  test('@smoke 首頁可以正常載入', async ({ page }) => {
    await page.goto('/', { timeout: 30_000 })
    await expect(page).toHaveTitle(/.+/)
  })

  test('@smoke wp-admin 登入頁面可以正常載入', async ({ page }) => {
    await page.goto('/wp-login.php', { timeout: 30_000 })
    await expect(page.locator('#loginform')).toBeVisible({ timeout: 10_000 })
  })

  test('@smoke 已購買使用者可以進入課程教室', async ({ page }) => {
    await loginAs(page, 'student_purchased', 'password')
    await page.goto('/classroom/course-1/', { timeout: 30_000 })
    await expect(page.locator('[data-testid="video-player"]')).toBeVisible({ timeout: 10_000 })
  })

})
```

### 快樂路徑模板（核心業務流程）

每個核心業務流程一個 spec 檔案，從使用者進入到拿到價值的最短路徑。

```typescript
// tests/e2e/happy/course-purchase.spec.ts
import { test, expect } from '@playwright/test'
import { loginAs } from '../helpers/frontend-setup'
import { COURSES } from '../fixtures/test-data'

test.setTimeout(60_000)

test.describe('課程購買快樂路徑', () => {

  test('@happy 使用者可以完成課程購買並進入教室', async ({ page }) => {
    // Arrange：以一般使用者身份登入
    await loginAs(page, 'student', 'password')

    // Act 1：瀏覽課程商品頁
    await page.goto(COURSES.PUBLISHED.productUrl, { timeout: 30_000 })
    await expect(page.locator('h1.product_title')).toBeVisible({ timeout: 10_000 })

    // Act 2：加入購物車
    await page.locator('button.single_add_to_cart_button').click()
    await expect(page.locator('.woocommerce-message')).toBeVisible({ timeout: 10_000 })

    // Act 3：前往結帳並完成付款
    await page.goto('/checkout/', { timeout: 30_000 })
    await page.locator('#place_order').click()
    await expect(page).toHaveURL(/order-received/, { timeout: 30_000 })

    // Assert：使用者可以進入課程教室並看到影片播放器
    await page.goto(COURSES.PUBLISHED.classroomUrl, { timeout: 30_000 })
    await expect(page.locator('[data-testid="video-player"]')).toBeVisible({ timeout: 10_000 })
  })

})
```

### 多步驟流程模板（含 API 前置資料準備）

當核心業務流程需要複雜的前置資料（課程、訂單、會員等），用 REST API 建立，避免在 UI 上點來點去浪費時間。

```typescript
// tests/e2e/happy/course-completion.spec.ts
import { test, expect, Browser } from '@playwright/test'
import { loginAs } from '../helpers/frontend-setup'
import { wpPost, wpDelete } from '../helpers/api-client'

test.setTimeout(60_000)

async function setupApiWithLongTimeout(browser: Browser) {
  const context = await browser.newContext({
    storageState: 'tests/e2e/.auth/admin.json',
    ignoreHTTPSErrors: true,
    serviceWorkers: 'block',
  })
  context.setDefaultTimeout(60_000)
  return context.request
}

test.describe('課程學習完成快樂路徑', () => {
  let courseId: number
  let chapterId: number

  test.beforeAll(async ({ browser }) => {
    // 用 REST API 建立測試資料（不依賴 UI）
    const api = await setupApiWithLongTimeout(browser)
    const course = await wpPost(api, 'wp/v2/course', { title: '測試課程', status: 'publish' })
    courseId = course.id
    const chapter = await wpPost(api, 'wp/v2/chapter', {
      title: '第一章',
      status: 'publish',
      parent: courseId,
    })
    chapterId = chapter.id
  })

  test.afterAll(async ({ browser }) => {
    const api = await setupApiWithLongTimeout(browser)
    await wpDelete(api, `wp/v2/chapter/${chapterId}?force=true`)
    await wpDelete(api, `wp/v2/course/${courseId}?force=true`)
  })

  test('@happy 已購買使用者完成章節後可以看到進度更新', async ({ page }) => {
    await loginAs(page, 'student_purchased', 'password')

    // 進入章節頁
    await page.goto(`/classroom/${courseId}/chapter/${chapterId}/`, { timeout: 30_000 })
    await expect(page.locator('[data-testid="chapter-content"]')).toBeVisible({ timeout: 10_000 })

    // 標記章節完成
    await page.locator('[data-testid="mark-complete"]').click()
    await expect(page.locator('[data-testid="completed-badge"]')).toBeVisible({ timeout: 10_000 })

    // 回到課程頁，確認進度顯示為 100%
    await page.goto(`/classroom/${courseId}/`, { timeout: 30_000 })
    await expect(page.locator('[data-testid="progress-bar"]')).toContainText('100%')
  })

})
```

### 寫測試時的禁區

下列情境**絕對不要**寫進 E2E spec，請改寫整合測試：

```typescript
// ❌ 禁止：權限矩陣覆蓋
for (const role of ['guest', 'subscriber', 'admin', 'expired']) {
  test(`${role} 存取課程`, ...)
}

// ❌ 禁止：邊界值與特殊輸入
test('課程標題包含 XSS 字串', ...)
test('課程價格為 0', ...)
test('Unicode 與 Emoji 標題', ...)

// ❌ 禁止：API 邊界
test('未授權請求應回傳 401', ...)
test('SQL injection 防護', ...)

// ❌ 禁止：並發與競態條件
test('兩個 tab 同時結帳', ...)
test('到期前後刷新頁面', ...)
```

> 這些情境的測試價值很高，但成本應該由整合測試承擔。E2E 跑這些東西只會慢、不穩、難維護。

---

## wp-env + Playwright 環境設定

### 目錄結構

```
project-root/
├── .wp-env.json
├── tests/
│   └── e2e/
│       ├── package.json            # 獨立 npm（非 pnpm）
│       ├── playwright.config.ts
│       ├── global-setup.ts         # 建立測試資料（各角色使用者）
│       ├── global-teardown.ts      # 還原環境
│       ├── helpers/
│       │   ├── api-client.ts       # REST API CRUD 工具
│       │   ├── frontend-setup.ts   # 角色登入輔助
│       │   ├── lc-bypass.ts        # License check 繞過（若需要）
│       │   └── wc-checkout.ts      # WooCommerce 結帳自動化
│       ├── fixtures/
│       │   └── test-data.ts        # 測試常數（URL、選擇器、名稱）
│       ├── 01-admin/               # Admin SPA 測試
│       ├── 02-frontend/            # 前端頁面測試（各角色情境）
│       └── 03-integration/         # 端對端整合測試（含邊緣案例）
```

### Global Setup：建立所有角色的測試使用者

```typescript
// global-setup.ts
async function globalSetup(config: FullConfig) {
  const BASE = config.projects[0].use.baseURL!
  const browser = await chromium.launch()
  const page = await browser.newPage()

  // 管理員登入
  await page.goto(`${BASE}/wp-login.php`)
  await page.fill('#user_login', 'admin')
  await page.fill('#user_pass', 'password')
  await page.click('#wp-submit')
  await page.context().storageState({ path: '.auth/admin.json' })

  const nonce = await extractNonce(page)
  const opts = { request: page.context().request, baseURL: BASE, nonce }

  // 清除舊測試資料
  await cleanTestData(opts)

  // 建立各角色使用者（對應邊緣案例矩陣）
  await wpPost(opts, 'wp/v2/users', {
    username: 'student_purchased', password: 'password', email: 'purchased@test.com',
    roles: ['subscriber'],
  })
  await wpPost(opts, 'wp/v2/users', {
    username: 'student_expired', password: 'password', email: 'expired@test.com',
    roles: ['subscriber'],
  })

  // 建立測試課程
  const course = await wpPost(opts, 'plugin/v1/courses', {
    title: '[E2E] 測試課程',
    status: 'publish',
  })

  // 授予 purchased 使用者存取（有效）
  await wpPost(opts, `plugin/v1/courses/${course.id}/enroll`, {
    user_id: purchasedUserId,
    expire_date: null,  // 無限期
  })

  // 授予 expired 使用者存取（已過期）
  await wpPost(opts, `plugin/v1/courses/${course.id}/enroll`, {
    user_id: expiredUserId,
    expire_date: '2020-01-01T00:00:00',  // 過去的日期
  })

  await browser.close()
}
```

### REST API Client

```typescript
// helpers/api-client.ts
type ApiOptions = { request: APIRequestContext; baseURL: string; nonce: string }

export async function wpGet<T>(opts: ApiOptions, endpoint: string, params?: Record<string, string>): Promise<T> {
  const url = new URL(`${opts.baseURL}/wp-json/${endpoint}`)
  if (params) Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v))
  const res = await opts.request.get(url.toString(), { headers: { 'X-WP-Nonce': opts.nonce } })
  if (!res.ok()) throw new Error(`GET ${endpoint} → ${res.status()}`)
  return res.json()
}

export async function wpPost<T>(opts: ApiOptions, endpoint: string, data: Record<string, unknown>): Promise<T> {
  const res = await opts.request.post(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: { 'X-WP-Nonce': opts.nonce },
    data,
  })
  if (!res.ok()) throw new Error(`POST ${endpoint} → ${res.status()}`)
  return res.json()
}

export async function wpDelete(opts: ApiOptions, endpoint: string): Promise<void> {
  const res = await opts.request.delete(`${opts.baseURL}/wp-json/${endpoint}`, {
    headers: { 'X-WP-Nonce': opts.nonce },
  })
  if (!res.ok()) throw new Error(`DELETE ${endpoint} → ${res.status()}`)
}

// 從 wp-admin 提取 nonce
export async function extractNonce(page: Page): Promise<string> {
  await page.goto(`${page.context().browser()?.newContext}/wp-admin/`)
  return page.evaluate(() => (window as any).wpApiSettings?.nonce ?? '')
}
```

### License Check Bypass（若有 LC 機制）

```typescript
// helpers/lc-bypass.ts
const PLUGIN_FILE = path.resolve(__dirname, '../../../plugin.php')
const BACKUP_FILE = PLUGIN_FILE + '.e2e-backup'
const MARKER = "/* E2E-LC-BYPASS */"
const INJECTION = `$args['lc'] = false; ${MARKER}`

export function applyLcBypass(): void {
  const content = fs.readFileSync(PLUGIN_FILE, 'utf-8')
  if (content.includes(MARKER)) return
  fs.copyFileSync(PLUGIN_FILE, BACKUP_FILE)
  const needle = "Plugin::instance($args);"
  const idx = content.indexOf(needle)
  if (idx === -1) throw new Error('找不到 LC bypass 注入點')
  fs.writeFileSync(PLUGIN_FILE, content.slice(0, idx) + INJECTION + '\n' + content.slice(idx))
}

export function revertLcBypass(): void {
  if (fs.existsSync(BACKUP_FILE)) {
    fs.copyFileSync(BACKUP_FILE, PLUGIN_FILE)
    fs.unlinkSync(BACKUP_FILE)
  }
}
```

---

## 疑難排解

| 症狀 | 原因 | 解法 |
|------|------|------|
| `Cannot connect to Docker` | Docker 未啟動 | 啟動 Docker Desktop |
| `wp-env: command not found` | wp-env 未安裝 | 在 `tests/e2e/` 執行 `npm install` |
| global-setup 中 `spawn UNKNOWN` | execSync 呼叫 Docker | 改用 REST API |
| 章節 URL 404 | Rewrite rules 未刷新 | setup 中造訪永久連結設定頁 |
| 重複資料 slug 加 `-2` | 未強制刪除舊資料 | 清除時加 `?force=true` |
| CI 測試不穩定 | 缺少等待 | 補充 `waitForLoadState('networkidle')` |
| Windows pnpm junction 錯誤 | NTFS mount | E2E 相依套件改用 npm |
| 第一個測試 timeout | Cold start | 套用 `setupApiWithLongTimeout()` 模式 |

---

## 最佳實踐

1. **核心流程先行** — 先從 `./specs/` 識別出哪些是「核心業務流程」，其他通通不寫 E2E
2. **單一路徑深入** — 每個流程只測 happy path，不展開角色矩陣或錯誤分支
3. **冒煙測試極簡** — `@smoke` 群組總執行時間控制在 1 分鐘內
4. **獨立測試資料** — 每個測試用 REST API 建立自己的資料，執行後清除
5. **REST API 建立資料** — 絕不在測試程式碼中使用 WP CLI 或 execSync
6. **強制刪除** — 建立新資料前刪除所有狀態（publish、draft、trash）
7. **單一 worker** — WordPress 無法處理平行 session
8. **繁體中文** — 測試名稱與註解一律繁體中文
9. **明確 timeout** — 所有 E2E 設定 60s 以上的 test timeout，避免 flaky

---

## 測試執行指令範例

撰寫測試說明文件（README）時，必須列出以下情境的完整指令範例，讓開發者一眼就能複製貼上：

```bash
# 預設（無頭模式，CI 用）
npx playwright test

# 有頭模式（本機除錯，看瀏覽器跑）
npx playwright test --headed

# 指定瀏覽器
npx playwright test --project=chromium
npx playwright test --project=firefox

# 只跑冒煙測試
npx playwright test --grep "@smoke"

# 只跑快樂路徑
npx playwright test --grep "@happy"

# 跑單一測試檔
npx playwright test tests/e2e/checkout.spec.ts

# 跑單一測試（依名稱）
npx playwright test -g "使用者可以完成結帳流程"

# 顯示測試報告
npx playwright show-report

# 互動式 UI 模式（最好用的除錯方式）
npx playwright test --ui
```
