# HPOS Migration Guide for Large Stores

> Source: [A large store's guide to enable HPOS on WooCommerce](https://developer.woocommerce.com/docs/features/high-performance-order-storage/guide-large-store/)

## Table of Contents

- [Overview](#overview)
- [Phase 1: Local Development Testing](#phase-1-local-development-testing)
- [Phase 2: Staging Environment](#phase-2-staging-environment)
- [Phase 3: Production Deployment](#phase-3-production-deployment)
- [Quick Reference: Enable HPOS on Smaller Stores](#quick-reference-enable-hpos-on-smaller-stores)

## Overview

This guide covers the 3-phase migration approach for high-volume WooCommerce stores moving from posts-based storage to HPOS. For stores with fewer than ~100k orders, the simpler "Enable HPOS" path may suffice (see bottom of this document).

**Key principle**: The migration is a "hot" process -- no downtime required. However, plan to be online monitoring after the switch.

## Phase 1: Local Development Testing

**Goal**: Validate HPOS functionality in a controlled environment mirroring production.

### Environment Setup

- Install ALL production plugins at their latest versions
- Enable all custom code
- Replicate production architecture as closely as possible

### Mandatory Test Cases

Run ALL of the following tests:

1. Complete checkout with **every available payment method**
2. Full refund workflows for **each payment scenario**
3. WooCommerce Subscriptions flows (if applicable):
   - New subscription purchase
   - Subscription renewal
4. All other business-critical flows

### Synchronization Testing

Run tests **twice**:
1. With compatibility mode (sync) **enabled**: WooCommerce > Settings > Advanced > Features > "Enable compatibility mode" checked
2. With compatibility mode **disabled**: same setting unchecked

### Phase 1 Checklist

- [ ] All plugin versions confirmed current
- [ ] Checkout tested across all payment methods
- [ ] Refunds processed and verified
- [ ] Subscription workflows validated (if applicable)
- [ ] All critical flows tested with sync ON
- [ ] All critical flows tested with sync OFF

## Phase 2: Staging Environment

**Goal**: Validate migration with real production data and benchmark timing.

### Database Preparation

Copy production database to staging environment for realistic testing.

### Migration Execution

Do NOT rely on automatic Action Scheduler sync for large stores. Use the CLI command:

```bash
wp wc hpos sync
```

**Track execution time** to predict production duration. Benchmark example: ~1 week for 9 million orders.

### Data Integrity Verification

After migration completes:

```bash
wp wc hpos verify_data --verbose
```

This compares all order data between posts and HPOS tables, reporting any mismatches.

### Testing Protocol

1. Run all Phase 1 tests with sync **enabled**
2. Disable sync
3. Run all Phase 1 tests with sync **disabled**

### Third-Party System Audit

Identify external systems that access the WordPress database directly:
- Data warehouses
- Shipping platforms
- Accounting software
- Custom reporting tools
- Any integration reading from `wp_posts` / `wp_postmeta` where `post_type = 'shop_order'`

These systems need to be updated to read from the new tables or use the WooCommerce REST API.

### Phase 2 Checklist

- [ ] Staging plugins updated to latest versions
- [ ] Production database copied to staging
- [ ] CLI migration completed (`wp wc hpos sync`)
- [ ] Migration time documented
- [ ] Data integrity verified (`wp wc hpos verify_data --verbose`)
- [ ] Phase 1 flows tested with sync ON
- [ ] Phase 1 flows tested with sync OFF
- [ ] Third-party systems audited for direct DB access

## Phase 3: Production Deployment

### Step 1: Enable Sync with Posts as Authoritative

Navigate to WooCommerce > Settings > Advanced > Features:
- Select "Use the WordPress posts tables" as order data storage
- Check "Enable compatibility mode"

**Effect**: New orders start populating HPOS tables while posts remain authoritative. Safe rollback point.

### Step 2: Migrate and Verify

Run the CLI migration:

```bash
wp wc hpos sync
```

**Safe to interrupt**: Turning off sync or interrupting the CLI job is safe. Resume migration after resolving any issues.

After completion, verify:

```bash
wp wc hpos verify_data --verbose
```

**Do not disable sync yet** -- keep it running for the next steps.

**Performance note**: No noticeable negative performance impact when keeping sync enabled with posts authoritative during high-volume migrations.

### Step 3: Switch HPOS to Authoritative

Navigate to WooCommerce > Settings > Advanced > Features:
- Select "Use the WooCommerce orders tables"
- Keep "Enable compatibility mode" checked (for rollback capability)

**Testing after switch:**
- Execute all critical flow tests
- Test checkout with multiple payment methods
- Verify order data populates correctly
- Monitor support channels

**Timing recommendation**: If your store has a natural low-volume period, make the switch then. Plan to be online monitoring for several hours after.

**No downtime required** -- this is a hot migration.

### Step 4: Disable Read Sync (6+ hours after Step 3)

Add this filter to your theme's `functions.php` or a custom plugin:

```php
add_filter( 'woocommerce_hpos_enable_sync_on_read', '__return_false' );
```

**Why**: Read sync consumes more resources than write sync. Disabling it first provides performance improvement while maintaining data safety through write sync.

In the documented large-store migration, this was done 6 hours after switching HPOS to authoritative.

### Step 5: Disable Write Sync (~1 week after Step 3)

**Prerequisite**: Read sync must already be disabled (Step 4 done).

Navigate to WooCommerce > Settings > Advanced > Features:
- Uncheck "Enable compatibility mode"

In the documented migration, full sync disabling occurred 1 week after HPOS became authoritative.

**Post-disabling:**
- Test critical flows
- Monitor natural order processing
- Maintain support channel oversight

### Ongoing Backup Strategy

Even after disabling sync, you can still fall back to posts:

```bash
# Run periodic manual sync as a safety net
wp wc hpos sync
```

This maintains the posts table as a recent backup. If anything goes wrong, you can switch back to posts storage (though a backfill job will run first).

### Phase 3 Checklist

- [ ] Plan extended online monitoring period
- [ ] Enable sync with posts authoritative (Settings)
- [ ] Run `wp wc hpos sync`
- [ ] Monitor for migration errors; stop and resume as needed
- [ ] Verify data: `wp wc hpos verify_data --verbose`
- [ ] Switch HPOS to authoritative (Settings)
- [ ] Test all critical flows, multi-method checkout, order data
- [ ] Monitor support channels
- [ ] Disable read sync (PHP filter) -- ~6 hours after switch
- [ ] Track site performance metrics
- [ ] Disable write sync (Settings) -- ~1 week after switch
- [ ] Continue monitoring performance and order processing

## Quick Reference: Enable HPOS on Smaller Stores

> Source: [How to enable HPOS](https://developer.woocommerce.com/docs/features/high-performance-order-storage/enable-hpos/)

For stores with moderate order volumes where the full 3-phase approach is unnecessary:

1. **Navigate to** WooCommerce > Settings > Advanced > Features
2. **Enable compatibility mode** first (check "Enable compatibility mode")
3. **Wait for background sync** to complete:
   - Sync runs via scheduled actions in batches of 25 orders
   - Two actions: `wc_schedule_pending_batch_process` (identifies orders) and `wc_run_batch_process` (syncs)
   - Monitor progress at WooCommerce > Status > Scheduled Actions
4. **Switch to HPOS** once sync is complete (select "High-Performance Order Storage")
5. **Keep compatibility mode on** temporarily for rollback safety
6. **Disable compatibility mode** after confirming everything works

**For new installations (WooCommerce 8.2+)**: HPOS is enabled by default. No migration needed.
