# WordPress 程式碼審查 Checklist（PR 審查視角）

供 wordpress-reviewer agent 使用的完整審查 checklist。本檔案聚合：嚴重性等級、強制執行測試、安全性 / Hook / REST API / 效能 / HPOS / PHP 8.1+ 五大維度的逐項勾選清單。

> 規範本身（程式碼怎麼寫）參見 `coding-standards.md`；本檔案是「審查時照著跑的 checklist」。

---

## 審查嚴重性等級

| 等級 | 符號 | 說明 | 合併建議 |
|------|------|------|---------|
| 嚴重 | 🔴 | 安全漏洞（XSS / SQL 注入 / CSRF）、資料毀損、導致 Fatal Error 的邏輯錯誤 | **阻擋合併** |
| 重要 | 🟠 | 違反核心規則、影響可維護性或效能的問題、HPOS 不相容 | **阻擋合併** |
| 建議 | 🟡 | 命名不一致、可讀性問題、可優化之處 | 可合併，建議後續處理 |
| 備註 | 🔵 | 風格偏好、未來可考慮的優化方向 | 可合併 |

---

## 強制執行測試

在開始代碼審查之前，**必須**執行以下所有測試指令。即使開發者聲稱已通過測試，reviewer 仍須獨立驗證。

```bash
# 1. 靜態分析
composer phpstan
composer psalm

# 2. 代碼風格檢查
composer phpcs

# 3. 格式化檢查（如果專案有配置 php-cs-fixer）
composer cs-check
# 或
./vendor/bin/php-cs-fixer fix --dry-run --diff

# 4. 單元測試 / 整合測試（排除 e2e）
composer test
# 或
./vendor/bin/phpunit --exclude-group=e2e

# 5. 其他專案自訂的 lint/test 指令（查閱 composer.json scripts 區塊）
```

> ⚠️ **不可跳過任何測試**。若指令不存在（如專案未配置 phpstan），在審查報告中註明「該工具未配置」即可，但已配置的工具必須全部執行。
> ⚠️ 若任何測試失敗，**直接判定審查不通過**，無需繼續代碼審查，立即將失敗結果退回給開發者。
> ⚠️ 若無法讀取相關檔案，應明確告知使用者缺少哪些資訊，再開始審查。

---

## 取得審查對象

```bash
# 取得 PHP 相關檔案的變更
git diff -- '*.php'
```

---

## WordPress 特殊情境快速對照表

| 情境 | 必查重點 |
|------|---------|
| **REST API 端點** | `permission_callback` 是否驗證能力、`args` 是否清洗輸入 |
| **AJAX 處理器** | `check_ajax_referer`、`current_user_can`、`wp_send_json_*` |
| **表單儲存** | `check_admin_referer`、`sanitize_*()`、`update_option` / `update_post_meta` |
| **資料輸出** | `esc_html`、`esc_attr`、`esc_url`、`wp_kses_post` |
| **WooCommerce 訂單** | 使用 `wc_get_order()`、物件方法讀寫 meta、`$order->save()` |
| **WooCommerce 結帳** | 同時支援傳統與區塊結帳 hook |
| **排程任務** | `wp_schedule_event` 是否有 deregister，避免重複排程 |
| **多站台** | `switch_to_blog()` 與 `restore_current_blog()` 是否成對使用 |
| **並發/競爭條件** | TOCTOU 模式、Cron lock、庫存原子扣減、`update_option` 並發安全 |

---

## 核心審查原則

- **只審查，不主動修改**：除非明確被要求，否則只提供意見
- **具體而非籠統**：每個問題都需指出確切位置與改善方案（附程式碼對比）
- **尊重現有風格**：若專案有既定慣例，優先依照專案規範而非外部標準
- **平衡品質與務實**：明確區分「必須修改」與「建議優化」
- **符合規範就不改**：若程式碼已符合規範，不需要為了修改而修改
- **正向反饋**：審查中也要指出寫得好的地方
- **測試必須通過**：所有非 e2e 測試必須通過，否則直接判定審查不通過

---

# 一、安全性審查 Checklist

