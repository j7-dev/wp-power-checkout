# 進階規範

## 繼承類注意事項

```php
// ✅ 正確：保持與父類一致
class ChildService extends ParentService {
    protected function process( $data, $context ): void {
        // 父類 process 是 protected 且 $data 沒有型別，這裡也不加
        parent::process( $data, $context );
    }
}

// ❌ 錯誤：擅自加型別、改可見性、省略參數
class ChildService extends ParentService {
    public function process( array $data ): void {
        parent::process( $data, null );
    }
}
```

---

## 多語系字串

```php
// text_domain 請從 CLAUDE.md 或 plugin.php 查找
\__( '這是一段文字', 'my-plugin' );
\esc_html__( '安全的文字', 'my-plugin' );
\esc_attr__( '屬性文字', 'my-plugin' );
\wp_kses_post( \__( '<strong>粗體文字</strong>', 'my-plugin' ) );
```

---

## Log 記錄

```php
// WordPress error_log（通用）
\error_log( \wp_json_encode( $debug_data ) );

// WooCommerce Logger（WC 專案）
$logger = \wc_get_logger();
$logger->error( '錯誤訊息', [ 'source' => 'my-plugin' ] );
```

**Log 檔案位置：**
- WordPress debug log：`/wp-content/debug.log`
- WooCommerce log：`/wp-content/uploads/wc-logs/`

**開啟除錯模式（wp-config.php）：**

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
