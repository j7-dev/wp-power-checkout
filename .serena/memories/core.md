# Power Checkout Plugin — Core

WordPress WooCommerce checkout plugin (PHP/Vue 3).  
PSR-4: `J7\PowerCheckout\` → `inc/classes/`; test ns `Tests\Integration\` → `tests/Integration/`.

## Domain layout
```
inc/classes/Domains/
  Payment/     — gateways (SLP/ECPay/Payuni/PayNow)
  Invoice/     — providers (Amego/Ecpay/Ezpay/Paynow)
  Logistics/   — providers (Ecpay/Payuni)
  Settings/    — WC settings tab
inc/classes/Shared/  — ProviderUtils, OrderUtils, BaseSettingsDTO, AbstractPaymentGateway
```

## Key invariants
- `final class` default; `declare(strict_types=1)` every file
- PHPStan level 9; PHPCS WordPress standards
- Hook callbacks: always `[__CLASS__, 'method']` (no closures except blocks registration)
- Provider option key: `woocommerce_{provider_id}_settings` (ProviderUtils::get_option_name)
- Test group whitelist (phpunit.xml.dist): smoke/happy/error/edge/security — tests without one are never collected
- Currency guard: tests with amounts must `update_option('woocommerce_currency','TWD')`

## Key memories
- Provider system: `mem:conventions`
- Test patterns: `mem:tech_stack`
- Commands: `mem:suggested_commands`
