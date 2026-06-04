# General & Layout Components

## Table of Contents
- [Button](#button)
- [ConfigProvider](#configprovider)
- [Space](#space)
- [Layout](#layout)
- [Grid (Row/Col)](#grid-rowcol)
- [Spin](#spin)
- [Pagination](#pagination)
- [Divider](#divider)
- [Flex](#flex)
- [FloatButton](#floatbutton)
- [Splitter](#splitter)
- [Typography](#typography)
- [App](#app)

---

## Button

```tsx
import { Button } from 'antd';
import type { ButtonProps } from 'antd';
```

### Button Props

| Prop | Type | Default |
|------|------|---------|
| `autoInsertSpace` | `boolean` | `true` (v5.17.0+, space between CJK chars) |
| `block` | `boolean` | `false` |
| `color` | `'default' \| 'primary' \| 'danger' \| PresetColors` | - (v5.21.0+) |
| `danger` | `boolean` | `false` |
| `disabled` | `boolean` | `false` |
| `ghost` | `boolean` | `false` |
| `href` | `string` | - (renders as `<a>`) |
| `htmlType` | `'submit' \| 'reset' \| 'button'` | `'button'` |
| `icon` | `ReactNode` | - |
| `iconPlacement` | `'start' \| 'end'` | `'start'` (v5.17.0+) |
| `loading` | `boolean \| { delay: number, icon?: ReactNode }` | `false` |
| `shape` | `'default' \| 'circle' \| 'round'` | `'default'` |
| `size` | `'large' \| 'medium' \| 'small'` | `'medium'` |
| `target` | `string` | - (with href) |
| `type` | `'primary' \| 'dashed' \| 'link' \| 'text' \| 'default'` | `'default'` |
| `variant` | `'outlined' \| 'dashed' \| 'solid' \| 'filled' \| 'text' \| 'link'` | - (v5.21.0+) |
| `onClick` | `(event: MouseEvent) => void` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### PresetColors

```tsx
type PresetColors = 'blue' | 'purple' | 'cyan' | 'green' | 'magenta' |
  'pink' | 'red' | 'orange' | 'yellow' | 'volcano' | 'geekblue' | 'lime' | 'gold';
```

### Button Types Summary

| type | Use Case |
|------|----------|
| `primary` | Main action, one per section |
| `default` | Secondary actions |
| `dashed` | Add/create operations |
| `text` | Minimal emphasis actions |
| `link` | Navigation/external links |

---

## ConfigProvider

```tsx
import { ConfigProvider, theme } from 'antd';
```

### ConfigProvider Props

| Prop | Type | Default |
|------|------|---------|
| `direction` | `'ltr' \| 'rtl'` | `'ltr'` |
| `locale` | `object` | - |
| `theme` | `ThemeConfig` | - |
| `prefixCls` | `string` | `'ant'` |
| `componentSize` | `'small' \| 'medium' \| 'large'` | - |
| `componentDisabled` | `boolean` | - |
| `getPopupContainer` | `(trigger?: HTMLElement) => HTMLElement \| ShadowRoot` | - |
| `getTargetContainer` | `() => HTMLElement \| Window \| ShadowRoot` | - |
| `renderEmpty` | `(componentName: string) => ReactNode` | - |
| `csp` | `{ nonce: string }` | - |
| `iconPrefixCls` | `string` | - |
| `variant` | `'outlined' \| 'filled' \| 'borderless'` | - |
| `popupMatchSelectWidth` | `boolean \| number` | - |
| `popupOverflow` | `'viewport' \| 'scroll'` | - |
| `virtual` | `boolean` | - |
| `warning` | `{ strict: boolean }` | - |

### ConfigProvider.config()

Configure static method behavior (Modal, Message, Notification):

```tsx
ConfigProvider.config({
  holderRender: (children) => (
    <ConfigProvider theme={myTheme}>{children}</ConfigProvider>
  ),
});
```

### ConfigProvider.useConfig()

```tsx
const { componentSize, componentDisabled } = ConfigProvider.useConfig();
```

### Locale Setup

```tsx
import zhTW from 'antd/locale/zh_TW';
// Available: en_US, zh_CN, zh_TW, ja_JP, ko_KR, ...

<ConfigProvider locale={zhTW}>
  <App />
</ConfigProvider>
```

### Component-Level Overrides

ConfigProvider supports per-component configuration for className, style, classNames, styles:

```tsx
<ConfigProvider
  button={{ className: 'custom-btn' }}
  table={{ className: 'custom-table' }}
  input={{ className: 'custom-input' }}
  // ... 70+ components supported
>
```

---

## Space

```tsx
import { Space } from 'antd';
```

### Space Props

| Prop | Type | Default |
|------|------|---------|
| `align` | `'start' \| 'end' \| 'center' \| 'baseline'` | - |
| `direction` | `'vertical' \| 'horizontal'` | `'horizontal'` |
| `size` | `'small' \| 'middle' \| 'large' \| number \| [number, number]` | `'small'` |
| `split` | `ReactNode` | - (deprecated, use `separator`) |
| `separator` | `ReactNode` | - |
| `wrap` | `boolean` | `false` |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Space.Compact Props

Groups related components (Button, Input, Select, DatePicker)。
**取代已廢棄的 `Input.Group`** —— `Input.Group` 已 deprecated，一律改用 `Space.Compact`。

| Prop | Type | Default |
|------|------|---------|
| `block` | `boolean` | `false` |
| `direction` | `'vertical' \| 'horizontal'` | `'horizontal'` |
| `size` | `'large' \| 'middle' \| 'small'` | `'middle'` |

```tsx
<Space.Compact>
  <Input placeholder="URL" />
  <Button type="primary">Submit</Button>
</Space.Compact>
```

---

## Layout

```tsx
import { Layout } from 'antd';
const { Header, Footer, Sider, Content } = Layout;
```

### Layout Props

| Prop | Type | Default |
|------|------|---------|
| `className` | `string` | - |
| `hasSider` | `boolean` | - (auto-detect; set for SSR) |
| `style` | `CSSProperties` | - |

### Layout.Sider Props

| Prop | Type | Default |
|------|------|---------|
| `breakpoint` | `'xs' \| 'sm' \| 'md' \| 'lg' \| 'xl' \| 'xxl'` | - |
| `className` | `string` | - |
| `collapsed` | `boolean` | - |
| `collapsedWidth` | `number` | `80` |
| `collapsible` | `boolean` | `false` |
| `defaultCollapsed` | `boolean` | `false` |
| `reverseArrow` | `boolean` | `false` |
| `style` | `CSSProperties` | - |
| `theme` | `'light' \| 'dark'` | `'dark'` |
| `trigger` | `ReactNode` | - |
| `width` | `number \| string` | `200` |
| `zeroWidthTriggerStyle` | `CSSProperties` | - |
| `onBreakpoint` | `(broken: boolean) => void` | - |
| `onCollapse` | `(collapsed: boolean, type: 'clickTrigger' \| 'responsive') => void` | - |

### Layout.Header, Layout.Content, Layout.Footer

Accept standard `className` and `style` props. Must be placed inside Layout.

### Layout Patterns

```tsx
// Basic with Sider
<Layout>
  <Sider>Sider</Sider>
  <Layout>
    <Header>Header</Header>
    <Content>Content</Content>
    <Footer>Footer</Footer>
  </Layout>
</Layout>
```

---

## Grid (Row/Col)

```tsx
import { Row, Col } from 'antd';
```

### Row Props

| Prop | Type | Default |
|------|------|---------|
| `align` | `'top' \| 'middle' \| 'bottom' \| 'stretch' \| Responsive` | `'top'` |
| `gutter` | `number \| string \| object \| [horizontal, vertical]` | `0` |
| `justify` | `'start' \| 'end' \| 'center' \| 'space-around' \| 'space-between' \| 'space-evenly' \| Responsive` | `'start'` |
| `wrap` | `boolean` | `true` |

### Col Props

| Prop | Type | Default |
|------|------|---------|
| `flex` | `string \| number` | - |
| `span` | `number` | - (0 = display:none) |
| `offset` | `number` | `0` |
| `order` | `number` | `0` |
| `push` | `number` | `0` |
| `pull` | `number` | `0` |

### Responsive Breakpoints

Each breakpoint accepts `number` (span) or `{ span, offset, push, pull, order }`:

| Prop | Screen Width |
|------|-------------|
| `xs` | < 576px |
| `sm` | >= 576px |
| `md` | >= 768px |
| `lg` | >= 992px |
| `xl` | >= 1200px |
| `xxl` | >= 1600px |

### Grid Example

```tsx
<Row gutter={[16, 16]} justify="center">
  <Col xs={24} sm={12} md={8} lg={6}>
    <div>Content</div>
  </Col>
  <Col xs={24} sm={12} md={8} lg={6}>
    <div>Content</div>
  </Col>
</Row>
```

---

## Spin

```tsx
import { Spin } from 'antd';
```

### Spin Props

| Prop | Type | Default |
|------|------|---------|
| `delay` | `number` | - (ms) |
| `fullscreen` | `boolean` | `false` (v5.11.0+) |
| `indicator` | `ReactNode` | - |
| `percent` | `number \| 'auto'` | - (v5.18.0+) |
| `size` | `'small' \| 'medium' \| 'large'` | `'medium'` |
| `spinning` | `boolean` | `true` |
| `tip` | `ReactNode` | - (deprecated, use `description`) |
| `description` | `ReactNode` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Static Method

```tsx
Spin.setDefaultIndicator(indicator: ReactNode); // Set global default spinner
```

### Design Tokens

`contentHeight` (400), `dotSize` (20), `dotSizeLG` (32), `dotSizeSM` (14)

### Spin as Wrapper

```tsx
<Spin spinning={loading}>
  <div>Content will be dimmed when loading</div>
</Spin>
```

---

## Pagination

```tsx
import { Pagination } from 'antd';
import type { PaginationProps } from 'antd';
```

### Pagination Props

| Prop | Type | Default |
|------|------|---------|
| `align` | `'start' \| 'center' \| 'end'` | - |
| `current` | `number` | - |
| `defaultCurrent` | `number` | `1` |
| `defaultPageSize` | `number` | `10` |
| `disabled` | `boolean` | - |
| `hideOnSinglePage` | `boolean` | `false` |
| `itemRender` | `(page, type: 'page' \| 'prev' \| 'next' \| 'jump-prev' \| 'jump-next', element) => ReactNode` | - |
| `pageSize` | `number` | - |
| `pageSizeOptions` | `number[]` | `[10, 20, 50, 100]` |
| `responsive` | `boolean` | - |
| `showLessItems` | `boolean` | `false` |
| `showQuickJumper` | `boolean \| { goButton: ReactNode }` | `false` |
| `showSizeChanger` | `boolean \| SelectProps` | - (auto when total > 50) |
| `showTitle` | `boolean` | `true` |
| `showTotal` | `(total: number, range: [number, number]) => ReactNode` | - |
| `simple` | `boolean \| { readOnly: boolean }` | - |
| `size` | `'large' \| 'medium' \| 'small'` | `'medium'` |
| `total` | `number` | `0` |
| `onChange` | `(page: number, pageSize: number) => void` | - |
| `onShowSizeChange` | `(current: number, size: number) => void` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Table Pagination Usage

```tsx
// Table accepts pagination props directly
<Table
  pagination={{
    current: page,
    pageSize: 20,
    total: 100,
    showSizeChanger: true,
    showQuickJumper: true,
    showTotal: (total, range) => `${range[0]}-${range[1]} of ${total}`,
    onChange: (page, pageSize) => { ... },
  }}
/>

// Hide pagination
<Table pagination={false} />
```

---

## Divider

分割線。

```tsx
import { Divider } from 'antd';
```

### Divider Props

| Prop | Type | Default |
|------|------|---------|
| `children` | `ReactNode` | - (包裹的標題) |
| `type` | `'horizontal' \| 'vertical'` | `'horizontal'` |
| `orientation` | `'start' \| 'end' \| 'center'` | `'center'` (start/end: v5.24.0+) |
| `orientationMargin` | `string \| number` | - (標題與最近邊界距離) |
| `dashed` | `boolean` | `false` |
| `variant` | `'dashed' \| 'dotted' \| 'solid'` | `'solid'` (v5.20.0+) |
| `plain` | `boolean` | `true` (文字普通樣式，v4.2.0+) |
| `size` | `'small' \| 'middle' \| 'large'` | - (v5.25.0+，僅 horizontal) |

### Design Tokens

`orientationMargin` (0.05), `textPaddingInline`, `verticalMarginInline` (8)

---

## Flex

彈性佈局容器。

```tsx
import { Flex } from 'antd';
```

### Flex Props

| Prop | Type | Default |
|------|------|---------|
| `vertical` | `boolean` | `false` (`flex-direction: column`) |
| `wrap` | `boolean \| CSS flex-wrap` | `'nowrap'` (boolean: v5.17.0+) |
| `justify` | `CSS justify-content` | `'normal'` |
| `align` | `CSS align-items` | `'normal'` |
| `flex` | `CSS flex` | `'normal'` |
| `gap` | `'small' \| 'middle' \| 'large' \| string \| number` | - |
| `component` | `React.ComponentType` | `'div'` (自訂元素類型) |

```tsx
<Flex gap="middle" vertical align="center">
  <Button>A</Button>
  <Button>B</Button>
</Flex>
```

---

## FloatButton

懸浮按鈕。

```tsx
import { FloatButton } from 'antd';
```

### FloatButton Props

| Prop | Type | Default |
|------|------|---------|
| `icon` | `ReactNode` | - |
| `description` | `ReactNode` | - (文字等內容) |
| `tooltip` | `ReactNode \| TooltipProps` | - (TooltipProps: v5.25.0+) |
| `type` | `'default' \| 'primary'` | `'default'` |
| `shape` | `'circle' \| 'square'` | `'circle'` |
| `href` | `string` | - |
| `target` | `string` | - |
| `htmlType` | `'submit' \| 'reset' \| 'button'` | `'button'` (v5.21.0+) |
| `badge` | `BadgeProps` | - (v5.4.0+，不支援 status 相關 props) |
| `onClick` | `(event) => void` | - |

### FloatButton.Group Props

| Prop | Type | Default |
|------|------|---------|
| `shape` | `'circle' \| 'square'` | `'circle'` |
| `trigger` | `'click' \| 'hover'` | - (觸發選單開合) |
| `open` | `boolean` | - (配合 trigger 使用) |
| `closeIcon` | `ReactNode` | `<CloseOutlined />` |
| `placement` | `'top' \| 'left' \| 'right' \| 'bottom'` | `'top'` (v5.21.0+) |
| `onOpenChange` | `(open: boolean) => void` | - |
| `onClick` | `(event) => void` | - (僅 Menu 模式，v5.3.0+) |

### FloatButton.BackTop Props

| Prop | Type | Default |
|------|------|---------|
| `duration` | `number` | `450` (回到頂部時間 ms) |
| `target` | `() => HTMLElement` | `() => window` |
| `visibilityHeight` | `number` | `400` (滾動高度達此值才顯示) |
| `onClick` | `() => void` | - |

---

## Splitter

分隔面板，可拖曳調整尺寸。

```tsx
import { Splitter } from 'antd';
```

### Splitter Props

| Prop | Type | Default |
|------|------|---------|
| `layout` | `'horizontal' \| 'vertical'` | `'horizontal'` |
| `lazy` | `boolean` | `false` (v5.23.0+，懶模式) |
| `onResizeStart` | `(sizes: number[]) => void` | - |
| `onResize` | `(sizes: number[]) => void` | - |
| `onResizeEnd` | `(sizes: number[]) => void` | - |
| `onCollapse` | `(collapsed: boolean[], sizes: number[]) => void` | - (v5.28.0+) |

### Splitter.Panel Props

| Prop | Type | Default |
|------|------|---------|
| `defaultSize` | `number \| string` | - (初始尺寸，數字為 px，字串如 `'30%'`) |
| `size` | `number \| string` | - (受控尺寸) |
| `min` | `number \| string` | - (最小閾值) |
| `max` | `number \| string` | - (最大閾值) |
| `collapsible` | `boolean \| { start?, end?, showCollapsibleIcon? }` | `false` (showCollapsibleIcon: v5.27.0+) |
| `resizable` | `boolean` | `true` |

### Design Tokens

`splitBarSize` (2), `splitBarDraggableSize` (20), `splitTriggerSize` (6)

---

## Typography

排版元件。包含 `Typography.Text`、`Typography.Title`、`Typography.Paragraph`、`Typography.Link`。

```tsx
import { Typography } from 'antd';
const { Text, Title, Paragraph, Link } = Typography;
```

### Text / Paragraph 通用 Props

| Prop | Type | Default |
|------|------|---------|
| `code` | `boolean` | `false` (程式碼樣式) |
| `keyboard` | `boolean` | `false` (v4.3.0+) |
| `mark` | `boolean` | `false` (標記樣式) |
| `strong` | `boolean` | `false` (粗體；Title 無此屬性) |
| `italic` | `boolean` | `false` (v4.16.0+) |
| `underline` | `boolean` | `false` |
| `delete` | `boolean` | `false` (刪除線) |
| `disabled` | `boolean` | `false` |
| `type` | `'secondary' \| 'success' \| 'warning' \| 'danger'` | - (success: v4.6.0+) |
| `copyable` | `boolean \| CopyConfig` | `false` |
| `editable` | `boolean \| EditConfig` | `false` |
| `ellipsis` | `boolean \| EllipsisConfig` | `false` |
| `onClick` | `(event) => void` | - |

### Title 額外 Props

| Prop | Type | Default |
|------|------|---------|
| `level` | `1 \| 2 \| 3 \| 4 \| 5` | `1` (對應 h1~h5，5: v4.6.0+) |

> `Text` 的 `ellipsis` 物件不支援 `expandable` / `rows` / `onExpand`。

### CopyConfig

| Prop | Type | Default |
|------|------|---------|
| `text` | `string` | - (要複製的文字) |
| `format` | `'text/plain' \| 'text/html'` | - (v4.21.0+) |
| `icon` | `[ReactNode, ReactNode]` | - (`[copyIcon, copiedIcon]`，v4.6.0+) |
| `tooltips` | `[ReactNode, ReactNode]` | `['Copy', 'Copied']` (false 隱藏，v4.4.0+) |
| `tabIndex` | `number` | `0` (v5.17.0+) |
| `onCopy` | `() => void` | - |

### EditConfig

| Prop | Type | Default |
|------|------|---------|
| `editing` | `boolean` | `false` |
| `text` | `string` | - (編輯內容，v4.24.0+) |
| `icon` | `ReactNode` | `<EditOutlined />` (v4.6.0+) |
| `tooltip` | `ReactNode` | `'Edit'` (false 隱藏，v4.6.0+) |
| `autoSize` | `boolean \| { minRows, maxRows }` | - (v4.4.0+) |
| `maxLength` | `number` | - (v4.4.0+) |
| `triggerType` | `Array<'icon' \| 'text'>` | `['icon']` |
| `enterIcon` | `ReactNode` | `<EnterOutlined />` (null 移除，v4.17.0+) |
| `tabIndex` | `number` | `0` (v5.17.0+) |
| `onChange` | `(value: string) => void` | - |
| `onStart` / `onCancel` / `onEnd` | `() => void` | - (onEnd: v4.14.0+) |

### EllipsisConfig

| Prop | Type | Default |
|------|------|---------|
| `rows` | `number` | - (最大行數) |
| `expandable` | `boolean \| 'collapsible'` | - (collapsible: v5.16.0+) |
| `suffix` | `string` | - (省略內容後綴) |
| `symbol` | `ReactNode \| ((expanded: boolean) => ReactNode)` | `'Expand'` / `'Collapse'` |
| `tooltip` | `ReactNode \| TooltipProps` | - (v4.11.0+) |
| `defaultExpanded` | `boolean` | - (v5.16.0+) |
| `expanded` | `boolean` | - (v5.16.0+) |
| `onEllipsis` | `(ellipsis: boolean) => void` | - (v4.2.0+) |
| `onExpand` | `(event, { expanded: boolean }) => void` | - (info: v5.16.0+) |

### Design Tokens

`titleMarginTop` (1.2em), `titleMarginBottom` (0.5em)

---

## App

應用包裹元件，提供 message / notification / Modal 的 hook context，無需手動放置 contextHolder。

```tsx
import { App } from 'antd';

const Page = () => {
  const { message, notification, modal } = App.useApp();
  return <Button onClick={() => message.success('OK')}>Click</Button>;
};

// 根層級包裹
<App>
  <Page />
</App>
```

### App Props

| Prop | Type | Default |
|------|------|---------|
| `component` | `ComponentType \| false` | `'div'` (false 則不建立 DOM 節點，v5.11.0+) |
| `message` | `MessageConfig` | - (Message 全域設定，v5.3.0+) |
| `notification` | `NotificationConfig` | - (Notification 全域設定，v5.3.0+) |

### App.useApp()

回傳 `{ message, notification, modal }`，三者皆已綁定當前 React context（ConfigProvider、theme、locale），是取代靜態方法的建議用法。必須在 `<App>` 元件內呼叫。
