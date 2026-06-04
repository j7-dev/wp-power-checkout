# Data Entry Components

## Table of Contents
- [Form](#form)
- [Input](#input)
- [Select](#select)
- [Radio](#radio)
- [Segmented](#segmented)
- [Switch](#switch)
- [Upload](#upload)
- [DatePicker / RangePicker](#datepicker--rangepicker)

---

## Form

```tsx
import { Form } from 'antd';
import type { FormInstance, FormProps, FormItemProps, Rule } from 'antd';
```

### Form Props

| Prop | Type | Default |
|------|------|---------|
| `colon` | `boolean` | `true` |
| `disabled` | `boolean` | `false` (v4.21.0+) |
| `component` | `ComponentType \| false` | `'form'` |
| `fields` | `FieldData[]` | - |
| `form` | `FormInstance` | - |
| `feedbackIcons` | `FeedbackIcons` | - (v5.9.0+) |
| `initialValues` | `object` | - |
| `labelAlign` | `'left' \| 'right'` | `'right'` |
| `labelWrap` | `boolean` | `false` |
| `labelCol` | `{ span?: number, offset?: number }` | - |
| `layout` | `'horizontal' \| 'vertical' \| 'inline'` | `'horizontal'` |
| `name` | `string` | - |
| `preserve` | `boolean` | `true` |
| `requiredMark` | `boolean \| 'optional' \| ((label, info) => ReactNode)` | `true` |
| `scrollToFirstError` | `boolean \| ScrollOptions \| { focus: boolean }` | `false` |
| `size` | `'small' \| 'medium' \| 'large'` | - |
| `validateMessages` | `ValidateMessages` | - |
| `validateTrigger` | `string \| string[]` | `'onChange'` |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `wrapperCol` | `{ span?: number, offset?: number }` | - |
| `clearOnDestroy` | `boolean` | `false` (v5.18.0+) |
| `onFieldsChange` | `(changedFields, allFields) => void` | - |
| `onFinish` | `(values) => void` | - |
| `onFinishFailed` | `({ values, errorFields, outOfDate }) => void` | - |
| `onValuesChange` | `(changedValues, allValues) => void` | - |

### Form.Item Props

| Prop | Type | Default |
|------|------|---------|
| `colon` | `boolean` | `true` |
| `dependencies` | `NamePath[]` | - |
| `extra` | `ReactNode` | - |
| `getValueFromEvent` | `(...args: any[]) => any` | - |
| `getValueProps` | `(value) => Record<string, any>` | - |
| `hasFeedback` | `boolean \| { icons: FeedbackIcons }` | `false` |
| `help` | `ReactNode` | - (auto-generated from rules) |
| `hidden` | `boolean` | `false` |
| `htmlFor` | `string` | - |
| `initialValue` | `any` | - (Form initialValues takes priority) |
| `label` | `ReactNode` | - |
| `labelAlign` | `'left' \| 'right'` | `'right'` |
| `labelCol` | `{ span?, offset? }` | - |
| `messageVariables` | `Record<string, string>` | - |
| `name` | `NamePath` | - |
| `normalize` | `(value, prevValue, prevValues) => any` | - |
| `preserve` | `boolean` | `true` |
| `required` | `boolean` | `false` |
| `rules` | `Rule[]` | - |
| `shouldUpdate` | `boolean \| (prevValues, curValues) => boolean` | `false` |
| `tooltip` | `ReactNode \| TooltipProps & { icon?: ReactNode }` | - |
| `trigger` | `string` | `'onChange'` |
| `validateDebounce` | `number` | - (v5.9.0+, ms) |
| `validateFirst` | `boolean \| 'parallel'` | `false` |
| `validateStatus` | `'success' \| 'warning' \| 'error' \| 'validating'` | - |
| `validateTrigger` | `string \| string[]` | `'onChange'` |
| `valuePropName` | `string` | `'value'` |
| `wrapperCol` | `{ span?, offset? }` | - |
| `layout` | `'horizontal' \| 'vertical'` | - (v5.18.0+) |

**Key behaviors:**
- `shouldUpdate={true}`: Re-renders on any form value change. Child must be render function.
- `dependencies`: Triggers validation/re-render when specified fields change. Use with render function children for conditional rendering.
- `valuePropName='checked'`: Use for Switch, Checkbox components.
- When Form.Item has `name`, it injects `value`/`onChange` automatically. Don't use `defaultValue` on the child.

### Form.List

| Prop | Type |
|------|------|
| `name` | `NamePath` |
| `initialValue` | `any[]` (v4.9.0+) |
| `rules` | `{ validator, message }[]` (v4.7.0+) |
| `children` | `(fields: Field[], operation: Operation, meta: { errors }) => ReactNode` |

**Operation methods:**
- `add(defaultValue?, insertIndex?)` - Add item
- `remove(index \| index[])` - Remove item(s)
- `move(from, to)` - Move item

### Form.ErrorList

| Prop | Type |
|------|------|
| `errors` | `ReactNode[]` |

### Form.Provider

| Prop | Type |
|------|------|
| `onFormChange` | `(formName, info: { changedFields, forms }) => void` |
| `onFormFinish` | `(formName, info: { values, forms }) => void` |

### FormInstance Methods

| Method | Signature |
|--------|-----------|
| `getFieldError` | `(name: NamePath) => string[]` |
| `getFieldInstance` | `(name: NamePath) => any` |
| `getFieldsError` | `(nameList?: NamePath[]) => FieldError[]` |
| `getFieldsValue` | `(nameList?: true \| NamePath[], filterFunc?) => any` |
| `getFieldValue` | `(name: NamePath) => any` |
| `isFieldsTouched` | `(nameList?: NamePath[], allTouched?: boolean) => boolean` |
| `isFieldTouched` | `(name: NamePath) => boolean` |
| `isFieldValidating` | `(name: NamePath) => boolean` |
| `resetFields` | `(fields?: NamePath[]) => void` |
| `scrollToField` | `(name: NamePath, options?: ScrollOptions \| { focus: boolean }) => void` |
| `setFields` | `(fields: FieldData[]) => void` |
| `setFieldValue` | `(name: NamePath, value: any) => void` (v4.22.0+) |
| `setFieldsValue` | `(values: Record<string, any>) => void` |
| `submit` | `() => void` |
| `validateFields` | `(nameList?: NamePath[], config?: ValidateConfig) => Promise` |

**ValidateConfig (v5.5.0+):**
```tsx
{ validateOnly?: boolean; recursive?: boolean; dirty?: boolean }
```
- `validateOnly`: Validate without showing UI error
- `recursive`: Validate sub-paths too (v5.9.0+)
- `dirty`: Only validate touched/validated fields (v5.11.0+)

### Form Hooks

```tsx
const [form] = Form.useForm<T>();                    // Create form instance
const form = Form.useFormInstance();                   // Get context form (v4.20.0+)
const value = Form.useWatch(namePath, form);           // Watch field value
const value = Form.useWatch(selector, { form, preserve }); // With options (v5.12.0+)
const { status, errors, warnings } = Form.Item.useStatus(); // Get validation status (v4.22.0+)
```

### Rule Type

| Prop | Type |
|------|------|
| `enum` | `any[]` |
| `len` | `number` |
| `max` | `number` |
| `message` | `string \| ReactElement` |
| `min` | `number` |
| `pattern` | `RegExp` |
| `required` | `boolean` |
| `transform` | `(value) => any` |
| `type` | `'string' \| 'number' \| 'boolean' \| 'url' \| 'email' \| 'tel' \| ...` |
| `validator` | `(rule, value) => Promise` |
| `warningOnly` | `boolean` (v4.17.0+) |
| `whitespace` | `boolean` |

Rules can also be functions: `(form: FormInstance) => RuleConfig`

### NamePath

```tsx
type NamePath = string | number | (string | number)[];
```

---

## Input

```tsx
import { Input } from 'antd';
```

### Input Props

| Prop | Type | Default |
|------|------|---------|
| `addonAfter` | `ReactNode` | - |
| `addonBefore` | `ReactNode` | - |
| `allowClear` | `boolean \| { clearIcon: ReactNode }` | `false` |
| `count` | `CountConfig` | - |
| `defaultValue` | `string` | - |
| `disabled` | `boolean` | `false` |
| `maxLength` | `number` | - |
| `prefix` | `ReactNode` | - |
| `showCount` | `boolean \| { formatter }` | `false` |
| `status` | `'error' \| 'warning'` | - |
| `size` | `'large' \| 'medium' \| 'small'` | - |
| `suffix` | `ReactNode` | - |
| `type` | `string` | `'text'` |
| `value` | `string` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` |
| `onChange` | `(e: ChangeEvent) => void` | - |
| `onPressEnter` | `(e: KeyboardEvent) => void` | - |
| `onClear` | `() => void` | - |

**Methods:** `focus(options?)`, `blur()`

**CountConfig:**
```tsx
{ max?: number; strategy?: (value) => number; show?: boolean | ((args) => ReactNode); exceedFormatter?: (value, { max }) => string }
```

### Input.TextArea Props

Extends Input props plus:

| Prop | Type | Default |
|------|------|---------|
| `autoSize` | `boolean \| { minRows?: number, maxRows?: number }` | `false` |

### Input.Search Props

Extends Input props plus:

| Prop | Type | Default |
|------|------|---------|
| `enterButton` | `ReactNode` | `false` |
| `loading` | `boolean` | `false` |
| `onSearch` | `(value, event, { source }) => void` | - |

### Input.Password Props

Extends Input props plus:

| Prop | Type | Default |
|------|------|---------|
| `visibilityToggle` | `boolean \| { visible, onVisibleChange }` | `true` |
| `iconRender` | `(visible: boolean) => ReactNode` | - |

### Input.OTP (v5.16.0+)

| Prop | Type | Default |
|------|------|---------|
| `defaultValue` | `string` | - |
| `disabled` | `boolean` | `false` |
| `formatter` | `(value: string) => string` | - |
| `length` | `number` | `6` |
| `mask` | `boolean \| string` | `false` |
| `separator` | `ReactNode \| ((index: number) => ReactNode)` | - |
| `size` | `'small' \| 'medium' \| 'large'` | `'medium'` |
| `status` | `'error' \| 'warning'` | - |
| `value` | `string` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` |
| `onChange` | `(value: string) => void` | - (all fields filled) |
| `onInput` | `(values: string[]) => void` | - (v5.22.0+) |

### Input.Group（已廢棄 / Deprecated）

`Input.Group` 已廢棄，請改用 `Space.Compact` 替代。

```tsx
// ❌ 已廢棄
<Input.Group compact>
  <Input style={{ width: '50%' }} />
  <Input style={{ width: '50%' }} />
</Input.Group>

// ✅ 改用 Space.Compact
<Space.Compact>
  <Input style={{ width: '50%' }} />
  <Input style={{ width: '50%' }} />
</Space.Compact>
```

詳見 `general-layout.md` 的 Space.Compact 段落。

---

## Select

```tsx
import { Select } from 'antd';
import type { SelectProps, DefaultOptionType } from 'antd';
```

### Select Props

| Prop | Type | Default |
|------|------|---------|
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `false` |
| `autoClearSearchValue` | `boolean` | `true` |
| `defaultActiveFirstOption` | `boolean` | `true` |
| `defaultOpen` | `boolean` | - |
| `defaultValue` | `string \| string[] \| number \| number[] \| LabeledValue \| LabeledValue[]` | - |
| `disabled` | `boolean` | `false` |
| `popupClassName` | `string` | - |
| `popupMatchSelectWidth` | `boolean \| number` | `true` (v5.5.0+) |
| `fieldNames` | `{ label, value, options, groupLabel }` | `{ label: 'label', value: 'value', options: 'options' }` |
| `filterOption` | `boolean \| (inputValue, option) => boolean` | `true` |
| `filterSort` | `(optionA, optionB, info: { searchValue }) => number` | - |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `document.body` |
| `labelInValue` | `boolean` | `false` |
| `listHeight` | `number` | `256` |
| `loading` | `boolean` | `false` |
| `maxCount` | `number` | - (v5.13.0+) |
| `maxTagCount` | `number \| 'responsive'` | - |
| `maxTagPlaceholder` | `ReactNode \| (omittedValues) => ReactNode` | - |
| `maxTagTextLength` | `number` | - |
| `menuItemSelectedIcon` | `ReactNode` | - |
| `mode` | `'multiple' \| 'tags'` | - |
| `notFoundContent` | `ReactNode` | `'Not Found'` |
| `open` | `boolean` | - |
| `optionLabelProp` | `string` | `'children'` |
| `options` | `{ label, value, disabled? }[]` | - |
| `optionRender` | `(option, info: { index }) => ReactNode` | - (v5.11.0+) |
| `placeholder` | `ReactNode` | - |
| `placement` | `'bottomLeft' \| 'bottomRight' \| 'topLeft' \| 'topRight'` | `'bottomLeft'` |
| `prefix` | `ReactNode` | - (v5.22.0+) |
| `searchValue` | `string` | - |
| `showSearch` | `boolean \| Object` | single: `false`, multiple: `true` |
| `size` | `'large' \| 'medium' \| 'small'` | `'medium'` |
| `status` | `'error' \| 'warning'` | - |
| `suffixIcon` | `ReactNode` | `<DownOutlined />` |
| `tagRender` | `(props) => ReactNode` | - |
| `labelRender` | `(props: LabelInValueType) => ReactNode` | - (v5.15.0+) |
| `tokenSeparators` | `string[]` | - (tags mode) |
| `value` | `string \| string[] \| number \| number[] \| LabeledValue \| LabeledValue[]` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `virtual` | `boolean` | `true` |

### Select Event Handlers

| Callback | Signature |
|----------|-----------|
| `onChange` | `(value, option \| option[]) => void` |
| `onClear` | `() => void` |
| `onDeselect` | `(value) => void` (multiple/tags) |
| `onOpenChange` | `(open: boolean) => void` |
| `onFocus` | `(event) => void` |
| `onBlur` | `() => void` |
| `onSearch` | `(value: string) => void` |
| `onSelect` | `(value, option) => void` |
| `onPopupScroll` | `(event) => void` |
| `onInputKeyDown` | `(event) => void` |

### Select Methods

`focus()`, `blur()`, `scrollTo()`

### Option Props

| Prop | Type |
|------|------|
| `disabled` | `boolean` |
| `title` | `string` |
| `value` | `string \| number` |
| `className` | `string` |

### OptGroup Props

| Prop | Type |
|------|------|
| `key` | `string` |
| `label` | `ReactNode` |

---

## Radio

```tsx
import { Radio } from 'antd';
```

### Radio Props

| Prop | Type | Default |
|------|------|---------|
| `checked` | `boolean` | `false` |
| `defaultChecked` | `boolean` | `false` |
| `disabled` | `boolean` | `false` |
| `value` | `any` | - |

### Radio.Group Props

| Prop | Type | Default |
|------|------|---------|
| `block` | `boolean` | `false` (v5.21.0+) |
| `buttonStyle` | `'outline' \| 'solid'` | `'outline'` |
| `defaultValue` | `any` | - |
| `disabled` | `boolean` | `false` |
| `name` | `string` | - |
| `options` | `string[] \| number[] \| CheckboxOptionType[]` | - |
| `optionType` | `'default' \| 'button'` | `'default'` |
| `size` | `'large' \| 'medium' \| 'small'` | - |
| `value` | `any` | - |
| `onChange` | `(e: RadioChangeEvent) => void` | - |

### CheckboxOptionType

```tsx
{ label: string; value: string | number | boolean; disabled?: boolean; style?: CSSProperties; className?: string }
```

---

## Segmented

```tsx
import { Segmented } from 'antd';
```

### Segmented Props

| Prop | Type | Default |
|------|------|---------|
| `block` | `boolean` | `false` |
| `defaultValue` | `string \| number` | - |
| `disabled` | `boolean` | `false` |
| `options` | `string[] \| number[] \| SegmentedItemType[]` | `[]` |
| `size` | `'large' \| 'medium' \| 'small'` | `'medium'` |
| `value` | `string \| number` | - |
| `shape` | `'default' \| 'round'` | `'default'` |
| `onChange` | `(value: string \| number) => void` | - |

### SegmentedItemType

```tsx
{ disabled?: boolean; icon?: ReactNode; label?: ReactNode; tooltip?: string | TooltipProps; value: string | number }
```

### Design Tokens

`itemActiveBg`, `itemColor`, `itemHoverBg`, `itemHoverColor`, `itemSelectedBg`, `itemSelectedColor`, `trackBg`, `trackPadding`

---

## Switch

```tsx
import { Switch } from 'antd';
```

### Switch Props

| Prop | Type | Default |
|------|------|---------|
| `checked` | `boolean` | `false` |
| `checkedChildren` | `ReactNode` | - |
| `defaultChecked` | `boolean` | `false` |
| `defaultValue` | `boolean` | - (v5.12.0+) |
| `disabled` | `boolean` | `false` |
| `loading` | `boolean` | `false` |
| `size` | `'medium' \| 'small'` | `'medium'` |
| `unCheckedChildren` | `ReactNode` | - |
| `value` | `boolean` | - (v5.12.0+) |
| `onChange` | `(checked: boolean, event) => void` | - |
| `onClick` | `(checked: boolean, event) => void` | - |

**Methods:** `focus()`, `blur()`

**Form usage:** Use `valuePropName="checked"` on Form.Item wrapping Switch.

---

## Upload

```tsx
import { Upload } from 'antd';
import type { UploadFile, UploadProps, UploadChangeParam } from 'antd';
```

### Upload Props

| Prop | Type | Default |
|------|------|---------|
| `accept` | `string` | - |
| `action` | `string \| (file) => Promise<string>` | - |
| `beforeUpload` | `(file: RcFile, fileList: RcFile[]) => boolean \| Promise<File> \| Upload.LIST_IGNORE` | - |
| `customRequest` | `(options: RequestOptions) => void` | - |
| `data` | `object \| (file) => object \| Promise<object>` | - |
| `defaultFileList` | `UploadFile[]` | - |
| `directory` | `boolean` | `false` |
| `disabled` | `boolean` | `false` |
| `fileList` | `UploadFile[]` | - |
| `headers` | `object` | - |
| `iconRender` | `(file, listType?) => ReactNode` | - |
| `itemRender` | `(originNode, file, fileList, actions) => ReactNode` | - |
| `listType` | `'text' \| 'picture' \| 'picture-card' \| 'picture-circle'` | `'text'` |
| `maxCount` | `number` | - |
| `method` | `string` | `'post'` |
| `multiple` | `boolean` | `false` |
| `name` | `string` | `'file'` |
| `openFileDialogOnClick` | `boolean` | `true` |
| `previewFile` | `(file: File) => Promise<string>` | - |
| `progress` | `ProgressProps` | `{ strokeWidth: 2, showInfo: false }` |
| `showUploadList` | `boolean \| { showDownloadIcon, showPreviewIcon, showRemoveIcon }` | `true` |
| `withCredentials` | `boolean` | `false` |
| `onChange` | `(info: UploadChangeParam) => void` | - |
| `onDrop` | `(event: DragEvent) => void` | - |
| `onDownload` | `(file) => void` | - |
| `onPreview` | `(file) => void` | - |
| `onRemove` | `(file) => boolean \| Promise` | - |

### UploadFile Type

```tsx
interface UploadFile {
  uid: string;
  name: string;
  status?: 'error' | 'done' | 'uploading' | 'removed';
  percent?: number;
  url?: string;
  thumbUrl?: string;
  crossOrigin?: 'anonymous' | 'use-credentials' | '';
  response?: any;
  error?: any;
  originFileObj?: File;
}
```

### UploadChangeParam

```tsx
{ file: UploadFile; fileList: UploadFile[]; event?: { percent: number } }
```

### RequestOptions (customRequest)

```tsx
{ action: string; data: object; filename: string; file: UploadFile;
  withCredentials: boolean; headers: object; method: string;
  onProgress: (event, file) => void; onError: (event, body?) => void;
  onSuccess: (body, fileOrXhr?) => void }
```

### Upload.Dragger

Same props as Upload, renders as drag-and-drop area.

---

## DatePicker / RangePicker

```tsx
import { DatePicker } from 'antd';
import type { DatePickerProps, RangePickerProps } from 'antd';
const { RangePicker } = DatePicker;
```

**Note:** antd v5 uses dayjs by default (not moment.js).

### Common Props (shared by DatePicker and RangePicker)

| Prop | Type | Default |
|------|------|---------|
| `allowClear` | `boolean \| { clearIcon?: ReactNode }` | `true` |
| `disabled` | `boolean` | `false` |
| `disabledDate` | `(current: dayjs, info: { from?: dayjs, type: Picker }) => boolean` | - |
| `format` | `string \| (value: Dayjs) => string \| Array \| { format: string, type?: 'mask' }` | `'YYYY-MM-DD'` |
| `getPopupContainer` | `(trigger) => HTMLElement` | `document.body` |
| `inputReadOnly` | `boolean` | `false` |
| `locale` | `object` | - |
| `minDate` | `dayjs` | - (v5.14.0+) |
| `maxDate` | `dayjs` | - (v5.14.0+) |
| `mode` | `'time' \| 'date' \| 'month' \| 'year' \| 'decade'` | - |
| `needConfirm` | `boolean` | - (v5.14.0+) |
| `open` | `boolean` | - |
| `defaultOpen` | `boolean` | - |
| `placeholder` | `string \| [string, string]` | - |
| `placement` | `'bottomLeft' \| 'bottomRight' \| 'topLeft' \| 'topRight'` | `'bottomLeft'` |
| `prefix` | `ReactNode` | - (v5.22.0+) |
| `presets` | `{ label: ReactNode, value: Dayjs \| (() => Dayjs) }[]` | - |
| `size` | `'large' \| 'medium' \| 'small'` | - |
| `status` | `'error' \| 'warning'` | - |
| `suffixIcon` | `ReactNode` | - |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` (v5.13.0+) |
| `onOpenChange` | `(open: boolean) => void` | - |
| `onPanelChange` | `(value, mode) => void` | - |

**Methods:** `focus()`, `blur()`

### DatePicker-Specific Props

| Prop | Type | Default |
|------|------|---------|
| `defaultPickerValue` | `dayjs` | - (v5.14.0+) |
| `defaultValue` | `dayjs` | - |
| `disabledTime` | `(date: dayjs) => DisabledTimeConfig` | - |
| `multiple` | `boolean` | `false` (v5.14.0+) |
| `picker` | `'date' \| 'week' \| 'month' \| 'quarter' \| 'year'` | `'date'` |
| `pickerValue` | `dayjs` | - (v5.14.0+) |
| `renderExtraFooter` | `(mode) => ReactNode` | - |
| `showNow` | `boolean` | - |
| `showTime` | `object \| boolean` | - |
| `showWeek` | `boolean` | `false` (v5.14.0+) |
| `value` | `dayjs` | - |
| `onChange` | `(date: dayjs \| null, dateString: string \| null) => void` | - |
| `onOk` | `() => void` | - |

### RangePicker-Specific Props

| Prop | Type | Default |
|------|------|---------|
| `allowEmpty` | `[boolean, boolean]` | `[false, false]` |
| `defaultPickerValue` | `dayjs` | - (v5.14.0+) |
| `defaultValue` | `[dayjs, dayjs]` | - |
| `disabled` | `[boolean, boolean]` | - |
| `disabledTime` | `(date: dayjs, partial: 'start' \| 'end') => DisabledTimeConfig` | - |
| `id` | `{ start?: string, end?: string }` | - (v5.14.0+) |
| `presets` | `{ label, value: [Dayjs, Dayjs] \| (() => [Dayjs, Dayjs]) }[]` | - |
| `separator` | `ReactNode` | `<SwapRightOutlined />` |
| `showTime` | `object \| boolean` | - |
| `value` | `[dayjs, dayjs]` | - |
| `onCalendarChange` | `(dates, dateStrings, info: { range: 'start' \| 'end' }) => void` | - |
| `onChange` | `(dates: [dayjs, dayjs] \| null, dateStrings: [string, string]) => void` | - |
| `onFocus` | `(event, { range: 'start' \| 'end' }) => void` | - |
| `onBlur` | `(event, { range: 'start' \| 'end' }) => void` | - |

### Picker Format Defaults

| Picker | Default Format |
|--------|---------------|
| `date` | `'YYYY-MM-DD'` |
| `week` | `'YYYY-wo'` |
| `month` | `'YYYY-MM'` |
| `quarter` | `'YYYY-\\QQ'` |
| `year` | `'YYYY'` |
