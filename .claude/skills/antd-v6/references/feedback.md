# antd v6 — Feedback Components

> Modal, Drawer, notification, message, Popconfirm.
> Source: https://ant.design/components/{modal,drawer,notification}.md

## Table of Contents
- [Modal](#modal)
- [Modal.useModal hook & static methods](#modalusemodal-hook--static-methods)
- [Drawer](#drawer)
- [notification](#notification)
- [message](#message)
- [Popconfirm (v6 notes)](#popconfirm-v6-notes)

---

## Modal

```tsx
import { Modal } from 'antd';
import type { ModalProps } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `open` | boolean | `false` | controlled visibility |
| `title` | ReactNode | — | |
| `footer` | `ReactNode \| (originNode, extra) => ReactNode \| null` | OK/Cancel | `null` hides footer |
| `onOk` | `(e) => void` | — | |
| `onCancel` | `(e) => void` | — | |
| `width` | `string \| number \| Breakpoint` | `520` | **v6**: responsive breakpoints supported |
| `centered` | boolean | `false` | |
| `closable` | `boolean \| { closeIcon?; disabled?; 'aria-label'? }` | `true` | |
| `confirmLoading` | boolean | `false` | OK button loading |
| `okText` / `cancelText` | ReactNode | — | |
| `okButtonProps` / `cancelButtonProps` | `ButtonProps` | — | |
| `okType` | ButtonType | `'primary'` | |
| `destroyOnHidden` | boolean | `false` | **v6** — replaces `destroyOnClose` |
| `forceRender` | boolean | `false` | |
| `keyboard` | boolean | `true` | ESC to close |
| `zIndex` | number | `1000` | |
| `getContainer` | `HTMLElement \| (() => HTMLElement) \| string \| false` | `body` | |
| `mask` | `boolean \| { enabled?; blur?; closable? }` | `true` | **v6**: object form; blur on by default |
| `focusable` | `{ trap?; focusTriggerAfterClose? }` | — | **v6** (6.3.0+ `trap`) |
| `loading` | boolean | `false` | skeleton placeholder |
| `classNames` / `styles` | object / fn | — | keys: `mask`, `wrapper`/`container`, `header`, `title`, `body`, `footer`, `content` |
| `afterClose` | `() => void` | — | |
| `afterOpenChange` | `(open) => void` | — | |
| `modalRender` | `(node) => ReactNode` | — | |

> **v6 removed**: `maskClosable` → `mask.closable`. `focusTriggerAfterClose` →
> `focusable.focusTriggerAfterClose`. `bodyStyle`/`maskStyle` → `styles.body`/`styles.mask`.

```tsx
<Modal
  title="Styled Modal"
  open={open}
  onOk={handleOk}
  onCancel={() => setOpen(false)}
  destroyOnHidden
  mask={{ blur: true, closable: true }}
  styles={{ body: { padding: 24 }, footer: { background: '#fafafa' } }}
  focusable={{ trap: true }}
>
  Content
</Modal>
```

## Modal.useModal hook & static methods

Prefer the hook — static methods run outside the React tree and lose `ConfigProvider`
context (theme, locale).

```tsx
const [modal, contextHolder] = Modal.useModal();
// render {contextHolder} in JSX
const confirmed = await modal.confirm({
  title: 'Delete this item?',
  content: 'This cannot be undone.',
  okType: 'danger',
});                                  // resolves boolean

const instance = modal.info({ title: '...', content: '...' });
instance.update({ content: 'updated' });
instance.destroy();
```

Hook & static both expose: `confirm`, `info`, `success`, `error`, `warning`.
Static-only: `Modal.destroyAll()`.

Confirm-dialog config: `{ title, content, icon, okText, cancelText, okType,
okButtonProps, cancelButtonProps, onOk, onCancel, centered, width, mask, closable,
maskClosable→mask.closable, autoFocusButton, zIndex }`. `onOk` may return a Promise to keep
the OK button in loading state.

---

## Drawer

```tsx
import { Drawer } from 'antd';
import type { DrawerProps } from 'antd';
```

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `open` | boolean | `false` | |
| `onClose` | `(e) => void` | — | |
| `title` | ReactNode | — | |
| `footer` | ReactNode | — | |
| `extra` | ReactNode | — | corner actions |
| `size` | `'default' \| 'large' \| number \| string` | `'default'` | **v6** — replaces `width`/`height` (`default`=378px, `large`=736px) |
| `maxSize` | number | — | resizable cap |
| `placement` | `'top' \| 'right' \| 'bottom' \| 'left'` | `'right'` | |
| `resizable` | `boolean \| ResizableConfig` | `false` | **v6 new** |
| `closable` | `boolean \| { closeIcon?; disabled?; placement? }` | `true` | `placement: 'start' \| 'end'` |
| `mask` | `boolean \| { enabled?; blur?; closable? }` | `true` | **v6**: object form; blur on by default |
| `destroyOnHidden` | boolean | `false` | **v6** — replaces `destroyOnClose` |
| `forceRender` | boolean | `false` | |
| `loading` | boolean | `false` | skeleton |
| `keyboard` | boolean | `true` | ESC to close |
| `focusable` | `{ trap?; focusTriggerAfterClose? }` | — | **v6 new** (6.3.0+) |
| `getContainer` | `HTMLElement \| (() => HTMLElement) \| string \| false` | `body` | |
| `push` | `boolean \| { distance }` | `{ distance: 180 }` | nested-drawer push |
| `zIndex` | number | `1000` | |
| `rootStyle` | `CSSProperties` | — | wrapper style |
| `classNames` / `styles` | object / fn | — | keys: `root`, `header`, `body`, `footer`, `wrapper`, `mask`, `section` |
| `afterOpenChange` | `(open) => void` | — | |
| `drawerRender` | `(node) => ReactNode` | — | |

`ResizableConfig`: `{ onResizeStart?, onResize?, onResizeEnd? }`.

> **v6 removed**: `bodyStyle`/`headerStyle`/`footerStyle`/`contentWrapperStyle`/
> `maskStyle`/`drawerStyle` → `styles.*`. `width`/`height` → `size`.

```tsx
const [size, setSize] = useState(378);
<Drawer
  open={open}
  onClose={() => setOpen(false)}
  size={size}
  resizable={{ onResize: setSize }}
  mask={{ blur: true }}
  styles={{ header: { background: '#fafafa' }, body: { padding: 24 } }}
>
  Content
</Drawer>
```

---

## notification

```tsx
import { notification } from 'antd';
```

Prefer the hook form (`useNotification`) — static methods lose `ConfigProvider` context.

```tsx
const [api, contextHolder] = notification.useNotification(globalConfig?);
// render {contextHolder}
api.success({ title: 'Saved', description: 'Changes persisted.' });
```

### Instance config (`api.open` / `api.success` / `error` / `info` / `warning`)

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| `title` | ReactNode | — | **v6** — replaces `message` |
| `description` | ReactNode | — | |
| `duration` | `number \| false \| null` | `4.5` | seconds; `0`/`null`/`false` = never auto-close |
| `placement` | `'top'\|'topLeft'\|'topRight'\|'bottom'\|'bottomLeft'\|'bottomRight'` | `'topRight'` | |
| `icon` | ReactNode | — | |
| `key` | string | — | reuse to update an open notification |
| `actions` | ReactNode | — | **v6** — replaces `btn` |
| `closeIcon` | ReactNode | `true` | `null`/`false` hides |
| `showProgress` | boolean | — | auto-close progress bar |
| `pauseOnHover` | boolean | `true` | |
| `role` | `'alert' \| 'status'` | `'alert'` | |
| `className` / `style` | — | — | |
| `classNames` / `styles` | `Record<string, ...>` | — | semantic DOM |
| `onClick` / `onClose` | `() => void` | — | |
| `props` | object | — | `data-*` / `aria-*` passthrough |

### Global config (`useNotification(config)` or `notification.config(config)`)

`placement`, `top`, `bottom` (px, default 24), `stack` (`boolean | { threshold }`, default
`{ threshold: 3 }`), `maxCount`, `closeIcon`, `showProgress`, `pauseOnHover`, `rtl`,
`duration`, `getContainer`.

```tsx
const key = 'job';
api.open({ key, title: 'Running…', description: 'Please wait' });
setTimeout(() => api.open({ key, title: 'Done', description: 'Finished' }), 1500);

api.destroy();        // close all
api.destroy(key);     // close one
```

---

## message

Lightweight transient toast. Same hook pattern.

```tsx
import { message } from 'antd';

const [messageApi, contextHolder] = message.useMessage();
// render {contextHolder}
messageApi.success('Saved');
messageApi.error('Failed');
messageApi.loading('Processing…', 0);   // duration 0 = persists; returns a hide() fn
messageApi.open({ type: 'info', content: 'Hello', duration: 3, key: 'k' });
```

Methods: `success`, `error`, `info`, `warning`, `loading`, `open`, `destroy`.
Config object: `{ content, type, duration (default 3s), icon, key, className, style,
onClick, onClose }`. Global config via `message.config({ top, duration, maxCount,
rtl, prefixCls, getContainer })`.

---

## Popconfirm (v6 notes)

Confirmation bubble anchored to a trigger element.

| Prop | Type | Default | Notes |
|------|------|---------|-------|
| `title` | ReactNode | — | |
| `description` | ReactNode | — | |
| `open` | boolean | — | controlled |
| `onConfirm` / `onCancel` | `(e) => void` | — | |
| `okText` / `cancelText` | ReactNode | — | |
| `okType` | ButtonType | `'primary'` | |
| `okButtonProps` / `cancelButtonProps` | `ButtonProps` | — | |
| `trigger` | `'hover'\|'click'\|'focus'\|'contextMenu'` | `'click'` | |
| `placement` | placement union | `'top'` | **v6**: `xxxCenter` collapsed to `xxx` |
| `icon` | ReactNode | — | |
| `disabled` | boolean | `false` | |
| `onOpenChange` | `(open) => void` | — | |

- Closes on **ESC by default** (6.2.0+).
- Trigger configurable globally via `ConfigProvider popconfirm={{ trigger }}` (6.1.0+).

```tsx
<Popconfirm
  title="Delete this record?"
  description="This action is irreversible."
  okType="danger"
  onConfirm={() => doDelete()}
>
  <Button danger>Delete</Button>
</Popconfirm>
```
