---
name: antd-v5
description: >
  Ant Design v5 (antd ^5.x) complete API reference for React component library.
  Use this skill whenever the task involves any antd v5 component: Table, Form, Select,
  Input, Button, Modal, notification, message, Upload, Tag, Tooltip, Badge, Image,
  Radio, Segmented, Switch, DatePicker, RangePicker, Popconfirm, Drawer, Descriptions,
  ConfigProvider, Space, Layout, Grid (Row/Col), Spin, Pagination, Menu, Dropdown,
  Breadcrumb, Tabs, Steps, Affix, Anchor, AutoComplete, Cascader, Checkbox, ColorPicker,
  InputNumber, Mentions, Rate, Slider, TimePicker, Transfer, TreeSelect, Avatar, Calendar,
  Card, Carousel, Collapse, Empty, List, Popover, QRCode, Statistic, Timeline, Tree,
  Alert, Progress, Result, Skeleton, Watermark, Divider, Flex, FloatButton, Splitter,
  Typography, App, or the antd theme system (token, algorithm, useToken, CSS variables).
  Also use when code imports from 'antd',
  references antd TypeScript types (TableProps, FormInstance, ColumnsType, UploadFile, etc.),
  or when configuring antd theming/tokens. Do NOT use this skill for @ant-design/pro-components
  (use ant-design-pro-v2 skill instead). Version coverage: antd 5.x (tested against 5.29.x).
---

# Ant Design v5 API Reference

## Quick Navigation

This skill covers antd v5 components organized by category. Each reference file provides
complete API signatures, all props with types and defaults, TypeScript types, and usage patterns.

### Official Documentation Source

權威查證入口（給 LLM 消化的精簡文件索引）：

- **LLMs.txt 索引頁**：https://5x.ant.design/docs/react/llms
- **完整文件索引**：https://ant.design/llms.txt （列出所有元件 / API 文件連結）
- **單一元件 API**：`https://5x.ant.design/components/<component-name>`（v5.x 版本權威來源）

需新增或查證元件 API 時，優先以上述來源為準。`5x.ant.design` 對應 v5.x，`ant.design` 可能已切換至更新 major。

### Reference Files

Read only the file relevant to your current task:

| File | Components | When to Read |
|------|-----------|--------------|
| `references/data-display.md` | Table, Tag, Badge, Image, Tooltip, Descriptions | Rendering data, lists, status indicators, previews |
| `references/data-display-advanced.md` | Avatar, Calendar, Card, Carousel, Collapse, Empty, List, Popover, QRCode, Statistic, Timeline, Tree | Cards, lists, trees, calendars, stats, popovers |
| `references/data-entry.md` | Form, Input, Select, Radio, Segmented, Switch, Upload, DatePicker/RangePicker | Form building, user input, file upload, date selection |
| `references/data-entry-advanced.md` | AutoComplete, Cascader, Checkbox, ColorPicker, InputNumber, Mentions, Rate, Slider, TimePicker, Transfer, TreeSelect | Advanced inputs: cascading select, numeric, color, rating, sliders |
| `references/navigation.md` | Affix, Anchor, Breadcrumb, Dropdown, Menu, Steps, Tabs | Menus, breadcrumbs, dropdowns, tabs, step flows, anchors |
| `references/feedback.md` | Modal, notification, message, Popconfirm, Drawer, Alert, Progress, Result, Skeleton, Watermark | Dialogs, alerts, confirmations, side panels, progress, result pages |
| `references/general-layout.md` | Button, ConfigProvider, Space, Layout, Grid, Spin, Pagination, Divider, Flex, FloatButton, Splitter, Typography, App | Buttons, layout structure, loading states, navigation, typography |
| `references/theme.md` | theme, useToken, ConfigProvider theme prop | Theming, design tokens, dark mode, CSS variables |

## Key Patterns (antd v5)

### Import Style
```tsx
import { Table, Form, Button, ConfigProvider, theme } from 'antd';
import type { TableProps, FormInstance, ColumnsType } from 'antd';
```

