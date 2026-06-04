# Theme System

## Table of Contents
- [ThemeConfig Structure](#themeconfig-structure)
- [Seed Tokens](#seed-tokens)
- [Map Tokens (Derived)](#map-tokens-derived)
- [Alias Tokens (Semantic)](#alias-tokens-semantic)
- [Algorithms](#algorithms)
- [Component Tokens](#component-tokens)
- [useToken Hook](#usetoken-hook)
- [CSS Variables Mode](#css-variables-mode)
- [Static Token Access](#static-token-access)
- [Common Patterns](#common-patterns)

---

## ThemeConfig Structure

```tsx
import { ConfigProvider, theme } from 'antd';

<ConfigProvider
  theme={{
    // Seed/Map/Alias token overrides
    token: { ... },

    // Algorithm(s) for deriving tokens
    algorithm: theme.defaultAlgorithm,

    // Per-component token overrides
    components: { ... },

    // Inherit parent ConfigProvider theme (default: true)
    inherit: true,

    // CSS Variables mode
    cssVar: { prefix: 'ant', key: 'theme-id' },

    // Enable hashed class names (default: true)
    hashed: true,
  }}
>
```

### TypeScript

```tsx
import type { ThemeConfig } from 'antd';
const themeConfig: ThemeConfig = { ... };
```

---

## Seed Tokens

Base design intent tokens. All other tokens derive from these.

| Token | Type | Default | Description |
|-------|------|---------|-------------|
| `colorPrimary` | `string` | `'#1677ff'` | Brand primary color |
| `colorSuccess` | `string` | `'#52c41a'` | Success state color |
| `colorWarning` | `string` | `'#faad14'` | Warning state color |
| `colorError` | `string` | `'#ff4d4f'` | Error state color |
| `colorInfo` | `string` | `'#1677ff'` | Info state color |
| `colorLink` | `string` | `'#1677ff'` | Link color |
| `fontSize` | `number` | `14` | Base font size (px) |
| `fontFamily` | `string` | system stack | Base font family |
| `borderRadius` | `number` | `6` | Base border radius (px) |
| `controlHeight` | `number` | `32` | Base control height (px) |
| `lineWidth` | `number` | `1` | Base border width (px) |
| `lineType` | `string` | `'solid'` | Base border style |
| `motion` | `boolean` | `true` | Enable animations |
| `motionUnit` | `number` | `0.1` | Animation timing unit |
| `wireframe` | `boolean` | `false` | Wireframe mode |
| `zIndexBase` | `number` | `0` | Base z-index |

### Preset Color Tokens

Each preset color can be overridden as a seed token:
`blue`, `purple`, `cyan`, `green`, `magenta`, `pink`, `red`, `orange`, `yellow`, `volcano`, `geekblue`, `lime`, `gold`

---

## Map Tokens (Derived)

Generated from Seed Tokens via algorithms. Can be overridden directly.

### Color Derivatives

For each semantic color (`Primary`, `Success`, `Warning`, `Error`, `Info`):

| Pattern | Example | Description |
|---------|---------|-------------|
| `color{Name}Bg` | `colorPrimaryBg` | Lightest background |
| `color{Name}BgHover` | `colorPrimaryBgHover` | Background hover state |
| `color{Name}Border` | `colorPrimaryBorder` | Border color |
| `color{Name}BorderHover` | `colorPrimaryBorderHover` | Border hover state |
| `color{Name}Hover` | `colorPrimaryHover` | Main hover state |
| `color{Name}` | `colorPrimary` | Main color |
| `color{Name}Active` | `colorPrimaryActive` | Active/pressed state |
| `color{Name}TextHover` | `colorPrimaryTextHover` | Text hover |
| `color{Name}Text` | `colorPrimaryText` | Text color |
| `color{Name}TextActive` | `colorPrimaryTextActive` | Text active |

### Size Derivatives

| Token | Default | Description |
|-------|---------|-------------|
| `borderRadiusXS` | `2` | Extra small radius |
| `borderRadiusSM` | `4` | Small radius |
| `borderRadius` | `6` | Base radius |
| `borderRadiusLG` | `8` | Large radius |
| `controlHeightXS` | `16` | Extra small control |
| `controlHeightSM` | `24` | Small control |
| `controlHeight` | `32` | Base control |
| `controlHeightLG` | `40` | Large control |
| `fontSizeSM` | `12` | Small text |
| `fontSize` | `14` | Base text |
| `fontSizeLG` | `16` | Large text |
| `fontSizeXL` | `20` | Extra large text |
| `fontSizeHeading1` | `38` | H1 |
| `fontSizeHeading2` | `30` | H2 |
| `fontSizeHeading3` | `24` | H3 |
| `fontSizeHeading4` | `20` | H4 |
| `fontSizeHeading5` | `16` | H5 |
| `lineHeight` | `1.5714` | Base line height |
| `lineHeightLG` | `1.5` | Large line height |
| `lineHeightSM` | `1.6667` | Small line height |
| `lineHeightHeading1` | `1.2105` | H1 line height |
| `lineHeightHeading2` | `1.2667` | H2 line height |
| `lineHeightHeading3` | `1.3333` | H3 line height |
| `lineHeightHeading4` | `1.4` | H4 line height |
| `lineHeightHeading5` | `1.5` | H5 line height |

---

## Alias Tokens (Semantic)

Higher-level tokens that reference Map/Seed tokens. Directly used by components.

### Background Colors

| Token | Description |
|-------|-------------|
| `colorBgContainer` | Container background (card, table, input) |
| `colorBgElevated` | Elevated surface (dropdown, popover) |
| `colorBgLayout` | Page layout background |
| `colorBgSpotlight` | Spotlight background (tooltip) |
| `colorBgMask` | Mask overlay |
| `colorBgTextHover` | Text element hover bg |
| `colorBgTextActive` | Text element active bg |
| `colorFill` | Fill color (first level) |
| `colorFillSecondary` | Fill color (second level) |
| `colorFillTertiary` | Fill color (third level) |
| `colorFillQuaternary` | Fill color (fourth level) |

### Text Colors

| Token | Description |
|-------|-------------|
| `colorText` | Primary text (highest contrast) |
| `colorTextSecondary` | Secondary text |
| `colorTextTertiary` | Tertiary text |
| `colorTextQuaternary` | Quaternary text (least contrast) |
| `colorTextHeading` | Heading text |
| `colorTextLabel` | Label text |
| `colorTextDescription` | Description/help text |
| `colorTextPlaceholder` | Placeholder text |
| `colorTextDisabled` | Disabled text |
| `colorIcon` | Icon color |
| `colorIconHover` | Icon hover color |

### Border & Shadow

| Token | Description |
|-------|-------------|
| `colorBorder` | Default border |
| `colorBorderSecondary` | Secondary border (divider) |
| `colorSplit` | Separator color |
| `boxShadow` | Primary shadow |
| `boxShadowSecondary` | Secondary shadow |
| `boxShadowTertiary` | Tertiary shadow |

### Spacing

| Token | Value | Description |
|-------|-------|-------------|
| `marginXXS` | `4` | |
| `marginXS` | `8` | |
| `marginSM` | `12` | |
| `margin` | `16` | |
| `marginMD` | `20` | |
| `marginLG` | `24` | |
| `marginXL` | `32` | |
| `marginXXL` | `48` | |
| `paddingXXS` | `4` | |
| `paddingXS` | `8` | |
| `paddingSM` | `12` | |
| `padding` | `16` | |
| `paddingMD` | `20` | |
| `paddingLG` | `24` | |
| `paddingXL` | `32` | |
| `paddingContentHorizontal` | `16` | Content horizontal padding |
| `paddingContentVertical` | `12` | Content vertical padding |
| `paddingContentHorizontalLG` | `24` | Large content padding |
| `paddingContentHorizontalSM` | `16` | Small content padding |

### Responsive Breakpoints

| Token | Value |
|-------|-------|
| `screenXS` | `480` |
| `screenSM` | `576` |
| `screenMD` | `768` |
| `screenLG` | `992` |
| `screenXL` | `1200` |
| `screenXXL` | `1600` |

### Z-Index

| Token | Value | Usage |
|-------|-------|-------|
| `zIndexBase` | `0` | Base |
| `zIndexPopupBase` | `1000` | Popup base |

### Other Alias Tokens

| Token | Description |
|-------|-------------|
| `opacityLoading` | Loading state opacity |
| `linkDecoration` | Link text decoration |
| `linkHoverDecoration` | Link hover text decoration |
| `linkFocusDecoration` | Link focus text decoration |
| `controlPaddingHorizontal` | Control horizontal padding |
| `controlPaddingHorizontalSM` | Small control horizontal padding |
| `controlOutline` | Control outline color |
| `controlOutlineWidth` | Control outline width |

---

## Algorithms

```tsx
import { theme } from 'antd';
const { defaultAlgorithm, darkAlgorithm, compactAlgorithm } = theme;
```

| Algorithm | Description |
|-----------|-------------|
| `defaultAlgorithm` | Standard light theme |
| `darkAlgorithm` | Dark mode (inverted backgrounds, adjusted contrast) |
| `compactAlgorithm` | Compact spacing/sizing (smaller controls, tighter layout) |

### Combining Algorithms

```tsx
<ConfigProvider
  theme={{
    algorithm: [theme.darkAlgorithm, theme.compactAlgorithm],
  }}
>
```

Multiple algorithms are applied in sequence.

---

## Component Tokens

Override tokens for specific components without affecting others.

```tsx
<ConfigProvider
  theme={{
    components: {
      Button: {
        colorPrimary: '#00b96b',
        algorithm: true, // inherit global algorithm for this component
      },
      Table: {
        headerBg: '#fafafa',
        headerColor: '#333',
        rowHoverBg: '#f5f5f5',
        cellPaddingBlock: 12,
        cellPaddingInline: 16,
      },
      Input: {
        activeBorderColor: '#1890ff',
        hoverBorderColor: '#40a9ff',
      },
      Select: {
        optionSelectedBg: '#e6f7ff',
        optionActiveBg: '#f5f5f5',
      },
    },
  }}
>
```

### Key Component Token Examples

**Table:** `headerBg`, `headerColor`, `headerSortActiveBg`, `bodySortBg`, `rowHoverBg`, `rowSelectedBg`, `rowSelectedHoverBg`, `borderColor`, `cellFontSize`, `cellPaddingBlock`, `cellPaddingInline`, `expandIconBg`, `filterDropdownBg`, `selectionColumnWidth`, `stickyScrollBarBg`

**Button:** `colorPrimary`, `colorPrimaryHover`, `colorPrimaryActive`, `primaryShadow`, `defaultBorderColor`, `defaultColor`, `defaultBg`, `contentFontSize`, `contentFontSizeLG`, `contentFontSizeSM`, `paddingBlock`, `paddingInline`, `paddingBlockLG`, `paddingInlineLG`

**Input:** `activeBorderColor`, `hoverBorderColor`, `activeShadow`, `errorActiveShadow`, `warningActiveShadow`, `addonBg`, `paddingBlock`, `paddingInline`

**Select:** `clearBg`, `multipleItemBg`, `optionActiveBg`, `optionHeight`, `optionSelectedBg`, `optionSelectedColor`, `selectorBg`, `zIndexPopup`

**DatePicker:** `activeBorderColor`, `activeShadow`, `cellHeight`, `cellWidth`, `timeColumnHeight`, `timeColumnWidth`, `zIndexPopup`

**Form:** `labelColor`, `labelFontSize`, `labelHeight`, `labelRequiredMarkColor`, `verticalLabelMargin`, `verticalLabelPadding`

**Modal:** `headerBg`, `titleColor`, `titleFontSize`, `contentBg`, `footerBg`

When `algorithm: true` is set on a component, it applies the global algorithm to derive tokens from the component's seed overrides.

---

## useToken Hook

```tsx
import { theme } from 'antd';

function MyComponent() {
  const { token, hashId } = theme.useToken();

  return (
    <div style={{
      color: token.colorPrimary,
      padding: token.padding,
      borderRadius: token.borderRadius,
      backgroundColor: token.colorBgContainer,
    }}>
      Themed content
    </div>
  );
}
```

### Return Value

| Property | Type | Description |
|----------|------|-------------|
| `token` | `GlobalToken` | All resolved design tokens |
| `hashId` | `string` | CSS hash for current theme |
| `theme` | `Theme` | Theme entity |

---

## CSS Variables Mode

Enable CSS custom properties for runtime theme switching without re-rendering.

```tsx
<ConfigProvider
  theme={{
    cssVar: true,
    // Or with options:
    cssVar: {
      prefix: 'ant',   // CSS variable prefix (default: 'ant')
      key: 'my-theme', // Unique key for multiple themes
    },
  }}
>
```

Generated CSS variables follow pattern: `--{prefix}-{token-name}`

Example: `--ant-color-primary`, `--ant-border-radius`, `--ant-font-size`

---

## Static Token Access

Get design tokens without rendering:

```tsx
import { theme } from 'antd';
const { getDesignToken } = theme;

const globalToken = getDesignToken({
  token: { colorPrimary: '#1890ff' },
  algorithm: theme.darkAlgorithm,
});

console.log(globalToken.colorPrimary);     // Resolved primary color
console.log(globalToken.colorBgContainer); // Resolved background
```

---

## Common Patterns

### Dark Mode Toggle

```tsx
const [isDark, setIsDark] = useState(false);

<ConfigProvider
  theme={{
    algorithm: isDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
    token: { colorPrimary: '#1890ff' },
  }}
>
  <App />
</ConfigProvider>
```

### Nested Theme Override

```tsx
<ConfigProvider theme={{ token: { colorPrimary: '#1890ff' } }}>
  <Button>Blue Button</Button>
  <ConfigProvider theme={{ token: { colorPrimary: '#00b96b' } }}>
    <Button>Green Button</Button>
  </ConfigProvider>
</ConfigProvider>
```

### Using Tokens in Custom Components

```tsx
import { theme } from 'antd';

const MyCard: React.FC = ({ children }) => {
  const { token } = theme.useToken();

  return (
    <div style={{
      padding: token.paddingLG,
      borderRadius: token.borderRadiusLG,
      backgroundColor: token.colorBgContainer,
      border: `${token.lineWidth}px ${token.lineType} ${token.colorBorderSecondary}`,
      boxShadow: token.boxShadow,
    }}>
      {children}
    </div>
  );
};
```

### Creating a Complete Theme

```tsx
const myTheme: ThemeConfig = {
  algorithm: theme.defaultAlgorithm,
  token: {
    // Brand
    colorPrimary: '#1890ff',
    colorSuccess: '#52c41a',
    colorWarning: '#faad14',
    colorError: '#ff4d4f',

    // Typography
    fontSize: 14,
    fontFamily: "'Inter', -apple-system, sans-serif",

    // Shape
    borderRadius: 8,
    controlHeight: 36,

    // Motion
    motion: true,
  },
  components: {
    Button: {
      primaryShadow: '0 2px 0 rgba(0,0,0,0.05)',
      contentFontSizeLG: 16,
    },
    Table: {
      headerBg: '#fafafa',
      rowHoverBg: '#f0f7ff',
    },
    Card: {
      paddingLG: 24,
    },
  },
};
```
