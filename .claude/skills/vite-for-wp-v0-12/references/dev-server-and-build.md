# Vite for WordPress - Dev Server, Build, and Troubleshooting

> Source: https://github.com/kucrut/vite-for-wp v0.12.0

## Table of Contents

- [How Dev/Production Mode Works](#how-devproduction-mode-works)
- [Dev Server Manifest Format](#dev-server-manifest-format)
- [Production Manifest Format](#production-manifest-format)
- [HMR (Hot Module Replacement)](#hmr-hot-module-replacement)
- [React Fast Refresh](#react-fast-refresh)
- [CSS Handling](#css-handling)
- [Code Splitting and Dynamic Imports](#code-splitting-and-dynamic-imports)
- [Build Output Structure](#build-output-structure)
- [Troubleshooting](#troubleshooting)

---

## How Dev/Production Mode Works

The PHP helper detects the current mode by checking which manifest file exists in the `outDir` directory:

```
Detection order:
1. Check for vite-dev-server.json  -> DEV MODE
2. Check for manifest.json         -> PRODUCTION MODE
3. Neither found                   -> Exception (wp_die in WP_DEBUG)
```

**Dev mode** (`npm run dev` is running):
- The `v4wp:dev-server` plugin writes `vite-dev-server.json` to the outDir when the dev server starts
- PHP reads this manifest and generates script URLs pointing to the Vite dev server (e.g., `http://localhost:5173/js/src/main.tsx`)
- The Vite client script (`@vite/client`) is registered as a dependency for HMR
- CSS is injected by Vite through the client script (no separate CSS files)
- The dev manifest is deleted when the dev server stops

**Production mode** (`npm run build` has been run):
- Vite generates `manifest.json` mapping entry points to hashed output files
- PHP reads this manifest and enqueues the correct hashed files via `wp_register_script()` / `wp_register_style()`
- CSS files are registered as separate WordPress stylesheets
- `type="module"` is added to script tags via the `script_loader_tag` filter

**Key implication**: You must run `npm run build` before deploying to production. If both manifests exist (e.g., you ran build while dev server is running), the dev manifest takes priority.

---

## Dev Server Manifest Format

File: `{outDir}/vite-dev-server.json`

```json
{
  "base": "./",
  "origin": "http://localhost:5173",
  "port": 5173,
  "plugins": ["vite:react-refresh"]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `base` | `string` | Vite base path (always `./` with v4wp) |
| `origin` | `string` | Full URL of the dev server (protocol + host + port) |
| `port` | `number` | Port the dev server is running on |
| `plugins` | `string[]` | List of detected plugins (used to enable React refresh) |

The `plugins` array is filtered from a check list: currently only `vite:react-refresh` is detected. The PHP helper uses this to decide whether to inject the React refresh preamble script.

---

## Production Manifest Format

File: `{outDir}/manifest.json`

Standard Vite manifest format. Example for a single entry:

```json
{
  "js/src/main.tsx": {
    "file": "assets/main-abc123.js",
    "src": "js/src/main.tsx",
    "isEntry": true,
    "css": [
      "assets/main-def456.css"
    ],
    "imports": [
      "_vendor-ghi789.js"
    ]
  },
  "_vendor-ghi789.js": {
    "file": "assets/vendor-ghi789.js",
    "css": [
      "assets/vendor-jkl012.css"
    ]
  }
}
```

| Field | Type | Description |
|-------|------|-------------|
| `file` | `string` | Output JS file path (relative to outDir) |
| `src` | `string` | Original source file path |
| `isEntry` | `boolean` | Whether this is an entry point |
| `css` | `string[]` | CSS files extracted from this chunk |
| `imports` | `string[]` | Dynamic/static import chunk keys |

The PHP helper recursively traverses `imports` to discover all CSS files from code-split chunks.

---

## HMR (Hot Module Replacement)

HMR works automatically in dev mode. The mechanism:

1. `v4wp:dev-server` plugin configures the Vite dev server with explicit HMR settings:
   ```js
   hmr: {
     port: port,       // Same as server port
     host: host,       // Same as server host
     protocol: 'ws',   // Or 'wss' if HTTPS
   }
   ```

2. PHP registers the Vite client script (`@vite/client`) as a dependency of your entry point script

3. The Vite client script connects to the dev server via WebSocket and handles module updates

**CORS**: For HMR to work across different domains (WordPress on `localhost:80`, Vite on `localhost:5173`), the Vite dev server needs CORS enabled:
```js
server: {
  cors: {
    origin: '*',
  },
}
```

**Port handling**: The dev server plugin automatically selects a free port. If port 5173 is busy, it tries 5174, 5175, etc. The chosen port is written to `vite-dev-server.json` so PHP always knows the correct port.

**Limitation**: HMR for WordPress editor blocks is NOT supported. This means you cannot use HMR when building custom Gutenberg blocks with this plugin.

---

## React Fast Refresh

When using `@vitejs/plugin-react`, React Fast Refresh is automatically configured:

1. The `v4wp:dev-server` plugin detects `vite:react-refresh` in the Vite plugin list and records it in `vite-dev-server.json`

2. The PHP helper reads this and injects the React refresh preamble as an inline module script:
   ```js
   import RefreshRuntime from "http://localhost:5173/@react-refresh";
   RefreshRuntime.injectIntoGlobalHook(window);
   window.$RefreshReg$ = () => {};
   window.$RefreshSig$ = () => (type) => type;
   window.__vite_plugin_react_preamble_installed__ = true;
   ```

3. This preamble is injected once globally (not per entry point) and is added as an inline script after the `vite-client` script

4. The inline script gets `type="module"` via a `wp_inline_script_attributes` filter

**Requirements for React Fast Refresh to work**:
- `@vitejs/plugin-react` must be in the Vite config
- The dev server must be running (`npm run dev`)
- The preamble script must load before any React component code

---

## CSS Handling

### Development Mode
- CSS is **not** registered as separate WordPress styles
- Vite injects CSS through the client script via HMR
- CSS changes are applied instantly without full page reload
- CSS dev sourcemaps are enabled by default (`css.devSourcemap: true`)
- The `css-dependencies` option values are still available in `$assets['styles']` for WordPress CSS dependencies

### Production Mode
- CSS is extracted into separate files with content hashes (e.g., `assets/main-abc123.css`)
- Each CSS file is registered as a WordPress stylesheet via `wp_register_style()`
- Style handles are auto-generated: `{script-handle}-{sanitized-css-filename}`
- CSS files from code-split chunks (via `imports`) are also discovered and registered
- CSS dependencies (`css-dependencies` option) are passed to `wp_register_style()`
- CSS media attribute (`css-media` option, default: `'all'`) is applied

### SCSS/SASS/Less
Vite handles CSS preprocessors natively. Just install the preprocessor:
```bash
npm add -D sass
```
Then import `.scss` files in your JS/TS entry points. No additional Vite config needed. The same dev/production CSS handling applies.

---

## Code Splitting and Dynamic Imports

Vite supports code splitting via dynamic imports. The PHP helper handles this correctly:

```js
// In your entry point
const LazyComponent = React.lazy(() => import('./LazyComponent'));
```

In production:
- Vite creates separate chunks for dynamic imports
- The manifest records the dependency chain via `imports`
- The PHP helper recursively traverses imports to register all CSS from split chunks
- JavaScript chunks are loaded by the browser on demand (via `import()`)

**Important**: `modulePreload` is disabled by default (`build.modulePreload: false`) in v4wp. This means the browser won't preload dynamic import chunks. This is intentional for WordPress compatibility.

Since v0.3.0, code splitting and dynamic imports are fully supported.

---

## Build Output Structure

After `npm run build`, the output directory contains:

```
js/dist/
├── manifest.json           # Vite build manifest
├── assets/
│   ├── main-abc123.js      # Entry point (hashed filename)
│   ├── main-def456.css     # Extracted CSS (hashed filename)
│   ├── vendor-ghi789.js    # Code-split chunk
│   └── ...
```

The `emptyOutDir: true` setting (set by v4wp) clears the output directory before each build.

Sourcemaps are generated by default (`build.sourcemap: true` in v4wp), respecting any user override.

---

## Troubleshooting

### "No manifest found" error
**Cause**: Neither `vite-dev-server.json` nor `manifest.json` exists in the specified directory.
**Fix**:
1. Check the `$manifest_dir` path in PHP matches your `outDir` in vite config
2. Run `npm run dev` (creates dev manifest) or `npm run build` (creates production manifest)
3. Verify the path is absolute: `__DIR__ . '/js/dist'`

### HMR not working / scripts not loading in dev mode
**Cause**: CORS blocking, port mismatch, or mixed content.
**Fix**:
1. Add CORS config to vite: `server: { cors: { origin: '*' } }`
2. If WordPress runs on HTTPS, configure HTTPS in Vite dev server too
3. Check browser console for blocked request errors
4. Verify the dev server is actually running on the port shown in `vite-dev-server.json`
5. Check browser DevTools Network tab for the Vite client script request

### Scripts load but React doesn't work
**Cause**: React not loaded as WordPress dependency.
**Fix**: Add `'react'` and `'react-dom'` to the `dependencies` array in PHP `enqueue_asset()` call. Even though `wp_scripts()` externalizes them from the build, WordPress still needs to load them.

### CSS not loading in production
**Cause**: Missing build step, or CSS entry not in manifest.
**Fix**:
1. Run `npm run build`
2. Check `manifest.json` for CSS entries
3. Verify CSS files exist in the output directory

### Old assets cached after build
**Cause**: Browser caching.
**Fix**: v4wp generates hashed filenames by default (e.g., `main-abc123.js`). Each build produces new hashes. Hard refresh the browser or clear cache.

### Dev server port already in use
**Cause**: Another process is using port 5173.
**Fix**: The dev server plugin auto-selects a free port. Or set a custom port:
```js
server: { port: 5174 }
```

### Windows path issues
**Cause**: Backslashes in Windows paths.
**Fix**: Fixed since v0.6.1. The `prepare_asset_url()` function normalizes Windows paths using `wp_normalize_path()`.

### "Entry not found" error in production
**Cause**: The `$entry` parameter in PHP doesn't match the `input` in vite config.
**Fix**: The entry path must match exactly. If vite config has `input: 'js/src/main.tsx'`, PHP must use `'js/src/main.tsx'` (not `'./js/src/main.tsx'` or `'/js/src/main.tsx'`).

### wp_scripts() breaks dev mode
**Cause**: wp_scripts() externalizes packages in build mode only. If there's an issue, it's likely with the peer dependencies.
**Fix**:
1. Ensure `rollup-plugin-external-globals` and `vite-plugin-external` are installed
2. In dev mode, React and other WP packages load from node_modules normally
3. Check that react/react-dom are in devDependencies (needed by @vitejs/plugin-react)

### Multiple Vite instances conflict
**Cause**: Multiple plugins using vite-for-wp with overlapping ports.
**Fix**: Set different ports for each plugin's vite config:
```js
// Plugin A: server: { port: 5173 }
// Plugin B: server: { port: 5174 }
```
The auto-port selection should handle this, but explicit ports are clearer.

### Inline scripts need type="module"
**Cause**: WordPress inline scripts don't get module type by default.
**Fix**: The library handles this for the React refresh preamble. For custom inline scripts that need module type, use the `wp_inline_script_attributes` filter.
