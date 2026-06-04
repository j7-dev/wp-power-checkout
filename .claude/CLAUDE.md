# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Project Overview

**Power Checkout** is a WooCommerce checkout integration plugin providing payment gateway (Shopline Payment), e-invoice (Amego), and checkout field customization. Built with Domain-Driven Design: PHP backend + Vue 3 frontend.

**Integrated Services:**
- **Shopline Payment (SLP)** — Redirect-based payment (credit card, ATM, Apple Pay, LINE Pay, JKOPay, ZingalaCard)
- **ECPay AIO** (`ecpay_aio`) — Redirect-based payment via ECPay Cashier V5; CheckMacValue SHA256; supports credit card (one-time/installment/period), ATM, WebATM, CVS, BARCODE, ApplePay
- **ECPay ECPG** (`ecpay_ecpg`) — Embedded payment (站內付 2.0); frontend JS SDK, AES-128-CBC, 3DS ThreeDURL; credit card only
- **Amego** — Taiwan e-invoice issuance/void
- **ECPay Invoice** (`ecpay`) — Taiwan e-invoice B2C/B2B via ECPay; AES-128-CBC; parallel with Amego, switchable from admin
- **Checkout Fields** — Classic checkout custom fields (including invoice info fields)

---

## Build & Development Commands

```bash
# Setup
pnpm bootstrap              # pnpm install + composer install

# Frontend dev (Vue 3 main app)
pnpm dev                    # Vite dev server (port 5182)
pnpm build                  # Build to js/dist/

# Frontend dev (React WC Blocks)
pnpm dev:blocks             # Watch mode build for WC block checkout integration
pnpm build:blocks           # Build blocks to inc/assets/dist/blocks/

# Code quality
pnpm lint                   # ESLint (frontend) + PHPCBF
pnpm lint:fix               # Auto-fix frontend + PHPCBF
composer lint               # PHPCS only
vendor/bin/phpstan analyse  # PHPStan level 9

# PHP tests (requires WP test DB — see phpunit.xml for DB config)
composer test               # PHPUnit with API_MODE=mock
composer test:sandbox       # PHPUnit with API_MODE=sandbox
composer test:prod          # PHPUnit with API_MODE=prod

# Run a single test class or method
vendor/bin/phpunit --filter RedirectGatewayTest
vendor/bin/phpunit --filter "test_method_name"

# Release (requires .env with GITHUB_TOKEN)
pnpm release                # Patch release (builds both Vue + Blocks, zips, GitHub release)
pnpm release:minor          # Minor release
pnpm release:major          # Major release
pnpm zip                    # Create plugin zip only
pnpm sync:version           # Sync package.json version → plugin.php header
pnpm i18n                   # Generate .pot translation template
```

---

## Architecture

### Dual Frontend System (Critical)

This plugin has **two separate frontend build pipelines**:

1. **Vue 3 Main App** (`vite.config.ts` → `js/dist/`)
   - Settings SPA, Refund Dialog, Invoice MetaBox, EcpgPayment — **3 Vue apps + 1 TS module** mounted from one bundle
   - Entry: `js/src/index.ts` → mounts on `#power-checkout-wc-setting-app` (injected into WC settings `#mainform`)
   - `MountRefundDialog()` creates a Vue instance on order detail pages
   - `MountInvoiceApp()` creates Vue instances on order detail pages (admin MetaBox) AND checkout page (frontend invoice form)
   - `MountEcpgPayment()` from `js/src/external/EcpgPayment/` — mounts on order-received page for ECPay ECPG embedded payment (loads SDK, triggers CreatePayment with PayToken, handles 3DS redirect)
   - Stack: Vue 3 + Element Plus + TanStack Vue Query + Vue Router 4 (hash mode)

2. **React WC Blocks** (`vite.config.block.ts` → `inc/assets/dist/blocks/`)
   - WooCommerce Block Checkout payment method registration
   - Entry: each `inc/assets/blocks/*.tsx` is a separate entry point (auto-discovered via glob)
   - Uses `registerPaymentMethod()` from `@woocommerce/blocks-registry`
   - Externals: jQuery, `@woocommerce/*`, `@wordpress/*` resolved from `window.wc`/`window.wp`

