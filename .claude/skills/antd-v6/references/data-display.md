# antd v6 — Data Display Components

> Table, Tag, Tabs, Menu, Steps, Timeline, Badge, Descriptions, Tooltip, Masonry.
> Source: https://ant.design/components/{table,tag,tabs,masonry}.md

## Table of Contents
- [Table](#table)
- [Tag](#tag)
- [Tabs](#tabs)
- [Menu](#menu)
- [Steps](#steps)
- [Timeline](#timeline)
- [Masonry (new in v6)](#masonry-new-in-v6)
- [Tooltip / Badge / Descriptions (v6 notes)](#tooltip--badge--descriptions-v6-notes)

---

## Table

```tsx
import { Table } from 'antd';
import type { TableProps, TableColumnsType } from 'antd';
```

### Table props

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `dataSource` | `RecordType[]` | — | |
| `columns` | `ColumnsType<RecordType>` | — | |
| `column` | `ColumnType<RecordType>` | — | shared props for all columns |
| `rowKey` | `string \| (record) => Key` | `'key'` | |
| `rowSelection` | `TableRowSelection` | — | |
| `expandable` | `ExpandableConfig` | — | |
| `pagination` | `PaginationProps \| false` | — | |
| `size` | `'large' \| 'middle' \| 'small'` | `'large'` | |
| `bordered` | boolean | `false` | |
| `loading` | `boolean \| SpinProps` | `false` | |
| `scroll` | `{ x?: string \| number; y?: string \| number }` | — | |
| `virtual` | boolean | `false` | needs numeric `scroll.x` & `scroll.y` |
| `title` / `footer` | `(data) => ReactNode` | — | |
| `summary` | `(pageData) => ReactNode` | — | |
| `tableLayout` | `'auto' \| 'fixed'` | — | |
| `childrenColumnName` | string | `'children'` | tree data key |
| `indentSize` | number | `15` | |
| `components` | `TableComponents` | — | override internal elements |
| `locale` | `TableLocale` | — | `emptyText`, `filterCheckAll` (v6) |
| `classNames` / `styles` | object / fn | — | keys below |
| `onChange` | see signature below | — | |
| `onRow` | `(record, index) => HTMLAttributes` | — | |

Semantic keys (`classNames` / `styles`): `table`, `thead`, `tbody`, `tfoot`, `header`,
`body`, `footer`, `headerRow`, `headerCell`, `row`, `cell`, `footerCell`, `expandedRow`,
`summary`.

### Column props (`ColumnType<RecordType>`)

| Prop | Type | Notes |
|------|------|-------|
| `key` | `Key` | |
| `dataIndex` | `string \| string[]` | path into record |
| `title` | ReactNode | |
| `render` | `(value, record, index) => ReactNode` | |
| `width` | `string \| number` | |
| `ellipsis` | `boolean \| { showTitle? }` | |
| `align` | `'left' \| 'center' \| 'right'` | |
| `fixed` | **`'start' \| 'end'`** `\| boolean` | **v6**: logical start/end (was left/right) |
| `sorter` | `boolean \| (a,b)=>number \| { compare; multiple? }` | |
| `defaultSortOrder` | `'ascend' \| 'descend'` | |
| `sortDirections` | `('ascend'\|'descend')[]` | |
| `showSorterTooltip` | `boolean \| { target: 'full-header' \| 'sorter-icon' }` | |
| `filters` | `{ text; value }[]` | |
| `onFilter` | `(value, record) => boolean` | |
| `filterMode` | `'menu' \| 'tree'` | |
| `filterSearch` | `boolean \| (input, record) => boolean` | |
| `filterDropdown` | `(props: FilterDropdownProps) => ReactNode` | |
| `filterDropdownProps` | `{ open?; onOpenChange? }` | **v6**: replaces `filterDropdownOpen` |
| `filteredValue` / `defaultFilteredValue` | `FilterValue[]` | |
| `filterMultiple` | boolean | |
| `onCell` | `(record, index) => HTMLAttributes & { colSpan?; rowSpan? }` | |
| `onHeaderCell` | `(column) => HTMLAttributes & { colSpan? }` | |
| `children` | `ColumnType[]` | grouped header |
| `hidden` | boolean | |
| `rowScope` | `'row' \| 'rowgroup'` | a11y |

### Pagination config

```tsx
interface PaginationProps {
  current?: number; pageSize?: number; total?: number;
  defaultCurrent?: number; defaultPageSize?: number;
  showSizeChanger?: boolean; pageSizeOptions?: (string | number)[];
  showQuickJumper?: boolean; showLessItems?: boolean;
  showTotal?: (total, range: [number, number]) => ReactNode;
  hideOnSinglePage?: boolean; simple?: boolean; responsive?: boolean;
  size?: 'default' | 'small'; disabled?: boolean;
  placement?: ('topLeft'|'topCenter'|'topRight'|'bottomLeft'|'bottomCenter'|'bottomRight')[]; // v6: was `position`
  onChange?: (page: number, pageSize: number) => void;
  onShowSizeChange?: (current: number, size: number) => void;
}
```

### rowSelection config

```tsx
interface TableRowSelection<RecordType> {
  type?: 'checkbox' | 'radio';
  selectedRowKeys?: Key[];
  defaultSelectedRowKeys?: Key[];
  onChange?: (keys: Key[], rows: RecordType[], info: { type }) => void;
  getCheckboxProps?: (record) => { disabled?: boolean; name?: string };
  selections?: Selection[] | boolean;       // custom dropdown selections
  preserveSelectedRowKeys?: boolean;        // keep keys across pages
  checkStrictly?: boolean;                  // tree data: parent/child independent
  columnWidth?: string | number;
  columnTitle?: ReactNode;
  renderCell?: (checked, record, index, originNode) => ReactNode;
  onSelect?: (record, selected, selectedRows) => void;
  onSelectAll?: (selected, selectedRows, changeRows) => void;
  onSelectMultiple?: (selected, selectedRows, changeRows) => void;
}
```

> v6: `onSelectInvert` removed — handle inversion via `onChange`.

### expandable config

```tsx
interface ExpandableConfig<RecordType> {
  expandedRowRender?: (record, index) => ReactNode;
  expandedRowClassName?: (record, index) => string;
  expandedRowKeys?: Key[]; defaultExpandedRowKeys?: Key[];
  onExpandedRowsChange?: (keys: Key[]) => void;
  onExpand?: (expanded: boolean, record) => void;
  rowExpandable?: (record) => boolean;
  expandIcon?: (props: ExpandIconProps) => ReactNode;
  expandRowByClick?: boolean;
  columnWidth?: string | number;
}
```

### onChange signature

```tsx
type OnChange = (
  pagination: PaginationConfig,
  filters: Record<string, FilterValue[] | null>,
  sorter: SorterResult | SorterResult[],
  extra: { currentDataSource: RecordType[]; action: 'paginate' | 'sort' | 'filter' },
) => void;

interface SorterResult {
  column?: ColumnType;
  order?: 'ascend' | 'descend' | null;
  field?: string | string[];
  columnKey?: Key;
}
```

### Examples

```tsx
// Fixed columns — v6 start/end API
const columns: TableColumnsType<DataType> = [
  { title: 'Name', dataIndex: 'name', fixed: 'start', width: 120 },
  { title: 'Age', dataIndex: 'age' },
  { title: 'Action', key: 'action', fixed: 'end', width: 100,
    render: (_, r) => <a>Edit {r.name}</a> },
];
<Table columns={columns} dataSource={data} scroll={{ x: 'max-content' }} />

// Controlled pagination + sort + filter
<Table
  columns={columns}
  dataSource={data}
  pagination={{ current: page, pageSize, total }}
  onChange={(pg, filters, sorter, extra) => {
    // extra.action: 'paginate' | 'sort' | 'filter'
  }}
/>

// Virtual scrolling — numeric scroll required
<Table virtual scroll={{ x: 1200, y: 500 }} columns={columns} dataSource={bigData} />

// Row selection
<Table
  rowSelection={{ type: 'checkbox', selectedRowKeys, onChange: setSelectedRowKeys,
                  preserveSelectedRowKeys: true }}
  columns={columns}
  dataSource={data}
/>
```

`FilterDropdownProps`: `{ prefixCls, setSelectedKeys, selectedKeys, confirm, clearFilters,
filters, visible, column, close }`.

---

## Tag

```tsx
import { Tag } from 'antd';
const { CheckableTag } = Tag;
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `variant` | `'filled' \| 'solid' \| 'outlined'` | `'filled'` | **v6** — replaces `bordered` |
| `color` | string \| PresetColor | `'default'` | works with all variants; `'x-inverse'` removed → `variant="solid"` |
| `icon` | ReactNode | — | |
| `closable` | `boolean \| { closeIcon?; 'aria-label'? }` | `false` | |
| `closeIcon` | ReactNode | `false` | |
| `disabled` | boolean | `false` | **v6 new** |
| `href` / `target` | string | — | **v6 new** — renders `<a>` |
| `onClose` | `(e) => void` | — | |
| `classNames` / `styles` | object / fn | — | semantic styling |

> **v6**: trailing `margin-inline-end` removed. Wrap tags in `Space`, or restore via
> `ConfigProvider tag={{ styles: { root: { marginInlineEnd: 8 } } }}`.

`Tag.CheckableTag`: `{ checked, onChange: (checked) => void, icon? }`.

```tsx
<Tag color="blue" variant="solid">Solid</Tag>
<Tag variant="outlined" color="red">Outlined</Tag>
<Tag closable={{ closeIcon: <CloseOutlined />, 'aria-label': 'Remove' }}>Closable</Tag>
<Tag href="https://x.com" target="_blank">Link tag</Tag>
```

---

## Tabs

```tsx
import { Tabs } from 'antd';
import type { TabsProps } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `items` | `TabItemType[]` | `[]` | **v6** — replaces `Tabs.TabPane` children |
| `activeKey` / `defaultActiveKey` | string | — | |
| `type` | `'line' \| 'card' \| 'editable-card'` | `'line'` | |
| `tabPlacement` | `'top' \| 'bottom' \| 'start' \| 'end'` | `'top'` | **v6** — replaces `tabPosition` |
| `size` | size union | `'medium'` | |
| `centered` | boolean | `false` | |
| `destroyOnHidden` | boolean | `false` | **v6** — replaces `destroyInactiveTabPane` |
| `tabBarExtraContent` | `ReactNode \| { left?; right? }` | — | |
| `addIcon` / `removeIcon` | ReactNode | — | editable-card |
| `classNames` / `styles` | object / fn | — | `classNames.popup` replaces `popupClassName` |
| `onChange` | `(activeKey: string) => void` | — | |
| `onEdit` | `(targetKey, action: 'add' \| 'remove') => void` | — | editable-card |

```tsx
interface TabItemType {
  key: string;
  label: ReactNode;
  children: ReactNode;
  disabled?: boolean;
  closable?: boolean;          // editable-card
  destroyOnHidden?: boolean;
  icon?: ReactNode;
}
```

```tsx
<Tabs
  defaultActiveKey="1"
  tabPlacement="start"
  items={[
    { key: '1', label: 'Tab 1', children: 'Content 1' },
    { key: '2', label: 'Tab 2', children: 'Content 2', disabled: true },
  ]}
/>
```

---

## Menu

`children` removed — use `items`.

| Prop | Type | Notes |
|------|------|-------|
| `items` | `ItemType[]` | **v6 required** — replaces JSX children |
| `mode` | `'vertical' \| 'horizontal' \| 'inline'` | |
| `selectedKeys` / `defaultSelectedKeys` | `string[]` | |
| `openKeys` / `defaultOpenKeys` | `string[]` | submenu open state |
| `theme` | `'light' \| 'dark'` | |
| `onClick` | `({ key, keyPath, domEvent }) => void` | |
| `onOpenChange` | `(openKeys: string[]) => void` | |

```tsx
type ItemType =
  | { key: string; label: ReactNode; icon?: ReactNode; disabled?: boolean;
      children?: ItemType[]; type?: 'group' }
  | { type: 'divider' };

<Menu mode="inline" items={[
  { key: 'home', icon: <HomeOutlined />, label: 'Home' },
  { key: 'sub', label: 'More', children: [
    { key: 'a', label: 'Item A' }, { key: 'b', label: 'Item B' },
  ]},
  { type: 'divider' },
]} />
```

---

## Steps

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `items` | `StepItem[]` | — | |
| `current` | number | `0` | |
| `orientation` | `'horizontal' \| 'vertical'` | `'horizontal'` | **v6** — replaces `direction` |
| `type` | `'default' \| 'navigation' \| 'inline' \| 'dot'` | `'default'` | **v6**: `type="dot"` replaces `progressDot` |
| `titlePlacement` | `'horizontal' \| 'vertical'` | — | **v6** — replaces `labelPlacement` |
| `size` | `'default' \| 'small'` | `'default'` | |
| `status` | `'wait'\|'process'\|'finish'\|'error'` | `'process'` | |
| `onChange` | `(current: number) => void` | — | |

```tsx
interface StepItem {
  title?: ReactNode;
  content?: ReactNode;            // v6 — replaces `description`
  subTitle?: ReactNode;
  status?: 'wait' | 'process' | 'finish' | 'error';
  icon?: ReactNode;
  disabled?: boolean;
}
```

---

## Timeline

`Timeline.Item` removed — use `items`. `pending` / `pendingDot` folded into `items`.

| Prop | Type | Notes |
|------|------|-------|
| `items` | `TimelineItem[]` | **v6 required** |
| `mode` | `'start' \| 'alternate' \| 'end'` | **v6**: `start`/`end` replace `left`/`right` |
| `reverse` | boolean | |

```tsx
interface TimelineItem {
  children: ReactNode;
  label?: ReactNode;
  color?: string;          // 'blue' | 'green' | 'red' | 'gray' | custom
  dot?: ReactNode;
  position?: 'start' | 'end';
  pending?: boolean;
}

<Timeline items={[
  { children: 'Created', color: 'green' },
  { children: 'In progress', color: 'blue' },
  { children: 'Pending...', pending: true },
]} />
```

---

## Masonry (new in v6)

Cascading grid layout. Added in 6.0.0.

```tsx
import { Masonry } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `items` | `MasonryItem[]` | — | |
| `columns` | `number \| Partial<Record<Breakpoint, number>>` | `3` | fixed or responsive |
| `gutter` | `Gap \| [Gap, Gap]` | `0` | spacing; `Gap = number \| Partial<Record<Breakpoint, number>>` |
| `itemRender` | `(item: MasonryItem) => ReactNode` | — | custom render |
| `fresh` | boolean | `false` | monitor child size changes |
| `onLayoutChange` | `(items) => void` | — | |
| `classNames` / `styles` | object / fn | — | |

```tsx
interface MasonryItem<T = any> {
  key: string | number;
  data?: T;
  children?: ReactNode;     // overrides itemRender
  column?: number;          // target column
  height?: number;
}
```

```tsx
<Masonry
  columns={{ xs: 1, sm: 2, md: 3, lg: 4 }}
  gutter={{ xs: 8, sm: 12, md: 16 }}
  items={items}
  itemRender={(item) => <Card style={{ height: item.data }} />}
/>
```

Breakpoints: `xs sm md lg xl xxl` (and `xxxl` 1920px since 6.3.0 for Grid; Masonry uses
the standard set).

---

## Tooltip / Badge / Descriptions (v6 notes)

- **Tooltip**: `overlayStyle`→`styles.root`, `overlayInnerStyle`→`styles.container`,
  `overlayClassName`→`classNames.root`, `destroyTooltipOnHide`→`destroyOnHidden`.
  Closes on **ESC by default** (6.2.0+). Trigger configurable globally via ConfigProvider
  `tooltip` (6.1.0+).
- **Badge**: largely unchanged; `Badge.Ribbon` for ribbon labels.
- **Descriptions**: `labelStyle`→`styles.label`, `contentStyle`→`styles.content`.
  Use the `items` prop (`{ label, children, span }[]`) rather than `Descriptions.Item`.
