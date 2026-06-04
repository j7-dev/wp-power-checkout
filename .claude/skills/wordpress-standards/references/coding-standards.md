# WordPress / PHP 編碼標準完整參考

WordPress Plugin / Theme 開發的完整編碼標準。供 wordpress-master 開發時遵循、wordpress-reviewer 審查時對照。

---

## 一、檔案結構標準

### 1.1 嚴格型別宣告

所有 PHP 檔案必須在開頭宣告 `declare(strict_types=1)`。

```php
// ❌ 不好的做法
<?php

namespace MyPlugin\Domain;

class ProductService {}

// ✅ 正確的做法
<?php
/**
 * @license GPL-2.0+
 */

declare(strict_types=1);

namespace MyPlugin\Domain\Product;

/**
 * 商品服務
 */
class ProductService {}
```

### 1.2 直接存取防護

非入口 PHP 檔案必須在開頭加上防護。

```php
// ✅ 正確
<?php
defined( 'ABSPATH' ) || exit;
```

---

## 二、命名規範

| 類型 | 規範 | 範例 |
|------|------|------|
| Class | `CamelCase` | `ProductService`、`StatusEnum` |
| Method / 函式 | `snake_case` | `get_product`、`process_submission` |
| 變數 | `snake_case` | `$product_id`、`$api_client` |
| 常數 / Enum Case | `UPPER_SNAKE_CASE` | `DAY_IN_SECONDS`、`ACTIVE` |
| Hook 名稱 | `{plugin_prefix}_{context}` | `my_plugin_before_submission` |

```php
// ✅ 正確的命名方式
class ProductService {
    private int $max_retry_count = 3;

    public function get_product_list(): array { /* ... */ }
}

// ❌ 錯誤的命名方式
class productService {
    private int $maxRetryCount;

    public function getProductList(): array { /* ... */ }
}
```

---

## 三、全域函式反斜線

在命名空間下使用全域函式時，必須加上反斜線 `\`。

```php
// ✅ 正確
$result = \get_posts( [ 'post_type' => 'post' ] );
$label  = \__( '標籤文字', 'my-plugin' );
$url    = \admin_url( 'admin.php' );
\add_action( 'init', [ __CLASS__, 'init' ] );
\add_filter( 'the_content', [ __CLASS__, 'filter_content' ] );
\do_action( 'my_plugin_loaded' );
$value = \apply_filters( 'my_plugin_value', $default );

// ❌ 沒有加上反斜線
$result = get_posts( [ 'post_type' => 'post' ] );
$label  = __( '標籤文字', 'my-plugin' );
```

---

## 四、PHPDoc 繁體中文說明

所有類別、方法必須有 PHPDoc 繁體中文說明，並完整標註參數與回傳型別。

```php
// ❌ 不好的做法
function get_product($id) {
    return get_post_meta($id, '_product_data', true);
}

// ✅ 正確的做法
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

---

## 五、DTO 資料傳輸物件

將散亂的 array 封裝為強型別 DTO，提升可讀性、IDE 補全、型別安全。

```php
// ✅ 正確的做法
declare(strict_types=1);

namespace MyPlugin\Domain\Product\DTOs;

/**
 * 商品資料傳輸物件
 */
class ProductDTO {

    /**
     * 建構子
     *
     * @param int    $product_id 商品 ID
     * @param string $name       商品名稱
     * @param int    $price      商品價格
     */
    public function __construct(
        public readonly int $product_id,
        public readonly string $name,
        public readonly int $price,
    ) {}

    /**
     * 從陣列建立 DTO
     *
     * @param array<string, mixed> $data 原始資料
     *
     * @return self
     */
    public static function from_array( array $data ): self {
        return new self(
            product_id: (int) ( $data['product_id'] ?? 0 ),
            name:       (string) ( $data['name'] ?? '' ),
            price:      (int) ( $data['price'] ?? 0 ),
        );
    }
}
```

---

## 六、Enum 取代魔術字串

使用 PHP 8.1 原生 enum 取代常數或魔術字串。

```php
// ✅ 正確的做法
/**
 * 狀態枚舉
 */
enum StatusEnum: string {
    /** 啟用 */
    case ACTIVE = 'active';
    /** 停用 */
    case INACTIVE = 'inactive';
    /** 待審核 */
    case PENDING = 'pending';

    /**
     * 取得狀態的繁體中文標籤
     *
     * @return string 繁體中文標籤
     */
    public function label(): string {
        return match ( $this ) {
            self::ACTIVE   => \__( '啟用', 'my-plugin' ),
            self::INACTIVE => \__( '停用', 'my-plugin' ),
            self::PENDING  => \__( '待審核', 'my-plugin' ),
        };
    }
}
```

