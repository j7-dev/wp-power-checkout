# BlockNote Theming & Styling Reference

> Theme configuration, CSS variables, CSS class overrides, and DOM attributes.

## Table of Contents

- [Theme Prop Options](#theme-prop-options)
- [Theme Type Definition](#theme-type-definition)
- [CSS Variables](#css-variables)
- [CSS Class Names](#css-class-names)
- [CSS Data Attributes](#css-data-attributes)
- [Adding DOM Attributes](#adding-dom-attributes)

---

## Theme Prop Options

The `theme` prop on `BlockNoteView` accepts:

| Value | Behavior |
|-------|----------|
| `"light"` | Force light mode |
| `"dark"` | Force dark mode |
| (omitted) | Auto-detect from system preference |
| `Theme` object | Custom theme for both modes |
| `{ light: Theme; dark: Theme }` | Separate light and dark themes |

```typescript
// Force light mode
<BlockNoteView editor={editor} theme="light" />

// Force dark mode
<BlockNoteView editor={editor} theme="dark" />

// Custom theme
<BlockNoteView editor={editor} theme={myCustomTheme} />

// Separate light/dark
<BlockNoteView editor={editor} theme={{ light: lightTheme, dark: darkTheme }} />
```

---

## Theme Type Definition

```typescript
type CombinedColor = Partial<{
  text: string;
  background: string;
}>;

type ColorScheme = Partial<{
  editor: CombinedColor;
  menu: CombinedColor;
  tooltip: CombinedColor;
  hovered: CombinedColor;
  selected: CombinedColor;
  disabled: CombinedColor;
  shadow: string;
  border: string;
  sideMenu: string;
  highlights: Partial<{
    gray: CombinedColor;
    brown: CombinedColor;
    red: CombinedColor;
    orange: CombinedColor;
    yellow: CombinedColor;
    green: CombinedColor;
    blue: CombinedColor;
    purple: CombinedColor;
    pink: CombinedColor;
  }>;
}>;

type Theme = Partial<{
  colors: ColorScheme;
  borderRadius: number;
  fontFamily: string;
}>;

type LightAndDarkThemes = {
  light: Theme;
  dark: Theme;
};
```

### Example Custom Theme

```typescript
const customTheme: Theme = {
  colors: {
    editor: {
      text: "#222222",
      background: "#ffffff",
    },
    menu: {
      text: "#333333",
      background: "#f8f8f8",
    },
    hovered: {
      text: "#111111",
      background: "#e8e8e8",
    },
    selected: {
      text: "#ffffff",
      background: "#3b82f6",
    },
    shadow: "rgba(0, 0, 0, 0.1)",
    border: "#e0e0e0",
    sideMenu: "#999999",
  },
  borderRadius: 8,
  fontFamily: "'Inter', sans-serif",
};
```

---

## CSS Variables

Override CSS variables by targeting the `.bn-container` selector with data attributes.

### Selector Patterns

```css
/* Both themes */
.bn-container[data-color-scheme] {
  --bn-colors-editor-text: #222;
}

/* Light theme only */
.bn-container[data-color-scheme="light"] {
  --bn-colors-editor-background: #fff;
}

/* Dark theme only */
.bn-container[data-color-scheme="dark"] {
  --bn-colors-editor-background: #1a1a1a;
}
```

### Available CSS Variables

**Core Colors:**
- `--bn-colors-editor-text`
- `--bn-colors-editor-background`
- `--bn-colors-menu-text`
- `--bn-colors-menu-background`
- `--bn-colors-tooltip-text`
- `--bn-colors-tooltip-background`
- `--bn-colors-hovered-text`
- `--bn-colors-hovered-background`
- `--bn-colors-selected-text`
- `--bn-colors-selected-background`
- `--bn-colors-disabled-text`
- `--bn-colors-disabled-background`
- `--bn-colors-shadow`
- `--bn-colors-border`
- `--bn-colors-side-menu`

**Highlight Colors** (8 color pairs):
- `--bn-colors-highlights-gray-text` / `--bn-colors-highlights-gray-background`
- `--bn-colors-highlights-brown-text` / `--bn-colors-highlights-brown-background`
- `--bn-colors-highlights-red-text` / `--bn-colors-highlights-red-background`
- `--bn-colors-highlights-orange-text` / `--bn-colors-highlights-orange-background`
- `--bn-colors-highlights-yellow-text` / `--bn-colors-highlights-yellow-background`
- `--bn-colors-highlights-green-text` / `--bn-colors-highlights-green-background`
- `--bn-colors-highlights-blue-text` / `--bn-colors-highlights-blue-background`
- `--bn-colors-highlights-purple-text` / `--bn-colors-highlights-purple-background`
- `--bn-colors-highlights-pink-text` / `--bn-colors-highlights-pink-background`

**Typography & Layout:**
- `--bn-font-family` (default: `"Inter", "SF Pro Display", -apple-system, ...`)
- `--bn-border-radius` (default: `6px`)

---

## CSS Class Names

### Editor Structure

| Class | Element |
|-------|---------|
| `.bn-container` | Main editor container (includes menus/toolbars) |
| `.bn-editor` | Primary editor element |
| `.bn-block` | Individual block (including nested) |
| `.bn-block-group` | Container for nested blocks |
| `.bn-block-content` | Block content wrapper |
| `.bn-inline-content` | Editable rich text content |

### UI Components

| Class | Element |
|-------|---------|
| `.bn-toolbar` | Formatting and link toolbars |
| `.bn-side-menu` | Side menu element |
| `.bn-drag-handle-menu` | Drag handle dropdown |
| `.bn-suggestion-menu` | Suggestion menu (slash menu) |
| `.bn-suggestion-menu-item` | Individual suggestion item |

### Example: Styling Specific Elements

```css
/* Blue text in editor */
.bn-container .bn-editor * {
  color: blue;
}

/* Highlight hovered suggestion items */
.bn-container .bn-suggestion-menu-item[aria-selected="true"],
.bn-container .bn-suggestion-menu-item:hover {
  background-color: blue;
}
```

---

## CSS Data Attributes

BlockNote adds data attributes to blocks for CSS targeting:

| Attribute | Usage |
|-----------|-------|
| `[data-content-type="paragraph"]` | Target specific block types |
| `[data-content-type="heading"]` | Target heading blocks |
| `[data-content-type="bulletListItem"]` | Target bullet list items |
| `[data-level="2"]` | Target heading level 2 |
| `[data-checked="true"]` | Target checked checkboxes |

Data attributes for props are only added when values differ from defaults.

### Example: Style Headings

```css
.bn-block-content[data-content-type="heading"][data-level="1"] {
  font-size: 2em;
  border-bottom: 2px solid #eee;
}
```

---

## Adding DOM Attributes

Add custom HTML attributes to editor DOM elements via `domAttributes` option.

### Available Targets

- `editor` -- main editor container (excludes menus/toolbars)
- `block` -- container for each block
- `blockGroup` -- wrapper for top-level and nested blocks
- `blockContent` -- content wrapper per block
- `inlineContent` -- rich text wrapper within blocks

### Example

```typescript
import { useCreateBlockNote } from "@blocknote/react";

const editor = useCreateBlockNote({
  domAttributes: {
    block: {
      class: "custom-block-class",
    },
    editor: {
      class: "custom-editor-class",
      "data-testid": "my-editor",
    },
  },
});
```

```css
/* styles.css */
.custom-block-class {
  border: 2px solid lightgray;
  border-radius: 4px;
  padding: 2px;
  margin: 2px;
}
```

### Scoping Styles with Custom Attributes

Use custom data attributes on `BlockNoteView` to scope CSS rules:

```typescript
<BlockNoteView editor={editor} data-custom-scope />
```

```css
.bn-container[data-custom-scope] .bn-editor {
  /* scoped styles */
}
```
