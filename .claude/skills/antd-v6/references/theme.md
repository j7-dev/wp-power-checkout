# antd v6 — Theme System

> ConfigProvider theme prop, design tokens, algorithms, cssVar, zeroRuntime, useToken.
> Source: https://ant.design/docs/react/customize-theme + changelog.

## Table of Contents
- [ThemeConfig structure](#themeconfig-structure)
- [v6 changes vs v5](#v6-changes-vs-v5)
- [Three-layer token model](#three-layer-token-model)
- [Seed Tokens](#seed-tokens)
- [Map Tokens (derived)](#map-tokens-derived)
- [Alias Tokens (semantic)](#alias-tokens-semantic)
- [Algorithms](#algorithms)
- [Component-level token overrides](#component-level-token-overrides)
- [useToken hook](#usetoken-hook)
- [CSS variables (cssVar)](#css-variables-cssvar)
- [zeroRuntime mode (v6.0.0+)](#zeroruntime-mode-v600)
- [Common patterns](#common-patterns)

---

## ThemeConfig structure

```tsx
import { ConfigProvider, theme } from 'antd';
import type { ThemeConfig } from 'antd';

const config: ThemeConfig = {
  token: { /* seed / map / alias token overrides */ },
  algorithm: theme.defaultAlgorithm,        // or array of algorithms
  components: { /* per-component token overrides */ },
  cssVar: true,                             // v6: default true; or { prefix, key }
  hashed: true,                             // hash classNames (default true)
  inherit: true,                            // inherit parent ConfigProvider theme (default true)
  zeroRuntime: false,                       // v6.0.0+: skip runtime style generation
};

<ConfigProvider theme={config}><App /></ConfigProvider>
```

## v6 changes vs v5

| Area | v5 | v6 |
|------|----|----|
| CSS variables | opt-in via `cssVar` | **default on** |
| `zeroRuntime` | not available | **new** — disables runtime style generation |
| `useToken` return | `{ token, hashId }` | also exports **`cssVar`** |
| Disabled borders | per-component tokens | new alias token **`colorBorderDisabled`** |
| reset.css | `mark` styled; required-mark forced `SimSun` | `mark` styling removed; required mark not font-locked |
| Component `algorithm` | supported | supported — components can inherit/override global algorithm |

The token names themselves (seed/map/alias) are largely **stable from v5 to v6**; the
delivery mechanism (CSS variables) and new `colorBorderDisabled` are the notable diffs.

## Three-layer token model

- **Seed Token** — origin design values (`colorPrimary`, `borderRadius`, `fontSize`). The
  smallest, most direct customization points.
- **Map Token** — derived from Seed Tokens by the algorithm (`colorPrimaryHover`,
  `colorPrimaryBg`, size variants). Overridable directly.
- **Alias Token** — high-level semantic tokens used directly by components
  (`colorBgContainer`, `colorText`, `colorBorder`). Control batches of components.

## Seed Tokens

| Token | Type | Default | Description |
|-------|------|---------|-------------|
| `colorPrimary` | string | `#1677ff` | Brand primary color |
| `colorSuccess` | string | `#52c41a` | Success state |
| `colorWarning` | string | `#faad14` | Warning state |
| `colorError` | string | `#ff4d4f` | Error state |
| `colorInfo` | string | `#1677ff` | Info state |
| `colorLink` | string | — | Link color |
| `fontSize` | number | `14` | Base font size (px) |
| `fontFamily` | string | system stack | Base font family |
| `borderRadius` | number | `6` | Base border radius (px) |
| `controlHeight` | number | `32` | Base control height (px) |
| `lineWidth` | number | `1` | Base border width (px) |
| `lineType` | string | `solid` | Base border style |
| `motion` | boolean | `true` | Enable animations |
| `wireframe` | boolean | `false` | Wireframe mode |
| `zIndexBase` | number | `0` | Base z-index |
| `sizeUnit` / `sizeStep` | number | `4` / `4` | Spacing derivation units |

Preset color seeds (overridable): `blue purple cyan green magenta pink red orange yellow
volcano geekblue lime gold`.

## Map Tokens (derived)

For each semantic color (`Primary`, `Success`, `Warning`, `Error`, `Info`):
`color{Name}Bg`, `color{Name}BgHover`, `color{Name}Border`, `color{Name}BorderHover`,
`color{Name}Hover`, `color{Name}`, `color{Name}Active`, `color{Name}TextHover`,
`color{Name}Text`, `color{Name}TextActive`.

Size derivatives: `borderRadiusXS/SM/LG`, `controlHeightXS/SM/LG`, `fontSizeSM/LG/XL`,
`fontSizeHeading1..5`, `lineHeight`, `lineHeightLG/SM`, `lineHeightHeading1..5`.

## Alias Tokens (semantic)

Backgrounds: `colorBgContainer`, `colorBgElevated`, `colorBgLayout`, `colorBgSpotlight`,
`colorBgMask`, `colorBgTextHover`, `colorBgTextActive`,
`colorFill` / `colorFillSecondary` / `colorFillTertiary` / `colorFillQuaternary`.

Text: `colorText`, `colorTextSecondary`, `colorTextTertiary`, `colorTextQuaternary`,
`colorTextDescription`, `colorTextDisabled`, `colorTextHeading`, `colorTextLabel`,
`colorTextPlaceholder`, `colorWhite`, `colorTextLightSolid`.

Borders: `colorBorder`, `colorBorderSecondary`, **`colorBorderDisabled`** (new in v6 —
unified disabled-state border color).

Layout / control: `controlItemBgHover`, `controlItemBgActive`, `controlOutline`,
`controlOutlineWidth`, `boxShadow`, `boxShadowSecondary`, `padding`, `paddingXS/SM/LG`,
`margin`, `marginXS/SM/LG`, `screenXS..XXL` (responsive breakpoints), `motionDurationFast/
Mid/Slow`.

## Algorithms

```tsx
const { defaultAlgorithm, darkAlgorithm, compactAlgorithm } = theme;

// single
theme={{ algorithm: theme.darkAlgorithm }}
// combine
theme={{ algorithm: [theme.darkAlgorithm, theme.compactAlgorithm] }}
```

Algorithms expand Seed Tokens into Map Tokens. `defaultAlgorithm` (light),
`darkAlgorithm` (dark mode), `compactAlgorithm` (denser sizing).

## Component-level token overrides

```tsx
theme={{
  components: {
    Button: { colorPrimary: '#00b96b', borderRadius: 8, algorithm: true },
    Table:  { headerBg: '#fafafa', rowHoverBg: '#f5f5f5' },
    Input:  { activeBorderColor: '#1677ff', hoverBorderColor: '#4096ff' },
  },
}}
```

- Each component has its own Component Token namespace.
- `algorithm: true` makes the component-token overrides go through algorithmic derivation;
  default `false` means they only override the final value.

## useToken hook

```tsx
import { theme } from 'antd';

const MyComponent = () => {
  const { token, hashId, cssVar } = theme.useToken();  // v6: cssVar added
  return <div style={{ color: token.colorPrimary, padding: token.padding }} />;
};
```

Static access outside React:

```tsx
const { getDesignToken } = theme;
const tokens = getDesignToken(themeConfig);
```

## CSS variables (cssVar)

**v6: CSS variables are enabled by default.** This makes runtime theme switching cheap and
reduces style recalculation.

```tsx
theme={{
  cssVar: { prefix: 'ant', key: 'my-app' },   // or just cssVar: true
}}
```

`prefix` sets the CSS custom-property prefix; `key` is a theme identifier (useful for
multiple themed regions on one page).

## zeroRuntime mode (v6.0.0+)

```tsx
import 'antd/dist/antd.css';   // or generate via @ant-design/static-style-extract

<ConfigProvider theme={{ zeroRuntime: true }}>
  <App />
</ConfigProvider>
```

When enabled, antd no longer generates component styles at runtime — styles must be
imported statically. Improves runtime performance and avoids style-injection cost. For a
customized theme, generate the static CSS with `@ant-design/static-style-extract`.

## Common patterns

```tsx
// Dynamic dark / light switching
const [dark, setDark] = useState(false);
<ConfigProvider theme={{ algorithm: dark ? theme.darkAlgorithm : theme.defaultAlgorithm }}>
  <App />
</ConfigProvider>

// Brand theme + compact + component overrides
<ConfigProvider
  theme={{
    algorithm: theme.compactAlgorithm,
    token: { colorPrimary: '#722ed1', borderRadius: 4, fontSize: 13 },
    components: { Card: { paddingLG: 16 } },
  }}
>
  <App />
</ConfigProvider>

// TypeScript
import type { ThemeConfig } from 'antd';
const themeConfig: ThemeConfig = { token: { colorPrimary: '#1677ff' } };
```
