# Task Completion Commands

## After coding changes
1. PHPStan: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "php -d memory_limit=2G vendor/bin/phpstan analyse"`
2. Tests: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit"`
3. Lint: `composer lint` (PHPCS only) or `pnpm lint` (ESLint + PHPCBF)
4. Build: `pnpm build && pnpm build:blocks` (if frontend changed)

## Pre-existing known failures (do NOT count as regressions)
- 1 ezpay edge test failure
- 1 RedirectSettingsDTO failure
Total: 2 pre-existing failures — final gate should show exactly these 2, not more.
