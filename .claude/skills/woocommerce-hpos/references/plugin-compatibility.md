# HPOS Plugin Compatibility & Code Migration Recipes

> Source: [HPOS extension recipe book](https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/)

## Table of Contents

- [Overview](#overview)
- [Step 1: Declare Compatibility](#step-1-declare-compatibility)
- [Step 2: Audit Your Codebase](#step-2-audit-your-codebase)
- [Step 3: Migrate Post/Postmeta Access](#step-3-migrate-postpostmeta-access)
- [Step 4: Migrate Admin Screen Functions](#step-4-migrate-admin-screen-functions)
- [Step 5: Migrate Meta Boxes](#step-5-migrate-meta-boxes)
- [Step 6: Migrate Direct SQL Queries](#step-6-migrate-direct-sql-queries)
- [Step 7: Handle Order Type Checking](#step-7-handle-order-type-checking)
- [Synchronization & Backward Compatibility](#synchronization--backward-compatibility)
- [Key Classes Reference](#key-classes-reference)

## Overview

HPOS requires extension developers to replace WordPress post APIs with WooCommerce CRUD APIs when working with orders. The WooCommerce CRUD layer was introduced in WC 3.0 (2017) specifically to enable this transition.

**The fundamental rule**: Never use `get_post()`, `update_post_meta()`, or direct SQL on `wp_posts`/`wp_postmeta` for order data. Always use `wc_get_order()` and `WC_Order` methods.

## Step 1: Declare Compatibility

Place this in your **main plugin file** (the file with the plugin header). This is mandatory -- WooCommerce will block HPOS activation if any active plugin hasn't declared compatibility.

### Declare Compatible

```php
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
} );
```

### Declare Incompatible

```php
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, false
        );
    }
} );
```

**Notes:**
- The feature slug is `custom_order_tables` (not `hpos`)
- Use `__FILE__` when declaring in the main plugin file
- Use `'my-plugin-slug/my-plugin.php'` when declaring from a different file
- The `class_exists` check ensures backward compatibility with WC versions before the feature API existed
- WooCommerce only shows compatibility info for extensions that declare `WC tested up to` in their plugin header

## Step 2: Audit Your Codebase

### Regex: Direct DB/Post Access

Search your codebase with this regex to find all potential order-related post/postmeta access:

```regexp
wpdb|get_post|get_post_field|get_post_status|get_post_type|get_post_type_object|get_posts|metadata_exists|get_post_meta|get_metadata|get_metadata_raw|get_metadata_default|get_metadata_by_mid|wp_insert_post|add_metadata|add_post_meta|wp_update_post|update_post_meta|update_metadata|update_metadata_by_mid|delete_metadata|delete_post_meta|delete_metadata_by_mid|delete_post_meta_by_key|wp_delete_post|wp_trash_post|wp_untrash_post|wp_transition_post_status|clean_post_cache|update_post_caches|update_postmeta_cache|post_exists|wp_count_post|shop_order
```

**Expect many false positives.** For each match:
1. Check if it relates to order data
2. If yes, migrate to WooCommerce CRUD (see Steps 3-7 below)
3. If no (e.g., it's about products, posts, pages), leave as-is

### Regex: Admin Screen Functions

```regexp
post_updated_messages|do_meta_boxes|enter_title_here|edit_form_before_permalink|edit_form_after_title|edit_form_after_editor|submitpage_box|submitpost_box|edit_form_advanced|dbx_post_sidebar|manage_shop_order_posts_columns|manage_shop_order_posts_custom_column
```

Same process: check each match for order relevance.

## Step 3: Migrate Post/Postmeta Access

### Getting Orders

```php
// BEFORE (breaks with HPOS):
$post = get_post( $order_id ); // Returns WP_Post

// AFTER (works with both backends):
$order = wc_get_order( $order_id ); // Returns WC_Order
```

### Reading Metadata

```php
// BEFORE:
$value = get_post_meta( $order_id, '_custom_key', true );

// AFTER:
$order = wc_get_order( $order_id );
$value = $order->get_meta( '_custom_key', true );
```

### Writing Metadata

```php
// BEFORE:
update_post_meta( $order_id, '_key1', 'value1' );
add_post_meta( $order_id, '_key2', 'value2' );
delete_post_meta( $order_id, '_key3' );

// AFTER:
$order = wc_get_order( $order_id );
$order->update_meta_data( '_key1', 'value1' );
$order->add_meta_data( '_key2', 'value2' );
$order->delete_meta_data( '_key3' );
$order->save(); // REQUIRED -- and expensive. Batch changes before calling.
```

**Performance warning**: `$order->save()` is a relatively expensive operation. Avoid calling it multiple times in the same flow. If you know `save()` will be called later (e.g., by WooCommerce core), skip the extra call.

### Checking Metadata Existence

```php
// BEFORE:
$exists = metadata_exists( 'post', $order_id, '_custom_key' );

// AFTER:
$order = wc_get_order( $order_id );
$exists = $order->meta_exists( '_custom_key' );
```

## Step 4: Migrate Admin Screen Functions

When HPOS is active, WooCommerce uses custom admin screens instead of the WordPress post edit screen. Filters and actions that previously received `WP_Post` objects now receive `WC_Order` objects.

```php
// BEFORE: Using post-based column filters
add_filter( 'manage_shop_order_posts_columns', 'add_custom_column' );
add_action( 'manage_shop_order_posts_custom_column', 'render_custom_column', 10, 2 );

// AFTER: Must handle both old and new screens
// The new screen uses wc_get_page_screen_id('shop-order') as the screen ID
```

## Step 5: Migrate Meta Boxes

### Registration (dual-compatible)

```php
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

add_action( 'add_meta_boxes', 'add_my_order_metabox' );

function add_my_order_metabox() {
    $screen = class_exists(
            '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController'
        )
        && wc_get_container()->get( CustomOrdersTableController::class )
            ->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';

    add_meta_box(
        'my-order-metabox',
        'My Custom Meta Box',
        'render_my_order_metabox',
        $screen,
        'side',
        'high'
    );
}
```

### Render Callback (dual-compatible)

The callback receives either a `WP_Post` (legacy) or `WC_Order` (HPOS). Always normalize to `WC_Order`:

```php
function render_my_order_metabox( $post_or_order_object ) {
    $order = ( $post_or_order_object instanceof WP_Post )
        ? wc_get_order( $post_or_order_object->ID )
        : $post_or_order_object;

    // Use $order exclusively below this point.
    // Do NOT reference $post_or_order_object directly.
    $custom_value = $order->get_meta( '_my_custom_field' );
    echo '<p>' . esc_html( $custom_value ) . '</p>';
}
```

## Step 6: Migrate Direct SQL Queries

If your plugin runs direct SQL queries against order data for performance reasons, you need to query the correct tables based on the active storage backend.

```php
use Automattic\WooCommerce\Utilities\OrderUtil;

global $wpdb;

if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
    // Query HPOS tables
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, total_amount, status
         FROM {$wpdb->prefix}wc_orders
         WHERE status = %s AND total_amount > %f",
        'wc-processing',
        100.00
    ) );
} else {
    // Query legacy posts tables
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.ID, pm.meta_value as total
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_order_total'
         WHERE p.post_type = 'shop_order'
         AND p.post_status = %s
         AND CAST(pm.meta_value AS DECIMAL(10,2)) > %f",
        'wc-processing',
        100.00
    ) );
}
```

**Better alternative**: Use `wc_get_orders()` with the new `field_query` and `meta_query` arguments instead of direct SQL. This works with both backends automatically.

```php
$orders = wc_get_orders( array(
    'status'      => 'processing',
    'field_query'  => array(
        array(
            'field'   => 'total',
            'value'   => '100.00',
            'compare' => '>',
            'type'    => 'NUMERIC',
        ),
    ),
    'return' => 'ids',
) );
```

## Step 7: Handle Order Type Checking

```php
use Automattic\WooCommerce\Utilities\OrderUtil;

// BEFORE: Check if a post is a shop order
if ( 'shop_order' === get_post_type( $id ) ) { ... }
if ( in_array( get_post_type( $id ), wc_get_order_types() ) ) { ... }

// AFTER: Works with both HPOS and legacy
if ( 'shop_order' === OrderUtil::get_order_type( $id ) ) { ... }
if ( OrderUtil::is_order( $id, wc_get_order_types() ) ) { ... }
```

## Synchronization & Backward Compatibility

### How Sync Works When HPOS Is Active

When compatibility mode is enabled:

1. **CRUD operations** update both datastores simultaneously
2. **Direct CPT writes** (by non-HPOS-compatible plugins) are detected via timestamp comparison:
   - If `CPT update_time < HPOS update_time`: Failed write, HPOS data overwrites CPT
   - If `CPT update_time == HPOS update_time`: Direct CPT write detected, CPT data syncs to HPOS
3. **Placeholder records** (type `shop_order_placehold`) reserve IDs in the posts table when HPOS is authoritative and sync is disabled

### Programmatic Sync

```php
$synchronizer = wc_get_container()->get(
    Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
);

$order_ids = $synchronizer->get_next_batch_to_process( 100 );
if ( count( $order_ids ) ) {
    $synchronizer->process_batch( $order_ids );
}
```

### Detecting HPOS Status in Code

```php
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
    // HPOS tables are authoritative
} else {
    // Posts tables are authoritative
}
```

### Relevant Options

| Option | Values | Description |
|--------|--------|-------------|
| `woocommerce_custom_orders_table_enabled` | `true`/`false` | HPOS active |
| `woocommerce_custom_orders_table_data_sync_enabled` | `true`/`false` | Compatibility mode (sync) active |
| `woocommerce_auto_flip_authoritative_table_roles` | `true`/`false` | Auto-switch authoritative tables after sync completes |

### Relevant Hooks

| Hook | Type | When |
|------|------|------|
| `woocommerce_order_data_store` | Filter | Determines which DataStore class to use |
| `woocommerce_update_options_advanced_custom_data_stores` | Action | Fires when HPOS settings are saved |
| `woocommerce_hpos_enable_sync_on_read` | Filter | Controls read sync behavior (return `false` to disable) |
| `before_woocommerce_init` | Action | Where to declare compatibility |

## Key Classes Reference

| Class | Role |
|-------|------|
| `Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore` | HPOS data store implementation ([source](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Internal/DataStores/Orders/OrdersTableDataStore.php)) |
| `Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController` | Controls HPOS activation, hooks data store filter ([source](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Internal/DataStores/Orders/CustomOrdersTableController.php)) |
| `Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer` | Handles sync between stores ([source](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Internal/DataStores/Orders/DataSynchronizer.php)) |
| `Automattic\WooCommerce\Internal\BatchProcessing\BatchProcessingController` | Manages batch sync scheduling ([source](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Internal/BatchProcessing/BatchProcessingController.php)) |
| `Automattic\WooCommerce\Utilities\OrderUtil` | Utility class for HPOS detection and order type checking |
| `Automattic\WooCommerce\Utilities\FeaturesUtil` | Plugin compatibility declaration |
| `WC_Order_Data_Store_CPT` | Legacy post-based data store ([source](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/includes/data-stores/class-wc-order-data-store-cpt.php)) |
