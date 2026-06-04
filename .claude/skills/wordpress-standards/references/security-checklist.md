# WordPress Plugin 資安審查 Checklist（攻擊者視角）

供 security-reviewer agent 使用的完整資安審查依據。本檔案聚合：審查嚴重性等級、流程、OWASP Top 10、WordPress 特有漏洞、依賴與敏感資訊、誤判排除等所有資安維度。

> 報告輸出格式請見 `review-output-template.md` 第二部分（security-reviewer 視角）。

---

## 適用時機

- 以攻擊者視角審查 WordPress Plugin / Theme 的 PHP 程式碼
- 判斷漏洞嚴重度與合併建議
- 撰寫結構化的資安審查報告（含 before/after diff）
- 建立開發階段的自我檢查清單

---

## 審查嚴重性等級

| 等級 | 符號 | 說明 | 合併建議 |
|------|------|------|---------|
| 嚴重 | 🔴 | 可直接被利用的漏洞：遠端代碼執行、未授權資料存取、認證繞過 | **立即阻擋，通知專案負責人** |
| 重要 | 🟠 | 需要特定條件才能利用：CSRF、存取控制缺失、資料洩漏風險 | **阻擋合併** |
| 建議 | 🟡 | 安全最佳實踐不符、防禦縱深不足 | 可合併，建議後續處理 |
| 備註 | 🔵 | 安全性偏好建議、未來強化方向 | 可合併 |

---

## 審查流程建議順序

1. **依賴盤點**：先跑 `composer audit` / `npm audit`，排除高 CVE 依賴
2. **高風險搜尋**：用 grep 快速定位危險函式（`eval`、`$_GET`、`wpdb->query` 等）
3. **維度逐一審查**：按照 OWASP + WordPress 特有清單逐項核對
4. **誤判排除**：比對「常見誤判」表，過濾已知非問題
5. **報告產出**：依輸出模板呈現，標註嚴重度與修補程式碼對比

---

## 快速啟動檢查

開始審查前，先執行以下指令取得變更範圍：

```bash
# 取得所有 PHP 變更
git diff -- '*.php'

# 搜尋高風險模式
grep -rn "eval\|base64_decode\|system\|exec\|shell_exec\|passthru" --include="*.php" .
grep -rn "\$_GET\|\$_POST\|\$_REQUEST\|\$_COOKIE\|\$_SERVER" --include="*.php" .
grep -rn "wpdb->query\|wpdb->get_results\|wpdb->get_var" --include="*.php" .

# 靜態分析（如果專案有配置）
composer phpstan
composer phpcs
```

---

## 核心審查原則

- **攻擊者視角**：以「如何利用這段程式碼」的思維審查，而非「這段程式碼是否正確」
- **具體而非籠統**：每個漏洞都需指出確切位置、攻擊情境與修補方案（附程式碼對比）
- **符合規範就不改**：若程式碼已符合安全規範，不需要為了修改而修改
- **避免誤報**：充分理解程式碼上下文後再判定，並說明誤判的排除依據
- **正向反饋**：審查中也要指出已正確處理的安全措施

---

# 一、OWASP Top 10 對應審查清單

WordPress Plugin 中對應 OWASP 常見風險的審查維度，著重 A03（Injection）、A01（Broken Access Control）、A07（Identification & Authentication Failures）、A10（SSRF）。

## 1.1 輸入驗證與資料清洗（A03: Injection / A04: Insecure Design）

- [ ] **所有使用者輸入**（`$_GET`、`$_POST`、`$_REQUEST`、`$_COOKIE`、REST body）是否在使用前清洗（🔴）
- [ ] `sanitize_text_field()`、`absint()`、`sanitize_email()` 等是否依據資料類型選用正確函式（🔴）
- [ ] 是否有直接將 `$_SERVER['HTTP_HOST']`、`$_SERVER['REQUEST_URI']` 等輸出至 HTML（🔴）
- [ ] 上傳檔案是否驗證 MIME Type 與副檔名（禁止信任 `$_FILES['type']`）（🔴）
- [ ] 是否有未驗證的序列化資料（`unserialize()` 搭配使用者輸入）（🔴）

## 1.2 SQL 注入（A03: Injection）

- [ ] 所有 `$wpdb->query()`、`$wpdb->get_results()` 是否使用 `$wpdb->prepare()`（🔴）
- [ ] `prepare()` 的 placeholder 是否使用正確（`%d`、`%s`、`%f`，禁止字串拼接）（🔴）
- [ ] `IN ()` 子句是否使用 `implode()` + `prepare()` 正確處理（🔴）
- [ ] 表格名稱、欄位名稱是否未經 `$wpdb->prefix` 或白名單驗證就直接拼接（🔴）

```php
// ❌ 嚴重漏洞：SQL 注入
$id = $_GET['id'];
$wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE ID = $id" );

// ✅ 安全做法
$id = absint( $_GET['id'] ?? 0 );
$wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id )
);
```

