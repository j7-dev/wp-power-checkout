# Vite for WordPress - Complete Examples

> Source: https://github.com/kucrut/vite-for-wp + official example repos

## Table of Contents

- [Vanilla JS Plugin](#vanilla-js-plugin)
- [React Plugin with wp_scripts](#react-plugin-with-wp_scripts)
- [Multiple Entry Points](#multiple-entry-points)
- [Admin Page Script](#admin-page-script)
- [CSS-Only Entry](#css-only-entry)
- [HTTPS Dev Server](#https-dev-server)
- [Register Without Enqueue](#register-without-enqueue)
- [Custom Server Port](#custom-server-port)
- [Real-World WordPress Plugin Pattern](#real-world-wordpress-plugin-pattern)
- [Theme Integration](#theme-integration)

---

## Vanilla JS Plugin

### Directory Structure
```
my-plugin/
├── js/
│   └── src/
│       └── main.ts
├── package.json
├── composer.json
├── plugin.php
└── vite.config.js
```

### package.json
```json
{
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vite build"
  },
  "devDependencies": {
    "@kucrut/vite-for-wp": "^0.12.0",
    "vite": "^7.0.0"
  }
}
```

### vite.config.js
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

### plugin.php
```php
<?php
/**
 * Plugin Name: My Plugin
 */

use Kucrut\Vite;

// Via Composer autoload
require_once __DIR__ . '/vendor/autoload.php';

// OR copy vite-for-wp.php directly
// require_once __DIR__ . '/vite-for-wp.php';

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

### composer.json (minimal)
```json
{
  "require": {
    "kucrut/vite-for-wp": "^0.12"
  }
}
```

---

## React Plugin with wp_scripts

This externalizes react/react-dom from the build, using WordPress's bundled copies instead.

### package.json
```json
{
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vite build"
  },
  "devDependencies": {
    "@kucrut/vite-for-wp": "^0.12.0",
    "@vitejs/plugin-react": "^4.3.1",
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "rollup-plugin-external-globals": "^0.13.0",
    "vite": "^7.0.0",
    "vite-plugin-external": "^6.2.2"
  }
}
```

### vite.config.js
```js
import { v4wp } from '@kucrut/vite-for-wp';
import { wp_scripts } from '@kucrut/vite-for-wp/plugins';
import react from '@vitejs/plugin-react';

export default {
  plugins: [
    v4wp({
      input: 'js/src/main.jsx',
      outDir: 'js/dist',
    }),
    wp_scripts(),
    react({
      jsxRuntime: 'classic',
    }),
  ],
};
```

### PHP Enqueue
```php
<?php
use Kucrut\Vite;

add_action('wp_enqueue_scripts', function (): void {
    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/main.jsx',
        [
            'dependencies' => ['react', 'react-dom'],
            'handle'       => 'my-react-app',
            'in-footer'    => true,
        ]
    );
});

// Render the React mount point
add_action('wp_footer', function (): void {
    echo '<div id="my-app"></div>';
});
```

**Important notes for React**:
1. `react` and `react-dom` must be installed as devDependencies (required by `@vitejs/plugin-react`).
2. `react` and `react-dom` must be listed in the PHP `dependencies` array so WordPress loads them before your script.
3. `rollup-plugin-external-globals` and `vite-plugin-external` are peer dependencies of `wp_scripts()`.
4. In dev mode, react/react-dom are loaded from node_modules (with HMR). In production, they are externalized to WordPress globals (`window.React`, `window.ReactDOM`).
5. Using `jsxRuntime: 'classic'` is recommended for WordPress compatibility when externalizing React.

---

## Multiple Entry Points

### vite.config.js
```js
import { v4wp } from '@kucrut/vite-for-wp';

export default {
  plugins: [
    v4wp({
      input: {
        main: 'js/src/main.ts',
        admin: 'js/src/admin.ts',
        editor: 'js/src/editor.ts',
      },
      outDir: 'js/dist',
    }),
  ],
};
```

### PHP - Each entry point enqueued separately
```php
<?php
use Kucrut\Vite;

// Frontend script
add_action('wp_enqueue_scripts', function (): void {
    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/main.ts',
        [
            'handle'    => 'my-plugin-frontend',
            'in-footer' => true,
        ]
    );
});

// Admin script
add_action('admin_enqueue_scripts', function (): void {
    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/admin.ts',
        [
            'handle'       => 'my-plugin-admin',
            'dependencies' => ['jquery'],
            'in-footer'    => true,
        ]
    );
});

// Block editor script
add_action('enqueue_block_editor_assets', function (): void {
    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/editor.ts',
        [
            'handle'       => 'my-plugin-editor',
            'dependencies' => ['wp-blocks', 'wp-element', 'wp-editor'],
            'in-footer'    => true,
        ]
    );
});
```

---

## Admin Page Script

```php
<?php
use Kucrut\Vite;

add_action('admin_enqueue_scripts', function (string $hook_suffix): void {
    // Only load on a specific admin page
    if ($hook_suffix !== 'toplevel_page_my-plugin') {
        return;
    }

    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/admin.tsx',
        [
            'handle'       => 'my-plugin-admin',
            'dependencies' => ['react', 'react-dom', 'wp-components'],
            'css-dependencies' => ['wp-components'],
            'in-footer'    => true,
        ]
    );
});
```

---

## CSS-Only Entry

When you only want the styles from an entry point (no JS execution in production):

```php
<?php
use Kucrut\Vite;

add_action('wp_enqueue_scripts', function (): void {
    Vite\enqueue_asset(
        __DIR__ . '/js/dist',
        'js/src/styles-only.ts',
        [
            'handle'   => 'my-plugin-styles',
            'css-only' => true,
        ]
    );
});
```

Note: `css-only` only affects production mode. In dev mode, the script still loads (Vite needs it to inject CSS via HMR).

---

## HTTPS Dev Server

For local development with HTTPS (e.g., when WordPress runs on HTTPS via local SSL):

### vite.config.js
```js
import { readFileSync } from 'node:fs';
import { v4wp } from '@kucrut/vite-for-wp';
import react from '@vitejs/plugin-react';

export default {
  plugins: [
    v4wp({ input: 'js/src/main.ts', outDir: 'js/dist' }),
    react(),
  ],
  server: {
    host: 'mydomain.com',
    https: {
      cert: readFileSync('path/to/cert.pem'),
      key: readFileSync('path/to/key.pem'),
    },
  },
};
```

The dev_server plugin will automatically set HMR protocol to `wss` when HTTPS is configured.

---

## Register Without Enqueue

Use `register_asset()` when you need conditional enqueue logic or to pass handles around:

```php
<?php
use Kucrut\Vite;

add_action('wp_enqueue_scripts', function (): void {
    $assets = Vite\register_asset(
        __DIR__ . '/js/dist',
        'js/src/main.ts',
        [
            'handle'    => 'my-plugin-script',
            'in-footer' => true,
        ]
    );

    if ($assets === null) {
        return;
    }

    // Conditionally enqueue based on some condition
    if (is_singular('product')) {
        foreach ($assets['scripts'] as $handle) {
            wp_enqueue_script($handle);
        }
        foreach ($assets['styles'] as $handle) {
            wp_enqueue_style($handle);
        }
    }

    // Or pass handles to wp_localize_script
    if (!empty($assets['scripts'])) {
        wp_localize_script($assets['scripts'][0], 'myPluginData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('my-plugin-nonce'),
        ]);
    }
});
```

**Note**: In dev mode, `$assets['styles']` only contains CSS dependency handles (not Vite-managed styles). In production, it includes auto-registered style handles.

---

## Custom Server Port

```js
// vite.config.js
import { v4wp } from '@kucrut/vite-for-wp';

export default {
  plugins: [
    v4wp({
      input: 'js/src/main.ts',
      outDir: 'js/dist',
    }),
  ],
  server: {
    port: 5174,        // Custom port (auto-incremented if busy)
    cors: {
      origin: '*',     // Allow cross-origin for WP dev
    },
    fs: {
      allow: ['./'],   // Allow serving files from project root
    },
  },
};
```

---

## Real-World WordPress Plugin Pattern

Based on the power-course plugin architecture:

### vite.config.ts
```ts
import react from '@vitejs/plugin-react';
import tsconfigPaths from 'vite-tsconfig-paths';
import alias from '@rollup/plugin-alias';
import path from 'path';
import { defineConfig } from 'vite';
import { v4wp } from '@kucrut/vite-for-wp';

export default defineConfig({
  server: {
    port: 5174,
    cors: {
      origin: '*',
    },
    fs: {
      allow: ['./'],
    },
  },
  plugins: [
    alias(),
    react(),
    tsconfigPaths(),
    v4wp({
      input: 'js/src/main.tsx',
      outDir: 'js/dist',
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'js/src'),
    },
  },
});
```

### PHP Enqueue (class-based)
```php
<?php
declare(strict_types=1);

namespace MyPlugin;

use Kucrut\Vite;

class Bootstrap {
    public static function enqueue_script(): void {
        Vite\enqueue_asset(
            Plugin::$dir . '/js/dist',
            'js/src/main.tsx',
            [
                'handle'    => Plugin::$kebab,
                'in-footer' => true,
            ]
        );
    }
}
```

---

## Theme Integration

The same pattern works for themes, using theme directory paths:

### functions.php
```php
<?php
use Kucrut\Vite;

require_once get_theme_file_path('/vendor/autoload.php');

add_action('wp_enqueue_scripts', function (): void {
    Vite\enqueue_asset(
        get_theme_file_path('/js/dist'),
        'js/src/main.ts',
        [
            'handle'    => 'my-theme-scripts',
            'in-footer' => true,
        ]
    );
});
```

### vite.config.js (same as plugin)
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