### v5 Breaking Changes from v4
- CSS-in-JS replaces Less (no more .less imports)
- `visible` prop renamed to `open` across all components
- `dropdownClassName` renamed to `popupClassName`
- `bordered` deprecated in favor of `variant` prop
- Design token system replaces Less variables
- `message`/`notification`/`modal` prefer hook API over static methods

### Common v5 Prop Patterns

**variant** (v5.13.0+): `'outlined' | 'borderless' | 'filled' | 'underlined'`
Applied to: Input, Select, DatePicker, Form (sets default for children)

**classNames / styles**: Semantic DOM customization available on nearly all components.
```tsx
<Table
  classNames={{ header: 'bg-gray-100', body: 'text-sm' }}
  styles={{ header: { fontWeight: 600 } }}
/>
```

**status**: `'error' | 'warning'` for validation display (Input, Select, DatePicker)

**allowClear** (v5.8.0+): `boolean | { clearIcon?: ReactNode }`

### TypeScript Quick Reference

```tsx
// Table
import type { TableProps, TableColumnsType, TablePaginationConfig } from 'antd';
const columns: TableColumnsType<DataType> = [{ title: 'Name', dataIndex: 'name' }];

// Form
import type { FormInstance, FormProps, FormItemProps, Rule } from 'antd';
const [form] = Form.useForm<FormValues>();

// Select
import type { SelectProps, DefaultOptionType } from 'antd';

// Upload
import type { UploadFile, UploadProps, UploadChangeParam } from 'antd';

// DatePicker
import type { DatePickerProps, RangePickerProps } from 'antd';
// Note: antd v5 uses dayjs by default (not moment.js)

// Theme
const { token } = theme.useToken();
```

### Hook APIs (Preferred over Static Methods)

```tsx
// notification
const [api, contextHolder] = notification.useNotification();
// Place {contextHolder} in JSX, then call api.success({...})

// message
const [messageApi, contextHolder] = message.useMessage();

// Modal
const [modal, contextHolder] = Modal.useModal();
const confirmed = await modal.confirm({ title: '...' }); // returns boolean (v5.4.0+)
```

### Form Patterns

```tsx
// Basic Form with useForm
const [form] = Form.useForm<{ name: string; age: number }>();

// Watch field value
const nameValue = Form.useWatch('name', form);

// Dependencies for conditional rendering
<Form.Item dependencies={['type']} noStyle>
  {({ getFieldValue }) =>
    getFieldValue('type') === 'other' ? <Form.Item name="detail"><Input /></Form.Item> : null
  }
</Form.Item>

// Form.List for dynamic fields
<Form.List name="users">
  {(fields, { add, remove }) => fields.map(field => (
    <Form.Item {...field} name={[field.name, 'name']}><Input /></Form.Item>
  ))}
</Form.List>

// Validation
form.validateFields()              // all fields
form.validateFields(['name'])      // specific fields
form.validateFields({ validateOnly: true })  // no UI error (v5.5.0+)
```

### Table Patterns

```tsx
// Controlled pagination + sorting + filtering
<Table
  dataSource={data}
  columns={columns}
  pagination={{ current: page, pageSize, total }}
  onChange={(pagination, filters, sorter, extra) => {
    // extra.action: 'paginate' | 'sort' | 'filter'
  }}
/>

// Virtual scrolling (v5.9.0+)
<Table virtual scroll={{ y: 500 }} columns={columns} dataSource={bigData} />

// Row selection
<Table
  rowSelection={{
    type: 'checkbox',
    selectedRowKeys,
    onChange: (keys, rows, info) => { /* info.type available v4.21.0+ */ },
  }}
/>
```

### Theme Configuration

```tsx
import { ConfigProvider, theme } from 'antd';

<ConfigProvider
  theme={{
    algorithm: theme.darkAlgorithm, // or [theme.darkAlgorithm, theme.compactAlgorithm]
    token: {
      colorPrimary: '#1890ff',
      borderRadius: 4,
      fontSize: 14,
    },
    components: {
      Button: { colorPrimary: '#00b96b' },
      Table: { headerBg: '#fafafa' },
    },
  }}
>
  <App />
</ConfigProvider>
```
