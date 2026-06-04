---
name: vite-for-wp-v0-12
user-invocable: false
description: >
  Vite for WordPress (kucrut/vite-for-wp, @kucrut/vite-for-wp) complete technical reference.
  Covers the v4wp() Vite plugin, Vite\enqueue_asset() / Vite\register_asset() PHP functions,
  wp_scripts() plugin for externalising WordPress-registered scripts, dev server HMR setup,
  production manifest.json handling, React/Svelte/Vue integration, and multi-entry point configuration.
  Use this skill whenever the task involves Vite integration in a WordPress plugin or theme,
  including vite.config.js/ts configuration for WordPress, enqueueing Vite-built assets via PHP,
  HMR dev server for WordPress, wp_enqueue_script with Vite, or any mention of @kucrut/vite-for-wp,
  kucrut/vite-for-wp, v4wp, Kucrut\Vite namespace, vite-dev-server.json, or wp_scripts() plugin.
  This skill should be preferred over web search for all vite-for-wp related questions.
---

# Vite for WordPress (vite-for-wp) v0.12.0

> **Package**: `@kucrut/vite-for-wp` (npm) + `kucrut/vite-for-wp` (composer)
> **Repo**: https://github.com/kucrut/vite-for-wp
> **License**: GPL v2 (PHP) / MIT (JS)
> **Vite compatibility**: Vite 7.x (v0.12.0), Vite 6.x (v0.10.0), Vite 5.x (v0.9.0)

Vite integration for WordPress plugins and themes. Provides a Vite plugin (`v4wp`) that handles build configuration and dev server manifest generation, plus a PHP helper (`Kucrut\Vite` namespace) that automatically enqueues the correct assets based on dev/production mode.

## Architecture Overview

```
Development mode:
  npm run dev -> Vite dev server starts -> writes vite-dev-server.json to outDir
  PHP detects vite-dev-server.json -> serves assets from Vite dev server with HMR

Production mode:
  npm run build -> Vite builds to outDir -> writes manifest.json
  PHP reads manifest.json -> enqueues hashed asset files with wp_enqueue_script/style
```

## Core API Quick Reference

### JS: v4wp() - Vite Plugin

```ts
import { v4wp } from '@kucrut/vite-for-wp';

// Signature
function v4wp(options?: V4wpOptions): PluginOption[];

interface V4wpOptions {
  input?: string | string[] | Record<string, string>; // Default: 'src/main.js'
  outDir?: string;                                      // Default: 'dist'
}
```

Internally sets: `base: './'`, `build.manifest: 'manifest.json'`, `build.modulePreload: false`, `build.emptyOutDir: true`, `build.sourcemap: true` (respects user override), `css.devSourcemap: true`.

### JS: wp_scripts() - Externalize WP Scripts

```ts
import { wp_scripts } from '@kucrut/vite-for-wp/plugins';

// Signature
async function wp_scripts(options?: WPScriptsOptions): Promise<PluginOption[]>;

interface WPScriptsOptions {
  extraScripts?: Record<string, string>; // Additional scripts to externalize
}
```

Requires peer deps: `rollup-plugin-external-globals`, `vite-plugin-external`.

Pre-configured externals include: `react`, `react-dom`, `react/jsx-runtime`, `jquery`, `lodash`, `moment`, `backbone`, `tinymce`, and all `@wordpress/*` packages (60+ modules).

### PHP: Vite\enqueue_asset()

```php
use Kucrut\Vite;

// Signature
function Vite\enqueue_asset(
    string $manifest_dir,  // Absolute path to dir containing manifest (e.g., __DIR__ . '/js/dist')
    string $entry,         // Entry point path matching vite config input (e.g., 'js/src/main.tsx')
    array  $options        // Options array (see below)
): bool;                   // Returns true on success, false on failure
```

**Options array:**

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `handle` | `string` | `''` | WordPress script handle identifier |
| `dependencies` | `string[]` | `[]` | Script dependencies (e.g., `['react', 'react-dom', 'wp-element']`) |
| `css-dependencies` | `string[]` | `[]` | Style dependencies |
| `css-media` | `string` | `'all'` | CSS media attribute |
| `css-only` | `bool` | `false` | If true, only load style assets in production (skip JS) |
| `in-footer` | `bool` | `false` | Load script in footer |

### PHP: Vite\register_asset()

