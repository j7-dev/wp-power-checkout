# Data Display Components (Advanced)

涵蓋 antd v5 進階資料展示元件。基礎元件（Table、Tag、Badge、Image、Tooltip、Descriptions）見 `data-display.md`。
所有 API 來自 https://5x.ant.design/ 官方文件（v5.29.x）。

## Table of Contents
- [Avatar](#avatar)
- [Calendar](#calendar)
- [Card](#card)
- [Carousel](#carousel)
- [Collapse](#collapse)
- [Empty](#empty)
- [List](#list)
- [Popover](#popover)
- [QRCode](#qrcode)
- [Statistic](#statistic)
- [Timeline](#timeline)
- [Tree](#tree)

---

## Avatar

頭像。

```tsx
import { Avatar } from 'antd';
```

### Avatar Props

| Prop | Type | Default |
|------|------|---------|
| `src` | `string \| ReactNode` | - (圖片地址或圖片元素，ReactNode: v4.8.0+) |
| `srcSet` | `string` | - (不同解析度來源) |
| `icon` | `ReactNode` | - (圖示型頭像) |
| `alt` | `string` | - |
| `shape` | `'circle' \| 'square'` | `'circle'` |
| `size` | `number \| 'large' \| 'small' \| 'default' \| { xs, sm, ... }` | `'default'` (響應式: v4.7.0+) |
| `gap` | `number` | `4` (字元型左右間距，v4.3.0+) |
| `draggable` | `boolean \| 'true' \| 'false'` | `true` |
| `crossOrigin` | `'anonymous' \| 'use-credentials' \| ''` | - (v4.17.0+) |
| `onError` | `() => boolean` | - (圖片載入失敗，回傳 false 阻止預設 fallback) |

### Avatar.Group Props

| Prop | Type | Default |
|------|------|---------|
| `max` | `{ count?: number; style?: CSSProperties; popover?: PopoverProps }` | - (v5.18.0+) |
| `size` | `number \| 'large' \| 'small' \| 'default' \| { xs, sm, ... }` | `'default'` (v4.8.0+) |
| `shape` | `'circle' \| 'square'` | `'circle'` (v5.8.0+) |

### Design Tokens

`containerSize` (32), `containerSizeLG` (40), `containerSizeSM` (24), `groupOverlapping` (-8),
`groupSpace` (4), `groupBorderColor` (#fff)

---

## Calendar

日曆。基於 dayjs。

```tsx
import { Calendar } from 'antd';
```

### Calendar Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `dayjs` | - (當前選中日期) |
| `defaultValue` | `dayjs` | - |
| `mode` | `'month' \| 'year'` | `'month'` |
| `fullscreen` | `boolean` | `true` (全螢幕顯示) |
| `showWeek` | `boolean` | `false` (v5.23.0+，顯示週數) |
| `validRange` | `[dayjs, dayjs]` | - (有效範圍) |
| `disabledDate` | `(currentDate: Dayjs) => boolean` | - (不可選日期) |
| `cellRender` | `(current: dayjs, info) => ReactNode` | - (v5.4.0+，自訂格子內容) |
| `fullCellRender` | `(current: dayjs, info) => ReactNode` | - (v5.4.0+，覆蓋整格) |
| `headerRender` | `({ value, type, onChange, onTypeChange }) => ReactNode` | - (自訂頭部) |
| `locale` | `object` | (預設) |
| `onChange` | `(date: Dayjs) => void` | - |
| `onPanelChange` | `(date: Dayjs, mode: string) => void` | - |
| `onSelect` | `(date: Dayjs, info: { source: 'year' \| 'month' \| 'date' \| 'customize' }) => void` | - (info: v5.6.0+) |

> `dateCellRender` / `dateFullCellRender` / `monthCellRender` 已 deprecated，改用 `cellRender` / `fullCellRender`。

---

## Card

卡片容器。

```tsx
import { Card } from 'antd';
```

### Card Props

| Prop | Type | Default |
|------|------|---------|
| `title` | `ReactNode` | - |
| `extra` | `ReactNode` | - (右上角內容) |
| `cover` | `ReactNode` | - (封面) |
| `actions` | `Array<ReactNode>` | - (底部操作列) |
| `variant` | `'outlined' \| 'borderless'` | `'outlined'` (v5.24.0+，取代 `bordered`) |
| `hoverable` | `boolean` | `false` (懸停浮起) |
| `loading` | `boolean` | `false` |
| `size` | `'default' \| 'small'` | `'default'` |
| `type` | `'inner'` | - |
| `tabList` | `TabItemType[]` | - (標籤列表) |
| `activeTabKey` | `string` | - |
| `defaultActiveTabKey` | `string` | 第一個 tab |
| `tabBarExtraContent` | `ReactNode` | - |
| `tabProps` | `TabsProps` | - |
| `classNames` | `Record<SemanticDOM, string>` | - (v5.14.0+) |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - (v5.14.0+) |
| `onTabChange` | `(key) => void` | - |

> `bordered` 已 deprecated，改用 `variant`。

### Card.Grid Props

| Prop | Type | Default |
|------|------|---------|
| `hoverable` | `boolean` | `true` |
| `className` | `string` | - |
| `style` | `CSSProperties` | - |

### Card.Meta Props

| Prop | Type | Default |
|------|------|---------|
| `avatar` | `ReactNode` | - |
| `title` | `ReactNode` | - |
| `description` | `ReactNode` | - |

### Design Tokens

`headerBg` (transparent), `headerFontSize` (16), `headerHeight` (56), `bodyPadding` (24),
`actionsBg` (#fff), `extraColor`

---

## Carousel

走馬燈。

```tsx
import { Carousel } from 'antd';
```

### Carousel Props

| Prop | Type | Default |
|------|------|---------|
| `autoplay` | `boolean \| { dotDuration?: boolean }` | `false` (dotDuration: v5.24.0+) |
| `autoplaySpeed` | `number` | `3000` (ms) |
| `arrows` | `boolean` | `false` (v5.17.0+，切換箭頭) |
| `dots` | `boolean \| { className?: string }` | `true` |
| `dotPosition` | `'top' \| 'bottom' \| 'left' \| 'right'` | `'bottom'` |
| `adaptiveHeight` | `boolean` | `false` |
| `draggable` | `boolean` | `false` (桌面可拖曳) |
| `fade` | `boolean` | `false` |
| `effect` | `'scrollx' \| 'fade'` | `'scrollx'` |
| `infinite` | `boolean` | `true` |
| `speed` | `number` | `500` (ms) |
| `easing` | `string` | `'linear'` |
| `waitForAnimate` | `boolean` | `false` |
| `afterChange` | `(current: number) => void` | - |
| `beforeChange` | `(current: number, next: number) => void` | - |

**Methods**: `goTo(slideNumber, dontAnimate)`, `next()`, `prev()`

---

## Collapse

折疊面板。

```tsx
import { Collapse } from 'antd';
import type { CollapseProps } from 'antd';
```

### Collapse Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `ItemType[]` | - (v5.6.0+，面板內容) |
| `activeKey` | `string[] \| string \| number[] \| number` | accordion 模式下為第一個 panel | - |
| `defaultActiveKey` | `string[] \| string \| number[] \| number` | - |
| `accordion` | `boolean` | `false` (手風琴模式) |
| `bordered` | `boolean` | `true` |
| `ghost` | `boolean` | `false` (v4.4.0+，無邊框透明背景) |
| `collapsible` | `'header' \| 'icon' \| 'disabled'` | - (v4.9.0+) |
| `destroyOnHidden` | `boolean` | `false` (v5.25.0+，取代 `destroyInactivePanel`) |
| `expandIcon` | `(panelProps) => ReactNode` | - |
| `expandIconPosition` | `'start' \| 'end'` | - (v4.21.0+) |
| `size` | `'large' \| 'middle' \| 'small'` | `'middle'` (v5.2.0+) |
| `onChange` | `(key) => void` | - |

### Collapse Item (ItemType)

| Prop | Type | Default |
|------|------|---------|
| `key` | `string \| number` | - |
| `label` | `ReactNode` | - (面板標題) |
| `children` | `ReactNode` | - (面板內容) |
| `extra` | `ReactNode` | - (右上角元素) |
| `collapsible` | `'header' \| 'icon' \| 'disabled'` | - |
| `showArrow` | `boolean` | `true` |
| `forceRender` | `boolean` | `false` |
| `classNames` | `Record<'header' \| 'body', string>` | - (v5.21.0+) |
| `styles` | `Record<'header' \| 'body', CSSProperties>` | - (v5.21.0+) |

### Design Tokens

`headerBg`, `headerPadding`, `contentBg` (#fff), `contentPadding`

---

## Empty

空狀態。

```tsx
import { Empty } from 'antd';
```

### Empty Props

| Prop | Type | Default |
|------|------|---------|
| `description` | `ReactNode` | - |
| `image` | `ReactNode` | `Empty.PRESENTED_IMAGE_DEFAULT` (字串視為圖片 url) |
| `imageStyle` | `CSSProperties` | - |

### 內建圖片常數

- `Empty.PRESENTED_IMAGE_DEFAULT` — 預設空狀態圖
- `Empty.PRESENTED_IMAGE_SIMPLE` — 簡約空狀態圖

```tsx
<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="無資料" />
```

---

## List

列表。

```tsx
import { List } from 'antd';
```

### List Props

| Prop | Type | Default |
|------|------|---------|
| `dataSource` | `any[]` | - |
| `renderItem` | `(item: T, index: number) => ReactNode` | - |
| `rowKey` | `keyof T \| (item: T) => React.Key` | `'key'` |
| `bordered` | `boolean` | `false` |
| `header` | `ReactNode` | - |
| `footer` | `ReactNode` | - |
| `itemLayout` | `'horizontal' \| 'vertical'` | `'horizontal'` |
| `size` | `'default' \| 'large' \| 'small'` | `'default'` |
| `split` | `boolean` | `true` |
| `grid` | `ListGridType` | - (柵格佈局，如 `{ gutter: 16, column: 4 }`) |
| `loading` | `boolean \| SpinProps` | `false` |
| `loadMore` | `ReactNode` | - |
| `locale` | `{ emptyText: ReactNode }` | `{ emptyText: 'No Data' }` |
| `pagination` | `boolean \| PaginationConfig` | `false` |

### ListGridType

| Prop | Type |
|------|------|
| `column` | `number` |
| `gutter` | `number` |
| `xs` / `sm` / `md` / `lg` / `xl` / `xxl` | `number` (各斷點欄數) |

### List.Item Props

| Prop | Type | Default |
|------|------|---------|
| `actions` | `Array<ReactNode>` | - |
| `extra` | `ReactNode` | - |
| `classNames` | `Record<'actions' \| 'extra', string>` | - (v5.18.0+) |
| `styles` | `Record<'actions' \| 'extra', CSSProperties>` | - (v5.18.0+) |

### List.Item.Meta Props

| Prop | Type |
|------|------|
| `avatar` | `ReactNode` |
| `title` | `ReactNode` |
| `description` | `ReactNode` |

---

## Popover

氣泡卡片。

```tsx
import { Popover } from 'antd';
```

### Popover Props

| Prop | Type | Default |
|------|------|---------|
| `content` | `ReactNode \| () => ReactNode` | - |
| `title` | `ReactNode \| () => ReactNode` | - |
| `trigger` | `'hover' \| 'focus' \| 'click' \| 'contextMenu' \| Array` | `'hover'` |
| `open` | `boolean` | `false` (v4.23.0+) |
| `defaultOpen` | `boolean` | `false` (v4.23.0+) |
| `placement` | `'top' \| 'left' \| 'right' \| 'bottom' \| 'topLeft' \| 'topRight' \| 'bottomLeft' \| 'bottomRight' \| 'leftTop' \| 'leftBottom' \| 'rightTop' \| 'rightBottom'` | `'top'` |
| `arrow` | `boolean \| { pointAtCenter: boolean }` | `true` (v5.2.0+) |
| `autoAdjustOverflow` | `boolean` | `true` |
| `color` | `string` | - (v4.3.0+) |
| `fresh` | `boolean` | `false` (v5.10.0+，關閉時不快取內容) |
| `destroyOnHidden` | `boolean` | `false` (v5.25.0+) |
| `mouseEnterDelay` | `number` | `0.1` (秒) |
| `mouseLeaveDelay` | `number` | `0.1` (秒) |
| `zIndex` | `number` | - |
| `classNames` | `Record<SemanticDOM, string>` | - (v5.23.0+) |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - (v5.23.0+) |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `() => document.body` |
| `onOpenChange` | `(open: boolean) => void` | - (v4.23.0+) |

> `overlayClassName` / `overlayStyle` / `overlayInnerStyle` 已 deprecated，改用 `classNames` / `styles`。

### Design Tokens

`titleMinWidth` (177), `zIndexPopup` (1030)

---

## QRCode

二維碼。

```tsx
import { QRCode } from 'antd';
```

### QRCode Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `string \| string[]` | - (掃描文字，string[]: v5.28.0+) |
| `type` | `'canvas' \| 'svg'` | `'canvas'` (v5.6.0+) |
| `icon` | `string` | - (僅支援圖片連結) |
| `size` | `number` | `160` |
| `iconSize` | `number \| { width, height }` | `40` (v5.19.0+) |
| `color` | `string` | `'#000'` |
| `bgColor` | `string` | `'transparent'` (v5.5.0+) |
| `bordered` | `boolean` | `true` |
| `errorLevel` | `'L' \| 'M' \| 'Q' \| 'H'` | `'M'` (錯誤碼等級) |
| `boostLevel` | `boolean` | `true` (v5.28.0+) |
| `status` | `'active' \| 'expired' \| 'loading' \| 'scanned'` | `'active'` (scanned: v5.13.0+) |
| `statusRender` | `(info: StatusRenderInfo) => ReactNode` | - (v5.20.0+) |
| `onRefresh` | `() => void` | - |

---

## Statistic

統計數值。

```tsx
import { Statistic } from 'antd';
```

### Statistic Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `string \| number` | - |
| `title` | `ReactNode` | - |
| `precision` | `number` | - (數值精度) |
| `decimalSeparator` | `string` | `'.'` |
| `groupSeparator` | `string` | `','` |
| `prefix` | `ReactNode` | - |
| `suffix` | `ReactNode` | - |
| `formatter` | `(value) => ReactNode` | - |
| `loading` | `boolean` | `false` (v4.8.0+) |
| `valueStyle` | `CSSProperties` | - |

### Statistic.Timer Props

| Prop | Type | Default |
|------|------|---------|
| `type` | `'countdown' \| 'countup'` | - |
| `value` | `number` | - (目標時間) |
| `format` | `string` | `'HH:mm:ss'` |
| `title` | `ReactNode` | - |
| `prefix` / `suffix` | `ReactNode` | - |
| `valueStyle` | `CSSProperties` | - |
| `onFinish` | `() => void` | - (type=countdown 時時間到觸發) |
| `onChange` | `(value: number) => void` | - |

> `Statistic.Countdown` 仍可用，建議改用 `Statistic.Timer type="countdown"`。

### Design Tokens

`titleFontSize` (14), `contentFontSize` (24)

---

## Timeline

時間軸。

```tsx
import { Timeline } from 'antd';
```

### Timeline Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `TimelineItem[]` | - (v5.2.0+) |
| `mode` | `'left' \| 'alternate' \| 'right'` | - (alternate 左右交替分布) |
| `pending` | `ReactNode \| boolean` | `false` (最後一個幽靈節點) |
| `pendingDot` | `ReactNode` | `<LoadingOutlined />` |
| `reverse` | `boolean` | `false` |

### TimelineItem

| Prop | Type | Default |
|------|------|---------|
| `color` | `string` | `'blue'` (`blue` / `red` / `green` / `gray` 或自訂) |
| `dot` | `ReactNode` | - (自訂節點圖示) |
| `label` | `ReactNode` | - |
| `children` | `ReactNode` | - (內容) |
| `position` | `'left' \| 'right'` | - (自訂節點位置) |

### Design Tokens

`dotBg` (#fff), `dotBorderWidth` (2), `tailColor`, `tailWidth` (2), `itemPaddingBottom` (20)

---

## Tree

樹形控件。

```tsx
import { Tree } from 'antd';
import type { TreeDataNode, TreeProps } from 'antd';
```

### Tree Props

| Prop | Type | Default |
|------|------|---------|
| `treeData` | `Array<{ key, title, children, disabled?, selectable? }>` | - (key 須全域唯一) |
| `checkable` | `boolean` | `false` (節點前加 Checkbox) |
| `checkedKeys` | `string[] \| { checked, halfChecked }` | `[]` |
| `defaultCheckedKeys` | `string[]` | `[]` |
| `checkStrictly` | `boolean` | `false` (父子節點不關聯) |
| `selectedKeys` | `string[]` | - |
| `defaultSelectedKeys` | `string[]` | `[]` |
| `expandedKeys` | `string[]` | `[]` |
| `defaultExpandedKeys` | `string[]` | `[]` |
| `defaultExpandAll` | `boolean` | `false` |
| `defaultExpandParent` | `boolean` | `true` |
| `autoExpandParent` | `boolean` | `false` |
| `multiple` | `boolean` | `false` |
| `selectable` | `boolean` | `true` |
| `disabled` | `boolean` | `false` |
| `blockNode` | `boolean` | `false` (節點填滿剩餘水平空間) |
| `draggable` | `boolean \| (node) => boolean \| { icon?, nodeDraggable? }` | `false` (config: v4.17.0+) |
| `allowDrop` | `({ dropNode, dropPosition }) => boolean` | - |
| `showIcon` | `boolean` | `false` |
| `icon` | `ReactNode \| (props) => ReactNode` | - |
| `showLine` | `boolean \| { showLeafIcon }` | `false` |
| `switcherIcon` | `ReactNode \| (props) => ReactNode` | - (renderProps: v4.20.0+) |
| `switcherLoadingIcon` | `ReactNode` | - (v5.20.0+) |
| `fieldNames` | `{ title, key, children }` | `{ title, key, children }` (v4.17.0+) |
| `filterTreeNode` | `(node) => boolean` | - (高亮過濾) |
| `height` | `number` | - (虛擬滾動高度) |
| `virtual` | `boolean` | `true` (v4.1.0+) |
| `loadData` | `(node) => Promise` | - (非同步載入) |
| `loadedKeys` | `string[]` | `[]` |
| `titleRender` | `(nodeData) => ReactNode` | - (v4.5.0+) |
| `rootStyle` | `CSSProperties` | - (v4.20.0+) |
| `onCheck` | `(checkedKeys, e) => void` | - |
| `onExpand` | `(expandedKeys, { expanded, node }) => void` | - |
| `onSelect` | `(selectedKeys, e) => void` | - |
| `onLoad` | `(loadedKeys, { event, node }) => void` | - |
| `onRightClick` | `({ event, node }) => void` | - |
| `onDragStart` / `onDragEnter` / `onDragOver` / `onDragLeave` / `onDragEnd` / `onDrop` | `(info) => void` | - |

### Tree.DirectoryTree

額外屬性：`expandAction`：`false \| 'click' \| 'doubleClick'`（預設 `'click'`，目錄展開邏輯）。

### TreeNode

| Prop | Type | Default |
|------|------|---------|
| `key` | `string` | - (須全樹唯一；配合 ExpandedKeys / CheckedKeys / SelectedKeys 使用) |
| `title` | `ReactNode` | - |
| `checkable` | `boolean` | - |
| `disabled` | `boolean` | `false` |
| `disableCheckbox` | `boolean` | `false` |
| `isLeaf` | `boolean` | - (指定 loadData 時生效) |
| `icon` | `ReactNode \| (props) => ReactNode` | - |
| `selectable` | `boolean` | `true` |

**Methods**: `scrollTo({ key, align?: 'top' \| 'bottom' \| 'auto', offset? })`

### Design Tokens

`indentSize` (24), `titleHeight` (24), `nodeSelectedBg` (#e6f4ff), `nodeHoverBg`,
`directoryNodeSelectedBg` (#1677ff), `directoryNodeSelectedColor` (#fff)
