---
name: woocommerce-hpos
user-invocable: false
description: >
  WooCommerce High-Performance Order Storage (HPOS) complete technical reference.
  Covers database schema (wp_wc_orders, wp_wc_order_addresses, wp_wc_order_operational_data, wp_wc_orders_meta),
  CRUD API migration from wp_posts/wp_postmeta to WC_Order objects, OrdersTableDataStore,
  plugin compatibility declaration via FeaturesUtil, synchronization mechanics,
  CLI tools (wp wc hpos), and order querying APIs (meta_query, field_query, date_query).
  Use this skill whenever the user's code involves WooCommerce orders, order storage,
  order metadata, order queries, wc_get_order, wc_get_orders, WC_Order, WC_Order_Query,
  custom_order_tables, OrderUtil, FeaturesUtil::declare_compatibility,
  or any migration from post-based order storage to HPOS custom tables.
  Also use when the user mentions HPOS, COT (Custom Order Tables), order tables,
  order data store, or order synchronization in a WooCommerce context.
  This skill replaces the need to search the web for HPOS documentation.
---

# WooCommerce HPOS (High-Performance Order Storage)

> **Since**: WooCommerce 8.2 (Oct 2023, default for new installs) | **Source**: [developer.woocommerce.com](https://developer.woocommerce.com/docs/features/high-performance-order-storage/) | **Updated**: 2026-03-15

HPOS replaces the legacy `wp_posts` + `wp_postmeta` order storage with four dedicated tables optimized for eCommerce queries. Performance gains: up to 5x faster order creation, 1.5x faster checkout, 40x faster order lookups. Order INSERTs drop from ~40 to at most 5 per order.

## Database Tables

| Table | Purpose |
|-------|---------|
| `wp_wc_orders` | Primary order data (status, currency, totals, customer, payment, dates) |
| `wp_wc_order_addresses` | Billing and shipping addresses (one row per address type per order) |
| `wp_wc_order_operational_data` | Internal state flags (created_via, order_key, stock_reduced, email_sent, etc.) |
| `wp_wc_orders_meta` | Extension/custom metadata (replaces `wp_postmeta` for orders) |

Order notes remain in `wp_comments`. Order items retain their dedicated tables. See `references/database-schema.md` for full column definitions.

## Core API Quick Reference

### Detect HPOS Status

```php
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
    // HPOS is active
} else {
    // Legacy CPT storage
}
```

### Get/Create/Update Orders (works with both storage backends)

```php
// Get order -- replaces get_post()
$order = wc_get_order( $order_id );

// Read data
$status = $order->get_status();
$total  = $order->get_total();
$email  = $order->get_billing_email();

// Update metadata -- replaces update_post_meta()
$order->update_meta_data( '_custom_key', 'value' );
$order->save(); // Required! Expensive -- batch changes before calling.

// Delete metadata -- replaces delete_post_meta()
$order->delete_meta_data( '_custom_key' );
$order->save();
```

### Check Order Type

```php
use Automattic\WooCommerce\Utilities\OrderUtil;

// replaces: 'shop_order' === get_post_type( $id )
'shop_order' === OrderUtil::get_order_type( $id );

// replaces: in_array( get_post_type( $id ), wc_get_order_types() )
OrderUtil::is_order( $id, wc_get_order_types() );
```

### Declare Plugin Compatibility (in main plugin file)

```php
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
    }
} );
```

Pass `false` as third arg to declare incompatibility. Use `'my-plugin-slug/my-plugin.php'` instead of `__FILE__` if the declaration is outside the main plugin file.

### Register Meta Boxes (dual-compatible)

```php
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;

add_action( 'add_meta_boxes', function() {
    $screen = class_exists( CustomOrdersTableController::class )
        && wc_get_container()->get( CustomOrdersTableController::class )
            ->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';

    add_meta_box( 'my-box', 'Title', 'render_callback', $screen, 'side', 'high' );
} );

function render_callback( $post_or_order ) {
    $order = ( $post_or_order instanceof WP_Post )
        ? wc_get_order( $post_or_order->ID )
        : $post_or_order;
    // Use $order exclusively below this point
}
```

### Query Orders (HPOS-enhanced)

```php
// Basic query (works on both backends)
$orders = wc_get_orders( array(
    'status'    => 'processing',
    'limit'     => 50,
    'orderby'   => 'date',
    'order'     => 'DESC',
) );

// HPOS-enhanced: meta_query
$orders = wc_get_orders( array(
    'meta_query' => array(
        array( 'key' => 'color', 'compare' => 'EXISTS' ),
        array( 'key' => 'size', 'value' => 'small', 'compare' => 'LIKE' ),
    ),
) );

// HPOS-enhanced: field_query
$orders = wc_get_orders( array(
    'field_query' => array(
        'relation' => 'OR',
        array( 'field' => 'total', 'value' => '5.0', 'compare' => '<' ),
        array( 'field' => 'shipping_total', 'value' => '5.0', 'compare' => '<' ),
    ),
) );

// HPOS-enhanced: date_query
$orders = wc_get_orders( array(
    'date_query' => array(
        array( 'column' => 'date_paid_gmt', 'after' => '1 month ago' ),
    ),
) );
```

See `references/querying-apis.md` for full query type documentation with advanced examples.

## Synchronization Model

When HPOS is enabled with compatibility mode, data is kept in sync between the new tables (authoritative) and legacy posts tables (backup).

| Setting | Authoritative | Backup | Behavior |
|---------|--------------|--------|----------|
| HPOS on, sync on | `wp_wc_orders` | `wp_posts` | Dual writes, immediate sync |
| HPOS on, sync off | `wp_wc_orders` | None (placeholders only) | Full performance, no fallback |
| Posts on, sync on | `wp_posts` | `wp_wc_orders` | Legacy mode with async backfill |
| Posts on, sync off | `wp_posts` | None | Classic WooCommerce behavior |

Key option: `woocommerce_custom_orders_table_enabled` (true = HPOS active).
Sync option: `woocommerce_custom_orders_table_data_sync_enabled`.

**Placeholder records**: When HPOS is authoritative and sync is disabled, creating an order inserts a post with type `shop_order_placehold` to reserve the ID. This ensures `post.ID == order.id` invariant.

**Conflict resolution** (sync on read): Compares timestamps. If CPT update_time < HPOS update_time, assumes failed CPT write and overwrites CPT. If timestamps match, assumes direct CPT write and syncs to HPOS.

## CLI Tools (wp wc hpos)

| Command | Purpose |
|---------|---------|
| `wp wc hpos status` | Overview of HPOS settings and sync state |
| `wp wc hpos enable [--with-sync]` | Enable HPOS (optionally with compatibility mode) |
| `wp wc hpos disable` | Disable HPOS (must sync pending orders first) |
| `wp wc hpos sync` | Migrate/sync orders between datastores |
| `wp wc hpos count_unmigrated` | Count orders pending sync |
| `wp wc hpos verify_data [--re-migrate]` | Verify consistency between datastores |
| `wp wc hpos diff <order_id>` | Show differences for a specific order |
| `wp wc hpos backfill <id> --from=<posts\|hpos> --to=<posts\|hpos>` | Copy order data between stores |
| `wp wc hpos cleanup <id\|range\|all> [--force]` | Remove legacy data after migration |

See `references/cli-tools.md` for full command documentation with examples.

## Migration Checklist (Critical Path)

For large stores, follow the 3-phase approach documented in `references/migration-guide.md`:

1. **Phase 1 -- Local testing**: Enable HPOS locally, test all checkout flows with every payment method, test refunds, test subscriptions, test with sync on and off.
2. **Phase 2 -- Staging**: Copy production DB, run `wp wc hpos sync`, benchmark migration time, verify with `wp wc hpos verify_data --verbose`, audit third-party systems accessing DB directly.
3. **Phase 3 -- Production**: Enable sync with posts authoritative -> run CLI sync -> switch HPOS authoritative -> disable read sync via filter -> after 1 week stable, disable write sync.

## Common Pitfalls

1. **Never use `get_post()` / `update_post_meta()` for orders** -- these bypass HPOS and write to wrong tables. Always use `wc_get_order()` and `$order->update_meta_data()`.
2. **Always call `$order->save()`** after metadata changes -- but minimize calls (batch changes first). `save()` is expensive.
3. **Direct SQL on `wp_posts` for orders will break** -- use `wc_get_orders()` or `WC_Order_Query`. If you must write SQL, check `OrderUtil::custom_orders_table_usage_is_enabled()` and query the correct table.
4. **Plugin compatibility declaration is mandatory** -- WooCommerce will block HPOS activation if any active plugin has not declared compatibility.
5. **Don't disable sync prematurely** in production -- keep sync enabled until confident all flows work, then disable read sync first (via filter), then write sync after 1 week.

## Audit Your Code

Search your codebase with these regex patterns to find code that needs HPOS migration:

**Direct DB/post access:**
```
wpdb|get_post|get_post_field|get_post_status|get_post_type|get_posts|get_post_meta|update_post_meta|add_post_meta|delete_post_meta|wp_insert_post|wp_update_post|wp_delete_post|wp_trash_post|shop_order
```

**Admin screen functions:**
```
post_updated_messages|do_meta_boxes|enter_title_here|edit_form_before_permalink|manage_shop_order_posts_columns|manage_shop_order_posts_custom_column
```

Most matches will be false positives. Check each for order-related usage.

## Programmatic Synchronization

```php
$synchronizer = wc_get_container()->get(
    Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
);
$order_ids = $synchronizer->get_next_batch_to_process( $batch_size );
if ( count( $order_ids ) ) {
    $synchronizer->process_batch( $order_ids );
}
```

## Disable Read Sync (production optimization)

```php
add_filter( 'woocommerce_hpos_enable_sync_on_read', '__return_false' );
```

Apply this filter 6+ hours after switching HPOS to authoritative. It reduces overhead while maintaining write sync for safety.

## References Guide

| Need | File |
|------|------|
| Full database table schemas and column definitions | `references/database-schema.md` |
| Complete CLI command reference with examples | `references/cli-tools.md` |
| Advanced order querying (meta_query, field_query, date_query) | `references/querying-apis.md` |
| Step-by-step migration guide for large stores | `references/migration-guide.md` |
| Plugin compatibility patterns and code migration recipes | `references/plugin-compatibility.md` |
