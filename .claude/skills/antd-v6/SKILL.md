---
name: antd-v6
description: >
  Ant Design v6 (antd ^6.x) complete API reference for React component library.
  Use this skill whenever the task involves any antd v6 component: Table, Form, Select,
  Input, InputNumber, Button, Modal, Drawer, notification, message, Upload, Tag, Tooltip,
  Badge, Image, Radio, Segmented, Switch, DatePicker, RangePicker, Popconfirm, Descriptions,
  Tabs, Menu, Steps, Timeline, ConfigProvider, Space, Space.Compact, Layout, Grid (Row/Col),
  Masonry, BorderBeam, or the antd v6 theme system (token, algorithm, useToken, cssVar,
  zeroRuntime). Also use when code imports from 'antd', references antd TypeScript types
  (TableProps, FormInstance, ColumnsType, SelectProps, UploadFile, etc.), uses the v6
  semantic DOM API (classNames / styles props with semantic keys like root / popup.root /
  body), or migrates an antd v5 project to v6. Also covers the Ant Design tooling: the
  @ant-design/cli command-line tool (antd list / info / doc / demo / token / doctor / lint /
  migrate) and the official Ant Design MCP server (antd mcp) for IDE / AI-agent integration
  and project scaffolding.
  CRITICAL: antd v6 has extensive breaking changes from v5. Many v5 props were renamed or
  removed (visible-style dropdown props -> popup.* semantic keys, direction -> orientation,
  Input.Group/Button.Group -> Space.Compact, BackTop/Icon removed, bordered -> variant,
  destroyOnClose -> destroyOnHidden). Confirm the antd major version in package.json before
  applying any API: if pinned ^5.x use the antd-v5 skill instead; this skill is v6-only.
  Do NOT use this skill for @ant-design/pro-components.
  Version coverage: antd 6.x (documented against 6.4.3).
---

# Ant Design v6 API Reference

> **Applies to**: antd `^6.x` (documented against 6.4.3) | **Docs source**: https://ant.design/llms-full.txt + https://ant.design/llms-semantic.md | **Last updated**: 2026-05-22

Ant Design v6 is the React component library's sixth major release. Compared with v5 it
reworks the semantic DOM customization API (`classNames` / `styles`), makes CSS variables
the default, drops React 17 / IE support, and renames or removes a large number of v5 APIs.

## STEP 0 — Confirm the antd version FIRST

Before writing or modifying any antd code, read the project `package.json` and check the
`antd` entry under `dependencies`:

| `antd` pin | Action |
|-----------|--------|
| `^6.x` / `6.x` | Use this skill. |
| `^5.x` / `5.x` | **STOP** — use the `antd-v5` skill instead. v6 API will break a v5 project. |
| `^4.x` or lower | Neither skill fully applies; warn the user. |

v6 also requires **React >= 18** and **@ant-design/icons >= 6**. The two icon majors are
incompatible — `@ant-design/icons@5` must not be paired with `antd@6`.

## Quick Navigation — Reference Files

Read only the file relevant to your current task. Each file provides complete API
signatures, props with types and defaults, TypeScript types, and v6-specific notes.

| File | Components / Topic | When to Read |
|------|--------------------|--------------|
| `references/data-display.md` | Table, Tag, Tabs, Menu, Steps, Timeline, Badge, Descriptions, Tooltip, Masonry | Rendering data, lists, navigation, status, layout grids |
| `references/data-entry.md` | Form, Input, InputNumber, Select, DatePicker/RangePicker, Radio, Switch, Upload | Forms, user input, file upload, date selection |
| `references/feedback.md` | Modal, Drawer, notification, message, Popconfirm | Dialogs, alerts, confirmations, side panels |
| `references/general-layout.md` | Button, ConfigProvider, Space, Space.Compact, Layout, Grid, BorderBeam | Buttons, layout structure, global config, new components |
| `references/theme.md` | theme, useToken, ConfigProvider theme prop, tokens, cssVar, zeroRuntime | Theming, design tokens, dark mode, CSS variables |
| `references/migration-v5-to-v6.md` | v5 → v6 migration guide | Upgrading an existing antd v5 project to v6 |
| `references/tooling-mcp-cli.md` | `@ant-design/cli` commands + Ant Design MCP server (`antd mcp`) | Using the antd CLI, setting up the MCP server, IDE / AI-agent integration |