> 對照 `coding-standards.md` 第八章的安全性規範，逐項檢查。本節聚焦 PR 程式碼審查視角；攻擊者視角的完整資安審查請見 `security-checklist.md`。

## 1.1 SQL 注入（🔴）

- [ ] 所有 SQL 查詢是否使用 `$wpdb->prepare()` 或 placeholder

```php
// ❌ 危險：SQL 注入漏洞
$results = $wpdb->get_results(
    "SELECT * FROM {$wpdb->posts} WHERE post_author = {$user_id}"
);

// ✅ 安全：使用 prepare()
$results = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} WHERE post_author = %d",
        $user_id
    )
);
```

## 1.2 XSS 輸出（🔴）

- [ ] 輸出至 HTML 是否使用 `esc_html()`、`esc_attr()`、`esc_url()`、`wp_kses()`

```php
// ❌ 危險：直接輸出未處理的使用者資料
echo $_GET['message'];

// ✅ 安全：依據上下文使用對應的 escape 函式
echo \esc_html( $message );          // 一般文字
echo \esc_attr( $attribute );        // HTML 屬性
echo \esc_url( $url );               // URL
echo \wp_kses_post( $html_content ); // 允許部分 HTML 標籤
```

## 1.3 CSRF 保護（🔴）

- [ ] 表單提交是否包含 nonce（`wp_nonce_field` / `check_admin_referer` / `check_ajax_referer`）

```php
// ✅ 表單加入 nonce
\wp_nonce_field( 'my_plugin_save_settings', 'my_plugin_nonce' );

// ✅ 驗證 nonce
\check_admin_referer( 'my_plugin_save_settings', 'my_plugin_nonce' );

// ✅ AJAX nonce 驗證
\check_ajax_referer( 'my_plugin_ajax_nonce', 'nonce' );
```

## 1.4 能力檢查（🔴）

- [ ] 變更資料的操作是否有 `current_user_can()` 驗證

```php
// ✅ 操作前驗證能力
if ( ! \current_user_can( 'manage_options' ) ) {
    \wp_die( \__( '您沒有權限執行此操作', 'my-plugin' ) );
}
```

## 1.5 資料驗證（🔴）

- [ ] 使用者輸入是否先 `sanitize_*()` 再儲存

```php
// ✅ 儲存前清洗輸入
$title   = \sanitize_text_field( $_POST['title'] ?? '' );
$content = \wp_kses_post( $_POST['content'] ?? '' );
$id      = \absint( $_POST['id'] ?? 0 );
$email   = \sanitize_email( $_POST['email'] ?? '' );
$url     = \esc_url_raw( $_POST['url'] ?? '' );
```

## 1.6 直接存取防護（🟠）

- [ ] 非入口 PHP 檔案是否有 `defined('ABSPATH') || exit;`

```php
// ✅ 正確
<?php
defined( 'ABSPATH' ) || exit;
```

## 1.7 敏感資訊（🔴）

- [ ] 是否在前端或日誌中暴露 API 金鑰、密碼、Token

## 1.8 競爭條件（🟠）

- [ ] 並發操作是否使用原子查詢（TOCTOU、Cron 重疊、WooCommerce 庫存需 `WHERE stock >= qty`）

## 1.9 並發安全（🟠）

- [ ] `update_option` / `update_post_meta` 在高並發場景是否有覆蓋風險

## 1.10 LLM 信任邊界（🟠）

若專案使用 AI 功能：

- [ ] AI 生成值是否在寫入 DB 前驗證
- [ ] AI 生成值是否在顯示前 escape

---

# 二、Hook 系統與 REST API 審查 Checklist

## 2.1 WordPress Hook 系統

- [ ] Hook callback 優先順序（priority）是否合理且有說明（🟡）
- [ ] 是否有未使用的 `add_action` / `add_filter`（🟡）
- [ ] `remove_action` / `remove_filter` 的優先順序是否與註冊時一致（🟠）
- [ ] `apply_filters` 的 hook 名稱是否遵循 `{plugin_prefix}_{context}` 命名慣例（🟡）
- [ ] 公開 API 是否提供 `do_action` / `apply_filters` 擴展點（🟡）
- [ ] hook 是否在正確時機點（如 `plugins_loaded`、`init`、`admin_init`）呼叫（🟠）

