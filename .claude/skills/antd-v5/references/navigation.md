# Navigation Components

涵蓋 antd v5 導航類元件。所有 API 來自 https://5x.ant.design/ 官方文件（v5.29.x）。

## Table of Contents
- [Affix](#affix)
- [Anchor](#anchor)
- [Breadcrumb](#breadcrumb)
- [Dropdown](#dropdown)
- [Menu](#menu)
- [Steps](#steps)
- [Tabs](#tabs)

---

## Affix

固定元件在頁面可視範圍（滾動時釘住）。

```tsx
import { Affix } from 'antd';
```

### Affix Props

| Prop | Type | Default |
|------|------|---------|
| `offsetTop` | `number` | `0` (距視窗頂部偏移 px) |
| `offsetBottom` | `number` | - (距視窗底部偏移 px) |
| `target` | `() => HTMLElement` | `() => window` (指定可滾動區域) |
| `onChange` | `(affixed?: boolean) => void` | - (固定狀態改變回呼) |

---

## Anchor

錨點導航，配合頁面滾動高亮對應連結。

```tsx
import { Anchor } from 'antd';
```

### Anchor Props

| Prop | Type | Default |
|------|------|---------|
| `affix` | `boolean \| Omit<AffixProps, 'offsetTop' \| 'target' \| 'children'>` | `true` (object: v5.19.0+) |
| `bounds` | `number` | `5` (錨點區域邊界) |
| `getContainer` | `() => HTMLElement` | `() => window` (滾動容器) |
| `getCurrentAnchor` | `(activeLink: string) => string` | - (自訂高亮錨點) |
| `offsetTop` | `number` | `0` |
| `targetOffset` | `number` | - (錨點滾動偏移，預設同 offsetTop) |
| `showInkInFixed` | `boolean` | `false` (affix=false 時是否顯示墨條) |
| `items` | `AnchorItem[]` | - (v5.1.0+) |
| `direction` | `'vertical' \| 'horizontal'` | `'vertical'` (v5.2.0+) |
| `replace` | `boolean` | `false` (用 replace 取代 push history，v5.7.0+) |
| `onChange` | `(currentActiveLink: string) => void` | - |
| `onClick` | `(e: MouseEvent, link: object) => void` | - |

### AnchorItem

| Prop | Type | Default |
|------|------|---------|
| `key` | `string \| number` | - (唯一識別) |
| `href` | `string` | - (目標連結) |
| `target` | `string` | - |
| `title` | `ReactNode` | - (連結內容) |
| `children` | `AnchorItem[]` | - (巢狀，horizontal 方向不支援) |
| `replace` | `boolean` | `false` (v5.7.0+) |

---

## Breadcrumb

麵包屑導航。v5.3.0+ 建議使用 `items` 而非 `Breadcrumb.Item` children。

```tsx
import { Breadcrumb } from 'antd';
```

### Breadcrumb Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `ItemType[]` | - (v5.3.0+，路由堆疊資訊) |
| `itemRender` | `(route, params, routes, paths) => ReactNode` | - (自訂渲染，配合 react-router) |
| `params` | `object` | - (路由參數) |
| `separator` | `ReactNode` | `'/'` |

### ItemType (RouteItemType)

| Prop | Type | Default |
|------|------|---------|
| `className` | `string` | - |
| `dropdownProps` | `DropdownProps` | - (下拉選單 props) |
| `href` | `string` | - (超連結目標，不可與 `path` 並用) |
| `path` | `string` | - (連接路徑，逐段串接，不可與 `href` 並用) |
| `menu` | `MenuProps` | - (v4.24.0+) |
| `title` | `ReactNode` | - (項目名稱) |
| `onClick` | `(e: MouseEvent) => void` | - |

### SeparatorType

設定 `type: 'separator'` 時為自訂分隔符：

| Prop | Type | Default |
|------|------|---------|
| `type` | `'separator'` | - (v5.3.0+，必填) |
| `separator` | `ReactNode` | `'/'` (v5.3.0+) |

### itemRender 範例（搭配 react-router）

```tsx
function itemRender(currentRoute, params, items, paths) {
  const isLast = currentRoute?.path === items[items.length - 1]?.path;
  return isLast
    ? <span>{currentRoute.title}</span>
    : <Link to={`/${paths.join('/')}`}>{currentRoute.title}</Link>;
}
<Breadcrumb itemRender={itemRender} items={items} />
```

---

## Dropdown

下拉選單。子節點需接受 `onMouseEnter`、`onMouseLeave`、`onFocus`、`onClick` 事件。

```tsx
import { Dropdown } from 'antd';
import type { MenuProps } from 'antd';
```

### Dropdown Props

| Prop | Type | Default |
|------|------|---------|
| `menu` | `MenuProps` | - (選單設定，v4.24.0+) |
| `arrow` | `boolean \| { pointAtCenter: boolean }` | `false` |
| `autoAdjustOverflow` | `boolean` | `true` (v5.2.0+) |
| `autoFocus` | `boolean` | `false` (開啟時聚焦，v4.21.0+) |
| `disabled` | `boolean` | - |
| `destroyOnHidden` | `boolean` | `false` (v5.25.0+，取代 `destroyPopupOnHide`) |
| `popupRender` | `(menus: ReactNode) => ReactNode` | - (v5.25.0+，取代 `dropdownRender`) |
| `getPopupContainer` | `(triggerNode: HTMLElement) => HTMLElement` | `() => document.body` |
| `placement` | `'bottom' \| 'bottomLeft' \| 'bottomRight' \| 'top' \| 'topLeft' \| 'topRight'` | `'bottomLeft'` |
| `trigger` | `Array<'click' \| 'hover' \| 'contextMenu'>` | `['hover']` |
| `open` | `boolean` | - |
| `onOpenChange` | `(open: boolean, info: { source: 'trigger' \| 'menu' }) => void` | - (`info.source`: v5.11.0+) |

**已 deprecated**：`destroyPopupOnHide`（用 `destroyOnHidden`）、`dropdownRender`（用 `popupRender`）、`overlayClassName`（用 `classNames.root`）、`overlayStyle`（用 `styles.root`）。

### Dropdown.Button Props

額外屬性（其他繼承 Button）：

| Prop | Type | Default |
|------|------|---------|
| `buttonsRender` | `(buttons: ReactNode[]) => ReactNode[]` | - |
| `loading` | `boolean \| { delay: number, icon?: ReactNode }` | `false` (icon: v5.23.0+) |
| `danger` | `boolean` | - (v4.23.0+) |
| `icon` | `ReactNode` | - (顯示於右側) |
| `size` | `'large' \| 'middle' \| 'small'` | `'middle'` |
| `type` | `'primary' \| 'dashed' \| 'link' \| 'text' \| 'default'` | `'default'` |
| `onClick` | `(event: MouseEvent) => void` | - (點左側按鈕觸發) |

### Design Tokens

`paddingBlock` (5), `zIndexPopup` (1050)

---

## Menu

導航選單，支援 horizontal / vertical / inline 三種模式。

```tsx
import { Menu } from 'antd';
import type { MenuProps } from 'antd';
type MenuItem = Required<MenuProps>['items'][number];
```

### Menu Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `ItemType[]` | - (v4.20.0+，選單內容) |
| `mode` | `'vertical' \| 'horizontal' \| 'inline'` | `'vertical'` |
| `theme` | `'light' \| 'dark'` | `'light'` |
| `defaultOpenKeys` | `string[]` | - (預設展開的子選單 key) |
| `defaultSelectedKeys` | `string[]` | - (預設選中項 key) |
| `openKeys` | `string[]` | - (受控展開 key) |
| `selectedKeys` | `string[]` | - (受控選中 key) |
| `expandIcon` | `ReactNode \| (props) => ReactNode` | - (自訂展開圖示，v4.9.0+) |
| `forceSubMenuRender` | `boolean` | `false` (可見前先渲染子選單到 DOM) |
| `inlineCollapsed` | `boolean` | - (inline 模式收合狀態) |
| `inlineIndent` | `number` | `24` (inline 各層縮排 px) |
| `multiple` | `boolean` | `false` (允許多選) |
| `overflowedIndicator` | `ReactNode` | `<EllipsisOutlined />` (水平折疊省略圖示) |
| `selectable` | `boolean` | `true` |
| `subMenuCloseDelay` | `number` | `0.1` (秒) |
| `subMenuOpenDelay` | `number` | `0` (秒) |
| `triggerSubMenuAction` | `'hover' \| 'click'` | `'hover'` |
| `onClick` | `({ item, key, keyPath, domEvent }) => void` | - |
| `onDeselect` | `({ item, key, keyPath, selectedKeys, domEvent }) => void` | - (僅 multiple) |
| `onOpenChange` | `(openKeys: string[]) => void` | - |
| `onSelect` | `({ item, key, keyPath, selectedKeys, domEvent }) => void` | - |

### ItemType

`type ItemType = MenuItemType | SubMenuType | MenuItemGroupType | MenuDividerType;`

**MenuItemType**

| Prop | Type | Default |
|------|------|---------|
| `key` | `string` | - (唯一 ID) |
| `label` | `ReactNode` | - |
| `icon` | `ReactNode` | - |
| `disabled` | `boolean` | `false` |
| `danger` | `boolean` | `false` |
| `extra` | `ReactNode` | - (v5.21.0+) |
| `title` | `string` | - (收合時顯示標題) |

**SubMenuType**

| Prop | Type | Default |
|------|------|---------|
| `key` | `string` | - |
| `label` | `ReactNode` | - |
| `icon` | `ReactNode` | - |
| `children` | `ItemType[]` | - |
| `disabled` | `boolean` | `false` |
| `popupClassName` | `string` | - (mode="inline" 時無效) |
| `popupOffset` | `[number, number]` | - |
| `theme` | `'light' \| 'dark'` | - (預設繼承 Menu) |
| `onTitleClick` | `({ key, domEvent }) => void` | - |

**MenuItemGroupType** — 設 `type: 'group'`：`{ type: 'group', label, children: MenuItemType[] }`

**MenuDividerType** — 設 `type: 'divider'`：`{ type: 'divider', dashed?: boolean }`（僅用於 vertical popup Menu 或 Dropdown Menu）

### Design Tokens（節錄）

`itemColor`, `itemBg`, `itemHeight` (40), `itemSelectedBg`, `itemSelectedColor`, `itemHoverBg`,
`itemHoverColor`, `itemBorderRadius` (8), `itemMarginBlock` (4), `itemMarginInline` (4),
`subMenuItemBg`, `collapsedWidth` (80), `collapsedIconSize` (16), `iconSize` (14),
`darkItemBg` (#001529), `darkItemColor`, `darkItemSelectedBg` (#1677ff), `dangerItemColor`,
`horizontalItemSelectedColor`, `groupTitleColor`, `dropdownWidth` (160), `zIndexPopup` (1050)

---

## Steps

步驟條，引導使用者依步驟完成任務。

```tsx
import { Steps } from 'antd';
```

### Steps Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `StepItem[]` | `[]` (v4.24.0+) |
| `current` | `number` | `0` (當前步驟，從 0 起算) |
| `initial` | `number` | `0` (起始步驟序號) |
| `direction` | `'horizontal' \| 'vertical'` | `'horizontal'` |
| `labelPlacement` | `'horizontal' \| 'vertical'` | `'horizontal'` |
| `type` | `'default' \| 'navigation' \| 'inline'` | `'default'` (inline: v5.0+) |
| `size` | `'default' \| 'small'` | `'default'` |
| `status` | `'wait' \| 'process' \| 'finish' \| 'error'` | `'process'` (當前步驟狀態) |
| `percent` | `number` | - (process 狀態的進度圈百分比，v4.5.0+，僅基本 Steps) |
| `progressDot` | `boolean \| (iconDot, {index, status, title, description}) => ReactNode` | `false` |
| `responsive` | `boolean` | `true` (寬度 < 532px 改垂直) |
| `onChange` | `(current: number) => void` | - |

### StepItem

| Prop | Type | Default |
|------|------|---------|
| `title` | `ReactNode` | - |
| `subTitle` | `ReactNode` | - |
| `description` | `ReactNode` | - |
| `icon` | `ReactNode` | - |
| `status` | `'wait' \| 'process' \| 'finish' \| 'error'` | `'wait'` (未設定時依 current 自動帶入) |
| `disabled` | `boolean` | `false` |

> `Steps.Step` 仍可用但建議改用 `items`。

### Design Tokens

`iconSize` (32), `iconFontSize` (14), `customIconSize` (32), `dotSize` (8), `dotCurrentSize` (10),
`descriptionMaxWidth` (140), `titleLineHeight` (32), `navArrowColor`

---

## Tabs

標籤頁。

```tsx
import { Tabs } from 'antd';
import type { TabsProps } from 'antd';
```

### Tabs Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `TabItemType[]` | `[]` (v4.23.0+，標籤內容設定) |
| `activeKey` | `string` | - (當前 TabPane key) |
| `defaultActiveKey` | `string` | 第一個 tab 的 key |
| `type` | `'line' \| 'card' \| 'editable-card'` | `'line'` |
| `size` | `'large' \| 'middle' \| 'small'` | `'middle'` |
| `tabPosition` | `'top' \| 'right' \| 'bottom' \| 'left'` | `'top'` |
| `centered` | `boolean` | `false` (v4.4.0+) |
| `animated` | `boolean \| { inkBar: boolean, tabPane: boolean }` | `{ inkBar: true, tabPane: false }` |
| `addIcon` | `ReactNode` | `<PlusOutlined />` (僅 editable-card，v4.4.0+) |
| `removeIcon` | `ReactNode` | `<CloseOutlined />` (僅 editable-card，v5.15.0+) |
| `hideAdd` | `boolean` | `false` (僅 editable-card) |
| `indicator` | `{ size?: number \| (origin: number) => number; align: 'start' \| 'center' \| 'end' }` | - (v5.13.0+) |
| `more` | `MoreProps` | `{ icon: <EllipsisOutlined />, trigger: 'hover' }` |
| `renderTabBar` | `(props, DefaultTabBar) => ReactElement` | - |
| `tabBarExtraContent` | `ReactNode \| { left?: ReactNode, right?: ReactNode }` | - (object: v4.6.0+) |
| `tabBarGutter` | `number` | - |
| `tabBarStyle` | `CSSProperties` | - |
| `destroyOnHidden` | `boolean` | `false` (v5.25.0+，取代 `destroyInactiveTabPane`) |
| `onChange` | `(activeKey: string) => void` | - |
| `onEdit` | `(action === 'add' ? event : targetKey, action) => void` | - (僅 editable-card) |
| `onTabClick` | `(key: string, event: MouseEvent) => void` | - |
| `onTabScroll` | `({ direction: 'left' \| 'right' \| 'top' \| 'bottom' }) => void` | - (v4.3.0+) |

### TabItemType

| Prop | Type | Default |
|------|------|---------|
| `key` | `string` | - |
| `label` | `ReactNode` | - (標籤頭顯示文字) |
| `children` | `ReactNode` | - (標籤頁內容) |
| `icon` | `ReactNode` | - (v5.12.0+) |
| `disabled` | `boolean` | `false` |
| `closable` | `boolean` | `true` (僅 editable-card) |
| `closeIcon` | `ReactNode` | - (僅 editable-card；設 null/false 隱藏，v5.7.0+) |
| `destroyOnHidden` | `boolean` | `false` (v5.25.0+) |
| `forceRender` | `boolean` | `false` (強制渲染內容，非懶載入) |

### Design Tokens（節錄）

`inkBarColor` (#1677ff), `itemColor`, `itemSelectedColor` (#1677ff), `itemHoverColor`,
`itemActiveColor`, `cardBg`, `cardHeight` (40), `cardPadding`, `titleFontSize` (14),
`horizontalItemPadding`, `horizontalItemGutter` (32), `zIndexPopup` (1050)