## The v6 Semantic DOM API (read this — it changed across the whole library)

v6 standardizes component customization on two props: `classNames` and `styles`. They map
**semantic part names** to a CSS class / inline style. This replaces the v5 scattered props
(`dropdownClassName`, `popupClassName`, `bodyStyle`, `headerStyle`, `overlayStyle`, etc.).

```tsx
// classNames + styles take an object of semantic keys
<Card
  classNames={{ root: 'bg-white rounded-lg', header: 'border-b', body: 'p-6' }}
  styles={{ header: { backgroundColor: '#fafafa' }, title: { fontWeight: 600 } }}
/>

// Nested popup targets use a nested object (NOT dot-string in the value)
<Select
  classNames={{ root: 'my-select', popup: { root: 'my-dropdown' } }}
  styles={{ popup: { root: { boxShadow: '0 2px 8px rgba(0,0,0,.15)' } } }}
/>

// Both props also accept a function for prop-aware styling
<Input
  styles={(info) => ({ root: info.props.size === 'large' ? { borderColor: '#1677ff' } : {} })}
/>
```

Common semantic keys: `root`, `header`, `body`, `footer`, `title`, `content`, `icon`,
`mask`, `wrapper`, `section`, `actions`, `close`, and the nested `popup.root` / `popup.list`
/ `popup.listItem` for dropdown-bearing components (Select, Cascader, DatePicker, TreeSelect,
AutoComplete, Dropdown, Tabs). Exact keys per component are in the reference files.

## Key v6 Changes from v5 (high-frequency breakage)

These are the changes most likely to break v5 code or trip up generated code. Full list in
`references/migration-v5-to-v6.md`.

- **Dropdown/popup props → `popup.*` semantic keys**: `dropdownClassName`, `popupClassName`,
  `dropdownStyle`, `dropdownRender` → `classNames.popup.root` / `styles.popup.root` /
  `popupRender`. `dropdownMatchSelectWidth` → `popupMatchSelectWidth`.
  `onDropdownVisibleChange` → `onOpenChange`. (Select, Cascader, TreeSelect, AutoComplete,
  DatePicker, Dropdown.)
- **`direction` → `orientation`**: Space, Space.Compact, Steps, Splitter, Slider, Divider.
- **`xxxPosition` → `xxxPlacement`**: Button `iconPosition`→`iconPlacement`, Tabs
  `tabPosition`→`tabPlacement`, Carousel `dotPosition`→`dotPlacement`, Collapse
  `expandIconPosition`→`expandIconPlacement`, Steps `labelPlacement`→`titlePlacement`,
  Progress `gapPosition`→`gapPlacement`.
- **`destroyOnClose` / `destroyInactive*` → `destroyOnHidden`**: Modal, Drawer, Tabs,
  Collapse, Dropdown.
- **`bordered` → `variant`**: Input, InputNumber, Select, Cascader, TreeSelect, DatePicker,
  Card. Tag `bordered={false}` → `variant="filled"`; `color="x-inverse"` → `variant="solid"`.
- **Removed components**: `BackTop` (→ `FloatButton.BackTop`), `Icon` (→ `@ant-design/icons`),
  `Button.Group` / `Input.Group` / `Dropdown.Button` (→ `Space.Compact`). `List` removed
  from docs.
- **`children` → `items`**: Tabs (`Tabs.TabPane`), Menu, Breadcrumb, Timeline, Anchor,
  Mentions, Steps.
