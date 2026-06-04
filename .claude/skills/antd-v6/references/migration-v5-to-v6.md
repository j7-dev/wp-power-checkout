# antd v5 → v6 Migration Guide

> **Source**: https://ant.design/docs/react/migration-v6 + https://ant.design/changelog
> antd v6.0.0 released 2025-11-22. Documented against 6.4.3.

## Table of Contents
- [1. Pre-upgrade requirements](#1-pre-upgrade-requirements)
- [2. Install commands](#2-install-commands)
- [3. Environment & peer dependency breaking changes](#3-environment--peer-dependency-breaking-changes)
- [4. Removed components](#4-removed-components)
- [5. Renamed / removed props — full table](#5-renamed--removed-props--full-table)
- [6. Behavioral breaking changes](#6-behavioral-breaking-changes)
- [7. CSS / theme / token changes](#7-css--theme--token-changes)
- [8. The new semantic class/style API vs old props](#8-the-new-semantic-classstyle-api-vs-old-props)
- [9. Step-by-step upgrade checklist](#9-step-by-step-upgrade-checklist)
- [10. New components & notable additions in 6.x](#10-new-components--notable-additions-in-6x)

---

## 1. Pre-upgrade requirements

Before upgrading, ensure (source: migration-v6):
- The project runs **React 18 or higher** — v6 drops React 17 support.
- You are on the **latest v5 release** and have resolved all v5 deprecation console warnings.
- Target browsers are modern — **IE is no longer supported** at all.

## 2. Install commands

```bash
npm install --save antd@6 @ant-design/icons@6
# or
pnpm add antd@6 @ant-design/icons@6
```

`@ant-design/icons` **must** be upgraded to `>=6.0.0` simultaneously — the two icon majors
are incompatible with the matching antd major.

## 3. Environment & peer dependency breaking changes

| Area | v5 | v6 |
|------|----|----|
| React | 17 / 18 | **18+** only |
| React 19 | needs `@ant-design/v5-patch-for-react-19` | natively supported — **remove the patch import** |
| `@ant-design/icons` | v5 | **v6 required** |
| IE | partial reset support | dropped entirely; CSS reset no longer carries IE compat |
| internal className util | `classnames` | replaced with `clsx` |
| Build target | lower | raised (modern syntax / modern CSS) |

Remove the React 19 patch if present:

```diff
- import '@ant-design/v5-patch-for-react-19';
```

## 4. Removed components

| Removed in v6 | Replacement |
|---------------|-------------|
| `BackTop` | `FloatButton.BackTop` |
| `Icon` | use `@ant-design/icons` directly |
| `Button.Group` | `Space.Compact` |
| `Input.Group` | `Space.Compact` (with `Space.Addon` for addons) |
| `Dropdown.Button` | `Space.Compact` + `Dropdown` + `Button` |
| `List` | deprecated and removed from documentation |
| `Tabs.TabPane` | `Tabs` `items` prop |
| `Breadcrumb.Item` / `Breadcrumb.Separator` | `Breadcrumb` `items` prop |
| `Timeline.Item` | `Timeline` `items` prop |
| `Mentions.Option` | `Mentions` `options` prop |
| `Statistic.Countdown` | `Statistic.Timer` with `type="countdown"` |

## 5. Renamed / removed props — full table

Source: https://ant.design/docs/react/migration-v6. Some props still function with a
deprecation warning; treat all as required migrations.

### Alert
- `closeText` → `closable.closeIcon`
- `message` → `title`

### Anchor
- `children` → `items`

### AutoComplete
- `dropdownMatchSelectWidth` → `popupMatchSelectWidth`
- `dropdownStyle` → `styles.popup.root`
- `dropdownClassName` / `popupClassName` → `classNames.popup.root`
- `dropdownRender` → `popupRender`
- `onDropdownVisibleChange` → `onOpenChange`
- `dataSource` → `options`

### Avatar.Group
- `maxCount` → `max={{ count: number }}`
- `maxStyle` → `max={{ style: CSSProperties }}`
- `maxPopoverPlacement` / `maxPopoverTrigger` → `max={{ popover: PopoverProps }}`

### Breadcrumb
- `routes` → `items`
- `Breadcrumb.Item` / `Breadcrumb.Separator` → `items`

### Button
- `Button.Group` → `Space.Compact`
- `iconPosition` → `iconPlacement`

### Calendar
- `dateFullCellRender` / `monthFullCellRender` → `fullCellRender`
- `dateCellRender` / `monthCellRender` → `cellRender`

### Card
- `headStyle` → `styles.header`
- `bodyStyle` → `styles.body`
- `bordered` → `variant`

### Carousel
- `dotPosition` → `dotPlacement`

### Cascader
- `dropdownClassName` → `classNames.popup.root`
- `dropdownStyle` → `styles.popup.root`
- `dropdownRender` → `popupRender`
- `dropdownMenuColumnStyle` → `styles.popup.listItem`
- `onDropdownVisibleChange` / `onPopupVisibleChange` → `onOpenChange`
- `bordered` → `variant`

### Collapse
- `destroyInactivePanel` → `destroyOnHidden`
- `expandIconPosition` → `expandIconPlacement`
- `Collapse.Panel` `disabled` → `collapsible="disabled"`

### ConfigProvider
- `dropdownMatchSelectWidth` → `popupMatchSelectWidth`

### DatePicker / DatePicker.RangePicker
- `dropdownClassName` / `popupClassName` → `classNames.popup.root`
- `popupStyle` → `styles.popup.root`
- `bordered` → `variant`
- `onSelect` → `onCalendarChange`

### Descriptions
- `labelStyle` → `styles.label`
- `contentStyle` → `styles.content`

### Divider
- `type` → `orientation`
- `orientationMargin` → `styles.content.margin`

### Drawer
- `headerStyle` → `styles.header`
- `bodyStyle` → `styles.body`
- `footerStyle` → `styles.footer`
- `contentWrapperStyle` → `styles.wrapper`
- `maskStyle` → `styles.mask`
- `drawerStyle` → `styles.section`
- `destroyInactivePanel` → `destroyOnHidden`
- `width` / `height` → `size`

### Dropdown
- `Dropdown.Button` → `Space.Compact` + `Dropdown` + `Button`
- `dropdownRender` → `popupRender`
- `destroyPopupOnHide` → `destroyOnHidden`
- `overlayClassName` → `classNames.root`
- `overlayStyle` → `styles.root`
- `placement: xxxCenter` → `placement: xxx`

### Empty
- `imageStyle` → `styles.image`

### FloatButton
- `description` → `content`

### Image
- `wrapperStyle` → `styles.root`
- `visible` → `open`
- `onVisibleChange` → `onOpenChange`
- `maskClassName` → `classNames.cover`
- `rootClassName` → `classNames.root`
- `toolbarRender` → `actionsRender`

### Input
- `Input.Group` → `Space.Compact`

### InputNumber
- `bordered` → `variant`
- `addonAfter` / `addonBefore` → `Space.Compact`

### Mentions
- `Mentions.Option` → `options`

### Menu
- `children` → `items`

### Modal
- `bodyStyle` → `styles.body`
- `maskStyle` → `styles.mask`
- `destroyOnClose` → `destroyOnHidden`
- `maskClosable` → `mask.closable`
- `focusTriggerAfterClose` → `focusable.focusTriggerAfterClose`

### Notification
- `btn` → `actions`
- `message` → `title`

### Progress
- `strokeWidth` → `size`
- `width` → `size`
- `trailColor` → `railColor`
- `gapPosition` → `gapPlacement`

### Select
- `dropdownMatchSelectWidth` → `popupMatchSelectWidth`
- `dropdownStyle` → `styles.popup.root`
- `dropdownClassName` / `popupClassName` → `classNames.popup.root`
- `dropdownRender` → `popupRender`
- `onDropdownVisibleChange` → `onOpenChange`
- `bordered` → `variant`
- `showArrow` → `suffixIcon={null}` (to hide the arrow)

### Slider
- `tooltipPrefixCls` → `tooltip.prefixCls`
- `getTooltipPopupContainer` → `tooltip.getPopupContainer`
- `tipFormatter` → `tooltip.formatter`
- `tooltipPlacement` → `tooltip.placement`
- `tooltipVisible` → `tooltip.open`

### Space
- `direction` → `orientation`
- `split` → `separator`
- `Space.Compact` `direction` → `orientation`

### Splitter
- `layout` → `orientation`

### Statistic
- `Statistic.Countdown` → `Statistic.Timer type="countdown"`
- `valueStyle` → `styles.content`

### Steps
- `labelPlacement` → `titlePlacement`
- `progressDot` → `type="dot"`
- `direction` → `orientation`
- `items.description` → `items.content`

### Table
- `pagination.position` → `pagination.placement`
- `onSelectInvert` → `onChange`
- `filterDropdownOpen` → `filterDropdownProps.open`
- `onFilterDropdownOpenChange` → `filterDropdownProps.onOpenChange`
- `filterCheckall` → `locale.filterCheckAll`
- `column.fixed: 'left' | 'right'` → `column.fixed: 'start' | 'end'`

### Tabs
- `popupClassName` → `classNames.popup`
- `tabPosition` → `tabPlacement`
- `destroyInactiveTabPane` → `destroyOnHidden`
- `Tabs.TabPane` → `items`

### Tag
- `bordered={false}` → `variant="filled"`
- `color="xxx-inverse"` → `variant="solid"`

### TimePicker
- `addon` → `renderExtraFooter`

### Timeline
- `Timeline.Item` → `items`
- `pending` / `pendingDot` → `items`
- `mode="left" | "right"` → `mode="start" | "end"`

### Tooltip
- `overlayStyle` → `styles.root`
- `overlayInnerStyle` → `styles.container`
- `overlayClassName` → `classNames.root`
- `destroyTooltipOnHide` → `destroyOnHidden`

### Transfer
- `listStyle` → `styles.section`
- `operationStyle` → `styles.actions`
- `operations` → `actions`

### TreeSelect
- `dropdownMatchSelectWidth` → `popupMatchSelectWidth`
- `dropdownStyle` → `styles.popup.root`
- `dropdownClassName` / `popupClassName` → `classNames.popup.root`
- `dropdownRender` → `popupRender`
- `onDropdownVisibleChange` → `onOpenChange`
- `bordered` → `variant`

## 6. Behavioral breaking changes

| Area | Change | What to do |
|------|--------|-----------|
| Overlay masks | Modal / Drawer / Image masks now **blur by default** | Disable globally via `<ConfigProvider modal={{ mask: { blur: false } }} drawer={{ mask: { blur: false } }}>` |
| Tag spacing | Trailing `margin-inline-end` **removed** | Restore via `<ConfigProvider tag={{ styles: { root: { marginInlineEnd: 8 } } }}>` or wrap tags in `Space` |
| Form.List | `onFinish` no longer includes **unregistered** Form.List child items | Remove `getFieldsValue({ strict: true })` filtering — `values` is already clean |
| InputNumber (mobile) | Controls hidden by default on mobile | Use `mode="spinner"` to force spinner controls if needed |
| Internal DOM | Many components restructured their internal DOM | Audit any custom CSS targeting antd internal selectors |
| Table fixed columns | `column.fixed` uses logical `start` / `end` | Replace `'left'`/`'right'` literals |

```diff
// Form.List unregistered field cleanup is no longer needed
- const realValues = getFieldsValue({ strict: true });
+ const realValues = values;
```

## 7. CSS / theme / token changes

- **CSS variables enabled by default.** Previously opt-in via `cssVar`; in v6 it is the
  default. This improves runtime performance and dynamic theme switching. Verify target
  browsers support CSS custom properties (all modern browsers do; IE does not — IE dropped).
- **`zeroRuntime` mode (v6.0.0+)**: set `theme={{ zeroRuntime: true }}` to skip runtime
  style generation entirely. Requires importing static CSS (`antd/dist/antd.css`) or
  generating it with `@ant-design/static-style-extract`.
- **New token `colorBorderDisabled`** for unified disabled-state border styling.
- **`useToken` now exports `cssVar`** alongside `token` and `hashId`.
- **reset.css**: `mark` element styling removed; the Form required mark is no longer
  hardcoded to the `SimSun` font.
- CSS reset no longer ships IE compatibility rules.

## 8. The new semantic class/style API vs old props

v5 customization was a scatter of one-off props. v6 unifies everything into two props:
`classNames` and `styles`, keyed by **semantic part name**.

```tsx
// v5 — scattered props
<Modal bodyStyle={{ padding: 24 }} maskStyle={{ background: '#0008' }} />
<Select dropdownClassName="dd" dropdownStyle={{ zIndex: 2000 }} />
<Card headStyle={{ fontWeight: 600 }} bodyStyle={{ padding: 12 }} />

// v6 — semantic classNames / styles objects
<Modal styles={{ body: { padding: 24 }, mask: { background: '#0008' } }} />
<Select
  classNames={{ popup: { root: 'dd' } }}
  styles={{ popup: { root: { zIndex: 2000 } } }}
/>
<Card styles={{ header: { fontWeight: 600 }, body: { padding: 12 } }} />
```

Rules:
- `classNames` maps semantic key → CSS class string; `styles` maps semantic key →
  `CSSProperties`.
- Nested popup targets are **nested objects**: `classNames={{ popup: { root: '...' } }}`,
  not a `'popup.root'` string key.
- Both props can be a **function** `(info) => Record<key, ...>` for prop-aware styling.
- The plain `className` / `style` props still exist and typically target the root element;
  `classNames.root` / `styles.root` are the explicit semantic equivalents.
- Old props (`bodyStyle`, `dropdownClassName`, `overlayStyle`, …) are deprecated/removed —
  migrate to the semantic equivalents listed in section 5.

## 9. Step-by-step upgrade checklist

1. Confirm React `>= 18`; remove `import '@ant-design/v5-patch-for-react-19'` if present.
2. `npm install --save antd@6 @ant-design/icons@6` (upgrade both majors together).
3. Replace removed components: `BackTop`→`FloatButton.BackTop`, `Icon`→`@ant-design/icons`,
   `Button.Group`/`Input.Group`/`Dropdown.Button`→`Space.Compact`, `Tabs.TabPane`/
   `Timeline.Item`/`Breadcrumb.Item`/`Mentions.Option`→`items`/`options`.
4. Migrate renamed props using section 5 (dropdown→popup, direction→orientation,
   position→placement, destroyOnClose→destroyOnHidden, bordered→variant).
5. Migrate styling props to the semantic `classNames` / `styles` API (section 8).
6. Verify target browsers support CSS variables; IE is unsupported.
7. Audit and adjust custom CSS that targets antd internal DOM selectors.
8. Configure overlay mask blur if the new default is unwanted
   (`ConfigProvider modal={{ mask: { blur: false } }}`).
9. Remove `getFieldsValue({ strict: true })` filtering around `Form.List`.
10. Run the app, fix all console deprecation warnings, verify build emits no errors.

> Tip: `<ConfigProvider warning={{ strict: false }}>` aggregates deprecation warnings if
> the console is noisy during a phased migration.

## 10. New components & notable additions in 6.x

Source: https://ant.design/changelog

- **6.0.0** — `Masonry` component; semantic `classNames`/`styles` + ConfigProvider global
  config across all components; CSS variables default; `zeroRuntime` mode;
  `colorBorderDisabled` token; `InputNumber mode="spinner"`; `Input.Search` refactored onto
  `Space.Compact`; `Form.useWatch` supports dynamic name paths.
- **6.1.0** — ConfigProvider global `trigger` config for Tooltip/Popover/Popconfirm;
  Drawer `resizable`; Select multi-field search via `optionFilterProp` array.
- **6.2.0** — QRCode `marginSize`; Tour `keyboard`; Tooltip/Popover/Popconfirm close on
  ESC by default; Form `tel` type validation; Pagination `size`.
- **6.3.0** — Grid `xxxl` (1920px) breakpoint; Switch `indicator`; Modal `focusable.trap`;
  Drawer `focusable`; Drawer/Modal mask `closable` object option.
- **6.4.0** — `BorderBeam` animated-border component; DatePicker `tagRender` for
  year/month/quarter/week multiple mode; Alert `variant`; Image `placeholder.progress`.