### Hook 命名範例

```php
// ✅ 正確：遵循 {plugin_prefix}_{context} 命名
\do_action( 'my_plugin_before_save', $data );
\apply_filters( 'my_plugin_product_price', $price, $product_id );

// ❌ 錯誤：命名空間不清楚
\do_action( 'before_save', $data );
\apply_filters( 'price', $price );
```

### 正確時機點範例

```php
// ✅ 在 plugins_loaded 執行國際化
\add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

// ✅ 在 init 註冊 CPT
\add_action( 'init', [ $this, 'register_post_type' ] );

// ✅ 在 admin_init 註冊設定
\add_action( 'admin_init', [ $this, 'register_settings' ] );
```

## 2.2 REST API 審查

- [ ] REST 路由是否有 `permission_callback` 檢查權限（🔴）
- [ ] `register_rest_route` 的 `args` 是否定義 `sanitize_callback` 與 `validate_callback`（🟠）
- [ ] REST 回應是否使用 `WP_REST_Response` 或 `WP_Error`（🟡）
- [ ] API namespace 是否遵循 `{plugin-slug}/v{N}` 格式（🟡）

### permission_callback 範例

```php
// ❌ 危險：永遠允許
\register_rest_route( 'my-plugin/v1', '/items', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'create_item' ],
    'permission_callback' => '__return_true',  // 危險！
] );

// ✅ 安全：驗證能力
\register_rest_route( 'my-plugin/v1', '/items', [
    'methods'             => 'POST',
    'callback'            => [ $this, 'create_item' ],
    'permission_callback' => function () {
        return \current_user_can( 'edit_posts' );
    },
    'args' => [
        'title' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'validate_callback' => function ( $value ) {
                return strlen( $value ) > 0;
            },
        ],
    ],
] );
```

### WP_REST_Response / WP_Error

```php
// ✅ 成功回應
return new \WP_REST_Response( $data, 200 );

// ✅ 錯誤回應
return new \WP_Error(
    'rest_not_found',
    \__( '找不到資源', 'my-plugin' ),
    [ 'status' => 404 ]
);
```

---

# 三、效能與 HPOS 審查 Checklist

## 3.1 資料存取

- [ ] WooCommerce 訂單是否使用物件方法（`$order->get_meta()`）而非 `get_post_meta()`（HPOS 相容）（🟠）
- [ ] **直接存取 `$wpdb`** 是否應改用 Repository 模式（🟡）
- [ ] 查詢是否有適當的快取（`wp_cache_get` / `transient`）（🟡）
- [ ] `WP_Query` 是否設定 `no_found_rows` 等效能參數（🟡）
- [ ] 迴圈中是否有 N+1 查詢問題（🟠）
- [ ] **條件式副作用**：分支邏輯是否有遺漏副作用（如某條件下促銷但未附加 URL，early return 跳過清理邏輯）（🟠）

### N+1 查詢修正範例

```php
// ❌ N+1 查詢：每次迴圈都發一次 meta 查詢
$posts = \get_posts( [ 'post_type' => 'product', 'numberposts' => 100 ] );
foreach ( $posts as $post ) {
    $price = \get_post_meta( $post->ID, '_price', true );  // N 次查詢
}

// ✅ 批次載入：一次查詢預熱快取
$posts = \get_posts( [ 'post_type' => 'product', 'numberposts' => 100 ] );
\update_meta_cache( 'post', wp_list_pluck( $posts, 'ID' ) );
foreach ( $posts as $post ) {
    $price = \get_post_meta( $post->ID, '_price', true );  // 從快取讀取
}
```

### WP_Query 效能參數

```php
// ✅ 設定效能參數
$query = new \WP_Query( [
    'post_type'              => 'product',
    'no_found_rows'          => true,   // 不計算總數
    'update_post_meta_cache' => false,  // 不需要 meta 時關閉
    'update_post_term_cache' => false,  // 不需要 term 時關閉
    'fields'                 => 'ids',  // 只取 ID
] );
```

