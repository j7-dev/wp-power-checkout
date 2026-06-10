# Tech Stack

## PHP Backend
- PHP 8.1+; WooCommerce; WordPress; wp-utils (j7-dev/wp-utils)
- PHPUnit 9 + wp-phpunit + yoast/phpunit-polyfills
- PHPStan level 9; PHPCS (WordPress coding standards)
- Test base: `tests/Integration/TestCase extends WP_UnitTestCase`
- Config: `phpunit.xml.dist` (not phpunit.xml); bootstrap `tests/bootstrap.php`
- API_MODE env: mock (default, CI safe) / sandbox / prod

## Frontend
- Vue 3 (`js/src/`) — Vite + Element Plus + TanStack Vue Query + Vue Router (memory mode)
- React (`inc/assets/blocks/`) — WC Blocks payment method registration only
- pnpm; build: `pnpm build` (Vue) / `pnpm build:blocks` (React)

## Test patterns
- `set_up()` → `parent::set_up()` + reset SettingsDTO singleton + `enable_provider(id, settings)`
- `tear_down()` → reset singleton + `delete_option(ProviderUtils::get_option_name(id))` + `parent::tear_down()`
- Reset SettingsDTO singleton via ReflectionClass on `$instance` property
- Mock HTTP: `add_filter('pre_http_request', ...)` — verify no real API calls
- Provider in container: `ProviderUtils::$container[$id] = Provider::instance()`
- `create_wc_order(['status','total'])` + add product + `set_total` (TWD guard)