## 1.3 XSS 跨站腳本（A03: Injection）

- [ ] 所有輸出至 HTML 的資料是否使用適當的 escape 函式（🔴）
- [ ] `echo`、`print` 是否直接輸出未處理的使用者資料（🔴）
- [ ] JavaScript 變數是否使用 `wp_json_encode()` 輸出（🔴）
- [ ] 允許 HTML 的欄位是否使用 `wp_kses()` 或 `wp_kses_post()` 過濾（🔴）

```php
// escape 函式選用對照
echo \esc_html( $title );           // 純文字輸出
echo \esc_attr( $class );           // HTML 屬性值
echo \esc_url( $link );             // URL（href、src）
echo \esc_js( $string );            // inline JS 字串（少用，prefer wp_json_encode）
echo \wp_json_encode( $data );      // JS 變數物件
echo \wp_kses_post( $content );     // 允許部分 HTML 標籤
```

## 1.4 遠端請求 SSRF（A10: Server-Side Request Forgery）

- [ ] 是否使用使用者提供的 URL 進行 `wp_remote_get()` 或 `wp_remote_post()`（🔴）
- [ ] 外部 URL 是否有白名單或 domain 驗證（🔴）
- [ ] 是否允許請求到 `localhost`、`127.0.0.1`、內網 IP（🔴）

```php
// ✅ 驗證外部 URL 不指向內網
$url = esc_url_raw( $_POST['webhook_url'] ?? '' );
$parsed = parse_url( $url );

$blocked_hosts = [ 'localhost', '127.0.0.1', '::1' ];
if ( in_array( $parsed['host'] ?? '', $blocked_hosts, true ) ) {
    wp_die( '不允許的 URL' );
}
```

## 1.5 REST API 安全（A01: Broken Access Control）

- [ ] `permission_callback` 是否明確定義（禁止 `'__return_true'` 用於敏感路由）（🔴）
- [ ] `args` 是否定義 `sanitize_callback` 與 `validate_callback`（🟠）
- [ ] 是否有未認證的端點可存取或修改私人資料（🔴）
- [ ] REST 端點是否有適當的速率限制考量（🟡）

```php
// ❌ 危險：任何人都可存取
\register_rest_route( 'my-plugin/v1', '/secret-data', [
    'methods'             => 'GET',
    'callback'            => [ $this, 'get_secret_data' ],
    'permission_callback' => '__return_true',
] );

// ✅ 正確：驗證能力
\register_rest_route( 'my-plugin/v1', '/secret-data', [
    'methods'             => 'GET',
    'callback'            => [ $this, 'get_secret_data' ],
    'permission_callback' => fn() => \current_user_can( 'manage_options' ),
] );
```

---

# 二、WordPress 特有安全漏洞審查清單

針對 WordPress Plugin / Theme 架構下的特殊安全模式。

## 2.1 CSRF 防護（Nonce）

- [ ] 所有後台表單是否包含 `wp_nonce_field()`（🔴）
- [ ] 所有 `admin-post.php` 處理器是否呼叫 `check_admin_referer()`（🔴）
- [ ] 所有 AJAX 處理器是否呼叫 `check_ajax_referer()`（🔴）
- [ ] REST API 路由是否依需求驗證 nonce（`X-WP-Nonce`）或改用 Application Passwords（🔴）
- [ ] Nonce action 字串是否足夠具體（避免使用過於通用的名稱）（🟡）

```php
// ❌ 缺少 nonce 驗證
add_action( 'wp_ajax_my_action', function () {
    $data = sanitize_text_field( $_POST['data'] );
    // 直接處理，任何已登入使用者都可觸發
} );

// ✅ 正確做法
add_action( 'wp_ajax_my_action', function () {
    \check_ajax_referer( 'my_plugin_ajax_nonce', 'nonce' );
    $data = \sanitize_text_field( $_POST['data'] ?? '' );
} );
```

## 2.2 能力檢查與存取控制

- [ ] 每個 AJAX handler、REST endpoint、admin action 是否有 `current_user_can()` 驗證（🔴）
- [ ] 能力（capability）是否足夠精確（不使用 `manage_options` 處理一般操作）（🟠）
- [ ] 資源存取是否驗證擁有者（避免水平權限提升，如使用者 A 存取 B 的資料）（🔴）
- [ ] `is_admin()` 是否被誤用於能力檢查（該函式只判斷頁面位置，不驗證權限）（🟠）

```php
// ❌ 誤用 is_admin()
if ( is_admin() ) {
    // 這不是能力檢查！前台 AJAX 也在 admin context 中執行
}

// ✅ 正確做法：能力 + 資源擁有者雙重驗證
if ( ! \current_user_can( 'edit_post', $post_id ) ) {
    \wp_die( \__( '您沒有權限執行此操作', 'my-plugin' ), 403 );
}
```

## 2.3 檔案系統安全

