# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

---

## Project Overview

**Power Checkout** is a WooCommerce checkout integration plugin providing payment gateway (Shopline Payment), logistics (ECPay AllInOne), e-invoice (Amego), and checkout field customization. Built with Domain-Driven Design: PHP backend + Vue 3 frontend.

**Integrated Services:**
- **Shopline Payment (SLP)** — Redirect-based payment (credit card, ATM, Apple Pay, LINE Pay, JKOPay, ZingalaCard)
- **ECPay AIO** (`ecpay_aio`) — Redirect-based payment via ECPay Cashier V5; CheckMacValue SHA256; supports credit card (one-time/installment/period), ATM, WebATM, CVS, BARCODE, ApplePay
- **ECPay ECPG** (`ecpay_ecpg`) — Embedded payment (站內付 2.0); frontend JS SDK, AES-128-CBC, 3DS ThreeDURL; credit card only
- **ECPay Logistics** (`ecpay_logistics`) — ECPay AllInOne Logistics v2; convenience store (FAMI/UNIMART/HILIFE) + home delivery (HOME, with temperature); B2C (2000132) + C2C (2000933) account types; COD (IsCollection) + online payment; two-phase store selection (TempTrade → CreateByTempTrade); AES-JSON callback
- **Amego** — Taiwan e-invoice issuance/void
- **ECPay Invoice** (`ecpay`) — Taiwan e-invoice B2C/B2B via ECPay; AES-128-CBC; parallel with Amego, switchable from admin
- **ezPay Invoice** (`ezpay`) — Taiwan e-invoice B2C/B2B via NewebPay ezPay; AES-256-CBC (PKCS#7 blocksize=32 + ZERO_PADDING + hex lowercase); CheckCode SHA256; issue/void/allowance (open+void)/query; parallel with Amego & ECPay Invoice, switchable from admin
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
   - Stack: Vue 3 + Element Plus + TanStack Vue Query + Vue Router 4 (memory mode, `createMemoryHistory`)

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
│   ├── Logistics/
│   │   ├── ProviderRegister.php     # Registers logistics providers + WC shipping method + checkout meta
│   │   ├── Ecpay/                   # ECPay AllInOne Logistics v2 (ID: ecpay_logistics)
│   │   │   ├── Services/EcpayLogisticsProvider.php  # implements ILogisticsProvider
│   │   │   ├── Services/WC_EcpayLogisticsShipping.php  # extends WC_Shipping_Method (classic checkout)
│   │   │   ├── Http/LogisticsApiClient.php  # AES-128-CBC (reuses Ecpg AesCrypto); RqHeader Revision 1.0.0; 5-min Timestamp
│   │   │   ├── Http/LogisticsCallback.php   # ServerReplyURL (AES-JSON 3-layer) + ClientReplyURL (selection)
│   │   │   └── DTOs/                        # EcpayLogisticsSettingsDTO, StoreSelectionParams, CreateShipmentParams
│   │   └── Shared/
│   │       ├── Interfaces/ILogisticsProvider.php  # 10-method interface (mirrors IInvoiceService)
│   │       ├── Enums/                             # LogisticsSubType, LogisticsAccountType, LogisticsTemperature, LogisticsPaymentScenario, LogisticsStatus
│   │       ├── Helpers/LogisticsMetaKeys.php       # Order meta CRUD helper (HPOS-aware)
│   │       └── Services/LogisticsApiService.php    # REST power-checkout/v1 (5 endpoints)
│   ├── Invoice/
│   │   ├── ProviderRegister.php     # Registers invoice providers + auto-issue hooks
│   │   ├── Amego/                   # AmegoProvider (IInvoiceService), API client, DTOs
│   │   ├── Ecpay/                   # EcpayInvoiceProvider (IInvoiceService, ID: ecpay)
│   │   │   ├── Services/EcpayInvoiceProvider.php
│   │   │   ├── Http/InvoiceApiClient.php  # AES-128-CBC
│   │   │   ├── DTOs/                      # EcpayInvoiceSettingsDTO, IssueParams, CancelParams, IssueResponse
│   │   │   └── Shared/                    # AesCrypto, Enums (EApi, ETaxType, ECarrierType)
│   │   ├── Ezpay/                   # EzpayInvoiceProvider (IInvoiceService + ISupportsAllowance + ISupportsQuery, ID: ezpay)
│   │   │   ├── Services/EzpayInvoiceProvider.php
│   │   │   ├── Http/InvoiceApiClient.php  # AES-256-CBC (PKCS#7 blocksize=32 + ZERO_PADDING + hex); CheckCode SHA256; test: cinv.ezpay.com.tw / prod: inv.ezpay.com.tw
│   │   │   ├── DTOs/                      # EzpaySettingsDTO, IssueParams, CancelParams, IssueResponse, AllowanceParams, AllowanceInvalidParams, AllowanceResponse, QueryParams, QueryResponse
│   │   │   └── Shared/                    # Helpers (AesCrypto, CheckCodeService, UrlEncoder, PiiMasker), Enums (EApi, ETaxType, ECarrierType, ECategory)
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

- Active test suite: `tests/Integration/` — namespace `Tests\Integration\`, base class `Tests\Integration\TestCase extends WP_UnitTestCase`, bootstrap `tests/bootstrap.php`
- Config: `phpunit.xml.dist` — **group whitelist**: only `smoke` / `happy` / `error` / `edge` / `security` groups are collected; tests must be annotated with at least one group to run
- Additional test categories used: `integration`, `invoice`, `<provider>` (e.g. `ezpay`)
- API mode: controlled by `API_MODE` env var (`mock` / `sandbox` / `prod`)
- Pure-logic offline verification (no WP bootstrap): `tests/offline/ezpay-pure-harness.php` — used when LocalWP DB constraints prevent `WP_UnitTestCase` from running locally
- Legacy directory `inc/tests/` exists but is **not** referenced by `phpunit.xml.dist`; treat as inactive
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
| `power-checkout/v1` | POST | `/logistics/{order_id}/store-selection` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/create-shipment` | Nonce |
| `power-checkout/v1` | GET | `/logistics/{order_id}` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/print` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/cancel` | Nonce |
| `power-checkout/v1` | POST | `/logistics/{order_id}/return` | Nonce |
| `power-checkout/slp` | POST | `/webhook` | HMAC-SHA256 |
| `power-checkout/ecpay` | POST | `/aio/return` | CheckMacValue SHA256 |
| `power-checkout/ecpay` | POST | `/aio/payment-info` | CheckMacValue SHA256 |
| `power-checkout/ecpay` | POST | `/ecpg/return` | AES-128-CBC (TransCode + RtnCode) |
| `power-checkout/ecpay` | POST | `/ecpg/create-payment` | order_key (in body) |
| `power-checkout/ecpay` | POST | `/logistics/status-callback` | MerchantID verified inside |
| `power-checkout/ecpay` | POST | `/logistics/selection-callback` | open (ClientReplyURL) |

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

## ECPay Logistics Flow (ecpay_logistics)

### Three-phase store selection (convenience store)

1. Frontend calls `POST /logistics/{order_id}/store-selection` with `sub_type` + `payment_scenario` → `get_store_selection()` builds `RedirectToLogisticsSelection` (AES-encrypted), returns `redirect_target` HTML → browser renders RWD store picker
2. Customer selects store → ECPay POSTs `ResultData` to `/wp-json/power-checkout/ecpay/logistics/selection-callback` (ClientReplyURL) → `parse_store_selection()` decodes `ResultData`, writes `_pc_logistics_temp_id` + store meta to order
3. Admin calls `POST /logistics/{order_id}/create-shipment` → `create_shipment()` calls `CreateByTempTrade` using `TempLogisticsID` → writes `_pc_logistics_ref` (LogisticsID), returns `logistics_id`

### Home delivery (HOME)

Same store-selection step is skipped; `create_shipment()` directly calls `CreateByTempTrade` with address data.

### Status callback (ServerReplyURL — AES-JSON 3-layer)

ECPay POSTs JSON to `/wp-json/power-checkout/ecpay/logistics/status-callback`:
- Response **must** be HTTP 200 + AES-JSON `{ MerchantID, RqHeader{ Timestamp, Revision:"1.0.0" }, TransCode, Data }` where `Data = AES({"RtnCode":1|0,"RtnMsg":...})` — returning plain `1|OK` causes ECPay to retry every 60 min
- Safety: verify MerchantID → lookup order by `_pc_logistics_ref` → idempotency check via `_pc_logistics_processed_status`
- COD: `LogisticsStatus` pickup-complete sets `_pc_logistics_collection_paid = yes`
- Any `\Throwable` is caught; still returns AES-JSON with `RtnCode=0`

### Account types

| Account Type | MerchantID | Supported sub-types |
|---|---|---|
| B2C | 2000132 | FAMI, UNIMART, HILIFE, HOME |
| C2C | 2000933 | FAMI, UNIMART, HILIFE |

C2C only: `cancel_shipment()` (C2C cancel), `_pc_logistics_cvs_payment_no` / `_pc_logistics_cvs_validation_no`.

### Returns / reverse logistics (P2-B — `create_return`)

`ILogisticsProvider::create_return()` builds a reverse-logistics order from an already-shipped order. Preconditions: provider enabled → order exists → has `_pc_logistics_ref` (forward shipment created) → `server_reply_url` is public. Dispatches by the original `_pc_logistics_sub_type`:

| Original sub-type | Return endpoint | Key Data fields |
|---|---|---|
| FAMI | `/Express/v2/ReturnCVS` | `ServiceType="4"`, `SenderName`, `[SenderPhone]` |
| UNIMART | `/Express/v2/ReturnUniMartCVS` | `ServiceType="4"`, `SenderName`, `[SenderPhone]` |
| HILIFE | `/Express/v2/ReturnHilifeCVS` | `ServiceType="4"`, `SenderName`, `[SenderPhone]` |
| HOME | `/Express/v2/ReturnHome` | `Temperature`, `Distance`, `Specification` |

All carry `LogisticsID` (original), `GoodsAmount`, `ServerReplyURL` (→ status-callback). On success writes `_pc_logistics_return_ref` (ReturnLogisticsID) + order note. Reverse-logistics status notifications reuse the existing AES-JSON status-callback; `get_order_by_ref()` looks up by both `_pc_logistics_ref` and `_pc_logistics_return_ref`. REST: `POST /logistics/{id}/return`.

PAYUNi logistics and block checkout are deferred.

---

## ezPay Invoice Flow (ezpay)

1. `issue()` → `InvoiceApiClient::issue()` → POST `invoice_issue` v1.5 (AES-256-CBC encrypted + CheckCode verified) → `Status=1` means immediate issuance → writes `pc_issued_data` (includes `invoice_trans_no` + `random_num`)
2. `cancel()` → POST `invoice_invalid` v1.0 → writes `pc_cancelled_data`
3. **Allowance (open)**: triggered by WC refund hook → `allowance()` → POST `allowance_issue` v1.3 → writes `allowance_data` (includes `allowance_no`) to `pc_issued_data`
4. **Allowance (void)**: `allowance_invalid()` → POST `allowanceInvalid` v1.0
5. **Query**: `query()` → POST `invoice_search` v1.3 → `UploadStatus` indicates upload to Ministry of Finance
6. Encryption differs from ECPay: **AES-256-CBC** with PKCS#7 blocksize=32 + `OPENSSL_ZERO_PADDING` + `bin2hex` lowercase; **not** interchangeable with ECPay's AES-128-CBC + base64
7. CheckCode: SHA256 of 5 fields ksort + HashIV/HashKey wrap → uppercase → `hash_equals` comparison

---

## Order Meta Keys

| Key | Purpose |
|---|---|
| `pc_payment_identity` | tradeOrderId (idempotency guard) — SLP |
| `pc_payment_detail` | Payment details (admin display) — SLP |
| `pc_refund_detail` | Refund details — SLP |
| `pc_issued_data` | Invoice issuance response (ezPay: includes `invoice_trans_no` + `random_num`; allowance_data includes `allowance_no`) |
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
| `_pc_logistics_provider_id` | Which logistics provider was used (e.g. `ecpay_logistics`) |
| `_pc_logistics_sub_type` | Logistics sub-type chosen at checkout (FAMI/UNIMART/HILIFE/HOME) |
| `_pc_logistics_payment_scenario` | Payment scenario at checkout (`online` / `cod`) |
| `_pc_logistics_temp_id` | TempLogisticsID from store selection callback (required for CreateByTempTrade) |
| `_pc_logistics_ref` | Unified logistics ID (ECPay LogisticsID); primary key for callback order lookup |
| `_pc_logistics_store_id` | Selected CVS store code |
| `_pc_logistics_store_name` | Selected CVS store name |
| `_pc_logistics_store_addr` | Selected CVS store address |
| `_pc_logistics_status` | Logistics status (raw ECPay LogisticsStatus string) |
| `_pc_logistics_cvs_payment_no` | C2C CVSPaymentNo (required for cancel shipment) |
| `_pc_logistics_cvs_validation_no` | C2C CVSValidationNo (required for cancel shipment) |
| `_pc_logistics_collection_paid` | COD collection completion flag (`yes`) |
| `_pc_logistics_processed_status` | Idempotency guard array — elements: `"{LogisticsID}:{LogisticsStatus}"` |
| `_pc_logistics_return_ref` | Reverse-logistics (return) ID (ECPay ReturnLogisticsID); written by `create_return`; also indexed by `get_order_by_ref` for reverse-logistics status callbacks |

---

## Key WordPress Hooks

| Hook | Purpose |
|---|---|
| `woocommerce_payment_gateways` | Inject SLP / ECPay AIO / ECPay ECPG gateways |
| `before_woocommerce_init` | Declare HPOS + Blocks compatibility |
| `wc_payment_gateways_initialized` | Populate ProviderUtils::$container |
| `woocommerce_order_status_{status}` | Auto issue/void invoices |
| `woocommerce_checkout_fields` | Classic checkout invoice fields |
| `woocommerce_shipping_methods` | Register WC_EcpayLogisticsShipping (classic checkout shipping method) |
| `woocommerce_checkout_create_order` | Write logistics sub_type + payment_scenario meta from checkout |
| `rest_api_init` | Register logistics status-callback + selection-callback endpoints |
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
