# antd v6 — Data Entry Components

> Form, Input, InputNumber, Select, DatePicker / RangePicker, Radio, Switch, Upload.
> Source: https://ant.design/components/{form,input,select,date-picker}.md

## Table of Contents
- [Form](#form)
- [Form.Item](#formitem)
- [Form.List](#formlist)
- [FormInstance methods](#forminstance-methods)
- [Form hooks](#form-hooks)
- [Validation rules](#validation-rules)
- [Input / TextArea / Search / Password / OTP](#input--textarea--search--password--otp)
- [InputNumber](#inputnumber)
- [Select](#select)
- [DatePicker / RangePicker](#datepicker--rangepicker)

---

## Form

```tsx
import { Form, Input, Button } from 'antd';
import type { FormProps, FormInstance } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `form` | `FormInstance` | — | from `Form.useForm()` |
| `name` | string | — | form id / field id prefix |
| `layout` | `'horizontal' \| 'vertical' \| 'inline'` | `'horizontal'` | |
| `labelCol` | object | — | label grid (`{ span, offset }`) |
| `wrapperCol` | object | — | control grid |
| `labelAlign` | `'left' \| 'right'` | `'right'` | |
| `labelWrap` | boolean | `false` | wrap long labels |
| `colon` | boolean | `true` | colon after label (horizontal) |
| `initialValues` | object | — | default field values |
| `fields` | `FieldData[]` | — | external state control |
| `disabled` | boolean | `false` | disable all child controls |
| `preserve` | boolean | `true` | keep field value after unmount |
| `requiredMark` | `boolean \| 'optional' \| fn` | `true` | required indicator style |
| `scrollToFirstError` | `boolean \| Options` | `false` | scroll to first error on submit |
| `size` | `'small' \| 'medium' \| 'large'` | — | child component size |
| `variant` | `'outlined' \| 'filled' \| 'borderless' \| 'underlined'` | `'outlined'` | default variant for children |
| `validateMessages` | `ValidateMessages` | — | message templates, e.g. `'${label} required'` |
| `validateTrigger` | `string \| string[]` | `'onChange'` | |
| `component` | `ComponentType \| false` | `'form'` | render element |
| `classNames` / `styles` | object / function | — | semantic keys: `root`, `label`, `content`, `itemContainer` |

Callbacks:
```tsx
onFinish?: (values: Record<string, any>) => void
onFinishFailed?: (info: { values; errorFields; outOfDate }) => void
onFieldsChange?: (changed: FieldData[], all: FieldData[]) => void
onValuesChange?: (changed: object, all: object) => void
```

## Form.Item

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `name` | `NamePath` | — | field path; `['a','b']` for nested |
| `label` | ReactNode | — | |
| `rules` | `Rule[]` | — | validation rules |
| `dependencies` | `NamePath[]` | — | revalidate when these change |
| `valuePropName` | string | `'value'` | e.g. `'checked'` for Switch/Checkbox |
| `getValueFromEvent` | fn | — | extract value from onChange |
| `getValueProps` | fn | — | compute props for the child |
| `normalize` | fn | — | transform value before storing |
| `noStyle` | boolean | `false` | pure field, no label/wrapper markup |
| `hidden` | boolean | `false` | hide but still collect & validate |
| `shouldUpdate` | `boolean \| fn` | `false` | custom re-render condition (render-prop child) |
| `validateFirst` | `boolean \| 'parallel'` | `false` | stop at first failing rule |
| `validateDebounce` | number | — | ms debounce before validating |
| `validateStatus` | `'success'\|'warning'\|'error'\|'validating'` | — | manual status |
| `validateTrigger` | `string \| string[]` | `'onChange'` | |
| `hasFeedback` | `boolean \| { icons: FeedbackIcons }` | `false` | status icon |
| `help` | ReactNode | — | custom help/error text |
| `extra` | ReactNode | — | extra hint below the field |
| `tooltip` | `ReactNode \| object` | — | label tooltip |
| `labelCol` / `wrapperCol` | object | — | per-item grid override |

```tsx
<Form.Item
  name="email"
  label="Email"
  rules={[{ required: true, type: 'email', message: 'Valid email required' }]}
>
  <Input />
</Form.Item>

// Conditional rendering via dependencies + render prop
<Form.Item dependencies={['type']} noStyle>
  {({ getFieldValue }) =>
    getFieldValue('type') === 'other' ? (
      <Form.Item name="detail" label="Detail"><Input /></Form.Item>
    ) : null
  }
</Form.Item>
```

## Form.List

Dynamic arrays of fields. The render function gets `fields` plus `{ add, remove, move }`.

```tsx
<Form.List name="users">
  {(fields, { add, remove }) => (
    <>
      {fields.map((field) => (
        <Form.Item {...field} key={field.key} name={[field.name, 'name']}>
          <Input placeholder="Name" />
        </Form.Item>
      ))}
      <Button onClick={() => add()}>Add</Button>
    </>
  )}
</Form.List>
```

- `add(initValue?, insertIndex?)` — append/insert an item.
- `remove(index | index[])` — remove item(s).
- **v6 behavior**: `onFinish` excludes unregistered Form.List child items. Do **not** use
  `getFieldsValue({ strict: true })` to filter — `values` is already clean.

## FormInstance methods

```tsx
const [form] = Form.useForm<FormValues>();

form.getFieldValue(name)                       // single value
form.getFieldsValue(nameList? | true)          // many / all
form.getFieldError(name)                       // string[]
form.getFieldsError(nameList?)                 // FieldError[]
form.isFieldTouched(name)                      // boolean
form.isFieldsTouched(nameList?, allTouched?)   // boolean
form.isFieldValidating(name)                   // boolean

form.setFieldValue(name, value)                // single (v5.10.0+)
form.setFieldsValue(values)                    // many
form.setFields(fields: FieldData[])            // value + meta (errors/touched)

form.validateFields(nameList?, options?)       // => Promise<values>; options: { validateOnly, dirty }
form.validateField(name)                       // => Promise<void>

form.resetFields(nameList?)                    // reset to initialValues
form.clearFields(nameList?)                    // clear values
form.submit()                                  // trigger onFinish
form.scrollToField(name, options?)             // scroll to a field
```

`validateFields({ validateOnly: true })` validates without painting UI error state.

## Form hooks

```tsx
// useForm — create the instance
const [form] = Form.useForm<FieldValues>();

// useWatch — subscribe to a field; re-renders only on that value's change
const name = Form.useWatch('name', form);
const full = Form.useWatch((values) => `${values.first} ${values.last}`, form);
// v6: useWatch supports dynamic name paths
const itemName = Form.useWatch(['users', idx, 'name'], form);

// useFormInstance — get nearest parent Form instance (inside a child component)
const form = Form.useFormInstance();
```

## Validation rules

```tsx
interface Rule {
  type?: 'string' | 'number' | 'boolean' | 'method' | 'regexp' | 'integer' | 'float'
       | 'array' | 'object' | 'enum' | 'date' | 'url' | 'hex' | 'email' | 'tel'; // 'tel' v6.2.0+
  required?: boolean;
  message?: ReactNode;
  pattern?: RegExp;
  min?: number;          // string length / number value / array length
  max?: number;
  len?: number;
  whitespace?: boolean;  // reject whitespace-only strings
  enum?: any[];          // with type: 'enum'
  warningOnly?: boolean; // shows warning, does NOT block submit
  transform?: (value: any) => any;
  validateTrigger?: string | string[];
  validator?: (rule: Rule, value: any) => Promise<void>; // reject(Error) to fail
}
```

```tsx
rules={[
  { required: true, message: 'Required' },
  { type: 'email', message: 'Invalid email' },
  { min: 6, message: 'At least 6 chars' },
  () => ({
    validator(_, value) {
      return value && value.length < 100 ? Promise.resolve() : Promise.reject(new Error('Too long'));
    },
  }),
]}
```

---

## Input / TextArea / Search / Password / OTP

```tsx
import { Input } from 'antd';
import type { InputRef } from 'antd';
const { TextArea, Search, Password, OTP } = Input;
```

### Input props (shared base)

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `value` / `defaultValue` | string | — | |
| `variant` | `'outlined' \| 'borderless' \| 'filled' \| 'underlined'` | `'outlined'` | replaces v5 `bordered` |
| `size` | `'large' \| 'medium' \| 'small'` | `'medium'` | |
| `status` | `'error' \| 'warning'` | — | validation display |
| `prefix` / `suffix` | ReactNode | — | |
| `allowClear` | `boolean \| { clearIcon; disabled? }` | `false` | |
| `maxLength` | number | — | |
| `showCount` | `boolean \| { formatter }` | `false` | char counter |
| `count` | `CountConfig` | — | custom counting (`max`, `strategy`, `show`, `exceedFormatter`) |
| `disabled` | boolean | `false` | |
| `classNames` / `styles` | object / fn | — | keys: `root`, `input`, `prefix`, `suffix`, `count` |
| `onChange` | `(e) => void` | — | |
| `onPressEnter` | `(e) => void` | — | |
| `onClear` | `() => void` | — | |

`Input.Group` is **removed** — use `Space.Compact` (see general-layout.md).

```tsx
<Input placeholder="Outlined" />
<Input placeholder="Filled" variant="filled" status="error" />
```

### Input.TextArea

Extends Input. Adds `autoSize?: boolean | { minRows; maxRows }`.

```tsx
<TextArea autoSize={{ minRows: 2, maxRows: 6 }} showCount maxLength={100} />
```

### Input.Search

v6 refactored Search onto `Space.Compact` internally.

| Prop | Type | Notes |
|------|------|-------|
| `enterButton` | `boolean \| string \| ReactNode` | the action button |
| `loading` | boolean | |
| `searchIcon` | ReactNode | v6.4.0+ |
| `onSearch` | `(value, event, info: { source: 'input' \| 'clear' }) => void` | |

### Input.Password

`visibilityToggle?: boolean | { visible?; onVisibleChange? }`, `iconRender?: (visible) => ReactNode`.

### Input.OTP (v5.16.0+)

| Prop | Type | Default |
|------|------|---------|
| `length` | number | `6` |
| `mask` | `boolean \| string` | — |
| `formatter` | `(value: string) => string` | — |
| `separator` | `ReactNode \| ((index) => ReactNode)` | — |
| `variant` | variant union | `'outlined'` |
| `autoComplete` | string | — (e.g. `'one-time-code'`, v6.3.0+) |
| `onChange` | `(value: string) => void` | fires when all fields filled |
| `onInput` | `(values: string[]) => void` | fires on each keystroke |

### InputRef

```tsx
const ref = useRef<InputRef>(null);
ref.current?.focus({ cursor: 'start' | 'end' | 'all', preventScroll?: boolean });
ref.current?.blur();
```

## InputNumber

```tsx
import { InputNumber } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `value` / `defaultValue` | `number \| string` | — | |
| `min` / `max` | `number \| string` | — | |
| `step` | `number \| string` | `1` | |
| `precision` | number | — | decimal places |
| `mode` | `'spinner'` | — | **v6**: force spinner controls (esp. mobile) |
| `controls` | `boolean \| { upIcon; downIcon }` | `true` | |
| `variant` | variant union | `'outlined'` | replaces v5 `bordered` |
| `status` | `'error' \| 'warning'` | — | |
| `size` | size union | `'medium'` | |
| `formatter` | `(value, info) => string` | — | display formatting |
| `parser` | `(displayValue) => number` | — | inverse of formatter |
| `stringMode` | boolean | `false` | high-precision decimal as string |
| `keyboard` | boolean | `true` | arrow-key support |
| `prefix` / `suffix` | ReactNode | — | |
| `onChange` | `(value: number \| string \| null) => void` | — | |
| `onStep` | `(value, info: { offset; type }) => void` | — | |

`addonBefore` / `addonAfter` are **removed** — wrap with `Space.Compact` + `Space.Addon`.

## Select

```tsx
import { Select } from 'antd';
import type { SelectProps, DefaultOptionType } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `options` | `Option[]` | — | preferred over `<Select.Option>` children |
| `value` / `defaultValue` | scalar / array / `LabeledValue` | — | |
| `mode` | `'multiple' \| 'tags'` | — | |
| `variant` | variant union | `'outlined'` | replaces v5 `bordered` |
| `size` | size union | `'medium'` | |
| `status` | `'error' \| 'warning'` | — | |
| `placeholder` | ReactNode | — | |
| `disabled` / `loading` | boolean | `false` | |
| `allowClear` | `boolean \| { clearIcon }` | `false` | |
| `open` / `defaultOpen` | boolean | — | controlled popup |
| `popupMatchSelectWidth` | `boolean \| number` | `true` | v5 `dropdownMatchSelectWidth` |
| `popupRender` | `(originNode) => ReactNode` | — | v5 `dropdownRender` |
| `placement` | `'topLeft'\|'topRight'\|'bottomLeft'\|'bottomRight'` | — | |
| `getPopupContainer` | `(trigger) => HTMLElement` | — | |
| `showSearch` | `boolean \| ShowSearchObject` | — | |
| `fieldNames` | `{ label; value; options; groupLabel }` | — | custom option keys |
| `maxCount` | number | — | max selected (multiple/tags) |
| `maxTagCount` | `number \| 'responsive'` | — | |
| `maxTagPlaceholder` | `ReactNode \| (omitted) => ReactNode` | — | |
| `tagRender` | `(props: TagRenderProps) => ReactNode` | — | |
| `labelRender` | `(props) => ReactNode` | — | |
| `optionRender` | `(option, info: { index }) => ReactNode` | — | |
| `labelInValue` | boolean | `false` | onChange gives `{ value, label }` |
| `virtual` | boolean | `true` | |
| `listHeight` | number | `256` | |
| `suffixIcon` | ReactNode | — | set `null` to hide arrow (v5 `showArrow`) |
| `notFoundContent` | ReactNode | — | |
| `tokenSeparators` | `string[]` | — | tags mode auto-split |
| `classNames` / `styles` | object / fn | — | see semantic keys below |
| `onChange` | `(value, option) => void` | — | |
| `onSelect` / `onDeselect` | `(value, option) => void` | — | |
| `onOpenChange` | `(open: boolean) => void` | — | v5 `onDropdownVisibleChange` |
| `onSearch` | `(value: string) => void` | — | |
| `onClear` | `() => void` | — | |
| `onPopupScroll` | `(e) => void` | — | |

`ShowSearchObject`: `{ filterOption, filterSort, optionFilterProp, searchValue, onSearch,
autoClearSearchValue }`. **v6**: `optionFilterProp` accepts `string[]` for multi-field search.

Semantic keys (`classNames` / `styles`): `root`, `selector`, `selectorPlaceholder`,
`selectorSearch`, `multi`, `multipleItem`, `multipleItemLabel`, `multipleItemRemove`,
`singleItem`, `singleItemLabel`, `popup` (nested: `popup.root`, `popup.list`,
`popup.listItem`), `option`.

```tsx
<Select
  showSearch={{ optionFilterProp: ['label', 'desc'] }}   // v6 multi-field search
  variant="filled"
  options={[
    { value: 'a', label: 'Alpha', desc: 'first' },
    { value: 'b', label: 'Beta',  desc: 'second' },
  ]}
  classNames={{ popup: { root: 'my-dd' } }}
  styles={{ popup: { root: { boxShadow: '0 2px 8px rgba(0,0,0,.15)' } } }}
  onChange={(v, opt) => console.log(v, opt)}
/>
```

Types:
```tsx
type LabeledValue = { value: string | number; label?: ReactNode };
type DefaultOptionType = {
  label?: ReactNode; value?: string | number; disabled?: boolean;
  title?: string; children?: DefaultOptionType[];
};
type TagRenderProps = {
  label: ReactNode; value: any; disabled: boolean;
  onClose: () => void; closable: boolean;
};
```

## DatePicker / RangePicker

```tsx
import { DatePicker } from 'antd';
import type { DatePickerProps, RangePickerProps } from 'antd';
import dayjs, { Dayjs } from 'dayjs';     // antd uses dayjs by default
const { RangePicker } = DatePicker;
```

### Common props (DatePicker & RangePicker)

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `variant` | variant union | `'outlined'` | replaces v5 `bordered` |
| `size` | size union | — | |
| `status` | `'error' \| 'warning'` | — | |
| `picker` | `'date'\|'week'\|'month'\|'quarter'\|'year'` | `'date'` | |
| `format` | `string \| fn \| Array \| { format; type:'mask' }` | locale | |
| `disabled` | `boolean \| [boolean, boolean]` | `false` | RangePicker: per-input |
| `disabledDate` | `(current, info: { from?; type }) => boolean` | — | |
| `minDate` / `maxDate` | `Dayjs` | — | |
| `allowClear` | `boolean \| { clearIcon }` | `true` | |
| `presets` | `{ label; value: Dayjs \| (() => Dayjs) }[]` | — | quick presets |
| `showTime` | `boolean \| TimepickerOptions` | — | `showTime.defaultOpenValue` for default time |
| `placement` | `'topLeft'\|'topRight'\|'bottomLeft'\|'bottomRight'` | — | |
| `getPopupContainer` | `(trigger) => HTMLElement` | — | |
| `prefix` / `suffixIcon` | ReactNode | — | |
| `order` | boolean | `true` | auto-order multiple/range selection |
| `inputReadOnly` | boolean | `false` | |
| `classNames` / `styles` | object / fn | — | nested `popup.root` for the panel |
| `onOpenChange` | `(open) => void` | — | |
| `onPanelChange` | `(value, mode) => void` | — | |

### DatePicker-specific

```tsx
interface DatePickerProps {
  value?: Dayjs; defaultValue?: Dayjs;
  multiple?: boolean;                  // 5.14.0+
  showNow?: boolean; showWeek?: boolean;
  renderExtraFooter?: (mode) => ReactNode;
  onChange?: (date: Dayjs | null, dateString: string | null) => void;
  onCalendarChange?: (date, dateString) => void;  // v5 onSelect removed
  onOk?: () => void;
}
```

### RangePicker-specific

```tsx
interface RangePickerProps {
  value?: [Dayjs, Dayjs]; defaultValue?: [Dayjs, Dayjs];
  allowEmpty?: [boolean, boolean];
  separator?: ReactNode;               // 6.3.0+, default <SwapRightOutlined/>
  id?: { start?: string; end?: string };
  tagRender?: (props) => ReactNode;    // 6.4.0+ for year/month/quarter/week multiple mode
  onChange?: (dates: [Dayjs, Dayjs] | null, dateStrings: [string, string] | null) => void;
  onCalendarChange?: (dates, dateStrings, info: { range: 'start' | 'end' }) => void;
}
```

```tsx
const App: React.FC = () => {
  const handle: DatePickerProps['onChange'] = (date, str) => console.log(str);
  return (
    <DatePicker
      variant="filled"
      minDate={dayjs()}
      presets={[{ label: 'Today', value: dayjs() }]}
      onChange={handle}
    />
  );
};
```

Default formats: DatePicker `YYYY-MM-DD`, month picker `YYYY-MM`. Configure locale via
`<ConfigProvider locale={...}>`.