### Backend Domain Structure

```
inc/classes/
├── Bootstrap.php                    # Wires all domains, checks Powerhouse compatibility
├── Domains/
│   ├── Payment/
│   │   ├── ProviderRegister.php     # Registers gateways + WC Blocks integration
│   │   ├── ShoplinePayment/         # SLP: redirect gateway, API client, webhook, status manager
│   │   ├── EcpayAIO/                # ECPay AIO redirect gateway (ID: ecpay_aio)
│   │   │   ├── Services/AioRedirectGateway.php
│   │   │   ├── Http/AioCallback.php       # ReturnURL + PaymentInfoURL callbacks
│   │   │   ├── Http/DoActionClient.php    # Credit card refund (Action=R, prod-only)
│   │   │   ├── DTOs/                      # AioSettingsDTO, RequestParams
│   │   │   ├── Managers/StatusManager.php
│   │   │   └── Shared/Helpers/            # CheckMacValueService, EcpayMetaKeys, EcpayPaymentType, TradeNo, ItemName, UrlEncoder
│   │   ├── Ecpg/                    # ECPay ECPG embedded gateway (ID: ecpay_ecpg)
│   │   │   ├── Services/EcpgGateway.php
│   │   │   ├── Http/EcpgCallback.php      # ReturnURL callback (AES JSON)
│   │   │   ├── Http/EcpgFrontendApi.php   # ecpg/create-payment (order_key auth)
│   │   │   ├── Http/EcpgApiClient.php     # GetTokenbyTrade + CreatePayment + refund
│   │   │   ├── DTOs/                      # EcpgSettingsDTO, CreatePaymentParams, GetTokenParams
│   │   │   ├── Managers/StatusManager.php
│   │   │   └── Shared/Helpers/AesCrypto.php + EcpgBlocksIntegration.php
│   │   └── Shared/                  # AbstractPaymentGateway, PaymentApiService (REST /refund)
│   ├── Invoice/
│   │   ├── ProviderRegister.php     # Registers invoice providers + auto-issue hooks
│   │   ├── Amego/                   # AmegoProvider (IInvoiceService), API client, DTOs
│   │   ├── Ecpay/                   # EcpayInvoiceProvider (IInvoiceService, ID: ecpay)
│   │   │   ├── Services/EcpayInvoiceProvider.php
│   │   │   ├── Http/InvoiceApiClient.php  # AES-128-CBC
│   │   │   ├── DTOs/                      # EcpayInvoiceSettingsDTO, IssueParams, CancelParams, IssueResponse
│   │   │   └── Shared/                    # AesCrypto, Enums (EApi, ETaxType, ECarrierType)
│   │   └── Shared/                  # IInvoiceService interface, InvoiceApiService (REST /invoices)
│   └── Settings/
│       └── Services/                # WC settings tab, REST /settings CRUD, default address format
└── Shared/
    ├── Utils/ProviderUtils.php      # Provider container + WC options CRUD (central to the system)
    ├── Utils/OrderUtils.php         # HPOS-aware order utilities
    └── DTOs/BaseSettingsDTO.php     # Base for all provider settings DTOs
```

### Provider System Lifecycle

All payment/invoice providers flow through `ProviderUtils`:
1. Listed in `ProviderRegister::$xxx_providers` static arrays
2. Enabled state stored in WC option: `woocommerce_{id}_settings` → `enabled`
3. Only enabled providers instantiated into `ProviderUtils::$container`

```php
ProviderUtils::is_enabled('amego');           // Check if active
ProviderUtils::get_provider('amego');         // Get from container
ProviderUtils::toggle('amego');               // Toggle enabled state
ProviderUtils::get_option('amego', 'key');    // Read setting
ProviderUtils::update_option('amego', [...]);  // Write settings
```

### PHP → JS Data Bridge

Three `wp_localize_script` data objects power the frontend:
- `window.power_checkout_data.env` — global env (nonce, URLs, user, order statuses)
- `window.power_checkout_order_data` — order detail page (gateway info, refund amounts)
- `window.power_checkout_invoice_metabox_app_data` — invoice MetaBox (provider list, invoice state)

Frontend access: always use `utils/env.ts`, never read `window` directly.

---

## Coding Standards

