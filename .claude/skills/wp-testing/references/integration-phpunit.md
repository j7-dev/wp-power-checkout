# Integration Test Reference (PHPUnit + WP_UnitTestCase + wp-env)

> WordPress Plugin Integration Testing 完整參考。涵蓋 PHPUnit 9 + wp-env + WP_UnitTestCase 設定、配置範本、測試 patterns、CI workflow。
>
> **PHP 8.1** | **PHPUnit ^9.6** | **wp-env (Docker)** | **WP_UnitTestCase**

---

## 目錄

- [Testing Strategy](#testing-strategy)
- [Required Packages](#required-packages)
- [Setup Guide](#setup-guide)
  - [.wp-env.json](#wp-envjson)
  - [phpunit.xml.dist](#phpunitxmldist)
  - [tests/bootstrap.php](#testsbootstrapphp)
  - [Project Structure](#project-structure)
- [WP_UnitTestCase API Cheat Sheet](#wp_unittestcase-api-cheat-sheet)
- [Test Patterns](#test-patterns)
- [wp-env Quick Commands](#wp-env-quick-commands)
- [package.json Scripts](#packagejson-scripts)
- [First-Time Setup (wp-env)](#first-time-setup-wp-env)
- [GitHub Actions CI](#github-actions-ci)
- [Key Gotchas](#key-gotchas)

---

## Testing Strategy

| Layer | Tool | Purpose | When |
|-------|------|---------|------|
| Static Analysis | PHPStan | Type safety, logic consistency | Every commit |
| Integration Test | PHPUnit + wp-env | WP hooks, DB, API logic | Local dev / CI |
| E2E Test | Playwright | Critical UI flows only | CI only（見 `e2e-playwright.md`） |

---

## Required Packages

**Environment**: PHP 8.1, Node.js 18+, Docker Desktop

| Package | Version | Purpose |
|---------|---------|---------|
| `phpunit/phpunit` | `^9.6` | Test framework |
| `yoast/phpunit-polyfills` | `^1.0` | PHPUnit cross-version compat |
| `wp-phpunit/wp-phpunit` | `^6.3` | WP test env (`WP_UnitTestCase`) |
| `yoast/wp-test-utils` | `^1.0` | WP integration test bootstrap |
| `@wordpress/env` | latest (npm) | Docker container with WP + MySQL |

```bash
# PHP dependencies
composer require --dev \
  phpunit/phpunit:^9.6 \
  yoast/phpunit-polyfills:^1.0 \
  wp-phpunit/wp-phpunit:^6.3 \
  yoast/wp-test-utils:^1.0

# WP test environment (Docker)
npm install --save-dev @wordpress/env
```

### Why PHPUnit 9 and not 10?

PHPUnit 10 requires PHP 8.1 minimum, but `yoast/phpunit-polyfills` on PHP 8.1 skips PHPUnit 10 and falls back to PHPUnit 9. The entire WordPress ecosystem on PHP 8.1 runs PHPUnit 9. Using `^9.6` is the most stable choice.

---

## Setup Guide

### .wp-env.json

```json
{
  "core": null,
  "plugins": [
    "."
  ],
  "config": {
    "WP_DEBUG": true,
    "SCRIPT_DEBUG": true
  },
  "env": {
    "tests": {
      "config": {
        "WP_DEBUG": true
      }
    }
  }
}
```

**Key fields:**

| Field | Description |
|-------|-------------|
| `"core": null` | Use latest WP core |
| `"plugins": ["."]` | Mount current directory as plugin |
| `"env.tests"` | Separate config for test environment |

### phpunit.xml.dist

```xml
<?xml version="1.0"?>
<phpunit
    bootstrap="tests/bootstrap.php"
    backupGlobals="false"
    colors="true"
    convertErrorsToExceptions="true"
    convertNoticesToExceptions="true"
    convertWarningsToExceptions="true"
>
    <testsuites>
        <testsuite name="integration">
            <directory suffix="Test.php">./tests/integration/</directory>
        </testsuite>
    </testsuites>

    <php>
        <env name="WP_PHPUNIT__TESTS_CONFIG" value="tests/wp-tests-config.php"/>
    </php>
</phpunit>
```

**Notes:**
- `convertErrorsToExceptions`, `convertNoticesToExceptions`, `convertWarningsToExceptions` are PHPUnit 9 attributes (removed in PHPUnit 10)
- Test files must end with `Test.php` suffix
- `bootstrap` points to the test bootstrap file

### tests/bootstrap.php

```php
<?php
/**
 * PHPUnit bootstrap file for integration tests.
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Get WP tests path.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Verify WP test suite exists.
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
    echo "Could not find {$_tests_dir}/includes/functions.php\n";
    exit( 1 );
}

// Set Polyfills path.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// Load WP test functions.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Activate plugin during WP load.
 */
function _manually_load_plugin() {
    require dirname( __DIR__ ) . '/my-plugin.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start WP test suite.
require "{$_tests_dir}/includes/bootstrap.php";
```

**Bootstrap load order (critical):**
1. Composer autoloader
2. Resolve `WP_TESTS_DIR` path
3. Verify WP test suite files exist
4. Define `WP_TESTS_PHPUNIT_POLYFILLS_PATH`
5. Load WP test functions (`functions.php`)
6. Register plugin via `muplugins_loaded` hook
7. Load WP test bootstrap (`bootstrap.php`)

> Changing this order will cause cryptic failures.

### Project Structure

```
my-plugin/
├── .wp-env.json                  # wp-env config
├── composer.json
├── package.json
├── phpunit.xml.dist              # PHPUnit config
├── src/                          # Plugin source code
│   └── ...
├── my-plugin.php                 # Plugin entry file
└── tests/
    ├── bootstrap.php             # Test bootstrap
    └── integration/              # Integration tests
        ├── ExampleTest.php
        └── ...
```

---

## WP_UnitTestCase API Cheat Sheet

### Factory Methods

| Method | Purpose |
|--------|---------|
| `$this->factory()->post->create( $args )` | Create test post |
| `$this->factory()->user->create( $args )` | Create test user |
| `$this->factory()->term->create( $args )` | Create test term |
| `$this->factory()->comment->create( $args )` | Create test comment |
| `$this->factory()->attachment->create( $args )` | Create test attachment |
| `$this->factory()->post->create_many( $count, $args )` | Create N posts |

### Testing Hooks

```php
// Check action registered
$this->assertNotFalse( has_action( 'init', 'my_callback' ) );

// Check filter registered
$this->assertNotFalse( has_filter( 'the_content', 'my_filter' ) );

// Check action fired
$this->assertGreaterThan( 0, did_action( 'my_custom_action' ) );

// Test filter output
$result = apply_filters( 'my_filter', 'input_value' );
$this->assertSame( 'expected_output', $result );
```

### Testing REST API

```php
$request  = new WP_REST_Request( 'GET', '/my-plugin/v1/items' );
$response = rest_do_request( $request );

$this->assertSame( 200, $response->get_status() );
$this->assertIsArray( $response->get_data() );
```

### Testing Post Meta

```php
$post_id = $this->factory()->post->create();
update_post_meta( $post_id, '_my_key', 'test_value' );
$value = get_post_meta( $post_id, '_my_key', true );
$this->assertSame( 'test_value', $value );
```

### Testing User Capabilities

```php
$user_id = $this->factory()->user->create( [ 'role' => 'editor' ] );
wp_set_current_user( $user_id );
$this->assertTrue( current_user_can( 'edit_posts' ) );
$this->assertFalse( current_user_can( 'manage_options' ) );
```

### Testing Shortcodes

```php
$output = do_shortcode( '[my_shortcode]' );
$this->assertStringContainsString( '<div', $output );
$this->assertStringNotContainsString( '[my_shortcode]', $output );
```

---

## Test Patterns

> Complete test examples for common WordPress plugin testing scenarios.
> All examples extend `WP_UnitTestCase`. DB is auto-rolled-back after each test.

### Plugin Activation

```php
public function test_plugin_is_active(): void {
    $this->assertTrue( is_plugin_active( 'my-plugin/my-plugin.php' ) );
}
```

### Custom Post Type

```php
public function test_custom_post_type_exists(): void {
    $this->assertTrue( post_type_exists( 'my_custom_type' ) );
}

public function test_post_type_supports(): void {
    $this->assertTrue( post_type_supports( 'my_custom_type', 'title' ) );
    $this->assertTrue( post_type_supports( 'my_custom_type', 'editor' ) );
    $this->assertFalse( post_type_supports( 'my_custom_type', 'comments' ) );
}
```

### Post CRUD & Factory

```php
// Create single post
public function test_can_create_post(): void {
    $post_id = $this->factory()->post->create( [
        'post_title'  => 'Test Post',
        'post_status' => 'publish',
        'post_type'   => 'post',
    ] );

    $post = get_post( $post_id );

    $this->assertInstanceOf( WP_Post::class, $post );
    $this->assertSame( 'Test Post', $post->post_title );
}

// Create multiple posts
public function test_bulk_create(): void {
    $post_ids = $this->factory()->post->create_many( 5, [
        'post_status' => 'publish',
    ] );

    $this->assertCount( 5, $post_ids );
}

// Create and get object directly
public function test_create_and_get(): void {
    $post = $this->factory()->post->create_and_get( [
        'post_title' => 'Direct Object',
    ] );

    $this->assertInstanceOf( WP_Post::class, $post );
    $this->assertSame( 'Direct Object', $post->post_title );
}
```

### Post Meta

```php
public function test_post_meta_crud(): void {
    $post_id = $this->factory()->post->create();

    // Create
    update_post_meta( $post_id, '_my_plugin_key', 'test_value' );

    // Read
    $value = get_post_meta( $post_id, '_my_plugin_key', true );
    $this->assertSame( 'test_value', $value );

    // Update
    update_post_meta( $post_id, '_my_plugin_key', 'new_value' );
    $this->assertSame( 'new_value', get_post_meta( $post_id, '_my_plugin_key', true ) );

    // Delete
    delete_post_meta( $post_id, '_my_plugin_key' );
    $this->assertEmpty( get_post_meta( $post_id, '_my_plugin_key', true ) );
}

public function test_serialized_meta(): void {
    $post_id = $this->factory()->post->create();
    $data    = [ 'key1' => 'value1', 'key2' => 42 ];

    update_post_meta( $post_id, '_my_array', $data );
    $result = get_post_meta( $post_id, '_my_array', true );

    $this->assertIsArray( $result );
    $this->assertSame( 'value1', $result['key1'] );
    $this->assertSame( 42, $result['key2'] );
}
```

### Hooks — Actions

```php
// Verify action is registered
public function test_action_registered(): void {
    $this->assertNotFalse(
        has_action( 'init', 'my_plugin_register_post_type' )
    );
}

// Verify action priority
public function test_action_priority(): void {
    $priority = has_action( 'init', 'my_plugin_register_post_type' );
    $this->assertSame( 10, $priority ); // default priority
}

// Verify action was fired
public function test_action_fired(): void {
    do_action( 'my_plugin_custom_action' );
    $this->assertGreaterThan( 0, did_action( 'my_plugin_custom_action' ) );
}

// Count action executions
public function test_action_count(): void {
    do_action( 'my_action' );
    do_action( 'my_action' );
    $this->assertSame( 2, did_action( 'my_action' ) );
}
```

### Hooks — Filters

```php
// Verify filter is registered
public function test_filter_registered(): void {
    $this->assertNotFalse(
        has_filter( 'the_content', 'my_plugin_filter_content' )
    );
}

// Test filter output
public function test_filter_modifies_content(): void {
    $input  = 'Original content';
    $output = apply_filters( 'my_plugin_filter', $input );

    $this->assertNotSame( $input, $output );
    $this->assertStringContainsString( 'expected', $output );
}

// Test filter with multiple args
public function test_filter_with_args(): void {
    $result = apply_filters( 'my_filter', 'value', 'arg2', 'arg3' );
    $this->assertSame( 'expected_result', $result );
}
```

### Shortcodes

```php
public function test_shortcode_registered(): void {
    $this->assertTrue( shortcode_exists( 'my_shortcode' ) );
}

public function test_shortcode_output(): void {
    $output = do_shortcode( '[my_shortcode]' );

    $this->assertStringContainsString( '<div', $output );
    // Verify shortcode was processed (not returned raw)
    $this->assertStringNotContainsString( '[my_shortcode]', $output );
}

public function test_shortcode_with_attributes(): void {
    $output = do_shortcode( '[my_shortcode color="red" size="large"]' );

    $this->assertStringContainsString( 'red', $output );
    $this->assertStringContainsString( 'large', $output );
}

public function test_shortcode_with_content(): void {
    $output = do_shortcode( '[my_shortcode]Inner content[/my_shortcode]' );

    $this->assertStringContainsString( 'Inner content', $output );
}
```

### REST API Endpoints

```php
public function test_rest_endpoint_registered(): void {
    $routes = rest_get_server()->get_routes();
    $this->assertArrayHasKey( '/my-plugin/v1/items', $routes );
}

public function test_rest_get_items(): void {
    $request  = new WP_REST_Request( 'GET', '/my-plugin/v1/items' );
    $response = rest_do_request( $request );

    $this->assertSame( 200, $response->get_status() );
    $this->assertIsArray( $response->get_data() );
}

public function test_rest_create_item(): void {
    // Authenticate as admin
    wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

    $request = new WP_REST_Request( 'POST', '/my-plugin/v1/items' );
    $request->set_body_params( [
        'title' => 'New Item',
        'status' => 'active',
    ] );

    $response = rest_do_request( $request );
    $this->assertSame( 201, $response->get_status() );
}

public function test_rest_unauthorized(): void {
    // No user set — should fail auth
    $request  = new WP_REST_Request( 'POST', '/my-plugin/v1/items' );
    $response = rest_do_request( $request );

    $this->assertSame( 401, $response->get_status() );
}

public function test_rest_with_query_params(): void {
    $request = new WP_REST_Request( 'GET', '/my-plugin/v1/items' );
    $request->set_query_params( [
        'per_page' => 5,
        'page'     => 1,
        'status'   => 'active',
    ] );

    $response = rest_do_request( $request );
    $data     = $response->get_data();

    $this->assertSame( 200, $response->get_status() );
    $this->assertLessThanOrEqual( 5, count( $data ) );
}
```

### User Capabilities

```php
public function test_editor_capabilities(): void {
    $user_id = $this->factory()->user->create( [ 'role' => 'editor' ] );
    wp_set_current_user( $user_id );

    $this->assertTrue( current_user_can( 'edit_posts' ) );
    $this->assertTrue( current_user_can( 'edit_others_posts' ) );
    $this->assertFalse( current_user_can( 'manage_options' ) );
}

public function test_custom_capability(): void {
    $role = get_role( 'administrator' );
    $role->add_cap( 'my_plugin_manage' );

    $admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
    wp_set_current_user( $admin_id );

    $this->assertTrue( current_user_can( 'my_plugin_manage' ) );

    // Cleanup
    $role->remove_cap( 'my_plugin_manage' );
}

public function test_subscriber_restrictions(): void {
    $user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
    wp_set_current_user( $user_id );

    $this->assertTrue( current_user_can( 'read' ) );
    $this->assertFalse( current_user_can( 'edit_posts' ) );
    $this->assertFalse( current_user_can( 'publish_posts' ) );
}
```

### Taxonomy & Terms

```php
public function test_custom_taxonomy_exists(): void {
    $this->assertTrue( taxonomy_exists( 'my_taxonomy' ) );
}

public function test_term_creation(): void {
    $term_id = $this->factory()->term->create( [
        'taxonomy' => 'category',
        'name'     => 'Test Category',
    ] );

    $term = get_term( $term_id );
    $this->assertSame( 'Test Category', $term->name );
}

public function test_post_term_relationship(): void {
    $post_id = $this->factory()->post->create();
    $term_id = $this->factory()->term->create( [ 'taxonomy' => 'category' ] );

    wp_set_post_terms( $post_id, [ $term_id ], 'category' );

    $terms = wp_get_post_terms( $post_id, 'category', [ 'fields' => 'ids' ] );
    $this->assertContains( $term_id, $terms );
}
```

### Options API

```php
public function test_option_crud(): void {
    // Create
    add_option( 'my_plugin_setting', 'default_value' );
    $this->assertSame( 'default_value', get_option( 'my_plugin_setting' ) );

    // Update
    update_option( 'my_plugin_setting', 'new_value' );
    $this->assertSame( 'new_value', get_option( 'my_plugin_setting' ) );

    // Delete
    delete_option( 'my_plugin_setting' );
    $this->assertFalse( get_option( 'my_plugin_setting' ) );
}

public function test_option_default_value(): void {
    $this->assertSame(
        'fallback',
        get_option( 'nonexistent_option', 'fallback' )
    );
}
```

### Transients

```php
public function test_transient_crud(): void {
    set_transient( 'my_cache', 'cached_data', HOUR_IN_SECONDS );

    $this->assertSame( 'cached_data', get_transient( 'my_cache' ) );

    delete_transient( 'my_cache' );
    $this->assertFalse( get_transient( 'my_cache' ) );
}
```

### WP_UnitTestCase Full API Reference

#### Factory Methods

| Factory | `create( $args )` | `create_many( $count, $args )` | `create_and_get( $args )` |
|---------|----|----|----|
| `$this->factory()->post` | Returns post ID | Returns array of IDs | Returns `WP_Post` |
| `$this->factory()->user` | Returns user ID | Returns array of IDs | Returns `WP_User` |
| `$this->factory()->term` | Returns term ID | Returns array of IDs | Returns `WP_Term` |
| `$this->factory()->comment` | Returns comment ID | Returns array of IDs | Returns comment object |
| `$this->factory()->attachment` | Returns attachment ID | Returns array of IDs | Returns `WP_Post` |
| `$this->factory()->category` | Returns term ID | Returns array of IDs | Returns `WP_Term` |
| `$this->factory()->tag` | Returns term ID | Returns array of IDs | Returns `WP_Term` |

#### Useful WP Test Functions

| Function | Purpose |
|----------|---------|
| `wp_set_current_user( $id )` | Switch current user |
| `do_shortcode( '[tag]' )` | Test shortcode output |
| `rest_do_request( $request )` | Test REST API |
| `apply_filters( 'hook', $value )` | Test filter result |
| `did_action( 'hook' )` | Check if action was fired |
| `has_action( 'hook', 'callback' )` | Check if action is registered |
| `has_filter( 'hook', 'callback' )` | Check if filter is registered |
| `is_plugin_active( 'dir/file.php' )` | Check plugin active status |
| `post_type_exists( 'type' )` | Check CPT registration |
| `taxonomy_exists( 'taxonomy' )` | Check taxonomy registration |
| `shortcode_exists( 'tag' )` | Check shortcode registration |

#### DB Auto-Rollback

`WP_UnitTestCase` wraps each test in a database transaction and rolls back after completion. No manual cleanup of test data is needed.

---

## wp-env Quick Commands

### Environment Management

```bash
npx wp-env start                    # Start Docker environment
npx wp-env stop                     # Stop environment
npx wp-env destroy                  # Remove containers and data
npx wp-env clean all                # Reset to fresh state
```

### Running Tests

```bash
# Run all integration tests
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  vendor/bin/phpunit

# Run specific test file
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  vendor/bin/phpunit --filter=ExampleTest

# Run specific test method
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  vendor/bin/phpunit --filter=test_plugin_is_active

# Run with verbose output
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  vendor/bin/phpunit --verbose
```

### WP-CLI Inside Container

```bash
# Run WP-CLI commands inside the container
npx wp-env run cli wp plugin list
npx wp-env run cli wp option get siteurl
npx wp-env run cli wp user list
```

**Important:** `--env-cwd=wp-content/plugins/my-plugin` must match the actual plugin directory name inside the container.

---

## package.json Scripts

```json
{
  "scripts": {
    "wp-env": "wp-env",
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "test:integration": "wp-env run cli --env-cwd=wp-content/plugins/my-plugin vendor/bin/phpunit",
    "test:filter": "wp-env run cli --env-cwd=wp-content/plugins/my-plugin vendor/bin/phpunit --filter"
  }
}
```

Usage:

```bash
npm run env:start
npm run test:integration
npm run test:filter -- ExampleTest
npm run env:stop
```

---

## First-Time Setup (wp-env)

Run these commands once after initial `wp-env start`:

```bash
# 1. Start environment
npx wp-env start

# 2. Scaffold test files
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  wp scaffold plugin-tests my-plugin

# 3. Install test database
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  bash bin/install-wp-tests.sh wordpress_test root password mysql

# 4. Run tests
npx wp-env run cli --env-cwd=wp-content/plugins/my-plugin \
  vendor/bin/phpunit
```

**`install-wp-tests.sh` arguments:**

| Arg | Value | Description |
|-----|-------|-------------|
| 1 | `wordpress_test` | Test database name |
| 2 | `root` | MySQL user |
| 3 | `password` | MySQL password |
| 4 | `mysql` | MySQL host (container name) |

---

## GitHub Actions CI

```yaml
name: Integration Tests

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  integration-tests:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wordpress_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
          extensions: mysqli, intl
          tools: composer

      - name: Install Composer dependencies
        run: composer install --no-progress --prefer-dist

      - name: Install WP Test Suite
        run: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest
        env:
          WP_TESTS_DIR: /tmp/wordpress-tests-lib

      - name: Run integration tests
        run: vendor/bin/phpunit
        env:
          WP_TESTS_DIR: /tmp/wordpress-tests-lib
```

### CI Notes

- **MySQL service**: Runs alongside the test runner, not inside Docker like wp-env
- **`install-wp-tests.sh`**: Downloads WP test suite to `/tmp/wordpress-tests-lib`
- **MySQL host**: Use `127.0.0.1` (not `localhost` or `mysql`) in GitHub Actions
- **PHP extensions**: `mysqli` is required for database connection, `intl` for i18n functions
- **No wp-env in CI**: GitHub Actions uses direct MySQL service instead of Docker-in-Docker

### Adding PHP Version Matrix

```yaml
    strategy:
      matrix:
        php-version: ['8.1', '8.2', '8.3']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php-version }}
          extensions: mysqli, intl
          tools: composer
      # ... rest of steps
```

---

## Key Gotchas

1. **DB auto-rollback**: `WP_UnitTestCase` rolls back DB transactions after each test — no manual cleanup needed
2. **Bootstrap order matters**: Load composer autoloader → set polyfills path → load WP test functions → register plugin → load WP test bootstrap
3. **PHPUnit 9 config attributes**: Use `convertErrorsToExceptions`, `convertNoticesToExceptions`, `convertWarningsToExceptions` (removed in PHPUnit 10)
4. **wp-env plugin path**: `--env-cwd=wp-content/plugins/my-plugin` must match the actual plugin directory name inside the container
5. **First-time setup**: Run `wp scaffold plugin-tests` and `bash bin/install-wp-tests.sh` before first test run
