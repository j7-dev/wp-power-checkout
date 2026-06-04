# WooCommerce 開發規範

## HPOS（高效能訂單儲存）相容

```php
// ✅ 同時相容 HPOS 與傳統儲存
/** @var \WC_Order|false $order */
$order = \wc_get_order( $order_id );

if ( ! $order instanceof \WC_Order ) {
    return;
}

// 使用物件方法，不直接操作 postmeta
$meta_value = $order->get_meta( '_my_meta_key', true );
$order->update_meta_data( '_my_meta_key', $new_value );
$order->save();

// ❌ 不相容 HPOS 的做法
$meta_value = \get_post_meta( $order_id, '_my_meta_key', true );
\update_post_meta( $order_id, '_my_meta_key', $new_value );
```

## 宣告 HPOS 相容性

```php
\add_action( 'before_woocommerce_init', function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );
```

## 區塊結帳與傳統結帳相容

```php
// 傳統結帳 hook
\add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'process_order' ], 10, 3 );

// 區塊結帳 hook（Store API）
\add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'process_order_block' ] );

// 通用做法：使用 woocommerce_checkout_order_created（WC 8.2+ 兩者都會觸發）
\add_action( 'woocommerce_checkout_order_created', [ __CLASS__, 'handle_order_created' ] );
```