## 3.2 WooCommerce 相容性

- [ ] 是否同時支援**傳統結帳**（`woocommerce_checkout_order_processed`）與**區塊結帳**（`woocommerce_store_api_checkout_order_processed`）（🟠）
- [ ] 是否宣告 HPOS 相容性（`FeaturesUtil::declare_compatibility`）（🟠）
- [ ] WooCommerce 物件（`WC_Order`、`WC_Product`）是否有型別提示（🟡）
- [ ] 是否使用 `wc_get_order()` 而非 `get_post()`（🟠）

### HPOS 相容訂單操作

```php
// ❌ HPOS 不相容：直接操作 postmeta
$order_total = \get_post_meta( $order_id, '_order_total', true );
\update_post_meta( $order_id, '_custom_field', $value );

// ✅ HPOS 相容：使用 WC_Order 物件方法
$order = \wc_get_order( $order_id );
$order_total = $order->get_total();
$order->update_meta_data( '_custom_field', $value );
$order->save();
```

### 宣告 HPOS 相容

```php
\add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
```

### 同時支援兩種結帳流程

```php
// 傳統結帳
\add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_created' ], 10, 3 );

// 區塊結帳（WooCommerce Blocks / Store API）
\add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_order_created_block' ], 10, 1 );
```

## 3.3 資源載入與排程

- [ ] 是否在 `admin_enqueue_scripts` 的正確頁面才載入資源（🟠）
- [ ] 是否避免在每次頁面載入時執行昂貴的計算或查詢（🟠）
- [ ] 大量資料處理是否考慮分批（batch）處理（🟡）
- [ ] 是否適當使用 `wp_schedule_event` 處理背景任務（🟡）

### 條件式載入資源

```php
// ✅ 只在特定頁面載入
\add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'toplevel_page_my-plugin' !== $hook ) {
        return;
    }
    \wp_enqueue_script( 'my-plugin-admin', ... );
} );
```

---

# 四、PHP 8.1+ 最佳實踐審查 Checklist

## 4.1 PHP 8.1+ 型別安全

- [ ] **`declare(strict_types=1)`** 是否在每個 PHP 檔案開頭宣告（🟠）
- [ ] 方法參數與回傳值是否完整標註型別（🟠）
- [ ] 是否使用 union types、nullable types（`?Type`）正確表達型別（🟡）
- [ ] 有限狀態值是否改用 PHP 8.1 原生 `enum`，**禁止魔術字串**（🟠）
- [ ] `readonly` 屬性是否正確應用於不可變資料（🟡）
- [ ] 是否使用 `match` 表達式取代複雜 `switch`（🟡）

### enum 範例

```php
// ❌ 魔術字串
if ( $status === 'active' ) { /* ... */ }

// ✅ PHP 8.1 Enum
enum StatusEnum: string {
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case PENDING = 'pending';

    public function label(): string {
        return match ( $this ) {
            self::ACTIVE   => \__( '啟用', 'my-plugin' ),
            self::INACTIVE => \__( '停用', 'my-plugin' ),
            self::PENDING  => \__( '待審核', 'my-plugin' ),
        };
    }
}

if ( $status === StatusEnum::ACTIVE ) { /* ... */ }
```

### readonly 屬性

```php
// ✅ 不可變 DTO
class ProductDTO {
    public function __construct(
        public readonly int $product_id,
        public readonly string $name,
        public readonly int $price,
    ) {}
}
```

## 4.2 PHPDoc 與命名規範

- [ ] 所有類別、方法是否有 **PHPDoc 繁體中文**說明（🟡）
- [ ] `@param`、`@return`、`@throws` 標籤是否完整標註（🟠）
- [ ] **Class**：是否使用 `CamelCase`（如 `ProductService`）（🟡）
- [ ] **Method / 函式**：是否使用 `snake_case`（如 `get_product`）（🟡）
- [ ] **變數**：是否使用 `snake_case`（如 `$product_id`）（🟡）
- [ ] **常數 / Enum Case**：是否使用 `UPPER_SNAKE_CASE`（如 `DAY_IN_SECONDS`）（🟡）
- [ ] **全域函式**：在命名空間下是否加上反斜線 `\`（如 `\get_post()`、`\add_action()`）（🟠）

### PHPDoc 範例

```php
/**
 * 根據 ID 取得商品詳細資料
 *
 * @param int $product_id 商品 ID
 *
 * @return ProductDTO 商品資料
 * @throws \RuntimeException 當商品不存在時拋出異常
 */
