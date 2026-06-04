# Data Entry Components (Advanced)

涵蓋 antd v5 進階表單輸入元件。基礎元件（Form、Input、Select、Radio、Segmented、Switch、Upload、DatePicker）見 `data-entry.md`。
所有 API 來自 https://5x.ant.design/ 官方文件（v5.29.x）。

## Table of Contents
- [AutoComplete](#autocomplete)
- [Cascader](#cascader)
- [Checkbox](#checkbox)
- [ColorPicker](#colorpicker)
- [InputNumber](#inputnumber)
- [Mentions](#mentions)
- [Rate](#rate)
- [Slider](#slider)
- [TimePicker](#timepicker)
- [Transfer](#transfer)
- [TreeSelect](#treeselect)

---

## AutoComplete

自動完成輸入框。

```tsx
import { AutoComplete } from 'antd';
```

### AutoComplete Props

| Prop | Type | Default |
|------|------|---------|
| `options` | `{ label, value }[]` | - (比 jsx 定義效能更好) |
| `value` | `string` | - |
| `defaultValue` | `string` | - |
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `false` (object: v5.8.0+) |
| `autoFocus` | `boolean` | `false` |
| `backfill` | `boolean` | `false` (鍵盤選擇時回填輸入框) |
| `children` | `HTMLInputElement \| HTMLTextAreaElement \| React.ReactElement<InputProps>` | `<Input />` |
| `defaultActiveFirstOption` | `boolean` | `true` |
| `disabled` | `boolean` | `false` |
| `filterOption` | `boolean \| (inputValue, option) => boolean` | `true` |
| `popupRender` | `(originNode: ReactNode) => ReactNode` | - (取代 `dropdownRender`) |
| `popupMatchSelectWidth` | `boolean \| number` | `true` |
| `notFoundContent` | `ReactNode` | - |
| `open` | `boolean` | - |
| `placeholder` | `string` | - |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `size` | `'large' \| 'middle' \| 'small'` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `virtual` | `boolean` | `true` (v4.1.0+) |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `() => document.body` |
| `onChange` | `(value) => void` | - |
| `onSelect` | `(value, option) => void` | - |
| `onSearch` | `(value) => void` | - |
| `onClear` | `() => void` | - (v4.6.0+) |
| `onFocus` / `onBlur` | `() => void` | - |
| `onOpenChange` | `(open: boolean) => void` | - (取代 `onDropdownVisibleChange`) |

**Methods**: `blur()`, `focus()`

---

## Cascader

級聯選擇器。

```tsx
import { Cascader } from 'antd';
```

### Cascader Props

| Prop | Type | Default |
|------|------|---------|
| `options` | `Option[]` | - (級聯資料) |
| `value` | `string[] \| number[]` | - |
| `defaultValue` | `string[] \| number[]` | `[]` |
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `true` (object: v5.8.0+) |
| `changeOnSelect` | `boolean` | `false` (每次選擇都改值) |
| `expandTrigger` | `'click' \| 'hover'` | `'click'` |
| `expandIcon` | `ReactNode` | - (v4.4.0+) |
| `displayRender` | `(label, selectedOptions) => ReactNode` | `label => label.join('/')` |
| `fieldNames` | `{ label, value, children }` | `{ label, value, children }` |
| `multiple` | `boolean` | - (v4.17.0+) |
| `showSearch` | `boolean \| ShowSearchType` | `false` (單選模式搜尋框) |
| `searchValue` | `string` | - (v4.17.0+，需配合 showSearch) |
| `loadData` | `(selectedOptions) => void` | - (懶載入，不可與 showSearch 並用) |
| `maxTagCount` | `number \| 'responsive'` | - (v4.17.0+) |
| `maxTagPlaceholder` | `ReactNode \| (omittedValues) => ReactNode` | - (v4.17.0+) |
| `notFoundContent` | `ReactNode` | `'Not Found'` |
| `open` | `boolean` | - (v4.17.0+) |
| `placement` | `'bottomLeft' \| 'bottomRight' \| 'topLeft' \| 'topRight'` | `'bottomLeft'` (v4.17.0+) |
| `prefix` | `ReactNode` | - (v5.22.0+) |
| `showCheckedStrategy` | `Cascader.SHOW_PARENT \| Cascader.SHOW_CHILD` | `Cascader.SHOW_PARENT` (v4.20.0+) |
| `size` | `'large' \| 'middle' \| 'small'` | - |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `suffixIcon` | `ReactNode` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+，underlined: v5.24.0+) |
| `optionRender` | `(option: Option) => ReactNode` | - (v5.16.0+) |
| `popupRender` | `(menus: ReactNode) => ReactNode` | - (取代 `dropdownRender`) |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `() => document.body` |
| `onChange` | `(value, selectedOptions) => void` | - |
| `onOpenChange` | `(value) => void` | - (取代 `onDropdownVisibleChange`) |
| `onSearch` | `(search: string) => void` | - (v4.17.0+) |

### ShowSearch Type

| Prop | Type | Default |
|------|------|---------|
| `filter` | `(inputValue, path) => boolean` | - |
| `limit` | `number \| false` | `50` |
| `matchInputWidth` | `boolean` | `true` |
| `render` | `(inputValue, path) => ReactNode` | - |
| `sort` | `(a, b, inputValue) => number` | - |

### Design Tokens

`controlWidth` (184), `controlItemWidth` (111), `dropdownHeight` (180), `optionSelectedBg` (#e6f4ff)

---

## Checkbox

多選框。

```tsx
import { Checkbox } from 'antd';
import type { CheckboxChangeEvent } from 'antd/es/checkbox';
```

### Checkbox Props

| Prop | Type | Default |
|------|------|---------|
| `checked` | `boolean` | `false` |
| `defaultChecked` | `boolean` | `false` |
| `disabled` | `boolean` | `false` |
| `indeterminate` | `boolean` | `false` (半選狀態) |
| `autoFocus` | `boolean` | `false` |
| `onChange` | `(e: CheckboxChangeEvent) => void` | - |
| `onBlur` / `onFocus` | `() => void` | - |

### Checkbox.Group Props

| Prop | Type | Default |
|------|------|---------|
| `options` | `string[] \| number[] \| Option[]` | `[]` |
| `value` | `(string \| number \| boolean)[]` | `[]` |
| `defaultValue` | `(string \| number)[]` | `[]` |
| `disabled` | `boolean` | `false` |
| `name` | `string` | - (子 input 的 name) |
| `onChange` | `(checkedValue: T[]) => void` | - |

`Option`: `{ label, value, disabled?, title?, className?, style? }`（className: v5.25.0+）

**Methods**: `blur()`, `focus()`, `nativeElement`（v5.17.3+）

---

## ColorPicker

顏色選擇器。

```tsx
import { ColorPicker } from 'antd';
import type { Color } from 'antd/es/color-picker';
```

### ColorPicker Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `string \| Color` | - |
| `defaultValue` | `string \| Color` | - |
| `allowClear` | `boolean` | `false` |
| `arrow` | `boolean \| { pointAtCenter: boolean }` | `true` |
| `disabled` | `boolean` | - |
| `disabledAlpha` | `boolean` | - (v5.8.0+) |
| `disabledFormat` | `boolean` | - |
| `format` | `'rgb' \| 'hex' \| 'hsb'` | - |
| `defaultFormat` | `'rgb' \| 'hex' \| 'hsb'` | `'hex'` (v5.9.0+) |
| `mode` | `'single' \| 'gradient' \| ('single' \| 'gradient')[]` | `'single'` (v5.20.0+) |
| `open` | `boolean` | - |
| `presets` | `{ label, colors, defaultOpen?, key? }[]` | - (defaultOpen: v5.11.0+) |
| `placement` | TooltipProps placement | `'bottomLeft'` |
| `panelRender` | `(panel, extra) => ReactNode` | - (v5.7.0+) |
| `showText` | `boolean \| (color: Color) => ReactNode` | - (v5.7.0+) |
| `size` | `'large' \| 'middle' \| 'small'` | `'middle'` (v5.7.0+) |
| `trigger` | `'hover' \| 'click'` | `'click'` |
| `destroyOnHidden` | `boolean` | `false` (v5.25.0+) |
| `onChange` | `(value: Color, css: string) => void` | - |
| `onChangeComplete` | `(value: Color) => void` | - (v5.7.0+) |
| `onFormatChange` | `(format) => void` | - |
| `onOpenChange` | `(open: boolean) => void` | - |
| `onClear` | `() => void` | - (v5.6.0+) |

### Color Object Methods

`toCssString()` (v5.20.0+), `toHex()`, `toHexString()`, `toHsb()`, `toHsbString()`, `toRgb()`, `toRgbString()`

---

## InputNumber

數字輸入框。

```tsx
import { InputNumber } from 'antd';
```

### InputNumber Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `number` | - |
| `defaultValue` | `number` | - |
| `min` | `number` | `Number.MIN_SAFE_INTEGER` |
| `max` | `number` | `Number.MAX_SAFE_INTEGER` |
| `step` | `number \| string` | `1` |
| `precision` | `number` | - (數值精度) |
| `decimalSeparator` | `string` | - (小數分隔符) |
| `controls` | `boolean \| { upIcon?: ReactNode; downIcon?: ReactNode }` | - (v4.19.0+) |
| `disabled` | `boolean` | `false` |
| `readOnly` | `boolean` | `false` |
| `keyboard` | `boolean` | `true` (v4.12.0+，鍵盤行為) |
| `changeOnWheel` | `boolean` | - (v5.14.0+，滑鼠滾輪控制) |
| `changeOnBlur` | `boolean` | `true` (v5.11.0+) |
| `formatter` | `(value, info: { userTyping, input }) => string` | - (info: v4.17.0+) |
| `parser` | `(string) => number` | - |
| `stringMode` | `boolean` | `false` (v4.13.0+，高精度小數，onChange 回傳字串) |
| `prefix` | `ReactNode` | - (v4.17.0+) |
| `suffix` | `ReactNode` | - (v5.20.0+) |
| `placeholder` | `string` | - |
| `size` | `'large' \| 'middle' \| 'small'` | - |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `onChange` | `(value: number \| string \| null) => void` | - |
| `onPressEnter` | `(e) => void` | - |
| `onStep` | `(value: number, info: { offset, type: 'up' \| 'down' }) => void` | - (v4.7.0+) |

> `addonBefore` / `addonAfter` 已不建議使用，改用 `Space.Compact`。

**Methods**: `blur()`, `focus(option?)` (cursor option: v5.22.0+), `nativeElement` (v5.17.3+)

---

## Mentions

提及元件（@ 觸發）。

```tsx
import { Mentions } from 'antd';
```

### Mentions Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `string` | - |
| `defaultValue` | `string` | - |
| `options` | `Option[]` | `[]` (v5.1.0+) |
| `prefix` | `string \| string[]` | `'@'` (觸發前綴關鍵字) |
| `split` | `string` | `' '` (選中項前後分隔字串) |
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `false` (v5.13.0+) |
| `autoFocus` | `boolean` | `false` |
| `autoSize` | `boolean \| { minRows, maxRows }` | `false` |
| `filterOption` | `false \| (input: string, option: OptionProps) => boolean` | - |
| `notFoundContent` | `ReactNode` | `'Not Found'` |
| `placement` | `'top' \| 'bottom'` | `'bottom'` |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `validateSearch` | `(text: string, props) => void` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `getPopupContainer` | `() => HTMLElement` | - |
| `onChange` | `(text: string) => void` | - |
| `onSelect` | `(option: OptionProps, prefix: string) => void` | - |
| `onSearch` | `(text: string, prefix: string) => void` | - |
| `onFocus` / `onBlur` | `() => void` | - |

`Option`: `{ label, value, key?, disabled?, className?, style? }`

**Methods**: `blur()`, `focus()`

---

## Rate

評分元件。

```tsx
import { Rate } from 'antd';
```

### Rate Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `number` | - |
| `defaultValue` | `number` | `0` |
| `count` | `number` | `5` (星數) |
| `allowHalf` | `boolean` | `false` (允許半選) |
| `allowClear` | `boolean` | `true` (再次點擊清除) |
| `character` | `ReactNode \| (RateProps) => ReactNode` | `<StarFilled />` (function: v4.4.0+) |
| `disabled` | `boolean` | `false` |
| `keyboard` | `boolean` | `true` (v5.18.0+) |
| `autoFocus` | `boolean` | `false` |
| `tooltips` | `string[]` | - (每個字元的 tooltip) |
| `onChange` | `(value: number) => void` | - |
| `onHoverChange` | `(value: number) => void` | - |
| `onFocus` / `onBlur` | `() => void` | - |
| `onKeyDown` | `(event) => void` | - |

**Methods**: `blur()`, `focus()`

### Design Tokens

`starColor` (#fadb14), `starBg`, `starSize` (20), `starHoverScale`

---

## Slider

滑動輸入條。

```tsx
import { Slider } from 'antd';
```

### Slider Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `number \| [number, number]` | - (range 為 false 用 number) |
| `defaultValue` | `number \| [number, number]` | `0` / `[0, 0]` |
| `min` | `number` | `0` |
| `max` | `number` | `100` |
| `step` | `number \| null` | `1` (null + marks 時僅可選 marks) |
| `range` | `boolean` | `false` (雙滑塊範圍選擇) |
| `marks` | `{ [number]: ReactNode \| { style, label } }` | - (刻度標記，key 必須是 number) |
| `dots` | `boolean` | `false` (滑塊僅能拖到刻度) |
| `included` | `boolean` | `true` (marks 非空時生效) |
| `vertical` | `boolean` | `false` |
| `reverse` | `boolean` | `false` |
| `disabled` | `boolean` | `false` |
| `keyboard` | `boolean` | `true` (v5.2.0+) |
| `autoFocus` | `boolean` | `false` |
| `tooltip` | `SliderTooltipProps` | - (v4.23.0+) |
| `classNames` | `Record<SemanticDOM, string>` | - (v5.10.0+) |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - (v5.10.0+) |
| `onChange` | `(value) => void` | - |
| `onChangeComplete` | `(value) => void` | - (mouseup / keyup 時觸發) |

### Range Props（range 為 object 時）

| Prop | Type | Default |
|------|------|---------|
| `draggableTrack` | `boolean` | `false` |
| `editable` | `boolean` | `false` (v5.20.0+，不可與 draggableTrack 並用) |
| `minCount` | `number` | `0` (v5.20.0+) |
| `maxCount` | `number` | - (v5.20.0+) |

### Tooltip Props

| Prop | Type | Default |
|------|------|---------|
| `open` | `boolean` | - |
| `placement` | string | - |
| `formatter` | `(value) => ReactNode \| null` | identity (回傳 null 隱藏) |
| `autoAdjustOverflow` | `boolean` | `true` (v5.8.0+) |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `() => document.body` |

### Design Tokens

`railBg`, `trackBg` (#91caff), `handleColor` (#91caff), `handleSize` (10), `railSize` (4), `dotSize` (8)

---

## TimePicker

時間選擇器。基於 dayjs。

```tsx
import { TimePicker } from 'antd';
import type { TimePickerProps } from 'antd';
```

### TimePicker Props

| Prop | Type | Default |
|------|------|---------|
| `value` | `dayjs` | - |
| `defaultValue` | `dayjs` | - |
| `format` | `string` | `'HH:mm:ss'` |
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `true` (object: v5.8.0+) |
| `autoFocus` | `boolean` | `false` |
| `cellRender` | `(current, info) => ReactNode` | - (v5.4.0+) |
| `changeOnScroll` | `boolean` | `false` (v5.14.0+) |
| `disabled` | `boolean` | `false` |
| `disabledTime` | `DisabledTime` | - (v4.19.0+) |
| `hideDisabledOptions` | `boolean` | `false` |
| `hourStep` | `number` | `1` |
| `minuteStep` | `number` | `1` |
| `secondStep` | `number` | `1` |
| `inputReadOnly` | `boolean` | `false` |
| `needConfirm` | `boolean` | - (v5.14.0+，需點確認才觸發值變更) |
| `open` | `boolean` | `false` |
| `placeholder` | `string \| [string, string]` | `'Select a time'` |
| `placement` | `'bottomLeft' \| 'bottomRight' \| 'topLeft' \| 'topRight'` | `'bottomLeft'` |
| `prefix` | `ReactNode` | - (v5.22.0+) |
| `renderExtraFooter` | `() => ReactNode` | - |
| `showNow` | `boolean` | - (v4.4.0+) |
| `size` | `'large' \| 'middle' \| 'small'` | - (large 40px / small 24px / 預設 32px) |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `suffixIcon` | `ReactNode` | - |
| `use12Hours` | `boolean` | `false` (預設 format `h:mm:ss a`) |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `onChange` | `(time: dayjs, timeString: string) => void` | - |
| `onOpenChange` | `(open: boolean) => void` | - |

### TimePicker.RangePicker 額外 Props

| Prop | Type | Default |
|------|------|---------|
| `disabledTime` | `RangeDisabledTime` | - (v4.19.0+) |
| `order` | `boolean` | `true` (v4.1.0+，自動排序起訖時間) |
| `onCalendarChange` | `(dates, dateStrings, info: { range: 'start' \| 'end' }) => void` | - |

---

## Transfer

穿梭框，雙欄項目轉移。

```tsx
import { Transfer } from 'antd';
import type { TransferProps } from 'antd';
```

### Transfer Props

| Prop | Type | Default |
|------|------|---------|
| `dataSource` | `TransferItem[]` | `[]` (來源資料) |
| `targetKeys` | `string[] \| number[]` | `[]` (右欄項目 key) |
| `selectedKeys` | `string[] \| number[]` | `[]` (受控選中項) |
| `render` | `(record) => ReactNode` | - (項目渲染) |
| `titles` | `ReactNode[]` | - (兩欄標題，左到右) |
| `operations` | `string[]` | `['>', '<']` (操作按鈕文字) |
| `disabled` | `boolean` | `false` |
| `oneWay` | `boolean` | `false` (v4.3.0+，單向樣式) |
| `showSearch` | `boolean \| { placeholder, defaultValue }` | `false` |
| `showSelectAll` | `boolean` | `true` |
| `filterOption` | `(inputValue, option, direction) => boolean` | - (direction: v5.9.0+) |
| `footer` | `(props, { direction }) => ReactNode` | - (direction: v4.17.0+) |
| `listStyle` | `object \| ({ direction }) => object` | - |
| `operationStyle` | `object` | - |
| `pagination` | `boolean \| { pageSize, simple, showSizeChanger?, showLessItems? }` | `false` (v4.3.0+) |
| `locale` | `{ itemUnit, itemsUnit, searchPlaceholder, notFoundContent }` | (預設值) |
| `selectAllLabels` | `(ReactNode \| (info) => ReactNode)[]` | - |
| `selectionsIcon` | `ReactNode` | - (v5.8.0+) |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `onChange` | `(targetKeys, direction, moveKeys) => void` | - |
| `onSelectChange` | `(sourceSelectedKeys, targetSelectedKeys) => void` | - |
| `onSearch` | `(direction, value) => void` | - |
| `onScroll` | `(direction, event) => void` | - |

### Design Tokens

`listWidth` (180), `listHeight` (200), `listWidthLG` (250), `headerHeight` (40), `itemHeight` (32)

---

## TreeSelect

樹選擇器。

```tsx
import { TreeSelect } from 'antd';
import type { TreeSelectProps } from 'antd';
```

### TreeSelect Props

| Prop | Type | Default |
|------|------|---------|
| `treeData` | `Array<{ value, title, children, disabled?, disableCheckbox?, selectable?, checkable? }>` | `[]` |
| `value` | `string \| string[]` | - |
| `defaultValue` | `string \| string[]` | - |
| `treeCheckable` | `boolean` | `false` (節點顯示 checkbox) |
| `treeCheckStrictly` | `boolean` | `false` (父子節點不關聯，會使 labelInValue 為 true) |
| `multiple` | `boolean` | `false` (treeCheckable 時為 true) |
| `labelInValue` | `boolean` | `false` |
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `false` (object: v5.8.0+) |
| `autoClearSearchValue` | `boolean` | `true` |
| `disabled` | `boolean` | `false` |
| `fieldNames` | `{ label, value, children }` | `{ label, value, children }` (v4.17.0+) |
| `filterTreeNode` | `boolean \| (inputValue, treeNode) => boolean` | - |
| `treeNodeFilterProp` | `string` | `'value'` |
| `treeNodeLabelProp` | `string` | `'title'` |
| `listHeight` | `number` | `256` |
| `loadData` | `(node) => void` | - (非同步載入) |
| `maxCount` | `number` | - (v5.23.0+，僅 multiple) |
| `maxTagCount` | `number \| 'responsive'` | - (responsive: v4.10+) |
| `maxTagPlaceholder` | `ReactNode \| (omittedValues) => ReactNode` | - |
| `notFoundContent` | `ReactNode` | `'Not Found'` |
| `open` | `boolean` | - |
| `placeholder` | `string` | - |
| `placement` | `'bottomLeft' \| 'bottomRight' \| 'topLeft' \| 'topRight'` | `'bottomLeft'` |
| `prefix` | `ReactNode` | - (v5.22.0+) |
| `searchValue` | `string` | - (配合 onSearch) |
| `showCheckedStrategy` | `TreeSelect.SHOW_ALL \| SHOW_PARENT \| SHOW_CHILD` | `SHOW_CHILD` |
| `showSearch` | `boolean` | 單選 `false` / 多選 `true` |
| `size` | `'large' \| 'middle' \| 'small'` | - |
| `status` | `'error' \| 'warning'` | - (v4.19.0+) |
| `suffixIcon` | `ReactNode` | `<DownOutlined />` |
| `switcherIcon` | `ReactNode \| (props) => ReactNode` | - (renderProps: v4.20.0+) |
| `treeDefaultExpandAll` | `boolean` | `false` |
| `treeDefaultExpandedKeys` | `string[]` | - |
| `treeExpandedKeys` | `string[]` | - |
| `treeExpandAction` | `false \| 'click' \| 'doubleClick'` | `false` (v4.21.0+) |
| `treeLine` | `boolean \| object` | `false` (v4.17.0+) |
| `treeIcon` | `boolean` | `false` |
| `treeTitleRender` | `(nodeData) => ReactNode` | - (v5.12.0+) |
| `treeDataSimpleMode` | `boolean \| { id, pId, rootPId }` | `false` |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `virtual` | `boolean` | `true` (v4.1.0+) |
| `popupRender` | `(originNode, props) => ReactNode` | - (取代 `dropdownRender`) |
| `popupMatchSelectWidth` | `boolean \| number` | `true` (v5.5.0+) |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `() => document.body` |
| `onChange` | `(value, label, extra) => void` | - |
| `onSelect` | `(value, node, extra) => void` | - |
| `onSearch` | `(value: string) => void` | - |
| `onTreeExpand` | `(expandedKeys) => void` | - |

### TreeNode

| Prop | Type | Default |
|------|------|---------|
| `key` | `string` | - (除非用 treeDataSimpleMode，否則必填且唯一) |
| `value` | `string` | - (預設作為 treeNodeFilterProp，須唯一) |
| `title` | `ReactNode` | - |
| `checkable` | `boolean` | - |
| `disabled` | `boolean` | `false` |
| `disableCheckbox` | `boolean` | `false` |
| `isLeaf` | `boolean` | `false` |
| `selectable` | `boolean` | `true` |

**Methods**: `blur()`, `focus()`

### Design Tokens

`indentSize` (24), `titleHeight` (24), `nodeSelectedBg` (#e6f4ff), `nodeHoverBg`
