---
globs:
  - "inc/assets/blocks/**/*.ts"
  - "inc/assets/blocks/**/*.tsx"
---

# React WC Blocks Payment Integration Rules

## Purpose

React code in this project is ONLY for WooCommerce Block Checkout payment method registration. The main app uses Vue 3 -- React is used solely because WC Blocks requires it.

## Architecture

Each `.tsx` file in `inc/assets/blocks/` is a separate Vite entry point that registers one payment method:

```
inc/assets/blocks/
  shopline_payment_redirect.tsx    # Active -- SLP redirect gateway
  ecpay_aio.tsx                    # Active -- ECPay AIO redirect gateway
  ecpay_ecpg.tsx                   # Active -- ECPay ECPG embedded gateway
  types/types.d.ts                 # Shared type declarations
```

Build output: `inc/assets/dist/blocks/{name}.js`

## WP/WC External Globals

All WordPress and WooCommerce packages are resolved from window globals via `vite-plugin-optimizer`:

```typescript
// These import from window.wc / window.wp at runtime, not bundled
import { registerPaymentMethod } from '@woocommerce/blocks-registry'  // window.wc.wcBlocksRegistry
import { getSetting } from '@woocommerce/settings'                    // window.wc.wcSettings
import { createElement } from '@wordpress/element'                    // window.wp.element
import { decodeEntities } from '@wordpress/html-entities'             // window.wp.htmlEntities
import { __ } from '@wordpress/i18n'                                 // window.wp.i18n
```

Do NOT add these as npm dependencies -- they are shimmed.

## Payment Method Registration Pattern

```tsx
const id = 'gateway_id'
const settings = getSetting(`${id}_data`, {})

// Data comes from BlocksIntegration::get_payment_method_data() on PHP side
const { name, order_button_text, supports: features } = settings
const label = decodeEntities(settings.title)

const Content = () => decodeEntities(settings.description || '')
const Label = (props: any) => {
    const { PaymentMethodLabel } = props.components
    return <PaymentMethodLabel text={label} />
}

registerPaymentMethod({
    name,
    label: <Label />,
    ariaLabel: label,
    placeOrderButtonLabel: order_button_text,
    content: <Content />,
    edit: <Content />,
    canMakePayment: () => true,
    paymentMethodId: id,
    supports: { features, showSavedCards: true, showSaveOption: false },
})
```

## PHP Side Integration

The `BlocksIntegration` class (extends WC `AbstractPaymentMethodType`) handles:

- Script registration with WP dependencies: react, wc-settings, wp-block-editor, wp-blocks, wp-components, wp-element, wp-i18n, wp-primitives
- `get_payment_method_data()` returns: name, title, description, supports, order_button_text, icons
- Registered via `woocommerce_blocks_payment_method_type_registration` action

## Build Config

- Vite config: `vite.config.block.ts`
- Dev server port: 5181
- Entry discovery: `glob('inc/assets/blocks/*.tsx')`
- Terser for minification with `$` reserved
- `lodash-es` aliased to `lodash`
- Dev watch: `pnpm dev:blocks`
- Production build: `pnpm build:blocks`
