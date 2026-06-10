# Suggested Commands (Windows / wp-env)

## Run tests (requires wp-env)
```bash
# All integration tests (API_MODE=mock)
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit"

# Single class
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit --filter ClassName"

# Invoice tests only
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/"

# B-Cycle 2 Red Gate
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Invoice/ --filter 'PaynowInvoice(Provider|Register)'"
```

## PHPStan
```bash
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "php -d memory_limit=2G vendor/bin/phpstan analyse"
```

## Lint / Build
```bash
pnpm lint          # ESLint + PHPCBF
pnpm build         # Vue app
pnpm build:blocks  # React WC Blocks
```

## composer test (direct, needs local WP DB)
```bash
composer test        # API_MODE=mock
composer test:sandbox
```