public function get_product( int $product_id ): ProductDTO {
    $post = \get_post( $product_id );

    if ( ! $post instanceof \WP_Post ) {
        throw new \RuntimeException( "商品 ID {$product_id} 不存在" );
    }

    return ProductDTO::from_post( $post );
}
```

### 全域函式反斜線

```php
// ✅ 正確
$result = \get_posts( [ 'post_type' => 'post' ] );
\add_action( 'init', [ __CLASS__, 'init' ] );

// ❌ 錯誤
$result = get_posts( [ 'post_type' => 'post' ] );
add_action( 'init', [ __CLASS__, 'init' ] );
```

## 4.3 架構與設計原則

- [ ] 是否使用 **DTO** 封裝資料，避免直接操作裸 `array`（🟠）
- [ ] 是否遵循 **SRP**（單一職責），一個類別不超過一個職責（🟠）
- [ ] 是否依賴 **Interface** 而非具體實作（DIP 原則）（🟡）
- [ ] 是否使用 **heredoc** 輸出多行 HTML，禁止 `.` 字串拼接（🟠）
- [ ] 字串插值是否優先使用雙引號 `"` 或 `sprintf`，避免 `.` 拼接（🟡）
- [ ] 陣列是否使用短語法 `[]`，禁止 `array()`（🟡）

### Heredoc 輸出 HTML

```php
// ✅ 正確
function render_notice( string $message, string $type ): string {
    return <<<HTML
    <div class="notice notice-{$type}">
        <p>{$message}</p>
    </div>
    HTML;
}

// ❌ 錯誤：字串拼接
function render_notice( string $message, string $type ): string {
    return '<div class="notice notice-' . $type . '">'
         . '<p>' . $message . '</p>'
         . '</div>';
}
```

### 字串插值

```php
// ✅ 優先使用雙引號插值
$text = "這是 {$variable} 的文字";

// ✅ 其次使用 sprintf
$text = \sprintf( '這是 %1$s 的文字 %2$s', $variable1, $variable2 );

// ❌ 避免使用 . 拼接
$text = '這是 ' . $variable . ' 的文字';

// ✅ 短語法陣列
$items = [ 'a', 'b', 'c' ];

// ❌ 避免 array()
$items = array( 'a', 'b', 'c' );
```

## 4.4 程式碼異味

- [ ] 函式是否過長（> 50 行建議拆分）（🟡）
- [ ] 巢狀深度是否過深（> 4 層改用 early return）（🟠）
- [ ] 是否有 magic number / magic string（改用命名常數或 enum）（🟡）
- [ ] 是否有重複程式碼（DRY 原則）（🟡）
- [ ] 是否有直接操作 postmeta 而非使用 WooCommerce / CPT 物件方法（🟠）
- [ ] **生產環境**是否有未清除的 `error_log`、`var_dump`、`print_r`（🟡）
- [ ] 是否有未使用的死碼、被注解掉的程式碼（🟡）

### Early Return 降低巢狀

```php
// ❌ 過深巢狀
public function process( $data ) {
    if ( $data ) {
        if ( \is_array( $data ) ) {
            if ( ! empty( $data['id'] ) ) {
                if ( \current_user_can( 'edit_posts' ) ) {
                    // 核心邏輯
                }
            }
        }
    }
}

// ✅ Early return
public function process( $data ) {
    if ( ! $data || ! \is_array( $data ) ) {
        return;
    }

    if ( empty( $data['id'] ) ) {
        return;
    }

    if ( ! \current_user_can( 'edit_posts' ) ) {
        return;
    }

    // 核心邏輯
}
```