- [ ] 檔案路徑是否使用 `realpath()` 驗證並限制在允許的目錄內（路徑穿越防護）（🔴）
- [ ] 檔案上傳是否使用 `wp_handle_upload()` 並驗證 MIME Type（🔴）
- [ ] 是否有 `include`、`require` 使用使用者提供的路徑（🔴）
- [ ] 上傳目錄是否防止直接執行 PHP（`.htaccess` 或 Nginx 規則）（🟠）

```php
// ❌ 路徑穿越漏洞
$file = $_GET['file'];
include PLUGIN_DIR . '/templates/' . $file;

// ✅ 白名單驗證
$allowed = [ 'header', 'footer', 'sidebar' ];
$template = sanitize_key( $_GET['template'] ?? '' );

if ( ! in_array( $template, $allowed, true ) ) {
    wp_die( '無效的模板' );
}

include PLUGIN_DIR . '/templates/' . $template . '.php';
```

## 2.4 WordPress 特有安全模式

- [ ] 是否有 `eval()` 使用（🔴）
- [ ] 是否使用 `base64_decode()` 執行動態代碼（🔴）
- [ ] `add_shortcode` 的 callback 是否對使用者輸入做充分驗證（🟠）
- [ ] Cron job 是否有能力驗證（防止未授權觸發）（🟠）
- [ ] Multisite 環境：`switch_to_blog()` 後是否正確 `restore_current_blog()`（🟠）

## 2.5 競爭條件與並發安全

- [ ] TOCTOU（先讀再檢查再寫入）是否改用原子操作（`INSERT ... ON DUPLICATE KEY UPDATE` 或加鎖查詢）（🔴）
- [ ] WordPress Cron 是否防止重疊執行（使用 transient lock 確保單一實例）（🟠）
- [ ] WooCommerce 庫存扣減是否防止超賣（`WHERE stock >= quantity` 原子更新）（🔴）
- [ ] 並發 AJAX 是否可能同時修改共享狀態（`update_option` 覆蓋彼此結果）（🟠）
- [ ] 「查找或建立」模式是否有唯一約束保護（無 unique index 時並發可建立重複記錄）（🟠）

## 2.6 LLM 輸出信任邊界

- [ ] AI 生成的值寫入 DB 前是否經過格式驗證（`is_email()`、`esc_url_raw()`）（🔴）
- [ ] AI 生成的內容顯示於前端時是否 escape（`esc_html()`、`wp_kses_post()`）（🔴）
- [ ] AI 建議的 URL 或檔案路徑是否經過白名單或 `realpath()` 驗證（🔴）
- [ ] 使用者輸入流向 LLM Prompt 時是否防範 Prompt Injection（隔離系統指令與使用者輸入）（🟠）
- [ ] LLM 工具呼叫的輸出是否在執行前驗證型別與結構（🟠）

---

# 三、依賴套件與敏感資訊審查清單

涵蓋第三方依賴 CVE 掃描、敏感資訊洩漏、常見誤判排除。

## 3.1 敏感資訊洩漏

- [ ] 是否有 API 金鑰、密碼、Token、Secret 硬寫在程式碼中（🔴）
- [ ] 錯誤訊息是否洩漏內部架構、SQL 查詢、檔案路徑（🟠）
- [ ] `WP_DEBUG` 模式下才顯示的除錯資訊，是否在正式環境被條件性關閉（🟠）
- [ ] `error_log()`、`var_dump()`、`print_r()` 是否遺留在生產環境程式碼（🟡）
- [ ] REST API 錯誤回應是否洩漏過多內部資訊（🟡）

```php
// ❌ 硬寫 API 金鑰
$api_key = 'sk-1234567890abcdef';

// ✅ 從設定或環境變數讀取
$api_key = \get_option( 'my_plugin_api_key', '' );
// 或
$api_key = defined( 'MY_PLUGIN_API_KEY' ) ? MY_PLUGIN_API_KEY : '';
```

## 3.2 依賴套件安全

- [ ] `composer.json` 的依賴是否有已知 CVE（🔴）
- [ ] `package.json` 的 npm 依賴是否有安全漏洞（🟠）
- [ ] 是否使用過時的第三方函式庫（🟡）
- [ ] 是否有不必要的依賴增加攻擊面（🟡）

```bash
# 檢查 Composer 依賴漏洞
composer audit

# 檢查 npm 依賴漏洞
npm audit --audit-level=high
```

## 3.3 常見誤判（False Positives）

審查前先確認以下情況，**避免誤報**：

| 情況 | 判斷方式 |
|------|---------|
| `.env.example` 中的範例金鑰 | 確認是範本而非實際值 |
| 測試檔案中的測試帳號 | 確認僅存在於 test/ 目錄且有明確標記 |
| 公開 API Key（如 Google Maps） | 確認是設計上公開的 Key |
| `base64_encode()` 用於資料傳輸 | 非用於混淆代碼即為正常用途 |
| MD5 / SHA1 用於非密碼用途 | 用於 checksum、cache key 等為可接受用途 |
