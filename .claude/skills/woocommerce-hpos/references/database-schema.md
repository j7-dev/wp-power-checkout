# HPOS Database Schema Reference

> Source: [HPOS Database Schema Deep Dive](https://developer.woocommerce.com/2022/09/15/high-performance-order-storage-database-schema/)

## Table of Contents

- [Overview](#overview)
- [wp_wc_orders](#wp_wc_orders)
- [wp_wc_order_addresses](#wp_wc_order_addresses)
- [wp_wc_order_operational_data](#wp_wc_order_operational_data)
- [wp_wc_orders_meta](#wp_wc_orders_meta)
- [Tables NOT Migrated](#tables-not-migrated)
- [Key Design Decisions](#key-design-decisions)

## Overview

HPOS replaces order storage in `wp_posts` + `wp_postmeta` with four dedicated tables. This reduces order creation from ~40 INSERTs to at most 5, and eliminates expensive JOINs against the shared posts/postmeta tables.

The `get_all_table_names` method in the `OrdersTableDataStore` class returns all table names:
- [OrdersTableDataStore source](https://github.com/woocommerce/woocommerce/blob/trunk/plugins/woocommerce/src/Internal/DataStores/Orders/OrdersTableDataStore.php)

## wp_wc_orders

Primary order table. Replaces `wp_posts` rows with `post_type = 'shop_order'`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT unsigned, PK | Unique order ID. Matches `post.ID` for backward compat. |
| `status` | VARCHAR(20) | Order status: `wc-processing`, `wc-completed`, `wc-on-hold`, `wc-pending`, `wc-cancelled`, `wc-refunded`, `wc-failed` |
| `currency` | VARCHAR(10) | ISO currency code: `USD`, `EUR`, `GBP`, etc. |
| `type` | VARCHAR(20) | `shop_order` or `shop_order_refund` |
| `total_amount` | DECIMAL(26,8) | Order total |
| `tax_amount` | DECIMAL(26,8) | Total tax amount |
| `customer_id` | BIGINT unsigned | WP user ID. `0` for guest checkout. |
| `billing_email` | VARCHAR(320) | Customer's billing email |
| `date_created_gmt` | DATETIME | Order creation timestamp (GMT) |
| `date_updated_gmt` | DATETIME | Last update timestamp (GMT) |
| `parent_order_id` | BIGINT unsigned | Parent order ID (for refunds) |
| `payment_method` | VARCHAR(100) | Payment gateway ID |
| `payment_method_title` | TEXT | Human-readable payment method name |
| `transaction_id` | VARCHAR(100) | Payment gateway transaction ID |
| `ip_address` | VARCHAR(100) | Customer IP at checkout |
| `user_agent` | TEXT | Customer browser user agent |
| `customer_note` | TEXT | Note provided by customer at checkout |

**Indexes:**
- `status`
- `date_created_gmt`
- `customer_id` + `billing_email`
- `type` + `status`
- `parent_order_id`

## wp_wc_order_addresses

Stores billing and shipping addresses. Each order has up to 2 rows (one per address type).

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT unsigned, PK, AUTO_INCREMENT | Record ID |
| `order_id` | BIGINT unsigned | References `wp_wc_orders.id` |
| `address_type` | VARCHAR(20) | `billing` or `shipping` |
| `first_name` | TEXT | First name |
| `last_name` | TEXT | Last name |
| `company` | TEXT | Company name |
| `address_1` | TEXT | Address line 1 |
| `address_2` | TEXT | Address line 2 |
| `city` | TEXT | City |
| `state` | TEXT | State/province code |
| `postcode` | TEXT | ZIP/postal code |
| `country` | TEXT | ISO country code |
| `email` | VARCHAR(320) | Email (billing only typically) |
| `phone` | VARCHAR(100) | Phone number |

**Indexes:**
- `order_id`
- UNIQUE: `address_type` + `order_id`
- `email`
- `phone`

## wp_wc_order_operational_data

Internal state and operational flags. One row per order. Separated from the main table because these fields may change implementation in the future.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT unsigned, PK, AUTO_INCREMENT | Record ID |
| `order_id` | BIGINT unsigned, UNIQUE | References `wp_wc_orders.id` |
| `created_via` | VARCHAR(100) | Order source: `admin`, `checkout`, `rest-api`, `store-api` |
| `woocommerce_version` | VARCHAR(20) | WC version active when order was created |
| `prices_include_tax` | TINYINT(1) | Whether prices included tax at time of order |
| `coupon_usages_are_counted` | TINYINT(1) | Whether coupon usage has been counted |
| `download_permission_granted` | TINYINT(1) | Whether download permissions were granted |
| `cart_hash` | VARCHAR(100) | Hash of cart contents (used to clear cart after payment) |
| `new_order_email_sent` | TINYINT(1) | Whether new order email was sent |
| `order_key` | VARCHAR(100), UNIQUE | Anonymous access key (format: `wc_order_*`) |
| `order_stock_reduced` | TINYINT(1) | Whether stock was reduced for this order |
| `date_paid_gmt` | DATETIME | When order was paid |
| `date_completed_gmt` | DATETIME | When order was completed |
| `shipping_tax_amount` | DECIMAL(26,8) | Shipping tax total |
| `shipping_total_amount` | DECIMAL(26,8) | Shipping total |
| `discount_tax_amount` | DECIMAL(26,8) | Discount tax total |
| `discount_total_amount` | DECIMAL(26,8) | Discount total |
| `recorded_sales` | TINYINT(1) | Whether sales have been recorded |

## wp_wc_orders_meta

Custom metadata storage. Functionally equivalent to `wp_postmeta` for orders.

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT unsigned, PK, AUTO_INCREMENT | Meta record ID |
| `order_id` | BIGINT unsigned | References `wp_wc_orders.id` |
| `meta_key` | VARCHAR(255) | Metadata key (e.g., `_stripe_customer_id`) |
| `meta_value` | TEXT | Metadata value (can be serialized) |

**Indexes:**
- `meta_key` + `meta_value`(100)
- `order_id` + `meta_key` + `meta_value`(100)

**Special key**: `deleted_from` -- used during deletion synchronization to track which authoritative table an order was deleted from. Processed and removed during sync.

## Tables NOT Migrated

These remain in their existing locations:

- **Order notes**: Stay in `wp_comments` table (not a performance bottleneck)
- **Order items**: Retain their dedicated `wp_woocommerce_order_items` and `wp_woocommerce_order_itemmeta` tables
- **Downloadable product permissions**: Retain `wp_woocommerce_downloadable_product_permissions`

## Key Design Decisions

1. **No `post_id` column** -- The `id` column in `wp_wc_orders` directly matches the `post.ID`, making a separate reference column redundant.
2. **`type` column added** -- Supports both `shop_order` and `shop_order_refund` in the same table (refunds were previously separate post types).
3. **Operational data separated** -- Fields like `order_stock_reduced` and `new_order_email_sent` are in a separate table because their implementation may evolve independently.
4. **DECIMAL(26,8)** for monetary values -- Provides sufficient precision for all currencies including high-denomination ones.
5. **VARCHAR(320) for email** -- Accommodates the maximum length of an email address per RFC 5321.
