# Data Display Components

## Table of Contents
- [Table](#table)
- [Tag](#tag)
- [Badge](#badge)
- [Image](#image)
- [Tooltip](#tooltip)
- [Descriptions](#descriptions)

---

## Table

```tsx
import { Table } from 'antd';
import type { TableProps, TableColumnsType, TablePaginationConfig, TableRef } from 'antd';
```

### Table Props

| Prop | Type | Default |
|------|------|---------|
| `bordered` | `boolean` | `false` |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `columns` | `ColumnsType<T>[]` | - |
| `components` | `TableComponents` | - |
| `dataSource` | `T[]` | - |
| `expandable` | `ExpandableConfig<T>` | - |
| `footer` | `(currentPageData: T[]) => ReactNode` | - |
| `getPopupContainer` | `(triggerNode: HTMLElement) => HTMLElement` | - |
| `loading` | `boolean \| SpinProps` | `false` |
| `locale` | `object` | - |
| `pagination` | `TablePaginationConfig \| false` | - |
| `rowClassName` | `(record: T, index: number) => string` | - |
| `rowKey` | `string \| (record: T) => string` | `'key'` |
| `rowSelection` | `TableRowSelection<T>` | - |
| `rowHoverable` | `boolean` | `true` (v5.16.0+) |
| `scroll` | `{ x?: number \| string \| true, y?: number \| string, scrollToFirstRowOnChange?: boolean }` | - |
| `showHeader` | `boolean` | `true` |
| `showSorterTooltip` | `boolean \| TooltipProps & { target?: 'full-header' \| 'sorter-icon' }` | `{ target: 'full-header' }` |
| `size` | `'large' \| 'medium' \| 'small'` | `'large'` |
| `sortDirections` | `('ascend' \| 'descend')[]` | `['ascend', 'descend']` |
| `sticky` | `boolean \| { offsetHeader?: number, offsetScroll?: number, getContainer?: () => HTMLElement }` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |
| `summary` | `(currentData: T[]) => ReactNode` | - |
| `tableLayout` | `'auto' \| 'fixed'` | - |
| `title` | `(currentPageData: T[]) => ReactNode` | - |
| `virtual` | `boolean` | - (v5.9.0+) |
| `onChange` | `(pagination, filters, sorter, extra: { currentDataSource: T[], action: 'paginate' \| 'sort' \| 'filter' }) => void` | - |
| `onHeaderRow` | `(columns, index) => HTMLAttributes` | - |
| `onRow` | `(record: T, index: number) => HTMLAttributes` | - |
| `onScroll` | `(event: UIEvent) => void` | - (v5.16.0+) |

### Column Props (ColumnsType)

| Prop | Type | Default |
|------|------|---------|
| `align` | `'left' \| 'right' \| 'center'` | `'left'` |
| `className` | `string` | - |
| `colSpan` | `number` | - |
| `dataIndex` | `string \| string[]` | - |
| `defaultFilteredValue` | `string[]` | - |
| `defaultSortOrder` | `'ascend' \| 'descend'` | - |
| `ellipsis` | `boolean \| { showTitle?: boolean }` | `false` |
| `filterDropdown` | `ReactNode \| (props: FilterDropdownProps) => ReactNode` | - |
| `filtered` | `boolean` | `false` |
| `filteredValue` | `string[]` | - |
| `filterIcon` | `ReactNode \| (filtered: boolean) => ReactNode` | - |
| `filterMultiple` | `boolean` | `true` |
| `filterMode` | `'menu' \| 'tree'` | `'menu'` |
| `filterSearch` | `boolean \| (input: string, record) => boolean` | `false` |
| `filterOnClose` | `boolean` | `true` (v5.15.0+) |
| `filters` | `{ text: ReactNode, value: string \| number \| boolean, children?: [] }[]` | - |
| `filterDropdownProps` | `DropdownProps` | - (v5.22.0+) |
| `fixed` | `boolean \| 'left' \| 'right'` | `false` |
| `hidden` | `boolean` | `false` (v5.13.0+) |
| `key` | `string` | - |
| `render` | `(value: any, record: T, index: number) => ReactNode` | - |
| `responsive` | `Breakpoint[]` | - |
| `rowScope` | `'row' \| 'rowgroup'` | - (v5.1.0+) |
| `shouldCellUpdate` | `(record: T, prevRecord: T) => boolean` | - |
| `showSorterTooltip` | `boolean \| TooltipProps & { target?: 'full-header' \| 'sorter-icon' }` | - |
| `sortDirections` | `('ascend' \| 'descend')[]` | - |
| `sorter` | `((a: T, b: T) => number) \| boolean \| { compare: (a, b) => number, multiple: number }` | - |
| `sortOrder` | `'ascend' \| 'descend' \| null` | - |
| `sortIcon` | `(props: { sortOrder }) => ReactNode` | - (v5.6.0+) |
| `title` | `ReactNode \| ({ sortColumns, filters }) => ReactNode` | - |
| `width` | `string \| number` | - |
| `minWidth` | `number` | - (v5.21.0+, tableLayout='auto') |
| `onCell` | `(record: T, index: number) => HTMLAttributes` | - |
| `onFilter` | `(value, record: T) => boolean` | - |
| `onHeaderCell` | `(column) => HTMLAttributes` | - |

### ColumnGroup

| Prop | Type |
|------|------|
| `title` | `ReactNode` |

### Expandable Config

| Prop | Type | Default |
|------|------|---------|
| `childrenColumnName` | `string` | `'children'` |
| `columnTitle` | `ReactNode` | - |
| `columnWidth` | `string \| number` | - |
| `defaultExpandAllRows` | `boolean` | `false` |
| `defaultExpandedRowKeys` | `string[]` | - |
| `expandedRowClassName` | `string \| (record, index, indent) => string` | - |
| `expandedRowKeys` | `string[]` | - |
| `expandedRowRender` | `(record, index, indent, expanded) => ReactNode` | - |
| `expandIcon` | `(props) => ReactNode` | - |
| `expandRowByClick` | `boolean` | `false` |
| `fixed` | `boolean \| 'left' \| 'right'` | `false` |
| `indentSize` | `number` | `15` |
| `rowExpandable` | `(record) => boolean` | - |
| `showExpandColumn` | `boolean` | `true` |
| `onExpand` | `(expanded, record) => void` | - |
| `onExpandedRowsChange` | `(expandedRows) => void` | - |

### RowSelection Config

| Prop | Type | Default |
|------|------|---------|
| `checkStrictly` | `boolean` | `true` |
| `columnTitle` | `ReactNode \| (originalNode) => ReactNode` | - |
| `columnWidth` | `string \| number` | `'32px'` |
| `fixed` | `boolean` | - |
| `getCheckboxProps` | `(record) => CheckboxProps` | - |
| `hideSelectAll` | `boolean` | `false` |
| `preserveSelectedRowKeys` | `boolean` | - |
| `renderCell` | `(checked, record, index, originNode) => ReactNode` | - |
| `selectedRowKeys` | `(string \| number)[]` | `[]` |
| `selections` | `Selection[] \| boolean` | - |
| `type` | `'checkbox' \| 'radio'` | `'checkbox'` |
| `onCell` | `(record, rowIndex) => HTMLAttributes` | - (v5.5.0+) |
| `onChange` | `(selectedRowKeys, selectedRows, info: { type }) => void` | - |
| `onSelect` | `(record, selected, selectedRows, nativeEvent) => void` | - |

### Selection Type

| Prop | Type |
|------|------|
| `key` | `string` |
| `text` | `ReactNode` |
| `onSelect` | `(changeableRowKeys) => void` |

### Table Ref (v5.11.0+)

| Property | Type |
|----------|------|
| `nativeElement` | `HTMLDivElement` |
| `scrollTo` | `(config: { index?: number, key?: React.Key, top?: number, offset?: number }) => void` |

### Design Tokens

`bodySortBg`, `borderColor`, `cellFontSize`, `cellPaddingBlock`, `cellPaddingInline`,
`expandIconBg`, `filterDropdownBg`, `headerBg`, `headerColor`, `rowExpandedBg`,
`rowHoverBg`, `rowSelectedBg`, `selectionColumnWidth`, `stickyScrollBarBg`

---

## Tag

```tsx
import { Tag } from 'antd';
```

### Tag Props

| Prop | Type | Default |
|------|------|---------|
| `closeIcon` | `ReactNode` | `false` (null/false hides) |
| `color` | `string` | - |
| `icon` | `ReactNode` | - |
| `onClose` | `(e) => void` | - (e.preventDefault() prevents close) |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Tag.CheckableTag Props

| Prop | Type | Default |
|------|------|---------|
| `checked` | `boolean` | `false` |
| `icon` | `ReactNode` | - |
| `onChange` | `(checked: boolean) => void` | - |

### Preset Colors

`magenta`, `red`, `volcano`, `orange`, `gold`, `lime`, `green`, `cyan`, `blue`, `geekblue`, `purple`

### Status Colors

`success`, `processing`, `warning`, `error`, `default`

---

## Badge

```tsx
import { Badge } from 'antd';
```

### Badge Props

| Prop | Type | Default |
|------|------|---------|
| `color` | `string` | - |
| `count` | `ReactNode` | - |
| `dot` | `boolean` | `false` |
| `offset` | `[number, number]` | - |
| `overflowCount` | `number` | `99` |
| `showZero` | `boolean` | `false` |
| `size` | `'medium' \| 'small'` | - |
| `status` | `'success' \| 'processing' \| 'default' \| 'error' \| 'warning'` | - |
| `text` | `ReactNode` | - (requires status) |
| `title` | `string` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Badge.Ribbon Props

| Prop | Type | Default |
|------|------|---------|
| `color` | `string` | - |
| `placement` | `'start' \| 'end'` | `'end'` |
| `text` | `ReactNode` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

---

## Image

```tsx
import { Image } from 'antd';
```

### Image Props

| Prop | Type | Default |
|------|------|---------|
| `alt` | `string` | - |
| `src` | `string` | - |
| `width` | `string \| number` | - |
| `height` | `string \| number` | - |
| `placeholder` | `ReactNode` | - (true = default) |
| `fallback` | `string` | - |
| `preview` | `boolean \| PreviewType` | `true` |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |
| `onError` | `(event: Event) => void` | - |

### PreviewType Props

| Prop | Type | Default |
|------|------|---------|
| `src` | `string` | - |
| `open` | `boolean` | - |
| `mask` | `boolean \| { blur?: boolean }` | `true` |
| `movable` | `boolean` | `true` |
| `minScale` | `number` | `1` |
| `maxScale` | `number` | `50` |
| `scaleStep` | `number` | `0.5` |
| `getContainer` | `string \| HTMLElement \| (() => HTMLElement) \| false` | - |
| `imageRender` | `(originalNode: ReactElement, info: { transform: TransformType }) => ReactNode` | - |
| `actionsRender` | `(originalNode: ReactElement, info: { transform: TransformType, current: number }) => ReactNode` | - |
| `closeIcon` | `ReactNode` | - |
| `onOpenChange` | `(open: boolean) => void` | - |
| `onTransform` | `(info: { transform: TransformType, action: TransformAction }) => void` | - |

### Image.PreviewGroup Props

| Prop | Type | Default |
|------|------|---------|
| `items` | `string[] \| { src: string, crossOrigin?: string }[]` | - |
| `preview` | `boolean \| PreviewGroupType` | `true` |
| `fallback` | `string` | - |

PreviewGroupType extends PreviewType with:

| Prop | Type |
|------|------|
| `current` | `number` |
| `countRender` | `(current: number, total: number) => ReactNode` |
| `onChange` | `(current: number, prevCurrent: number) => void` |

### TransformType

```tsx
{ x: number; y: number; rotate: number; scale: number; flipX: boolean; flipY: boolean }
```

### TransformAction

```tsx
'flipY' | 'flipX' | 'rotateLeft' | 'rotateRight' | 'zoomIn' | 'zoomOut' |
'close' | 'prev' | 'next' | 'wheel' | 'doubleClick' | 'move' | 'dragRebound'
```

---

## Tooltip

```tsx
import { Tooltip } from 'antd';
```

### Tooltip Props

| Prop | Type | Default |
|------|------|---------|
| `title` | `ReactNode \| () => ReactNode` | - |
| `color` | `string` | - |
| `arrow` | `boolean \| { pointAtCenter: boolean }` | `true` |
| `placement` | `'top' \| 'left' \| 'right' \| 'bottom' \| 'topLeft' \| 'topRight' \| 'bottomLeft' \| 'bottomRight' \| 'leftTop' \| 'leftBottom' \| 'rightTop' \| 'rightBottom'` | `'top'` |
| `trigger` | `'hover' \| 'focus' \| 'click' \| 'contextMenu' \| Array` | `'hover'` |
| `open` | `boolean` | - |
| `defaultOpen` | `boolean` | `false` |
| `mouseEnterDelay` | `number` | `0.1` (seconds) |
| `mouseLeaveDelay` | `number` | `0.1` (seconds) |
| `autoAdjustOverflow` | `boolean` | `true` |
| `getPopupContainer` | `(triggerNode: HTMLElement) => HTMLElement` | `document.body` |
| `destroyTooltipOnHide` | `boolean` | `false` |
| `fresh` | `boolean` | `false` |
| `zIndex` | `number` | - |
| `onOpenChange` | `(open: boolean) => void` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Design Tokens

`maxWidth` (default: 250), `zIndexPopup` (default: 1070)

---

## Descriptions

```tsx
import { Descriptions } from 'antd';
import type { DescriptionsProps } from 'antd';
```

### Descriptions Props

| Prop | Type | Default |
|------|------|---------|
| `bordered` | `boolean` | `false` |
| `colon` | `boolean` | `true` |
| `column` | `number \| Record<Breakpoint, number>` | `3` |
| `extra` | `ReactNode` | - |
| `items` | `DescriptionsItem[]` | - |
| `layout` | `'horizontal' \| 'vertical'` | `'horizontal'` |
| `size` | `'large' \| 'medium' \| 'small'` | `'large'` |
| `title` | `ReactNode` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### DescriptionsItem

| Prop | Type | Default |
|------|------|---------|
| `label` | `ReactNode` | - |
| `span` | `number \| 'filled' \| Screens` | `1` |
| `children` | `ReactNode` | - |
