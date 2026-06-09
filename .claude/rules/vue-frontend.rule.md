---
globs:
  - "js/src/**/*.ts"
  - "js/src/**/*.vue"
---

# Vue 3 Frontend Rules

## Architecture: 4 App Instances from 1 Bundle

Entry point `js/src/index.ts` creates four independent instances:

1. **Settings SPA** -- mounted on `#power-checkout-wc-setting-app` (injected into WC `#mainform`)
   - Full router + Element Plus + TanStack Vue Query
   - Only mounts when the WC settings tab page is active

2. **RefundDialog** -- `MountRefundDialog()` from `external/RefundDialog/`
   - Mounts on order detail pages
   - Standalone mini-app for gateway refund actions

3. **InvoiceApp** -- `MountInvoiceApp()` from `external/InvoiceApp/`
   - Mounts on order detail pages (admin MetaBox) AND checkout page (frontend invoice form)
   - Renders on multiple DOM elements identified by `render_ids` from PHP

4. **EcpgPayment** -- `MountEcpgPayment()` from `external/EcpgPayment/`
   - Mounts on order-received page for ECPay ECPG embedded payment
   - Loads ECPay JS SDK, renders card form (`#ECPayPayment`), sends PayToken to `ecpg/create-payment`, handles 3DS redirect
   - Not a Vue app — plain TypeScript module called from `index.ts`

## Composition API Only

```vue
<script setup lang="ts">
// CORRECT: Composition API with script setup
import { ref, computed } from 'vue'
</script>

<!-- NEVER use Options API -->
```

## Import Paths

Always use `@/` alias (resolves to `js/src/`). Never use relative paths:

```typescript
// CORRECT
import { API_URL } from '@/utils/env'
import apiClient from '@/api/index'

// WRONG
import { API_URL } from '../../utils/env'
```

## Environment Access

Access PHP-bridged data through `utils/env.ts` only. Never read `window` directly:

```typescript
// CORRECT
import { NONCE, API_URL, SITE_URL } from '@/utils/env'

// WRONG
const nonce = window.power_checkout_data.env.NONCE
```

Available env values: `SITE_URL`, `API_URL`, `CURRENT_USER_ID`, `CURRENT_POST_ID`, `PERMALINK`, `APP_NAME`, `KEBAB`, `SNAKE`, `NONCE`, `APP1_SELECTOR`

## API Client

Use the centralized `apiClient` from `@/api/index.ts`:

- Base URL: `{API_URL}/power-checkout/v1/`
- Auto-attaches `X-WP-Nonce` header
- Response interceptor auto-shows `ElNotification` for non-GET success responses
- Error interceptor handles 403 (session expired) with reload prompt
- **Do NOT manually trigger `ElNotification`** for API responses -- the interceptor handles it

## TanStack Vue Query Defaults

Configured in `index.ts` QueryClient:

```typescript
queries: {
    staleTime: 15 * 60 * 1000,    // 15 min
    gcTime: 15 * 60 * 1000,       // 15 min cache
    retry: 0,                      // No retry
    refetchOnWindowFocus: false,   // No auto-refetch
}
mutations: { retry: 0 }
```

## Element Plus Only

- UI library: Element Plus (`element-plus`) + `@element-plus/icons-vue`
- Do NOT use Ant Design, Vuetify, or any other UI library in Vue code
- Import Element Plus components as needed (auto-import via `app.use(ElementPlus)`)

## Router

Memory mode router (`createMemoryHistory`):

```
/payments                               → Payments list
/payments/shopline_payment_redirect     → SLP settings
/payments/ecpay_aio                     → ECPay AIO settings
/payments/ecpay_ecpg                    → ECPay ECPG settings
/payments/payuni_upp                    → PAYUNi UPP settings (js/src/pages/Payments/PayuniUpp/index.vue)
/logistics                              → Logistics list (js/src/pages/Logistics/index.vue)
/logistics/ecpay_logistics              → ECPay Logistics settings (js/src/pages/Logistics/Ecpay/index.vue)
/invoices                               → Invoice list
/invoices/amego                         → Amego settings
/invoices/ecpay                         → ECPay Invoice settings
/invoices/ezpay                         → ezPay Invoice settings (js/src/pages/Invoices/Ezpay/index.vue)
/settings                               → Global settings (placeholder)
```

`ROUTER_MAPPER` in `router/index.ts` maps provider IDs to routes.

## Type Declarations

- Global types: `js/src/types/global.d.ts` (declares `window.power_checkout_data` etc.)
- Shared types: `js/src/types/index.ts`
- Page-specific types: `js/src/pages/{Domain}/{Provider}/Shared/types.ts`
- Page-specific enums: `js/src/pages/{Domain}/{Provider}/Shared/enums.ts`

## Build

- Vite dev server: port 5182
- Output: `js/dist/index.js` + `js/dist/index.css`
- Single entry point: `js/src/index.ts`
- Asset names: `[name].[ext]` (no hash in filenames)
