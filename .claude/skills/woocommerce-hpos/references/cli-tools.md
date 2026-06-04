# HPOS CLI Tools Reference

> Source: [HPOS CLI Tools](https://developer.woocommerce.com/docs/features/high-performance-order-storage/cli-tools/)

## Table of Contents

- [Overview](#overview)
- [wp wc hpos status](#wp-wc-hpos-status)
- [wp wc hpos enable](#wp-wc-hpos-enable)
- [wp wc hpos disable](#wp-wc-hpos-disable)
- [wp wc hpos count_unmigrated](#wp-wc-hpos-count_unmigrated)
- [wp wc hpos sync](#wp-wc-hpos-sync)
- [wp wc hpos verify_data](#wp-wc-hpos-verify_data)
- [wp wc hpos diff](#wp-wc-hpos-diff)
- [wp wc hpos backfill](#wp-wc-hpos-backfill)
- [wp wc hpos cleanup](#wp-wc-hpos-cleanup)

## Overview

All HPOS CLI commands live under the `wp wc hpos` namespace. They require WP-CLI and a functional WooCommerce installation. Use `--help` on any command for built-in documentation.

## wp wc hpos status

Get an overview of all HPOS matters on the site.

```bash
wp wc hpos status
```

**Output includes:**
- Whether HPOS is enabled
- Whether compatibility mode (sync) is enabled
- Number of unsynced orders
- Number of orders eligible for cleanup

## wp wc hpos enable

Enable HPOS (and optionally compatibility mode).

```bash
# Enable HPOS only
wp wc hpos enable

# Enable HPOS with compatibility mode (recommended for initial migration)
wp wc hpos enable --with-sync
```

Pre-enable checks are performed before activation. If checks fail, the command reports why.

## wp wc hpos disable

Disable HPOS and revert to posts-based storage.

```bash
wp wc hpos disable
```

**Prerequisite**: All orders must be synchronized. If orders await sync, the command will fail with an error. Run `wp wc hpos sync` first.

## wp wc hpos count_unmigrated

Display count of orders pending synchronization.

```bash
wp wc hpos count_unmigrated
```

Returns a simple count of orders that need to be synced between datastores.

## wp wc hpos sync

Performantly sync orders from the currently active order storage to the other datastore. This is the primary migration command.

```bash
wp wc hpos sync
```

**Behavior:**
- Migrates orders based on current WooCommerce settings (direction depends on which datastore is authoritative)
- Shows a progress bar with completion timing
- Can be safely interrupted and resumed
- Much faster than relying on background Action Scheduler sync

**Performance benchmark**: For a store with ~9 million orders, migration took approximately 1 week on staging.

For selective per-order operations, use `wp wc hpos backfill` instead.

## wp wc hpos verify_data

Verify data consistency between legacy and HPOS datastores. Requires compatibility mode to be active.

```bash
# Basic verification
wp wc hpos verify_data

# Verbose output showing details of mismatches
wp wc hpos verify_data --verbose

# Automatically re-sync orders with differences
wp wc hpos verify_data --re-migrate
```

**Output examples:**

Successful verification:
```
All orders are identical.
```

Verification with failures:
```
Order 12345: status mismatch (posts: wc-processing, hpos: wc-completed)
Order 12346: total mismatch (posts: 99.00, hpos: 100.00)
```

The `--re-migrate` flag will automatically sync any orders that have differences, fixing mismatches in place.

**Note**: The older CLI alias `wp wc cot verify_cot_data` may still work but `wp wc hpos verify_data` is the current command.

## wp wc hpos diff

Display differences between datastores for a specific order in a user-friendly table format.

```bash
# Default list output
wp wc hpos diff 12345

# JSON output
wp wc hpos diff 12345 --format=json

# CSV output
wp wc hpos diff 12345 --format=csv
```

**Output when no differences:**
```
No differences found for order 12345.
```

**Output with differences (table format):**
```
+------------------+----------------+----------------+
| Property         | Posts Value    | HPOS Value     |
+------------------+----------------+----------------+
| status           | wc-processing | wc-completed   |
| total            | 99.00         | 100.00         |
| date_modified    | 2024-01-15    | 2024-01-16     |
+------------------+----------------+----------------+
```

Supports output formats: `list` (default), `json`, `csv`.

## wp wc hpos backfill

Copy whole orders or specific bits of order data from one datastore to another. More granular than `sync`.

```bash
wp wc hpos backfill <order_id> --from=<datastore> --to=<datastore> [--meta_keys=<keys>] [--props=<props>]
```

**Parameters:**

| Parameter | Required | Values | Description |
|-----------|----------|--------|-------------|
| `<order_id>` | Yes | integer | The order ID to backfill |
| `--from` | Yes | `posts` or `hpos` | Source datastore |
| `--to` | Yes | `posts` or `hpos` | Destination datastore |
| `--meta_keys` | No | comma-separated | Specific metadata keys to copy |
| `--props` | No | comma-separated | Specific order properties to copy |

**Examples:**

```bash
# Full order migration from posts to HPOS
wp wc hpos backfill 12345 --from=posts --to=hpos

# Copy only specific metadata
wp wc hpos backfill 12345 --from=hpos --to=posts --meta_keys=_stripe_customer_id,_custom_field

# Copy only specific properties
wp wc hpos backfill 12345 --from=posts --to=hpos --props=status,total

# Multi-step reconciliation: copy metadata one way, properties the other
wp wc hpos backfill 12345 --from=hpos --to=posts --meta_keys=_tracking_number
wp wc hpos backfill 12345 --from=posts --to=hpos --props=status
```

## wp wc hpos cleanup

Remove order data from legacy (posts/postmeta) tables after migration. Only works when HPOS is enabled and compatibility mode is disabled.

```bash
# Clean up a single order
wp wc hpos cleanup 12345

# Clean up a range of orders
wp wc hpos cleanup 90000-100000

# Clean up all legacy order data
wp wc hpos cleanup all

# Force cleanup (skip verification that post version isn't newer)
wp wc hpos cleanup all --force
```

**Safety checks:**
- Verifies that the post version of an order is not newer than HPOS version before deleting
- Use `--force` to skip verification (use with caution)

**Important notes:**
- This command does NOT remove placeholder records (posts with type `shop_order_placehold`)
- It DOES remove associated metadata from `wp_postmeta`
- Only available when HPOS is the authoritative datastore and sync is off