### PHP
- `declare(strict_types=1)` in every file
- `final class` by default (PHPCS enforced)
- PHP 8.1+ features: enum, readonly, named args, match expression
- PHPStan level 9 — all issues must be resolved
- Text domain: `'power_checkout'` (underscore, not hyphen)
- Hook callbacks: always static methods `[__CLASS__, 'method']`
- Exception handling: catch `\Throwable`, log via `Plugin::logger()`, never expose internals to frontend
- PSR-4: namespace `J7\PowerCheckout` → `inc/classes/`

### Vue 3 Frontend
- `<script setup lang="ts">` — Composition API only, no Options API
- `@/` alias for all imports (no relative paths)
- Element Plus only — no other UI libraries
- TanStack Vue Query defaults: `staleTime: 15min`, `retry: 0`, `refetchOnWindowFocus: false`
- `ElNotification` handled by API interceptor — don't trigger manually

### React WC Blocks
- TypeScript with JSX
- External WP/WC globals via `vite-plugin-optimizer` shimming
- Type declarations in `inc/assets/blocks/types/types.d.ts`

---

## Testing Infrastructure

- Base class: `J7\PowerCheckoutTests\Shared\WC_UnitTestCase` extends `WP_UnitTestCase`
- API mode enum: `Api::MOCK | Api::SANDBOX | Api::PROD` — controlled by `API_MODE` env var
- `@Create` PHP attribute on test classes auto-instantiates fixture helpers (Order, Product, User, Requester)
- Fixtures accessed via `$this->get_container(HelperClass::class)`
- Test DB configured in `phpunit.xml` (`WP_DB_HOST`, `WP_ABSPATH`) — points to a separate WP install
- Tests directory: `inc/tests/` with `Domains/` mirroring `inc/classes/Domains/`
- E2E tests: `tests/e2e/` (Playwright) with separate `package.json` — admin, frontend, integration suites

---

## REST API

| Namespace | Method | Endpoint | Auth |
|---|---|---|---|
| `power-checkout/v1` | GET | `/settings` | Nonce |
| `power-checkout/v1` | GET/POST | `/settings/{id}` | Nonce |
| `power-checkout/v1` | POST | `/settings/{id}/toggle` | Nonce |
| `power-checkout/v1` | POST | `/refund` | Nonce |
| `power-checkout/v1` | POST | `/refund/manual` | Nonce |
| `power-checkout/v1/invoices` | POST | `/issue/{order_id}` | Nonce |
| `power-checkout/v1/invoices` | POST | `/cancel/{order_id}` | Nonce |
| `power-checkout/slp` | POST | `/webhook` | HMAC-SHA256 |
| `power-checkout/ecpay` | POST | `/aio/return` | CheckMacValue SHA256 |
| `power-checkout/ecpay` | POST | `/aio/payment-info` | CheckMacValue SHA256 |
| `power-checkout/ecpay` | POST | `/ecpg/return` | AES-128-CBC (TransCode + RtnCode) |
| `power-checkout/ecpay` | POST | `/ecpg/create-payment` | order_key (in body) |

Nonce auth requires `X-WP-Nonce` header (`wp_create_nonce('wp_rest')`).
ECPay callbacks use `permission_callback: __return_true`; auth is verified inside callback.

---

## Shopline Payment Flow

1. `process_payment()` → `ApiClient::create_session()` → redirect to SLP hosted page
2. SLP sends webhook POST to `/wp-json/power-checkout/slp/webhook`
3. Webhook signature: `hash_hmac('sha256', "{timestamp}.{body}", $signKey)`
4. `StatusManager::update_order_status()`: SUCCEEDED→processing, EXPIRED→cancelled, others→pending
5. Refund support by payment method:

| Payment Method | Partial Refund | Full Refund |
|---|---|---|
| Credit Card | Yes | Yes |
| Apple Pay | No | Yes |
| LINE Pay | Yes | Yes |
| ZingalaCard (zero-card installment) | No | Yes |
| ATM Virtual Account | No | No |

---

## ECPay Payment Flow

### AIO Redirect (ecpay_aio)

