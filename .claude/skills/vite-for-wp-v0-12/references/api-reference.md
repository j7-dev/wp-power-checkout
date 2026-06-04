# Vite for WordPress - Complete API Reference

> Source: https://github.com/kucrut/vite-for-wp v0.12.0

## Table of Contents

- [JavaScript API](#javascript-api)
  - [v4wp()](#v4wp)
  - [create_config() (DEPRECATED)](#create_config-deprecated)
  - [wp_scripts()](#wp_scripts)
  - [dev_server()](#dev_server)
  - [Utility Functions](#utility-functions)
- [PHP API (Kucrut\Vite namespace)](#php-api)
  - [enqueue_asset()](#enqueue_asset)
  - [register_asset()](#register_asset)
  - [get_manifest()](#get_manifest)
  - [filter_script_tag()](#filter_script_tag)
  - [set_script_type_attribute()](#set_script_type_attribute)
  - [generate_development_asset_src()](#generate_development_asset_src)
  - [register_vite_client_script()](#register_vite_client_script)
  - [inject_react_refresh_preamble_script()](#inject_react_refresh_preamble_script)
  - [load_development_asset()](#load_development_asset)
  - [load_production_asset()](#load_production_asset)
  - [register_stylesheets()](#register_stylesheets)
  - [parse_options()](#parse_options)
  - [prepare_asset_url()](#prepare_asset_url)
- [TypeScript Type Definitions](#typescript-type-definitions)
- [WordPress Filters](#wordpress-filters)
- [Constants](#constants)

---

## JavaScript API

### v4wp()

**Import**: `import { v4wp } from '@kucrut/vite-for-wp';`

**Signature**:
```ts
function v4wp(options?: V4wpOptions): PluginOption[];
```

**Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `options.input` | `string \| string[] \| Record<string, string>` | `'src/main.js'` | Entry point(s). See [Rollup input docs](https://rollupjs.org/configuration-options/#input). |
| `options.outDir` | `string` | `'dist'` | Output directory for built files. |

**Returns**: `PluginOption[]` - Array containing two plugins: `v4wp:config` (enforce: `'pre'`) and `v4wp:dev-server` (apply: `'serve'`).

**Behavior**: Sets the following Vite config defaults:
```js
{
  base: './',
  build: {
    outDir,              // from options
    emptyOutDir: true,
    manifest: 'manifest.json',
    modulePreload: false,
    rollupOptions: { input },  // from options
    sourcemap: config.build?.sourcemap ?? true,  // respects user override
  },
  css: {
    devSourcemap: true,
  },
}
```

**Example**:
```js
import { v4wp } from '@kucrut/vite-for-wp';

export default {
  plugins: [
    v4wp({
      input: 'js/src/main.ts',
      outDir: 'js/dist',
    }),
  ],
};
```

**Since**: 0.7.0

---

### create_config() (DEPRECATED)

**Import**: `import create_config from '@kucrut/vite-for-wp';`

**Signature**:
```ts
function create_config(
  input: Input,
  out_dir: string,
  extra_config?: UserConfig
): UserConfig;
```

**Status**: DEPRECATED since v0.7.0. Will be removed in v1.0. Use `v4wp()` instead.

Logs a deprecation warning via `createLogger().warnOnce()` on every call.

---

### wp_scripts()

**Import**: `import { wp_scripts } from '@kucrut/vite-for-wp/plugins';`

**Signature**:
```ts
async function wp_scripts(options?: WPScriptsOptions): Promise<PluginOption[]>;
```

**Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `options.extraScripts` | `Record<string, string>` | `{}` | Additional package-to-global mappings to externalize |

**Returns**: `Promise<PluginOption[]>` - Array of three plugins: `v4wp:wp-scripts` (apply: `'build'`), `external_globals`, and `externals`.

**Required peer dependencies** (install manually):
```bash
npm add -D rollup-plugin-external-globals vite-plugin-external
```

**Behavior**: Externalizes all WordPress-registered scripts from the build. In dev mode, the packages are loaded normally from node_modules (needed for HMR). In build mode, they become external references to WordPress globals. See `references/wp-scripts-externals.md` for the full list.

**Example with extra scripts**:
```js
wp_scripts({
  extraScripts: {
    'my-external-lib': 'MyExternalLib',
  },
})
```

**Since**: 0.7.0

---

### dev_server()

**Import**: `import { dev_server } from '@kucrut/vite-for-wp/plugins';`

**Signature**:
```ts
function dev_server(options?: DevServerOptions): Plugin<DevServerOptions>;
```

**Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `options.manifest_dir` | `string` | Value of `build.outDir` | Directory where `vite-dev-server.json` is written |

**Behavior**:
- Apply: `'serve'` only (not used during build)
- On `config`: Resolves host, chooses an available port (auto-increments if busy), configures HMR
- On `buildStart`: Creates `vite-dev-server.json` in the manifest directory
- On `buildEnd`: Deletes `vite-dev-server.json`

**Dev server manifest format** (`vite-dev-server.json`):
```json
{
  "base": "./",
  "origin": "http://localhost:5173",
  "port": 5173,
  "plugins": ["vite:react-refresh"]
}
```

Note: `dev_server()` is automatically included when using `v4wp()`. Only use it directly if you need custom manifest directory placement.

**Since**: 0.1.0 (options support since 0.8.0)

---

### Utility Functions

**Import**: `import { camel_case_dash, choose_port, wp_globals } from '@kucrut/vite-for-wp/utils';`

#### camel_case_dash()
```ts
function camel_case_dash(str: string): string;
```
Converts kebab-case to camelCase. Example: `'block-editor'` -> `'blockEditor'`.

#### choose_port()
```ts
function choose_port(options?: ChoosePortOptions): Promise<number>;

interface ChoosePortOptions {
  host?: string;  // Default: 'localhost'
  port?: number;  // Default: 5173
}
```
Finds an available port starting from the preferred port. Auto-increments if the port is in use.

#### wp_globals()
```ts
function wp_globals(): Record<string, string>;
```
Returns the complete mapping of npm package names to WordPress global variable names. See `references/wp-scripts-externals.md` for the full list.

---

## PHP API

All functions are in the `Kucrut\Vite` namespace. Use `use Kucrut\Vite;` then call as `Vite\function_name()`.

### enqueue_asset()

```php
function enqueue_asset(
    string $manifest_dir,
    string $entry,
    array  $options
): bool;
```

**Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| `$manifest_dir` | `string` | Absolute path to directory containing manifest file (usually the `outDir` from vite config) |
| `$entry` | `string` | Entry point path, must match the `input` value in vite config exactly |
| `$options` | `array` | Options array (see below) |

**Options**:

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `handle` | `string` | `''` | WordPress script handle |
| `dependencies` | `string[]` | `[]` | Script dependencies passed to `wp_register_script()` |
| `css-dependencies` | `string[]` | `[]` | Style dependencies passed to `wp_register_style()` |
| `css-media` | `string` | `'all'` | Media attribute for registered stylesheets |
| `css-only` | `bool` | `false` | When true, only CSS is loaded in production (no JS) |
| `in-footer` | `bool` | `false` | Whether to load script in footer |

**Returns**: `true` if assets were registered and enqueued successfully, `false` on failure.

**Behavior**:
1. Calls `register_asset()` to detect dev/production mode and register assets
2. Enqueues all registered script handles via `wp_enqueue_script()`
3. Enqueues all registered style handles via `wp_enqueue_style()`

**Since**: 0.1.0

---

### register_asset()

```php
function register_asset(
    string $manifest_dir,
    string $entry,
    array  $options
): ?array;
```

Same parameters as `enqueue_asset()`.

**Returns**: `array|null`
```php
[
    'scripts' => ['handle-1', 'handle-2'],  // Registered script handles
    'styles'  => ['handle-1-slug', ...],     // Registered style handles (production only)
]
```
Returns `null` on failure. In dev mode, `styles` contains only `css-dependencies` values (Vite handles CSS via HMR). In production mode, `styles` includes auto-generated handles for each CSS file in the manifest.

**Behavior**:
1. Calls `get_manifest()` to load and cache manifest data
2. If dev manifest found (`vite-dev-server.json`), calls `load_development_asset()`
3. If production manifest found (`manifest.json`), calls `load_production_asset()`
4. On exception (missing manifest), calls `wp_die()` in WP_DEBUG mode, returns `null` otherwise

**Since**: 0.1.0

---

### get_manifest()

```php
function get_manifest(string $manifest_dir): object;
```

**Returns**: Object with properties:
```php
(object) [
    'data'   => $manifest,    // Decoded JSON manifest data
    'dir'    => $manifest_dir, // Path to manifest directory
    'is_dev' => $is_dev,      // Boolean: true if dev manifest, false if production
]
```

**Behavior**:
- Checks for `vite-dev-server.json` first, then `manifest.json`
- Caches results in a static variable (no repeated file reads)
- Uses `wp_json_file_decode()` for decoding
- Throws `Exception` if no manifest found, file unreadable, or invalid JSON
- Applies filter `vite_for_wp__manifest_data`

**Since**: 0.1.0 (wp_json_file_decode since 0.8.0)

---

### filter_script_tag()

```php
function filter_script_tag(string $handle): void;
```

Registers a `script_loader_tag` filter callback that adds `type="module"` to the script tag with the given handle. Called internally by `load_development_asset()` and `load_production_asset()`.

---

### set_script_type_attribute()

```php
function set_script_type_attribute(
    string $target_handle,
    string $tag,
    string $handle,
    string $src
): string;
```

Uses `WP_HTML_Tag_Processor` to add `type="module"` attribute to a script tag. Only modifies the tag if `$target_handle === $handle` and the script's `src` attribute matches.

**Since**: 0.1.0 (WP_HTML_Tag_Processor since 0.8.0)

---

### generate_development_asset_src()

```php
function generate_development_asset_src(object $manifest, string $entry): string;
```

Constructs the full URL to a development asset: `{origin}/{base}/{entry}`. Normalizes double slashes. Used to generate URLs pointing to the Vite dev server.

---

### register_vite_client_script()

```php
function register_vite_client_script(object $manifest): void;
```

Registers the `@vite/client` script with handle `vite-client`. Only registers once (static check). Adds `type="module"` via `filter_script_tag()`. The client script enables HMR in the browser.

---

### inject_react_refresh_preamble_script()

```php
function inject_react_refresh_preamble_script(object $manifest): void;
```

Injects the React Fast Refresh preamble as an inline module script after the Vite client script. Only runs once. Only runs if `vite:react-refresh` is in the manifest's plugins array.

The injected script:
```js
import RefreshRuntime from "{origin}/@react-refresh";
RefreshRuntime.injectIntoGlobalHook(window);
window.$RefreshReg$ = () => {};
window.$RefreshSig$ = () => (type) => type;
window.__vite_plugin_react_preamble_installed__ = true;
```

**Since**: 0.8.0

---

### load_development_asset()

```php
function load_development_asset(
    object $manifest,
    string $entry,
    array  $options
): ?array;
```

Registers the Vite client script, injects React refresh preamble if needed, registers the entry point script pointing to the Vite dev server, and adds `type="module"` attribute. The Vite client handle is prepended to script dependencies.

Returns `['scripts' => [...], 'styles' => [...]]` or `null` if registration failed.

Applies filter `vite_for_wp__development_assets`.

---

### load_production_asset()

```php
function load_production_asset(
    object $manifest,
    string $entry,
    array  $options
): ?array;
```

Reads the production `manifest.json`, finds the entry point, registers the JS file with `wp_register_script()`. Recursively traverses the `imports` array to discover and register CSS files from code-split chunks. Also registers CSS files directly listed on the entry point.

If `css-only` option is true, skips script registration (only registers stylesheets).

In WP_DEBUG mode, calls `wp_die()` if the entry is not found in the manifest. Otherwise returns `null`.

Applies filter `vite_for_wp__production_assets`.

Style handles are auto-generated as `{handle}-{slug}` where `slug` is derived from the CSS filename.

---

### register_stylesheets()

```php
function register_stylesheets(
    array  &$assets,
    array  $stylesheets,
    string $url,
    array  $options
): void;
```

Registers CSS files as WordPress stylesheets. Handle format: `{options['handle']}-{sanitized-filename}`.

---

### parse_options()

```php
function parse_options(array $options): array;
```

Merges user options with defaults using `wp_parse_args()`. Default values:
```php
[
    'css-dependencies' => [],
    'css-media'        => 'all',
    'css-only'         => false,
    'dependencies'     => [],
    'handle'           => '',
    'in-footer'        => false,
]
```

---

### prepare_asset_url()

```php
function prepare_asset_url(string $dir): string;
```

Converts a filesystem path to a WordPress content URL. Normalizes Windows paths. Handles nested WordPress installations where wp-content might be in a subdirectory.

**Since**: 0.4.0 (Windows fix since 0.6.1)

---

## TypeScript Type Definitions

```ts
// Module: '@kucrut/vite-for-wp'
declare module '@kucrut/vite-for-wp' {
  import type { ResolvedConfig } from 'vite';

  type Input = ResolvedConfig['build']['rollupOptions']['input'];
  // Equivalent to: string | string[] | Record<string, string> | undefined

  interface V4wpOptions {
    input?: Input;
    outDir?: ResolvedConfig['build']['outDir']; // string
  }

  function v4wp(options?: V4wpOptions): PluginOption[];
  function create_config(input: Input, out_dir: string, extra_config?: UserConfig): UserConfig; // DEPRECATED
}

// Module: '@kucrut/vite-for-wp/plugins'
declare module '@kucrut/vite-for-wp/plugins' {
  interface DevServerOptions {
    manifest_dir?: string;
  }

  type WPScriptsOptions = {
    extraScripts?: Record<string, string>;
  };

  function dev_server(options?: DevServerOptions): Plugin<DevServerOptions>;
  function wp_scripts(options?: WPScriptsOptions): Promise<PluginOption[]>;
}

// Module: '@kucrut/vite-for-wp/utils'
declare module '@kucrut/vite-for-wp/utils' {
  interface ChoosePortOptions {
    host?: string;  // Default: 'localhost'
    port?: number;  // Default: 5173
  }

  function camel_case_dash(str: string): string;
  function choose_port(options?: ChoosePortOptions): Promise<number>;
  function wp_globals(): Record<string, string>;
}
```

---

## WordPress Filters

### vite_for_wp__manifest_data

```php
apply_filters('vite_for_wp__manifest_data', object $manifest, string $manifest_dir, string $manifest_path): object;
```

Filter manifest data after loading from file. Allows modifying the manifest before it's cached and used.

### vite_for_wp__development_assets

```php
apply_filters('vite_for_wp__development_assets', array $assets, object $manifest, string $entry, array $options): array;
```

Filter registered development assets. `$assets` has keys `scripts` (string[]) and `styles` (string[]).

### vite_for_wp__production_assets

```php
apply_filters('vite_for_wp__production_assets', array $assets, object $manifest, string $entry, array $options): array;
```

Filter registered production assets. Same structure as development assets filter.

---

## Constants

```php
const Kucrut\Vite\VITE_CLIENT_SCRIPT_HANDLE = 'vite-client';
```

Handle used for the Vite client script registration in development mode.