Same signature as `enqueue_asset()`. Returns `array|null` with keys `scripts` (string[]) and `styles` (string[]) containing registered handles. Use when you need to conditionally enqueue later via `wp_enqueue_script()` / `wp_enqueue_style()`.

**Important**: Style assets are only registered in production mode. In dev mode, Vite loads CSS automatically via HMR.

## Minimal Setup (Single Entry)

**vite.config.js:**
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

**plugin.php:**
```php
<?php
use Kucrut\Vite;

add_action('wp_enqueue_scripts', function (): void {
    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/main.ts',
        [
            'handle'    => 'my-plugin-script',
            'in-footer' => true,
        ]
    );
});
```

**package.json scripts:**
```json
{
  "scripts": {
    "dev": "vite",
    "build": "vite build"
  }
}
```

## React Integration Setup

```bash
npm add -D vite @kucrut/vite-for-wp @vitejs/plugin-react react react-dom rollup-plugin-external-globals vite-plugin-external
```

```js
// vite.config.js
import { v4wp } from '@kucrut/vite-for-wp';
import { wp_scripts } from '@kucrut/vite-for-wp/plugins';
import react from '@vitejs/plugin-react';

export default {
  plugins: [
    v4wp({ input: 'js/src/main.jsx', outDir: 'js/dist' }),
    wp_scripts(),
    react({ jsxRuntime: 'classic' }),
  ],
};
```

```php
// PHP enqueue - react and react-dom MUST be in dependencies
Vite\enqueue_asset(
    __DIR__ . '/js/dist',
    'js/src/main.jsx',
    [
        'dependencies' => ['react', 'react-dom'],
        'handle'       => 'my-react-app',
        'in-footer'    => true,
    ]
);
```

**Critical**: Even though `wp_scripts()` externalizes react/react-dom from the build, you must still install them as devDependencies (needed by `@vitejs/plugin-react`) AND list them in the PHP `dependencies` array (so WordPress loads them before your script).

## Common Patterns and Gotchas

1. **Multiple entry points**: Each entry point requires a separate `Vite\enqueue_asset()` call in PHP.

2. **Dev server port conflicts**: The dev server plugin auto-selects a free port if the configured one is busy. Default is 5173.

3. **Admin scripts**: Use `admin_enqueue_scripts` hook instead of `wp_enqueue_scripts` for admin pages.

4. **CSS handling**: In dev mode, CSS is injected by Vite via HMR (no separate stylesheet registration). In production, CSS files extracted from the build are automatically registered as separate WordPress styles.

5. **`type="module"`**: The library automatically adds `type="module"` to script tags via WordPress filters. No manual intervention needed.

6. **Windows paths**: Asset URL preparation normalizes Windows backslashes. Cross-platform compatible since v0.6.1.

7. **Editor blocks**: HMR for building editor blocks is NOT supported.

8. **Manifest detection**: PHP checks for `vite-dev-server.json` first (dev mode), then falls back to `manifest.json` (production). The dev manifest is auto-created when `npm run dev` starts and deleted when it stops.

## Available WordPress Filters

| Filter | Parameters | Purpose |
|--------|-----------|---------|
| `vite_for_wp__manifest_data` | `$manifest, $manifest_dir, $manifest_path` | Modify manifest data after loading |
| `vite_for_wp__development_assets` | `$assets, $manifest, $entry, $options` | Modify registered dev assets |
| `vite_for_wp__production_assets` | `$assets, $manifest, $entry, $options` | Modify registered production assets |

## Deprecated API

`create_config()` (default export) is deprecated since v0.7.0. Use `v4wp()` plugin instead.

## References Guide

For detailed API signatures of all PHP and JS functions (including internal helpers like `get_manifest()`, `load_development_asset()`, `load_production_asset()`, TypeScript type definitions, and WordPress filter hooks), read `references/api-reference.md`.

For complete, runnable code examples covering React setup, multiple entry points, admin pages, CSS-only entries, HTTPS dev server, register-without-enqueue patterns, theme integration, and real-world plugin architecture, read `references/examples.md`.

For understanding how the dev server manifest works, production manifest.json structure, HMR internals, React Fast Refresh mechanism, CSS handling differences between dev/production, code splitting support, and common troubleshooting solutions, read `references/dev-server-and-build.md`.

For the complete list of all 60+ WordPress scripts externalized by `wp_scripts()` (including all `@wordpress/*` packages and their global variable mappings), custom externals configuration, and peer dependency details, read `references/wp-scripts-externals.md`.