1. `before_process_payment()` returns order-received URL (no API call at this stage)
2. `before_order_received()` assembles `RequestParams` (including CheckMacValue SHA256) and renders auto-submit form → browser POSTs to ECPay Cashier V5
3. ECPay sends server-to-server POST to `/wp-json/power-checkout/ecpay/aio/return` (ReturnURL) — CheckMacValue verified
4. ATM/CVS/BARCODE additionally receive `/aio/payment-info` with virtual account / store code / barcode
5. `StatusManager::update_order_status()` maps RtnCode: `1` → processing, others → pending
6. Refund support by payment method:

| Payment Method | API Refund |
|---|---|
| Credit Card (one-time/installment/period) | Yes — DoAction Action=R (prod-only) |
| ATM / WebATM / CVS / BARCODE / ApplePay | No — manual via ECPay admin |

### ECPG Embedded (ecpay_ecpg)

1. `before_process_payment()` → `EcpgApiClient::get_token()` (GetTokenbyTrade) → token stored in `_pc_ecpay_ecpg_token`, returns order-received URL
2. Frontend `MountEcpgPayment()` loads ECPay JS SDK, renders embedded card form (container `#ECPayPayment`), customer enters card details → SDK returns PayToken
3. Frontend POSTs PayToken to `/wp-json/power-checkout/ecpay/ecpg/create-payment` (auth: order_key)
4. Backend calls `EcpgApiClient::create_payment()` → if `ThreeDInfo.ThreeDURL` non-empty, returns `three_d_url` → frontend redirects to 3DS; otherwise waits for ReturnURL
5. ECPay sends JSON POST to `/wp-json/power-checkout/ecpay/ecpg/return` — AES-128-CBC decrypted, TransCode + RtnCode double-checked
6. Refund: credit card only → DoAction via ecpayment domain; non-credit returns `WP_Error('refund_unsupported')`

---

## Order Meta Keys

| Key | Purpose |
|---|---|
| `pc_payment_identity` | tradeOrderId (idempotency guard) — SLP |
| `pc_payment_detail` | Payment details (admin display) — SLP |
| `pc_refund_detail` | Refund details — SLP |
| `pc_issued_data` | Invoice issuance response |
| `pc_cancelled_data` | Invoice void response |
| `pc_provider_id` | Which invoice provider was used |
| `pc_issue_params` | Checkout-submitted invoice info |
| `_pc_tax_type` | Product tax type (for invoicing) |
| `_pc_ecpay_trade_no` | ECPay MerchantTradeNo (idempotency guard) — AIO + ECPG |
| `_pc_ecpay_payment_detail` | ECPay payment result detail (ReturnURL / CreatePayment response) |
| `_pc_ecpay_payment_info` | ATM/CVS/BARCODE payment info (BankCode, vAccount, PaymentNo, Barcode, ExpireDate) |
| `_pc_ecpay_ecpg_token` | ECPG GetTokenbyTrade token (stored for frontend SDK) |
| `_pc_ecpay_credit_variant` | Credit card variant: `''` / `'installment'` / `'period'` |
| `_pc_ecpay_installment` | Credit card installment count (e.g. `'6'`) |

---

## Key WordPress Hooks

| Hook | Purpose |
|---|---|
| `woocommerce_payment_gateways` | Inject SLP / ECPay AIO / ECPay ECPG gateways |
| `before_woocommerce_init` | Declare HPOS + Blocks compatibility |
| `wc_payment_gateways_initialized` | Populate ProviderUtils::$container |
| `woocommerce_order_status_{status}` | Auto issue/void invoices |
| `woocommerce_checkout_fields` | Classic checkout invoice fields |
| `admin_enqueue_scripts` | Load Vue app bundle (admin pages) |
| `wp_enqueue_scripts` | Load Vue app bundle (frontend checkout for invoice form) |

---

## HPOS Compatibility

- `OrderUtils::is_order_detail($hook)` supports both HPOS and legacy order screens
- MetaBox registered on both `shop_order` and `woocommerce_page_wc-orders`
- `custom_order_tables` compatibility declared in `before_woocommerce_init`

---

## Release Pipeline

Release (`pnpm release`) runs: build Vue → build blocks → bump version → sync version to plugin.php → composer install --no-dev → create zip → GitHub release with zip asset. Requires `.env` file with `GITHUB_TOKEN`.