---

## 七、字串與陣列風格

```php
// ✅ 優先使用雙引號插值
$text = "這是 {$variable} 的文字";

// ✅ 其次使用 sprintf
$text = \sprintf( '這是 %1$s 的文字 %2$s', $variable1, $variable2 );

// ❌ 避免使用 . 拼接
$text = '這是 ' . $variable . ' 的文字';

// ✅ 使用短語法陣列
$items = [ 'a', 'b', 'c' ];

// ❌ 避免使用 array()
$items = array( 'a', 'b', 'c' );
```

### Heredoc 輸出 HTML

```php
// ✅ 正確的做法
/**
 * 渲染後台通知 HTML
 *
 * @param string $message 通知訊息
 * @param string $type    通知類型 (success|error|warning|info)
 *
 * @return string 通知 HTML
 */
function render_notice( string $message, string $type ): string {
    return <<<HTML
    <div class="notice notice-{$type}">
        <p>{$message}</p>
    </div>
    HTML;
}
```

---

## 八、安全性

### 8.1 SQL 注入防護

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

### 8.2 XSS 輸出防護

```php
// ❌ 危險：直接輸出未處理的使用者資料
echo $_GET['message'];

// ✅ 安全：依據上下文使用對應的 escape 函式
echo \esc_html( $message );          // 一般文字
echo \esc_attr( $attribute );        // HTML 屬性
echo \esc_url( $url );               // URL
echo \wp_kses_post( $html_content ); // 允許部分 HTML 標籤
```

### 8.3 CSRF 防護（Nonce）

```php
// ✅ 表單加入 nonce
\wp_nonce_field( 'my_plugin_save_settings', 'my_plugin_nonce' );

// ✅ 驗證 nonce
\check_admin_referer( 'my_plugin_save_settings', 'my_plugin_nonce' );

// ✅ AJAX nonce 驗證
\check_ajax_referer( 'my_plugin_ajax_nonce', 'nonce' );
```

### 8.4 能力檢查

```php
// ✅ 操作前驗證能力
if ( ! \current_user_can( 'manage_options' ) ) {
    \wp_die( \__( '您沒有權限執行此操作', 'my-plugin' ) );
}
```

### 8.5 資料清洗

```php
// ✅ 儲存前清洗輸入
$title   = \sanitize_text_field( $_POST['title'] ?? '' );
$content = \wp_kses_post( $_POST['content'] ?? '' );
$id      = \absint( $_POST['id'] ?? 0 );
$email   = \sanitize_email( $_POST['email'] ?? '' );
$url     = \esc_url_raw( $_POST['url'] ?? '' );
```

---

## 快速審查對照表

| 類別 | 常見問題 | 嚴重性 |
|------|---------|--------|
| 安全 | 未使用 `$wpdb->prepare()` | 高 |
| 安全 | 未 escape 輸出 | 高 |
| 安全 | 缺少 nonce 驗證 | 高 |
| 安全 | 缺少能力檢查 | 高 |
| 安全 | 缺少 `defined('ABSPATH')` | 中 |
| 型別 | 缺少 `strict_types` | 中 |
| 型別 | 使用魔術字串取代 enum | 中 |
| 架構 | 直接操作裸 array 取代 DTO | 中 |
| 架構 | 字串拼接 HTML（應用 heredoc） | 中 |
| 命名 | 全域函式未加反斜線 | 中 |
| WooCommerce | 直接 `get_post_meta` 操作訂單 | 中 |
| WooCommerce | 未宣告 HPOS 相容 | 中 |
| REST API | `permission_callback` 返回 true | 高 |
| 效能 | 迴圈中 N+1 查詢 | 中 |
| 品質 | 函式超過 50 行 | 低 |
| 品質 | 巢狀超過 4 層 | 中 |

---

## 參考文件

依需要載入對應的詳細規範：

- **Hook 系統**：`coding-hooks.md` — action/filter 擴展點、hook 命名慣例
- **WooCommerce**：`coding-woocommerce.md` — HPOS 相容、區塊結帳、訂單操作
- **REST API**：`coding-rest-api.md` — 路由注冊、回應格式、權限檢查
- **進階規範**：`coding-advanced.md` — 繼承類注意事項、多語系字串、Log 記錄
