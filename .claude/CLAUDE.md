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
- **PAYUNi UPP** (`payuni_upp`) — Redirect-based payment (UNiPaypage V2.0); AES-256-GCM + `hex(base64(cipher):::base64(tag))` + SHA256 HashInfo; supports credit card (one-time/installment), ATM, CVS, icash Pay, LINE Pay, JKoPay, Apple Pay, Google Pay; single NotifyURL endpoint; credit card API refund/capture/void_auth; query trade
- **PAYUNi UNi Embed** (`payuni_uni_embed`) — Embedded payment (UNi Embed V3, iframe 站內付); AES-256-GCM (reuses PayuniCrypto); two-phase: token_get (`/api/iframe/token_get`) then merchant_trade (`/api/iframe/merchant_trade`); 3DS support; credit card only; single NotifyURL endpoint; credit card API refund/capture/void_auth; buyer token (credit_hash/credit_life) stored but never card number/CVC; parallel with payuni_upp
- **PayNow** (`paynow`) — Embedded payment (立吉富體系 1, Component SDK v2 iframe 站內付); REST PaymentIntent + HMAC-SHA256 Webhook; supports credit card (one-time/installment), ATM, convenience store (ibon/FamiPort), LINE Pay, Apple Pay (excludes ApplePayDeferred); single NotifyURL endpoint; credit card/ATM API refund; query payment intent + refund query admin actions; capture/void_auth no-op (no corresponding endpoint); offline payment two-phase (pending → processing on payment); [GAP: sandbox PublicKey/PrivateKey pending application]
- **PayNow Logistics** (`paynow_logistics`) — Convenience store pickup (7-11/FAMI/HiLife) + TCAT home delivery (立吉富體系 1); TripleDES DES-EDE3 ECB encryption (JsonOrder base64(3DES(JSON)), apicode ECB+space→plus); PassCode SHA1; status push callback (orderno+LogisticCode+Description+paymentno) with idempotency; query/print/cancel; amount limits CVS ≤20000 / home ≤100000; idempotent ReNewOrder when existing valid shipment; create_return throws 尚未實作; [GAP: sandbox user_account/apicode pending; prod key/IV to confirm with PayNow]
- **PayNow Invoice** (`paynow_invoice`) — Taiwan e-invoice B2C/B2B via PayNow (立吉富體系 3); Bearer JWT-Token auth (no symmetric encryption); issue/cancel/allowance (open+void)/query; IInvoiceService + ISupportsAllowance + ISupportsQuery; B2C tax_amount=0 / B2B actual tax; carrier/donation mutual exclusion; zero-tax requires reason; allowance_data uses `allowance_number` key (not ezPay's `allowance_no`); parallel with Amego/ECPay/ezPay, switchable from admin; [GAP: sandbox jwt_token pending application; dev env invoiceapi-dev.paynow.com.tw / prod invoiceapi-prod.paynow.com.tw]
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
   - `MountPayuniUniEmbed()` from `js/src/external/PayuniUniEmbed/` — mounts on order-received page for PAYUNi UNi Embed V3 (fetches SDK token, renders iframe, triggers merchant_trade, handles 3DS redirect)
   - `MountPaynowPayment()` from `js/src/external/PaynowPayment/` — mounts on order-received page for PayNow Component SDK v2 (loads CDN SDK, renders iframe, calls checkout() directly, handles 3DS; no create-payment POST; result determined by Webhook)
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
│   │   ├── Payuni/                  # PAYUNi UPP V2 redirect gateway (ID: payuni_upp)
│   │   │   ├── Services/PayuniUppGateway.php  # before_process_payment + before_order_received + refund/capture/void_auth/query_trade
│   │   │   ├── Http/PayuniCallback.php         # NotifyURL: power-checkout/payuni upp/notify (AES-256-GCM + HashInfo)
│   │   │   ├── Http/DoActionClient.php         # Credit card close/cancel (/api/trade/close + /api/trade/cancel)
│   │   │   ├── Http/QueryTradeClient.php       # Trade query (/api/trade/query)
│   │   │   ├── DTOs/                           # PayuniSettingsDTO, PayuniRequestParams
│   │   │   ├── Managers/StatusManager.php      # TradeStatus: 0=取號→payment_info, 1=已付款→processing, 2/3/8=pending
│   │   │   └── Shared/                         # Helpers (PayuniCrypto, PayuniMetaKeys, PayuniTradeNo, ItemName), Enums (PayuniPaymentMethod, PayuniTradeStatus)
│   │   ├── PayuniUniEmbed/          # PAYUNi UNi Embed V3 embedded gateway (ID: payuni_uni_embed)
│   │   │   ├── Services/PayuniUniEmbedGateway.php  # before_process_payment=token_get; before_order_received=localize SDK; create-payment REST; refund/capture/void_auth/query_trade
│   │   │   ├── Http/TokenGetClient.php              # /api/iframe/token_get V3.0 (MerID+Timestamp+IFrameDomain only)
│   │   │   ├── Http/MerchantTradeClient.php         # /api/iframe/merchant_trade (幕後授權 + 3DS; TradeAmt 後端算)
│   │   │   ├── Http/PayuniUniEmbedFrontendApi.php   # REST power-checkout/payuni/uni-embed/create-payment (order_key auth)
│   │   │   ├── Http/PayuniUniEmbedCallback.php      # NotifyURL power-checkout/payuni/uni-embed/notify (AES-256-GCM + HashInfo; always 200)
│   │   │   ├── Http/UniDoActionClient.php           # Credit card close/cancel (/api/trade/close + /api/trade/cancel)
│   │   │   ├── Http/UniQueryTradeClient.php         # Trade query (/api/trade/query)
│   │   │   ├── DTOs/PayuniUniEmbedSettingsDTO.php
│   │   │   ├── Managers/StatusManager.php           # TradeStatus: 1=processing (amount+Gateway=9 guard), 2/3/8=pending
│   │   │   └── Shared/                              # Helpers (PayuniUniEmbedMetaKeys, PayuniUniEmbedTradeNo, ItemName), Enums (PayuniUniEmbedTradeStatus, PayuniUniEmbedPaymentMethod); uses Payuni/Shared/Helpers/PayuniCrypto (no third copy)
│   │   ├── Paynow/                  # PayNow Component SDK v2 embedded gateway (ID: paynow)
│   │   │   ├── Services/PaynowGateway.php            # before_process_payment=create_payment_intent; before_order_received=localize SDK; process_refund; query_trade; admin order actions
│   │   │   ├── Http/PaynowRestClient.php              # Four-in-one: create/retrieve payment_intent + refund/retrieve_refund; Bearer PrivateKey
│   │   │   ├── Http/PaynowCallback.php                # NotifyURL power-checkout/paynow/notify (HMAC-SHA256 raw body + always 200)
│   │   │   ├── DTOs/                                  # PaynowSettingsDTO, CreatePaymentIntentParams, RefundParams
│   │   │   ├── Managers/StatusManager.php             # Status=Success/Failed; offline payment two-phase (_pc_paynow_payment_info + pending); amount guard; idempotency
│   │   │   └── Shared/                                # Helpers (WebhookVerifier, PaynowMetaKeys, PaynowTradeNo, ItemName, PaynowBlocksIntegration), Enums (PaynowPaymentMethod, PaynowIntentStatus, PaynowRefundStatus)
│   │   └── Shared/                  # AbstractPaymentGateway implements IPaymentProvider, PaymentApiService (REST /refund)
│   │       └── Interfaces/IPaymentProvider.php # Payment 領域統一介面（7 methods，extends IGateway；mirrors ILogisticsProvider）
│   ├── Logistics/
│   │   ├── ProviderRegister.php     # Registers logistics providers + WC shipping method + checkout meta
│   │   ├── Ecpay/                   # ECPay AllInOne Logistics v2 (ID: ecpay_logistics)
│   │   │   ├── Services/EcpayLogisticsProvider.php  # implements ILogisticsProvider
│   │   │   ├── Services/WC_EcpayLogisticsShipping.php  # extends WC_Shipping_Method (classic checkout)
│   │   │   ├── Http/LogisticsApiClient.php  # AES-128-CBC (reuses Ecpg AesCrypto); RqHeader Revision 1.0.0; 5-min Timestamp
│   │   │   ├── Http/LogisticsCallback.php   # ServerReplyURL (AES-JSON 3-layer) + ClientReplyURL (selection)
│   │   │   └── DTOs/                        # EcpayLogisticsSettingsDTO, StoreSelectionParams, CreateShipmentParams
│   │   ├── Paynow/                  # PayNow Logistics (ID: paynow_logistics)
│   │   │   ├── Services/PaynowLogisticsProvider.php  # implements ILogisticsProvider; SEVEN→01/FAMI→03/HILIFE→05/TCAT→06; CVS ≤20000 / home ≤100000; idempotent ReNewOrder
│   │   │   ├── Services/WC_PaynowLogisticsShipping.php  # extends WC_Shipping_Method (per-service rates)
│   │   │   ├── Http/LogisticsApiClient.php  # Add_Order/ReNewOrder/CancelOrder/Get_Order_Info/print (DELETE for cancel; SEVEN GET /api/Order711; TCAT POST PrintBlackCatLabel)
│   │   │   ├── Http/LogisticsCallback.php   # power-checkout/paynow: logistics/selection-callback + logistics/status-callback (orderno反查 + idempotency; always HTTP 200)
│   │   │   ├── DTOs/                        # PaynowLogisticsSettingsDTO (test: testlogistic.paynow.com.tw / prod: logistic.paynow.com.tw), CreateShipmentParams
│   │   │   └── Shared/                      # Helpers (TripleDesCrypto [R2: ECB不是CBC], PassCodeService [SHA1], PaynowLogisticsMetaKeys [前綴_pc_paynow_logistics_], ItemName), Enums (PaynowLogisticService, PaynowDeliverMode, PaynowLogisticsStatus)
│   │   └── Shared/
│   │       ├── Interfaces/ILogisticsProvider.php  # 10-method interface (mirrors IInvoiceService)
│   │       ├── Enums/                             # LogisticsSubType, LogisticsAccountType, LogisticsTemperature, LogisticsPaymentScenario, LogisticsStatus
│   │       ├── Helpers/LogisticsMetaKeys.php       # Order meta CRUD helper (HPOS-aware)
│   │       └── Services/LogisticsApiService.php    # REST power-checkout/v1 (5 endpoints); PROVIDER_IDS includes paynow_logistics
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
│   │   ├── Paynow/                  # PaynowInvoiceProvider (IInvoiceService + ISupportsAllowance + ISupportsQuery, ID: paynow_invoice)
│   │   │   ├── Services/PaynowInvoiceProvider.php  # const ID='paynow_invoice' (R5: 不同於金流 'paynow'); option woocommerce_paynow_invoice_settings
│   │   │   ├── Http/InvoiceApiClient.php  # Bearer JWT-Token (no symmetric encryption); type==='success'; issue/cancel/allowance/cancel-allowance/query; dev: invoiceapi-dev.paynow.com.tw / prod: invoiceapi-prod.paynow.com.tw
│   │   │   ├── DTOs/                      # PaynowInvoiceSettingsDTO, IssueParams (B2C tax_amount=0/B2B actual; carrier/donation mutex; ZeroTax requires reason), IssueResponse, AllowanceParams, AllowanceResponse, QueryParams, QueryResponse
│   │   │   └── Shared/Enums/              # ECarrierType (5 cases), ETaxType (4 cases), EZeroTaxReason (10 cases)
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
| `power-checkout/payuni` | POST | `/upp/notify` | AES-256-GCM + HashInfo SHA256 (verified inside; always HTTP 200) |
| `power-checkout/payuni` | POST | `/uni-embed/create-payment` | order_key hash_equals (in body) |
| `power-checkout/payuni` | POST | `/uni-embed/notify` | AES-256-GCM + HashInfo SHA256 (verified inside; always HTTP 200) |
| `power-checkout/paynow` | POST | `/notify` | HMAC-SHA256 raw body (`X-Payment-Center-Hmac-Sha256`, verified inside; always HTTP 200) |
| `power-checkout/paynow` | POST | `/logistics/selection-callback` | open (returnUrl, Form POST; orderno 反查; always HTTP 200) |
| `power-checkout/paynow` | POST | `/logistics/status-callback` | open (Form POST; orderno+LogisticCode idempotency; always HTTP 200) |

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

## PAYUNi UPP Payment Flow (payuni_upp)

1. `before_process_payment()` writes idempotency key `_pc_payuni_trade_no` (format `PCU{order_id}`) and returns order-received URL — no API call at this stage
2. `before_order_received()` assembles `PayuniRequestParams` (AES-256-GCM `EncryptInfo` + SHA256 `HashInfo`, Version 2.0) and renders auto-submit form → browser POSTs to PAYUNi `/api/upp`
3. PAYUNi sends server-to-server POST to `/wp-json/power-checkout/payuni/upp/notify` (NotifyURL)
4. Callback verification chain: outer `Status=SUCCESS` → `MerID` timing-safe compare → `EncryptInfo`/`HashInfo` present → `HashInfo` `hash_equals` verify → AES-256-GCM decrypt → lookup order by `_pc_payuni_trade_no` → idempotency → `StatusManager`
5. `StatusManager::update_order_status()` maps `TradeStatus`: `1`(paid) → amount guard → `payment_complete()` → processing; `0`(get code) → write `_pc_payuni_payment_info` + pending; `2`/`3`/`8` → pending + order note
6. All NotifyURL paths (including `\Throwable`) always return HTTP 200

Refund support by payment method (determined by PAYUNi `PaymentType` in `_pc_payuni_payment_detail`, not frontend):

| Payment Method | API Refund |
|---|---|
| Credit Card (PaymentType=1) | Yes — Close CloseType=2 (`/api/trade/close`); wpdb TRANSACTION + ROLLBACK on failure |
| ATM / CVS / icash / LINE Pay / JKoPay / etc. | No — `WP_Error('refund_unsupported')`, manual via PAYUNi admin |

Admin order actions (credit card only):

| Action | API |
|---|---|
| 查詢補單 (`pc_payuni_query_trade`) | `/api/trade/query` — if TradeStatus=1 + DataSource=A + not processing → `StatusManager` |
| 請款 (`pc_payuni_capture`) | Close CloseType=1; writes `_pc_payuni_capture_status='captured'` |
| 取消授權 (`pc_payuni_cancel_auth`) | `/api/trade/cancel`; writes `_pc_payuni_capture_status='voided'` |

Encryption: **AES-256-GCM** `hex(base64(cipher):::base64(tag))`; `HashInfo = strtoupper(sha256(HashKey+EncryptInfo+HashIV))`; distinct from ECPay AES-128-CBC and ezPay AES-256-CBC. `PayuniCrypto` (Payment namespace) is a same-source copy of Logistics `PayuniCrypto`; both must stay in sync.

Environment: sandbox `https://sandbox-api.payuni.com.tw/api/upp` / prod `https://api.payuni.com.tw/api/upp`; test mode uses official public test vector keys.

---

## PAYUNi UNi Embed Payment Flow (payuni_uni_embed)

1. `before_process_payment()` writes idempotency key `_pc_payuni_uni_trade_no` (format `PCE{order_id}`) → calls `TokenGetClient::get_token()` (`/api/iframe/token_get` V3.0, inner payload: MerID + Timestamp + IFrameDomain only) → stores SDK token in `_pc_payuni_uni_sdk_token` → returns order-received URL
2. `before_order_received()` localizes SDK token + REST endpoint to page; frontend `MountPayuniUniEmbed()` loads PAYUNi iframe SDK, renders embedded card form, customer submits card details → SDK returns buyer credential
3. Frontend POSTs buyer credential to `/wp-json/power-checkout/payuni/uni-embed/create-payment` (auth: `order_key` `hash_equals`)
4. Backend calls `MerchantTradeClient::merchant_trade()` (`/api/iframe/merchant_trade` V1.0, TradeAmt computed server-side) → if `ThreeDURL` non-empty, returns `three_d_url` → frontend redirects to 3DS; stores `credit_hash` + `credit_life` (buyer token, never card number/CVC)
5. PAYUNi sends server-to-server POST to `/wp-json/power-checkout/payuni/uni-embed/notify` (NotifyURL)
6. Callback verification chain: outer `Status=SUCCESS` → `MerID` timing-safe compare → `EncryptInfo`/`HashInfo` present → `HashInfo` `hash_equals` verify → AES-256-GCM decrypt → lookup order by `_pc_payuni_uni_trade_no` → idempotency → `StatusManager`
7. `StatusManager::update_order_status()` maps `TradeStatus`: `1`(paid) → amount guard + Gateway=9 guard → `payment_complete()` → processing; `2`/`3`/`8` → pending + order note
8. All NotifyURL paths (including `\Throwable`) always return HTTP 200

Refund support (determined by `PaymentType` in `_pc_payuni_uni_payment_detail`):

| Payment Method | API Refund |
|---|---|
| Credit Card (PaymentType=1) | Yes — Close CloseType=2 (`/api/trade/close`); wpdb TRANSACTION + ROLLBACK on failure |
| Others | No — `WP_Error('refund_unsupported')`, manual via PAYUNi admin |

Admin order actions (credit card only):

| Action | API |
|---|---|
| 查詢補單 (`pc_payuni_uni_query_trade`) | `/api/trade/query` — if TradeStatus=1 + DataSource=A + not processing → `StatusManager` |
| 請款 (`pc_payuni_uni_capture`) | Close CloseType=1; writes `_pc_payuni_uni_capture_status='captured'` |
| 取消授權 (`pc_payuni_uni_cancel_auth`) | `/api/trade/cancel`; writes `_pc_payuni_uni_capture_status='voided'` |

Encryption: same **AES-256-GCM** as UPP (`PayuniCrypto` shared, no third copy); environment: sandbox `https://sandbox-api.payuni.com.tw` / prod `https://api.payuni.com.tw`.

---

## PayNow Payment Flow (paynow)

1. `before_process_payment()` writes idempotency keys `_pc_paynow_trade_no` (format `PCN{order_id}`) + calls `PaynowRestClient::create_payment_intent()` (Bearer PrivateKey, POST `/payment-intents`) → stores `_pc_paynow_payment_intent_id` (`pp_xxx`) and `_pc_paynow_secret` (SDK secret `pp_xxx_st_xxx`) → returns order-received URL; idempotency: if `_pc_paynow_payment_intent_id` already exists, skips API call and returns URL directly
2. `before_order_received()` localizes `public_key`/`secret`/`env`/`order_received_url` to page; frontend `MountPaynowPayment()` loads PayNow Component SDK v2 (CDN `https://js.paynow.com.tw/sdk/v2/index.js`), renders embedded iframe, customer submits payment → SDK `checkout()` directly completes authorization + 3DS with PayNow servers — **no `create-payment` POST to backend**; on SDK success, frontend redirects to order-received; payment result is determined by Webhook
3. PayNow sends server-to-server POST to `/wp-json/power-checkout/paynow/notify` (NotifyURL)
4. Callback verification chain: take `$request->get_body()` raw + `X-Payment-Center-Hmac-Sha256` header → `WebhookVerifier::verify()` (HMAC-SHA256, key=PrivateKey, `strtoupper` + `hash_equals`, against raw body — never re-encode) → decode JSON → lookup order by `_pc_paynow_payment_intent_id` (`get_order_by_payment_intent_id`) → amount guard → idempotency → `StatusManager`
5. `StatusManager::update_order_status()` maps `Status`: `Success` → amount guard (`ctype_digit` + `ceil` compare) → idempotency (already processing → skip) → by `PaymentType`: instant (Credit/Installment/LINEPay/ApplePay) → `payment_complete()` → processing + write `_pc_paynow_payment_detail`; offline pending phase (ATM/ConvenienceStore) → write `_pc_paynow_payment_info` + maintain pending; offline `Success` → `payment_complete()` → processing; `Failed` → maintain pending + order note
6. All NotifyURL paths (including `\Throwable`) always return HTTP 200

Refund support (determined by `PaymentType` in `_pc_paynow_payment_detail`, not frontend):

| Payment Method | API Refund |
|---|---|
| Credit Card (PaymentType=Credit/CreditCardInstallment) | Yes — REST `POST /payment-intents/:id/refunds`; wpdb TRANSACTION + ROLLBACK on failure; writes `_pc_paynow_refund_detail` |
| ATM | Yes — REST `POST /payment-intents/:id/refunds` (requires bankCode/bankBranchCode/bankAccount); missing bank fields rejected before API call |
| ConvenienceStore / LINE Pay / Apple Pay | No — `WP_Error('refund_unsupported')`, manual via PayNow admin |

Admin order actions:

| Action | API |
|---|---|
| 補查付款意圖 (`pc_paynow_query_trade`) | `GET /payment-intents/:id` — if status=success + not processing → `StatusManager`補單 (amount guard + idempotency) |
| 退款查詢 (`pc_paynow_refund_query`) | `GET /payment-intents/:id/refunds/:uuid` — writes back `_pc_paynow_refund_detail` + order note |

Encryption/auth: **HMAC-SHA256** only; `WebhookVerifier` is standalone (does not reuse `PayuniCrypto` — PayNow 體系 1 has no symmetric encryption); API auth is **Bearer PrivateKey** (not AES envelope). Environment: sandbox `https://sandboxapi.paynow.com.tw` / prod `https://api.paynow.com.tw`.

[GAP: sandbox PublicKey/PrivateKey not yet applied for (contact PayNow with subject「申請 PayNow 串接私鑰 (PrivateKey)」); sandbox end-to-end pending]

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

## PayNow Logistics Flow (paynow_logistics)

### Store selection (convenience store — SEVEN/FAMI/HILIFE)

1. Frontend calls `POST /logistics/{order_id}/store-selection` with `sub_type` + `payment_scenario` → `get_store_selection()` encrypts `apicode` (TripleDES ECB + space→plus), builds form-POST to `{api_url}/Member/Order/Choselogistics`, returns `redirect_target` HTML
2. Customer selects store → PayNow POSTs store data to `/wp-json/power-checkout/paynow/logistics/selection-callback` (returnUrl) → `parse_store_selection()` writes `_pc_paynow_logistics_store_*` + `_pc_paynow_logistics_order_no` meta
3. Admin calls `POST /logistics/{order_id}/create-shipment` → validates amount limit (CVS ≤20000 / home ≤100000) → if existing valid shipment (status≠1) → ReNewOrder; otherwise Add_Order (`JsonOrder=base64(TripleDES(JSON))`) → writes `_pc_paynow_logistics_ref` (LogisticNumber) + paymentno + validationno

### Home delivery (TCAT)

Store-selection step skipped; `create_shipment()` calls Add_Order directly with delivery address.

### Encryption (R2 — DES-EDE3 ECB, not CBC)

Two distinct modes; **not interchangeable**:
- `encrypt_order_json()` — `DES-EDE3` without `-CBC` suffix (OpenSSL defaults to ECB when no IV given), `OPENSSL_NO_PADDING` + manual `\0` zero-pad to 8-byte boundary + base64
- `encrypt_apicode()` — `DES-EDE3-ECB`, `OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING` + manual `\0` pad + base64 + `str_replace(' ', '+', ...)`
- Fixed key=`123456789070828783123456` / iv=`12345678`; [GAP: prod換鑰待 PayNow 官方確認]

### Status callback (貨態推送 — always HTTP 200)

PayNow POSTs to `/wp-json/power-checkout/paynow/logistics/status-callback` (Form POST, `permission_callback: __return_true`):
- Parse `orderno` / `PayNowLogisticCode` / `Detail_Status_Description` / `paymentno`
- Lookup order by `_pc_paynow_logistics_order_no` (OrderNo = `$order->get_order_number()`)
- Idempotency: `"{OrderNo}:{LogisticCode}"` composite key in `_pc_paynow_logistics_processed_status`
- COD + pickup-complete (LogisticCode=8000 or description contains 取貨完成) → `_pc_paynow_logistics_collection_paid=yes`
- All paths (including `\Throwable`) always return HTTP 200

`query_shipment()` (GET /api/Orderapi/Get_Order_Info) is retained as supplemental reconciliation; webhook is primary.

### Operations

| Operation | API | Notes |
|---|---|---|
| 建單 (`create_shipment`) | POST Add_Order | idempotent ReNewOrder if valid shipment exists |
| 查詢 (`query_shipment`) | GET Get_Order_Info | supplemental reconciliation |
| 列印 (`print_document`) | SEVEN: GET /api/Order711; TCAT: POST PrintBlackCatLabel | RenewOrderNo preferred |
| 取消 (`cancel_shipment`) | DELETE CancelOrder | not C2C-only (unlike ECPay) |
| 逆物流 (`create_return`) | throw `\Exception('尚未實作')` | [GAP: no woomp evidence] |

[GAP: sandbox user_account/apicode not yet applied; prod key/IV to confirm with PayNow; official logistics API document pending verification]

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

## PayNow Invoice Flow (paynow_invoice)

Provider ID `paynow_invoice` (R5: distinct from payment gateway `paynow`); WC option `woocommerce_paynow_invoice_settings` (no collision with `woocommerce_paynow_settings`). Parallel with Amego / `ecpay` / `ezpay`, switchable from admin.

1. `issue()` → `InvoiceApiClient::issue()` → POST `/api/invoices/issue` (Bearer JWT-Token, JSON body) → `type==='success'` → client writes `pc_issued_data` (`invoice_number`, `invoice_date`, `order_no`, `total_amount`) + `pc_provider_id='paynow_invoice'` meta
2. `cancel()` → POST `/api/invoices/cancel` (带 `invoice_number`) → success → provider writes `pc_cancelled_data` + clears `pc_issued_data`; **client does not write meta for cancel** (meta responsibility split differs from ezPay)
3. **Allowance (open)**: triggered by WC refund hook → `issue_allowance()` → POST `/api/invoices/allowance` → writes `allowance_data` (`allowance_number`, `allowance_amount`, `invoice_number`, `remain_amount`) to order meta; key `allowance_number` (not ezPay's `allowance_no`)
4. **Allowance (void)**: `invalid_allowance()` → POST `/api/invoices/cancel-allowance` (带 `allowance_number`) → success → clears `allowance_data`; guard: existing allowance required before cancel
5. **Query**: `query_invoice()` → GET `/api/invoices?InvoiceNumber=...` (Bearer) → read-only, no meta/status changes
6. Auth: **Bearer JWT-Token** only — no AES envelope, no CheckCode; `Authorization: Bearer {jwt_token}` + `Content-Type: application/json`
7. Full refund → `cancel()` (void invoice, not allowance); partial refund → `issue_allowance()` (triggered by `woocommerce_order_refunded` hook via provider-agnostic layer)

Tax rules (IssueParams):
- B2C: `tax_amount=0` (government calculates); B2B (has buyer identifier): `tax_amount` = actual tax
- `tax_type=ZeroTax` requires `is_pass_customs` + `zero_tax_rate_reason` (e.g. `ExportGoods`)
- Carrier and donation are mutually exclusive (throw on conflict)

Environment: dev `https://invoiceapi-dev.paynow.com.tw` / prod `https://invoiceapi-prod.paynow.com.tw`

[GAP: sandbox jwt_token not yet applied; sandbox end-to-end pending]

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
| `_pc_payuni_trade_no` | MerTradeNo idempotency key (format `PCU{order_id}`); written by `before_process_payment`; primary key for NotifyURL order lookup |
| `_pc_payuni_payment_detail` | PAYUNi payment result detail from NotifyURL decrypted inner payload (includes `TradeNo`, `PaymentType` — used for refund routing) |
| `_pc_payuni_payment_info` | ATM/CVS get-code info (BankType, PayNo, Store, ExpireDate etc.); written on TradeStatus=0 |
| `_pc_payuni_capture_status` | Credit card capture/void status (`''` / `'captured'` / `'voided'`) |
| `_pc_payuni_uni_trade_no` | MerTradeNo idempotency key (format `PCE{order_id}`); written by `before_process_payment`; primary key for NotifyURL order lookup — UNi Embed |
| `_pc_payuni_uni_sdk_token` | SDK iframe token from token_get; stored for frontend use |
| `_pc_payuni_uni_payment_detail` | Payment result detail from NotifyURL decrypted inner payload (includes `TradeNo`, `PaymentType` — used for refund routing) — UNi Embed |
| `_pc_payuni_uni_capture_status` | Credit card capture/void status (`''` / `'captured'` / `'voided'`) — UNi Embed |
| `_pc_payuni_uni_credit_hash` | Buyer token (buyer credit card hash); **not** card number or CVC |
| `_pc_payuni_uni_credit_life` | Buyer credit card expiry (MMYY format) |
| `_pc_paynow_trade_no` | MerTradeNo idempotency key (format `PCN{order_id}`); written by `before_process_payment`; auxiliary for reconciliation (not Webhook lookup key) |
| `_pc_paynow_payment_intent_id` | PaymentIntentId (`pp_xxx`); written by `before_process_payment`; **primary key for Webhook order lookup** (`get_order_by_payment_intent_id`) |
| `_pc_paynow_secret` | Component SDK secret (`pp_xxx_st_xxx`); stored for frontend SDK rendering; never card number or CVC |
| `_pc_paynow_payment_detail` | Payment result detail from Webhook decrypted payload (includes `PaymentType` — used for refund routing) |
| `_pc_paynow_payment_info` | Offline payment pending info (ATM vAccount / convenience store payment code / ExpireDate etc.); written on offline pending phase |
| `_pc_paynow_refund_detail` | Refund result detail; written on successful refund or refund query |
| `_pc_paynow_logistics_provider_id` | `paynow_logistics`; written by create_shipment |
| `_pc_paynow_logistics_service_id` | Logistic_serviceID (01=SEVEN/03=FAMI/05=HILIFE/06=TCAT); written at checkout |
| `_pc_paynow_logistics_order_no` | PayNow OrderNo (=`$order->get_order_number()`); **primary key for status-callback order lookup** |
| `_pc_paynow_logistics_store_id` | Selected CVS store code (selection-callback) |
| `_pc_paynow_logistics_store_name` | Selected CVS store name |
| `_pc_paynow_logistics_store_addr` | Selected CVS store address |
| `_pc_paynow_logistics_ref` | LogisticNumber (PayNow shipment ID; auxiliary lookup key) |
| `_pc_paynow_logistics_sno` | Shipment sequence number (default "1") |
| `_pc_paynow_logistics_payment_no` | Carrier paymentno (物流商託運單號) |
| `_pc_paynow_logistics_validation_no` | Carrier validationno |
| `_pc_paynow_logistics_renew_order_no` | OrderNo after ReNewOrder (used for print) |
| `_pc_paynow_logistics_status` | Shipment status (0=成立中 / 1=無效) |
| `_pc_paynow_logistics_delivery_status` | Delivery status description (Detail_Status_Description) |
| `_pc_paynow_logistics_logistic_code` | Delivery status code (PayNowLogisticCode, e.g. 5000/8000) |
| `_pc_paynow_logistics_delivery_type` | TCAT temperature tier (DeliveryType) |
| `_pc_paynow_logistics_collection_paid` | COD pickup-complete flag (`yes`) |
| `_pc_paynow_logistics_processed_status` | Idempotency guard array — elements: `"{OrderNo}:{LogisticCode}"` |

---

## Key WordPress Hooks

| Hook | Purpose |
|---|---|
| `woocommerce_payment_gateways` | Inject SLP / ECPay AIO / ECPay ECPG / NewebPay MPG / PAYUNi UPP / PAYUNi UNi Embed / PayNow gateways |
| `before_woocommerce_init` | Declare HPOS + Blocks compatibility |
| `wc_payment_gateways_initialized` | Populate ProviderUtils::$container |
| `woocommerce_order_status_{status}` | Auto issue/void invoices |
| `woocommerce_checkout_fields` | Classic checkout invoice fields |
| `woocommerce_shipping_methods` | Register WC_EcpayLogisticsShipping + WC_PaynowLogisticsShipping (classic checkout shipping methods) |
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
