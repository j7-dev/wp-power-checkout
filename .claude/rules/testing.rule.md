---
globs:
  - "tests/Integration/**/*.php"
  - "tests/e2e/**/*.ts"
---

# Testing Rules

## PHP Unit Tests

### Infrastructure

- Active test directory: `tests/Integration/` — this is the authoritative suite used by `composer test`
- Base class: `Tests\Integration\TestCase` (extends `WP_UnitTestCase`)
- Namespace: `Tests\Integration\`
- Bootstrap: `tests/bootstrap.php`; DB config: `tests/wp-tests-config.php`
- Config file: `phpunit.xml.dist` (note: **not** `phpunit.xml`)
- **Group whitelist** (phpunit.xml.dist `<groups><include>`): only `smoke` / `happy` / `error` / `edge` / `security` are collected — every test must carry at least one of these annotations to be executed
- Additional classification groups used alongside: `integration`, `invoice`, provider name (e.g. `ezpay`)
- Legacy directory `inc/tests/` is **not** referenced by the active config; do not add new tests there

### API Mode

Tests run in one of three modes controlled by `API_MODE` env var:

| Mode | Behavior |
|---|---|
| `mock` | No external API calls, returns mocked responses |
| `sandbox` | Calls sandbox/test API endpoints |
| `prod` | Calls production API endpoints |

```bash
composer test              # API_MODE=mock (default, safe for CI)
composer test:sandbox      # API_MODE=sandbox
composer test:prod         # API_MODE=prod (use with caution)
```

### Running Single Tests

```bash
vendor/bin/phpunit --filter ClassName
vendor/bin/phpunit --filter "test_method_name"
vendor/bin/phpunit --filter "ClassName::test_method_name"
```

## E2E Tests (Playwright)

### Structure

```
tests/e2e/
  01-admin/          # Admin-side tests (settings, invoices, refunds, webhook)
  02-frontend/       # Frontend tests (checkout page, payment selection, invoice form)
  03-integration/    # Cross-cutting tests (data boundary, edge cases, security)
  fixtures/          # Test data (test-data.ts)
  helpers/           # Shared helpers
    api-client.ts    # REST API client for test setup
    webhook-hmac.ts  # HMAC signature generation for webhook tests
    admin-setup.ts   # Admin page navigation helpers
    lc-bypass.ts     # License check bypass
  global-setup.ts    # Test environment initialization
  global-teardown.ts # Cleanup
```

### Running E2E

E2E tests have their own `package.json` in `tests/e2e/`:

```bash
cd tests/e2e
npm install
npx playwright test
npx playwright test --grep "settings"
```

Config: `tests/e2e/playwright.config.ts`

### Webhook Test Pattern

Webhook tests generate valid HMAC signatures using `helpers/webhook-hmac.ts`:

```typescript
// Generate signature for webhook payload verification
import { generateHmacSignature } from '../helpers/webhook-hmac'
```
