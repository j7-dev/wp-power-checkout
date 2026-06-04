# HPOS Order Querying APIs Reference

> Source: [HPOS order querying APIs](https://developer.woocommerce.com/docs/features/high-performance-order-storage/wc-order-query-improvements/)

## Table of Contents

- [Overview](#overview)
- [meta_query](#meta_query)
- [field_query](#field_query)
- [date_query](#date_query)
- [Advanced Combined Examples](#advanced-combined-examples)
- [Existing wc_get_orders Parameters](#existing-wc_get_orders-parameters)

## Overview

HPOS enhances `wc_get_orders()` with three new query argument types: `meta_query`, `field_query`, and `date_query`. These follow the same syntax as WordPress `WP_Query` equivalents and can be combined with each other and with standard `wc_get_orders()` arguments.

All new query types support:
- `compare` operators: `=`, `!=`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, `RLIKE`, `IN`, `NOT IN`, `BETWEEN`, `NOT BETWEEN`, `EXISTS`, `NOT EXISTS`
- `relation` clause: `AND` (default) or `OR` for combining multiple conditions
- Nesting: arrays can be nested for complex boolean logic

## meta_query

Query orders by custom metadata stored in `wp_wc_orders_meta`.

**Clause structure:**

| Key | Required | Description |
|-----|----------|-------------|
| `key` | Yes | The metadata key name |
| `value` | No | The metadata value to compare (not needed for `EXISTS`/`NOT EXISTS`) |
| `compare` | No | Comparison operator (default: `=`) |
| `type` | No | SQL type to cast value to: `NUMERIC`, `DECIMAL`, `SIGNED`, `UNSIGNED`, `CHAR`, `DATE`, `DATETIME`, `TIME` |
| `relation` | No | How to combine sibling arrays: `AND` or `OR` |

**Example: Orders with specific metadata**

```php
// Orders that have 'color' metadata (any value) AND 'size' metadata containing 'small'
$orders = wc_get_orders(
    array(
        'meta_query' => array(
            array(
                'key' => 'color',
            ),
            array(
                'key'     => 'size',
                'value'   => 'small',
                'compare' => 'LIKE'
            ),
        ),
    )
);
```

**Example: OR relationship**

```php
// Orders with EITHER 'vip' metadata OR 'priority' = 'high'
$orders = wc_get_orders(
    array(
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key'     => 'vip',
                'compare' => 'EXISTS',
            ),
            array(
                'key'   => 'priority',
                'value' => 'high',
            ),
        ),
    )
);
```

**Syntax reference**: Identical to [WordPress WP_Query meta_query](https://developer.wordpress.org/reference/classes/wp_query/#custom-field-post-meta-parameters).

## field_query

Query orders by their built-in fields/properties. Similar syntax to `meta_query` but uses `field` instead of `key`.

**Clause structure:**

| Key | Required | Description |
|-----|----------|-------------|
| `field` | Yes | Order property name (e.g., `billing_first_name`, `total`, `order_key`, `shipping_total`, `discount_total`) |
| `value` | Yes | The value to compare |
| `compare` | No | Comparison operator (default: `=`) |
| `type` | No | SQL type to cast value to |
| `relation` | No | How to combine sibling arrays: `AND` or `OR` |

**Available fields**: Any order property accessible via `WC_Order` getters, including `billing_first_name`, `billing_last_name`, `billing_email`, `shipping_first_name`, `total`, `shipping_total`, `discount_total`, `order_key`, `payment_method`, `customer_id`, etc.

**Example: Simple field query vs direct property**

```php
// These two are equivalent for simple equality queries:
$orders = wc_get_orders( array(
    'billing_first_name' => 'Lauren',
    'order_key'          => 'my_order_key',
) );

$orders = wc_get_orders( array(
    'field_query' => array(
        array(
            'field' => 'billing_first_name',
            'value' => 'Lauren'
        ),
        array(
            'field' => 'order_key',
            'value' => 'my_order_key',
        )
    )
) );
```

**Example: Complex comparison (not possible without field_query)**

```php
// Orders where total OR shipping_total is less than 5.0
$orders = wc_get_orders( array(
    'field_query' => array(
        'relation' => 'OR',
        array(
            'field'   => 'total',
            'value'   => '5.0',
            'compare' => '<',
        ),
        array(
            'field'   => 'shipping_total',
            'value'   => '5.0',
            'compare' => '<',
        ),
    )
) );
```

**Example: LIKE comparison on fields**

```php
// Orders where billing first name contains "laur" (matches "lauren", "laura", etc.)
$orders = wc_get_orders( array(
    'field_query' => array(
        array(
            'field'   => 'billing_first_name',
            'value'   => 'laur',
            'compare' => 'LIKE',
        ),
    )
) );
```

## date_query

Query orders by their associated date fields. Fully compatible with WordPress `WP_Query` `date_query` syntax.

**Queryable date columns:**
- `date_created_gmt`
- `date_updated_gmt`
- `date_completed_gmt`
- `date_paid_gmt`

**Clause structure:**

| Key | Required | Description |
|-----|----------|-------------|
| `column` | No | Date field to query (default: `date_created_gmt`) |
| `after` | No | Date string or relative date (e.g., `'1 month ago'`, `'2024-01-01'`) |
| `before` | No | Date string or relative date |
| `year` | No | Year to match |
| `month` | No | Month to match (1-12) |
| `day` | No | Day to match (1-31) |
| `hour` | No | Hour to match (0-23) |
| `minute` | No | Minute to match (0-59) |
| `second` | No | Second to match (0-59) |
| `compare` | No | Comparison operator |
| `relation` | No | `AND` or `OR` |

**Example: Orders paid recently and created before noon**

```php
// Orders paid in the last month that were created before noon (on any date)
$orders = wc_get_orders( array(
    'date_query' => array(
        'relation' => 'AND',
        array(
            'column'  => 'date_created_gmt',
            'hour'    => 12,
            'compare' => '<'
        ),
        array(
            'column' => 'date_paid_gmt',
            'after'  => '1 month ago',
        ),
    ),
) );
```

**Syntax reference**: Identical to [WordPress WP_Query date_query](https://developer.wordpress.org/reference/classes/wp_query/#date-parameters).

## Advanced Combined Examples

All three query types can be combined in a single `wc_get_orders()` call for sophisticated filtering.

**Example: Status + metadata + nested OR**

```php
// Pending or on-hold orders with weight >= 50 and either 'color' or 'size' metadata set
$query_args = array(
    'status' => array( 'pending', 'on-hold' ),
    'meta_query' => array(
        array(
            'key'     => 'weight',
            'value'   => '50',
            'compare' => '>=',
        ),
        array(
            'relation' => 'OR',
            array(
                'key'     => 'color',
                'compare' => 'EXISTS',
            ),
            array(
                'key'     => 'size',
                'compare' => 'EXISTS',
            )
        ),
    )
);
$orders = wc_get_orders( $query_args );
```

**Example: field_query with LIKE + nested AND**

```php
// Orders where billing first name contains "laur" AND (total < 10.0 AND discount_total >= 5.0)
$orders = wc_get_orders( array(
    'field_query' => array(
        array(
            'field'   => 'billing_first_name',
            'value'   => 'laur',
            'compare' => 'LIKE',
        ),
        array(
            'relation' => 'AND',
            array(
                'field'   => 'total',
                'value'   => '10.0',
                'compare' => '<',
                'type'    => 'NUMERIC',
            ),
            array(
                'field'   => 'discount_total',
                'value'   => '5.0',
                'compare' => '>=',
                'type'    => 'NUMERIC',
            )
        )
    ),
) );
```

**Example: Combining all three query types**

```php
// Processing orders from the last week, with 'subscription' metadata,
// where total > 100
$orders = wc_get_orders( array(
    'status' => 'processing',
    'date_query' => array(
        array(
            'column' => 'date_created_gmt',
            'after'  => '1 week ago',
        ),
    ),
    'meta_query' => array(
        array(
            'key'     => 'subscription',
            'compare' => 'EXISTS',
        ),
    ),
    'field_query' => array(
        array(
            'field'   => 'total',
            'value'   => '100',
            'compare' => '>',
            'type'    => 'NUMERIC',
        ),
    ),
) );
```

## Existing wc_get_orders Parameters

These standard parameters continue to work with HPOS and can be combined with the new query types:

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string/array | Order status(es) |
| `limit` | int | Max orders to return (default: 10) |
| `offset` | int | Number of orders to skip |
| `orderby` | string | Sort field: `date`, `id`, `title`, `modified`, etc. |
| `order` | string | Sort direction: `ASC` or `DESC` |
| `customer` | int/string | Customer ID or email |
| `customer_id` | int | Customer user ID |
| `billing_email` | string | Billing email exact match |
| `payment_method` | string | Payment gateway ID |
| `date_created` | string | Date range (e.g., `2024-01-01...2024-12-31`) |
| `date_modified` | string | Date range |
| `date_completed` | string | Date range |
| `date_paid` | string | Date range |
| `return` | string | `objects` (default) or `ids` |
| `paginate` | bool | Return paginated result with total counts |

See also: [wc_get_orders() documentation](https://developer.woocommerce.com/docs/extensions/core-concepts/wc-get-orders/)