- **CSS variables on by default**; React 17 & IE support dropped; React 19 supported
  natively (remove `@ant-design/v5-patch-for-react-19`).
- **Overlay masks blur by default** (Modal, Drawer, Image); disable via
  `mask={{ blur: false }}` or globally through ConfigProvider.

## Import Style

```tsx
import { Table, Form, Button, ConfigProvider, theme, Space } from 'antd';
import type { TableProps, FormInstance, ColumnsType, SelectProps } from 'antd';
import { SearchOutlined } from '@ant-design/icons'; // must be @ant-design/icons@6
```

## Hook APIs (preferred over static methods)

Static `notification.x()` / `message.x()` / `Modal.x()` calls run outside the React tree
and lose `ConfigProvider` context. Prefer the hook form and render `contextHolder`.

```tsx
const [api, contextHolder] = notification.useNotification();
// JSX: {contextHolder}  then  api.success({ title: 'Done', description: '...' })

const [messageApi, ctx] = message.useMessage();

const [modal, ctx2] = Modal.useModal();
const ok = await modal.confirm({ title: 'Delete?' }); // resolves to boolean
```

> v6: notification config uses `title` (not `message`) and `actions` (not `btn`).

## Theme Configuration (v6)

```tsx
import { ConfigProvider, theme } from 'antd';

<ConfigProvider
  theme={{
    algorithm: theme.darkAlgorithm,          // or [darkAlgorithm, compactAlgorithm]
    token: { colorPrimary: '#1677ff', borderRadius: 6, fontSize: 14 },
    components: { Button: { colorPrimary: '#00b96b', algorithm: true } },
    cssVar: true,                            // CSS variables (default true in v6)
    // zeroRuntime: true,                    // v6: no runtime style generation
  }}
>
  <App />
</ConfigProvider>
```

## Notes & Pitfalls

- **Version mismatch is the #1 trap.** A v5 snippet pasted into a v6 project fails on
  renamed props. Always run STEP 0 first.
- **Do not pair `@ant-design/icons@5` with `antd@6`** — runtime errors.
- **`maskClosable` was removed** from Modal — use `mask={{ closable: true }}`.
- **`getFieldsValue({ strict: true })`** filtering is unnecessary in v6 — `onFinish` already
  excludes unregistered `Form.List` children.
- **Tag lost its trailing `margin-inline-end`** — wrap in `Space` or restore via
  ConfigProvider `tag={{ styles: { root: { marginInlineEnd: 8 } } }}`.
- The `direction`/`split`/`bordered`/`visible`-style props still *may* work with deprecation
  warnings, but should be migrated; some (`Button.Group`, `BackTop`, `Icon`) are fully gone.

## Tooling — CLI & MCP

For authoritative, version-aware answers beyond this static reference, antd ships
`@ant-design/cli` (`antd info`, `antd doc`, `antd demo`, `antd token`, `antd doctor`,
`antd lint`, `antd migrate`, …) — fully offline, covers v4/v5/v6 — and an official MCP
server (`antd mcp`, in `@ant-design/cli` v6.3.5+) exposing 7 tools + 2 prompts for IDE /
AI-agent integration. Setup, command list, and MCP client config: see
`references/tooling-mcp-cli.md`.

## References Guide

| Need | Read |
|------|------|
| Table / Tabs / Tag / data display API | `references/data-display.md` |
| Form / Input / Select / DatePicker API | `references/data-entry.md` |
| Modal / Drawer / notification / message API | `references/feedback.md` |
| Button / ConfigProvider / Space / layout API | `references/general-layout.md` |
| Theme tokens / algorithms / cssVar / zeroRuntime | `references/theme.md` |
| Upgrading a v5 project: full breaking-change list | `references/migration-v5-to-v6.md` |
| `@ant-design/cli` commands, `antd mcp` MCP server setup, IDE integration | `references/tooling-mcp-cli.md` |
