# Vite for WordPress - wp_scripts() and WordPress Externals

> Source: https://github.com/kucrut/vite-for-wp/blob/main/src/exports/utils/wp-globals.js (v0.12.0)

## Table of Contents

- [Overview](#overview)
- [How wp_scripts() Works](#how-wp_scripts-works)
- [Complete Externals List](#complete-externals-list)
- [Adding Custom Externals](#adding-custom-externals)
- [Dev vs Build Behavior](#dev-vs-build-behavior)
- [Peer Dependencies](#peer-dependencies)

---

## Overview

The `wp_scripts()` plugin externalizes JavaScript packages that are already registered and available in WordPress. Instead of bundling these packages into your build output, Vite replaces `import` statements with references to WordPress global variables (e.g., `window.wp.element`, `window.React`).

This reduces bundle size and ensures your plugin uses the same version of shared libraries (React, wp-data, etc.) as WordPress core and other plugins.

---

## How wp_scripts() Works

`wp_scripts()` combines three mechanisms:

1. **`v4wp:wp-scripts` plugin** (apply: `'build'` only): Sets `build.rollupOptions.external` and `output.globals`
2. **`rollup-plugin-external-globals`**: Replaces imports with global variable references in the built output
3. **`vite-plugin-external`**: Handles externalization during development mode

The plugin only affects the **build** output. During development (`npm run dev`), packages are loaded from `node_modules` normally, allowing HMR and proper development experience.

---

## Complete Externals List

### WordPress @wordpress/* Packages

All `@wordpress/*` packages are mapped to `wp.*` globals (camelCase conversion of the package name).

| npm Package | WordPress Global | WordPress Handle |
|------------|-----------------|-----------------|
| `@wordpress/a11y` | `wp.a11y` | `wp-a11y` |
| `@wordpress/annotations` | `wp.annotations` | `wp-annotations` |
| `@wordpress/api-fetch` | `wp.apiFetch` | `wp-api-fetch` |
| `@wordpress/autop` | `wp.autop` | `wp-autop` |
| `@wordpress/blob` | `wp.blob` | `wp-blob` |
| `@wordpress/block-directory` | `wp.blockDirectory` | `wp-block-directory` |
| `@wordpress/block-editor` | `wp.blockEditor` | `wp-block-editor` |
| `@wordpress/block-library` | `wp.blockLibrary` | `wp-block-library` |
| `@wordpress/block-serialization-default-parser` | `wp.blockSerializationDefaultParser` | `wp-block-serialization-default-parser` |
| `@wordpress/blocks` | `wp.blocks` | `wp-blocks` |
| `@wordpress/commands` | `wp.commands` | `wp-commands` |
| `@wordpress/components` | `wp.components` | `wp-components` |
| `@wordpress/compose` | `wp.compose` | `wp-compose` |
| `@wordpress/core-commands` | `wp.coreCommands` | `wp-core-commands` |
| `@wordpress/core-data` | `wp.coreData` | `wp-core-data` |
| `@wordpress/customize-widgets` | `wp.customizeWidgets` | `wp-customize-widgets` |
| `@wordpress/data` | `wp.data` | `wp-data` |
| `@wordpress/data-controls` | `wp.dataControls` | `wp-data-controls` |
| `@wordpress/date` | `wp.date` | `wp-date` |
| `@wordpress/deprecated` | `wp.deprecated` | `wp-deprecated` |
| `@wordpress/dom` | `wp.dom` | `wp-dom` |
| `@wordpress/dom-ready` | `wp.domReady` | `wp-dom-ready` |
| `@wordpress/edit-post` | `wp.editPost` | `wp-edit-post` |
| `@wordpress/edit-site` | `wp.editSite` | `wp-edit-site` |
| `@wordpress/edit-widgets` | `wp.editWidgets` | `wp-edit-widgets` |
| `@wordpress/editor` | `wp.editor` | `wp-editor` |
| `@wordpress/element` | `wp.element` | `wp-element` |
| `@wordpress/escape-html` | `wp.escapeHtml` | `wp-escape-html` |
| `@wordpress/fields` | `wp.fields` | `wp-fields` |
| `@wordpress/format-library` | `wp.formatLibrary` | `wp-format-library` |
| `@wordpress/hooks` | `wp.hooks` | `wp-hooks` |
| `@wordpress/html-entities` | `wp.htmlEntities` | `wp-html-entities` |
| `@wordpress/i18n` | `wp.i18n` | `wp-i18n` |
| `@wordpress/is-shallow-equal` | `wp.isShallowEqual` | `wp-is-shallow-equal` |
| `@wordpress/keyboard-shortcuts` | `wp.keyboardShortcuts` | `wp-keyboard-shortcuts` |
| `@wordpress/keycodes` | `wp.keycodes` | `wp-keycodes` |
| `@wordpress/list-reusable-blocks` | `wp.listReusableBlocks` | `wp-list-reusable-blocks` |
| `@wordpress/media-utils` | `wp.mediaUtils` | `wp-media-utils` |
| `@wordpress/notices` | `wp.notices` | `wp-notices` |
| `@wordpress/nux` | `wp.nux` | `wp-nux` |
| `@wordpress/patterns` | `wp.patterns` | `wp-patterns` |
| `@wordpress/plugins` | `wp.plugins` | `wp-plugins` |
| `@wordpress/preferences` | `wp.preferences` | `wp-preferences` |
| `@wordpress/preferences-persistence` | `wp.preferencesPersistence` | `wp-preferences-persistence` |
| `@wordpress/primitives` | `wp.primitives` | `wp-primitives` |
| `@wordpress/priority-queue` | `wp.priorityQueue` | `wp-priority-queue` |
| `@wordpress/private-apis` | `wp.privateApis` | `wp-private-apis` |
| `@wordpress/redux-routine` | `wp.reduxRoutine` | `wp-redux-routine` |
| `@wordpress/reusable-blocks` | `wp.reusableBlocks` | `wp-reusable-blocks` |
| `@wordpress/rich-text` | `wp.richText` | `wp-rich-text` |
| `@wordpress/router` | `wp.router` | `wp-router` |
| `@wordpress/server-side-render` | `wp.serverSideRender` | `wp-server-side-render` |
| `@wordpress/shortcode` | `wp.shortcode` | `wp-shortcode` |
| `@wordpress/style-engine` | `wp.styleEngine` | `wp-style-engine` |
| `@wordpress/token-list` | `wp.tokenList` | `wp-token-list` |
| `@wordpress/undo-manager` | `wp.undoManager` | `wp-undo-manager` |
| `@wordpress/url` | `wp.url` | `wp-url` |
| `@wordpress/viewport` | `wp.viewport` | `wp-viewport` |
| `@wordpress/warning` | `wp.warning` | `wp-warning` |
| `@wordpress/widgets` | `wp.widgets` | `wp-widgets` |
| `@wordpress/wordcount` | `wp.wordcount` | `wp-wordcount` |

### Other Externalized Packages

| npm Package | WordPress Global | WordPress Handle |
|------------|-----------------|-----------------|
| `jquery` | `jQuery` | `jquery` |
| `tinymce` | `tinymce` | (varies) |
| `moment` | `moment` | `moment` |
| `react` | `React` | `react` |
| `react/jsx-runtime` | `ReactJSXRuntime` | `react-jsx-runtime` |
| `react-dom` | `ReactDOM` | `react-dom` |
| `backbone` | `Backbone` | `backbone` |
| `lodash` | `lodash` | `lodash` |

---

## Adding Custom Externals

Use the `extraScripts` option to add package-to-global mappings that are not in the default list:

```js
import { wp_scripts } from '@kucrut/vite-for-wp/plugins';

export default {
  plugins: [
    // ...
    wp_scripts({
      extraScripts: {
        'my-external-lib': 'MyExternalLib',
        '@some/package': 'SomePackage',
      },
    }),
  ],
};
```

The keys are npm package names (used in `import` statements) and the values are the global variable names where they're available at runtime.

You also need to ensure these scripts are registered in WordPress and added to your script's dependencies:

```php
// PHP side: add the WP handle to dependencies
Vite\enqueue_asset(
    __DIR__ . '/js/dist',
    'js/src/main.ts',
    [
        'handle'       => 'my-plugin',
        'dependencies' => ['my-external-lib-handle'],
    ]
);
```

---

## Dev vs Build Behavior

| Aspect | Dev Mode (`npm run dev`) | Build Mode (`npm run build`) |
|--------|------------------------|------------------------------|
| Package resolution | Loaded from `node_modules` | Externalized to globals |
| React/ReactDOM | Full dev version with HMR | WordPress-bundled version |
| @wordpress/* | Full packages from npm | WordPress global `wp.*` |
| HMR | Fully functional | N/A |
| Bundle size | N/A (served individually) | Minimal (externals excluded) |

This means your `devDependencies` must include packages that are externalized in production. For example, even though React is externalized in the build, `@vitejs/plugin-react` needs `react` and `react-dom` packages available during development for HMR to work.

---

## Peer Dependencies

`wp_scripts()` dynamically imports two packages that must be installed:

```bash
npm add -D rollup-plugin-external-globals vite-plugin-external
```

These are declared as optional peer dependencies of `@kucrut/vite-for-wp`. They are only needed if you use `wp_scripts()`. If you don't use `wp_scripts()`, you don't need to install them.

| Package | Version (v0.12.0) | Purpose |
|---------|-------------------|---------|
| `rollup-plugin-external-globals` | `^0.13.0` | Replaces import references with globals in Rollup output |
| `vite-plugin-external` | `^6.2.2` | Handles externalization during dev mode |
