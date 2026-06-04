# antd v6 — General & Layout Components

> Button, ConfigProvider, Space / Space.Compact / Space.Addon, Layout, Grid, Flex,
> BorderBeam. Source: https://ant.design/components/{button,space,config-provider}.md

## Table of Contents
- [Button](#button)
- [Space / Space.Compact / Space.Addon](#space--spacecompact--spaceaddon)
- [ConfigProvider](#configprovider)
- [Layout](#layout)
- [Grid (Row / Col)](#grid-row--col)
- [Flex](#flex)
- [BorderBeam (new in 6.4.0)](#borderbeam-new-in-640)

---

## Button

```tsx
import { Button } from 'antd';
import type { ButtonProps } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `type` | `'default'\|'primary'\|'dashed'\|'link'\|'text'` | `'default'` | syntactic sugar for color+variant |
| `variant` | `'outlined'\|'dashed'\|'solid'\|'filled'\|'text'\|'link'` | — | v5.21.0+; **takes precedence over `type`** |
| `color` | `'default'\|'primary'\|'danger'\|PresetColor` | — | v5.21.0+; PresetColors v5.23.0+ |
| `shape` | `'default'\|'circle'\|'round'` | `'default'` | |
| `size` | `'large'\|'medium'\|'small'` | `'medium'` | |
| `icon` | ReactNode | — | |
| `iconPlacement` | `'start'\|'end'` | `'start'` | **v6** — replaces `iconPosition` |
| `loading` | `boolean \| { delay?; icon? }` | `false` | |
| `disabled` | boolean | `false` | |
| `danger` | boolean | `false` | |
| `ghost` | boolean | `false` | transparent bg for colored containers |
| `block` | boolean | `false` | full container width |
| `href` / `target` | string | — | renders `<a>` |
| `htmlType` | `'submit'\|'reset'\|'button'` | `'button'` | |
| `autoInsertSpace` | boolean | `true` | space between two Chinese chars |
| `onClick` | `(e) => void` | — | |
| `classNames` / `styles` | object / fn | — | keys: `root`, `content`, `icon` |

`PresetColor`: `blue purple cyan green magenta pink red orange yellow volcano geekblue
lime gold`.

> **v6**: `Button.Group` removed → `Space.Compact`. `iconPosition` → `iconPlacement`.
> The `color`+`variant` pair is the v6-preferred styling API; `type` is sugar over it.

```tsx
<Button type="primary">Primary</Button>
<Button color="danger" variant="outlined">Danger outlined</Button>
<Button color="cyan" variant="solid">Cyan solid</Button>
<Button icon={<SearchOutlined />} iconPlacement="end">Search</Button>
<Button loading={{ icon: <SyncOutlined spin /> }}>Saving</Button>
```

---

## Space / Space.Compact / Space.Addon

```tsx
import { Space } from 'antd';
```

### Space

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | **v6** — replaces `direction` |
| `size` | `'small'\|'middle'\|'large'\|number\|[h,v]` | `'small'` | |
| `align` | `'start'\|'end'\|'center'\|'baseline'` | — | |
| `separator` | ReactNode | — | **v6** — replaces `split` |
| `wrap` | boolean | `false` | wrap when horizontal |
| `classNames` / `styles` | object / fn | — | |

```tsx
<Space orientation="vertical" size="large">
  <Card />
  <Card />
</Space>

<Space separator={<Divider type="vertical" />}>
  <a>Home</a><a>About</a><a>Contact</a>
</Space>
```

### Space.Compact

Compactly connects form controls (collapsed borders). **Replaces** v5 `Input.Group`,
`Button.Group`, and `Dropdown.Button`.

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | **v6** — replaces `direction` |
| `size` | size union | `'medium'` | |
| `block` | boolean | `false` | fit parent width |

Supported children: Button, AutoComplete, Cascader, DatePicker, Input / Input.Search,
InputNumber, Select, TimePicker, TreeSelect.

### Space.Addon

A non-interactive cell inside a `Space.Compact` (replaces `addonBefore`/`addonAfter`).

```tsx
<Space.Compact block>
  <Space.Addon>https://</Space.Addon>
  <Input placeholder="ant.design" />
  <Button type="primary">Go</Button>
</Space.Compact>

// Replaces InputNumber addon props
<Space.Compact>
  <Space.Addon>$</Space.Addon>
  <InputNumber />
</Space.Compact>
```

---

## ConfigProvider

```tsx
import { ConfigProvider } from 'antd';
import zhCN from 'antd/locale/zh_CN';
```

### Global props

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `theme` | `ThemeConfig` | — | see `theme.md` |
| `locale` | locale object | — | `antd/locale/zh_CN`, etc. |
| `direction` | `'ltr' \| 'rtl'` | `'ltr'` | |
| `componentSize` | `'small' \| 'medium' \| 'large'` | — | global size |
| `componentDisabled` | boolean | — | disable everything |
| `variant` | `'outlined'\|'filled'\|'borderless'` | — | default input variant |
| `prefixCls` | string | `'ant'` | |
| `iconPrefixCls` | string | `'anticon'` | |
| `csp` | `{ nonce: string }` | — | CSP nonce |
| `getPopupContainer` | `(trigger?) => HTMLElement` | `() => document.body` | |
| `getTargetContainer` | `() => HTMLElement \| Window` | `() => window` | Affix/Anchor target |
| `popupMatchSelectWidth` | `boolean \| number` | — | global dropdown width |
| `popupOverflow` | `'viewport' \| 'scroll'` | `'viewport'` | |
| `renderEmpty` | `(componentName) => ReactNode` | — | |
| `virtual` | boolean | — | global virtual scroll |
| `wave` | `WaveConfig` | — | click-wave effect |
| `warning` | `{ strict: boolean }` | — | aggregate deprecation warnings |

### Component-level global config (v6)

ConfigProvider accepts a per-component prop object to set defaults for **every** instance
of that component. Keys include: `button`, `input`, `inputNumber`, `inputSearch`,
`textArea`, `select`, `cascader`, `datePicker`, `rangePicker`, `timePicker`, `treeSelect`,
`mentions`, `table`, `pagination`, `steps`, `tabs`, `menu`, `collapse`, `tree`, `card`,
`calendar`, `carousel`, `divider`, `empty`, `image`, `progress`, `skeleton`, `rate`,
`badge`, `avatar`, `statistic`, `result`, `timeline`, `ribbon`, `tag`, `tooltip`,
`popover`, `popconfirm`, `notification`, `message`, `modal`, `drawer`, `layout`, `space`,
`upload`, `transfer`, `form`, `anchor`, `qrcode`, `splitter`, `masonry`, `flex`,
`floatButton`, `borderBeam`.

```tsx
<ConfigProvider
  locale={zhCN}
  theme={{ token: { colorPrimary: '#1677ff' } }}
  componentSize="large"
  button={{ autoInsertSpace: false }}
  form={{ layout: 'vertical', requiredMark: 'optional' }}
  modal={{ mask: { blur: false } }}        // disable v6 default mask blur
  drawer={{ mask: { blur: false } }}
  tooltip={{ trigger: 'click' }}            // 6.1.0+ global trigger
  tag={{ styles: { root: { marginInlineEnd: 8 } } }}  // restore v5 tag spacing
>
  <App />
</ConfigProvider>
```

### Static method & hook

```tsx
ConfigProvider.config({ holderRender: (children) => <ConfigProvider theme={t}>{children}</ConfigProvider> });
const { componentDisabled, componentSize } = ConfigProvider.useConfig();
```

---

## Layout

```tsx
import { Layout } from 'antd';
const { Header, Sider, Content, Footer } = Layout;
```

`Layout` props: `hasSider?: boolean`. `Sider` props: `width` (default 200),
`collapsible`, `collapsed` / `defaultCollapsed`, `collapsedWidth` (default 80),
`breakpoint` (`xs`…`xxl`), `onCollapse`, `onBreakpoint`, `trigger` (custom collapse
trigger; `null` hides it), `theme` (`'light' | 'dark'`).

```tsx
<Layout style={{ minHeight: '100vh' }}>
  <Sider collapsible breakpoint="lg"><Menu items={menuItems} theme="dark" /></Sider>
  <Layout>
    <Header />
    <Content>...</Content>
    <Footer />
  </Layout>
</Layout>
```

---

## Grid (Row / Col)

```tsx
import { Row, Col } from 'antd';
```

`Row` props: `gutter` (`number | object | [h,v]`, e.g. `{ xs: 8, md: 16 }`), `justify`
(`start|end|center|space-around|space-between|space-evenly`), `align`
(`top|middle|bottom|stretch`), `wrap` (default `true`).

`Col` props: `span` (0–24), `offset`, `order`, `push`, `pull`, `flex`, and responsive
objects per breakpoint `xs sm md lg xl xxl` — each accepts a number or
`{ span, offset, order, push, pull }`.

> **v6 (6.3.0+)**: new `xxxl` breakpoint (1920px) for FHD screens.

```tsx
<Row gutter={[16, 16]}>
  <Col xs={24} md={12} lg={8}>A</Col>
  <Col xs={24} md={12} lg={8}>B</Col>
  <Col xs={24} md={24} lg={8}>C</Col>
</Row>
```

---

## Flex

```tsx
import { Flex } from 'antd';
```

`vertical?: boolean`, `wrap?: CSS wrap`, `justify?`, `align?`, `gap?`
(`'small'|'middle'|'large'|number`), `flex?`, `component?`. Lighter than `Space` for
simple flexbox layouts.

```tsx
<Flex vertical gap="middle">
  <Button>One</Button>
  <Button>Two</Button>
</Flex>
```

---

## BorderBeam (new in 6.4.0)

Decorative animated light beam tracing a container's border.

```tsx
import { BorderBeam } from 'antd';
```

Typical props: `size` (beam length), `duration` (animation seconds), `delay`,
`colorFrom` / `colorTo` (gradient endpoints), `borderWidth`, `reverse`. Render it as a
child of a `position: relative` container.

```tsx
<div style={{ position: 'relative', padding: 24, borderRadius: 8 }}>
  <BorderBeam size={120} duration={6} colorFrom="#1677ff" colorTo="#00b96b" />
  Highlighted card content
</div>
```

> Verify exact props against `https://ant.design/components/border-beam.md` for the pinned
> 6.x minor — this component is new and its API may still be evolving.
