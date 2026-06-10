# Testing Infrastructure

## Active Suite
- Directory: `tests/Integration/` (NOT `inc/tests/` — legacy, inactive)
- Namespace: `Tests\Integration\`
- Base class: `Tests\Integration\TestCase extends WP_UnitTestCase`
- Config: `phpunit.xml.dist` (not `phpunit.xml`)

## Group Whitelist (CRITICAL)
phpunit.xml.dist collects ONLY: `smoke` / `happy` / `error` / `edge` / `security`
Every test method MUST have at least one of these groups or it will NOT run.
Additional classification groups (integration, logistics, paynow, invoice) can be added alongside.

## API Modes
- `API_MODE=mock` — no external calls, fixture responses (default for CI)
- `API_MODE=sandbox` — calls sandbox endpoints
- `API_MODE=prod` — production endpoints (dangerous)

## Pre-existing Failures (do not fix)
- 2 pre-existing failures: ezpay edge + RedirectSettingsDTO — NOT in Logistics/ dir

## TestCase Helpers
- `enable_provider($id, $extra)` / `disable_provider($id)`
- `create_wc_order($args)` — HPOS-compatible
- `assert_operation_succeeded()` / `assert_operation_failed()` / `assert_operation_failed_with_message($msg)`
- `assert_order_note_contains($order, $text)`
- `$this->lastError` for exception capture pattern

## Currency Gotcha
Tests involving `get_total()` or amounts MUST call `update_option('woocommerce_currency','TWD')` in set_up.

## E2E (Playwright)
- Located: `tests/e2e/` with own `package.json`
- Separate from PHPUnit suite
