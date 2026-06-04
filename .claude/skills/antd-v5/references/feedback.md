# Feedback Components

## Table of Contents
- [Modal](#modal)
- [notification](#notification)
- [message](#message)
- [Popconfirm](#popconfirm)
- [Drawer](#drawer)
- [Alert](#alert)
- [Progress](#progress)
- [Result](#result)
- [Skeleton](#skeleton)
- [Watermark](#watermark)

---

## Modal

```tsx
import { Modal } from 'antd';
import type { ModalProps, ModalFuncProps } from 'antd';
```

### Modal Props

| Prop | Type | Default |
|------|------|---------|
| `afterClose` | `() => void` | - |
| `cancelButtonProps` | `ButtonProps` | - |
| `cancelText` | `ReactNode` | `'Cancel'` |
| `centered` | `boolean` | `false` |
| `closable` | `boolean \| { closeIcon?: ReactNode, disabled?: boolean }` | `true` |
| `closeIcon` | `ReactNode` | `<CloseOutlined />` |
| `confirmLoading` | `boolean` | `false` |
| `destroyOnClose` | `boolean` | `false` |
| `focusTriggerAfterClose` | `boolean` | `true` |
| `footer` | `ReactNode \| ((originNode, extra) => ReactNode)` | OK+Cancel buttons |
| `forceRender` | `boolean` | `false` |
| `getContainer` | `HTMLElement \| (() => HTMLElement) \| string \| false` | `document.body` |
| `keyboard` | `boolean` | `true` |
| `mask` | `boolean \| { enabled?, blur?, closable? }` | `true` |
| `maskClosable` | `boolean` | `true` |
| `modalRender` | `(node: ReactNode) => ReactNode` | - |
| `okButtonProps` | `ButtonProps` | - |
| `okText` | `ReactNode` | `'OK'` |
| `okType` | `string` | `'primary'` |
| `open` | `boolean` | `false` |
| `title` | `ReactNode` | - |
| `width` | `string \| number` | `520` |
| `wrapClassName` | `string` | - |
| `zIndex` | `number` | `1000` |
| `loading` | `boolean` | - (v5.18.0+, shows skeleton) |
| `onCancel` | `(e) => void` | - |
| `onOk` | `(e) => void` | - |
| `afterOpenChange` | `(open: boolean) => void` | - (v5.4.0+) |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

**Semantic DOM keys:** `root`, `mask`, `wrapper`, `header`, `title`, `body`, `footer`

### Modal.method() - Static Confirmation Methods

```tsx
Modal.confirm(config)  // Confirm dialog with OK/Cancel
Modal.info(config)     // Info dialog
Modal.success(config)  // Success dialog
Modal.error(config)    // Error dialog
Modal.warning(config)  // Warning dialog
```

Returns a reference with methods:
```tsx
const modal = Modal.confirm({ ... });
modal.update(config);             // Update props
modal.update(prev => ({ ... }));  // Functional update (v4.8.0+)
modal.destroy();                  // Close and destroy
```

### ModalFuncProps (config for static methods)

| Prop | Type | Default |
|------|------|---------|
| `autoFocusButton` | `null \| 'ok' \| 'cancel'` | `'ok'` |
| `cancelButtonProps` | `ButtonProps` | - |
| `cancelText` | `string` | `'Cancel'` |
| `centered` | `boolean` | `false` |
| `className` | `string` | - |
| `closable` | `boolean \| { closeIcon?, disabled? }` | `false` |
| `closeIcon` | `ReactNode` | - |
| `content` | `ReactNode` | - |
| `footer` | `ReactNode \| ((originNode, extra) => ReactNode)` | - |
| `getContainer` | `HTMLElement \| (() => HTMLElement) \| string \| false` | `document.body` |
| `icon` | `ReactNode` | `<ExclamationCircleFilled />` |
| `keyboard` | `boolean` | `true` |
| `mask` | `boolean \| { enabled?, blur? }` | `true` |
| `maskClosable` | `boolean` | `false` |
| `okButtonProps` | `ButtonProps` | - |
| `okText` | `string` | `'OK'` |
| `okType` | `string` | `'primary'` |
| `title` | `ReactNode` | - |
| `width` | `string \| number` | `416` |
| `zIndex` | `number` | `1000` |
| `onCancel` | `(close: () => void) => void` | - |
| `onOk` | `(close: () => void) => void` | - |

**Note:** `onOk`/`onCancel` receive a `close` function. Returning a Promise enables delayed closing with loading state.

### Modal.useModal() Hook (Preferred)

```tsx
const [modal, contextHolder] = Modal.useModal();
// Place {contextHolder} in JSX tree

modal.confirm(config);   // Returns instance
modal.info(config);
modal.success(config);
modal.error(config);
modal.warning(config);

// Await pattern (v5.4.0+)
const confirmed: boolean = await modal.confirm({ title: 'Sure?' });
```

Hook modals inherit React context (ConfigProvider, theme, locale).

### Modal.destroyAll()

```tsx
Modal.destroyAll(); // Destroys all open confirmation modals
```

---

## notification

```tsx
import { notification } from 'antd';
```

### Static Methods

```tsx
notification.success(config)
notification.error(config)
notification.info(config)
notification.warning(config)
notification.open(config)
notification.destroy(key?: string)
```

### Config Properties

| Prop | Type | Default |
|------|------|---------|
| `actions` | `ReactNode` | - (v5.24.0+, replaces `btn`) |
| `btn` | `ReactNode` | - (deprecated, use `actions`) |
| `className` | `string` | - |
| `closeIcon` | `ReactNode` | `true` (null/false hides) |
| `description` | `ReactNode` | - (required) |
| `duration` | `number \| false` | `4.5` (seconds, 0 = no auto-close) |
| `showProgress` | `boolean` | - (v5.18.0+) |
| `pauseOnHover` | `boolean` | `true` (v5.18.0+) |
| `icon` | `ReactNode` | - |
| `key` | `string` | - |
| `message` | `ReactNode` | - (deprecated, use `title`) |
| `title` | `ReactNode` | - |
| `placement` | `'top' \| 'topLeft' \| 'topRight' \| 'bottom' \| 'bottomLeft' \| 'bottomRight'` | `'topRight'` |
| `role` | `'alert' \| 'status'` | `'alert'` (v5.6.0+) |
| `style` | `CSSProperties` | - |
| `onClick` | `() => void` | - |
| `onClose` | `() => void` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### notification.useNotification() Hook (Preferred)

```tsx
const [api, contextHolder] = notification.useNotification();
// Place {contextHolder} in JSX tree

// Hook config options (passed to useNotification)
{
  bottom?: number;          // default: 24
  closeIcon?: ReactNode;
  getContainer?: () => HTMLElement;  // default: document.body
  placement?: string;       // default: 'topRight'
  showProgress?: boolean;
  pauseOnHover?: boolean;   // default: true
  rtl?: boolean;            // default: false
  stack?: boolean | { threshold: number };  // default: { threshold: 3 }
  top?: number;             // default: 24
  maxCount?: number;
}
```

### notification.config() - Global Config

```tsx
notification.config({
  placement: 'bottomRight',
  bottom: 50,
  duration: 3,
  rtl: true,
});
```

Same options as useNotification config.

---

## message

```tsx
import { message } from 'antd';
```

### Static Methods

```tsx
// Shorthand
message.success(content, duration?, onClose?)
message.error(content, duration?, onClose?)
message.info(content, duration?, onClose?)
message.warning(content, duration?, onClose?)
message.loading(content, duration?, onClose?)

// Config object
message.success(config)
message.error(config)
message.info(config)
message.warning(config)
message.loading(config)

// Destroy
message.destroy()
message.destroy(key)
```

### ArgsProps (Config Object)

| Prop | Type | Default |
|------|------|---------|
| `content` | `ReactNode` | - |
| `duration` | `number` | `3` (seconds, 0 = no auto-dismiss) |
| `className` | `string` | - |
| `icon` | `ReactNode` | - |
| `key` | `string \| number` | - |
| `style` | `CSSProperties` | - |
| `onClick` | `() => void` | - |
| `onClose` | `() => void` | - |
| `pauseOnHover` | `boolean` | `true` |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

### Promise Interface

```tsx
message.success('Done').then(afterClose => { ... });
```

### message.useMessage() Hook (Preferred)

```tsx
const [messageApi, contextHolder] = message.useMessage();
// Place {contextHolder} in JSX tree

messageApi.success('Done');
messageApi.error('Failed');
messageApi.loading('Processing...');
```

### message.config() - Global Config

```tsx
message.config({
  top: 100,          // default: 8
  duration: 2,       // default: 3
  maxCount: 3,
  rtl: false,
  getContainer: () => document.getElementById('msg-container'),
});
```

---

## Popconfirm

```tsx
import { Popconfirm } from 'antd';
```

### Popconfirm Props

| Prop | Type | Default |
|------|------|---------|
| `title` | `ReactNode \| (() => ReactNode)` | - |
| `description` | `ReactNode \| (() => ReactNode)` | - (v5.1.0+) |
| `okText` | `string` | `'OK'` |
| `cancelText` | `string` | `'Cancel'` |
| `okType` | `string` | `'primary'` |
| `okButtonProps` | `ButtonProps` | - |
| `cancelButtonProps` | `ButtonProps` | - |
| `showCancel` | `boolean` | `true` |
| `icon` | `ReactNode` | `<ExclamationCircleFilled />` |
| `disabled` | `boolean` | `false` |
| `onConfirm` | `(e) => void` | - |
| `onCancel` | `(e) => void` | - |
| `onPopupClick` | `(e) => void` | - (v5.5.0+) |

### Shared Tooltip Props (also available on Popconfirm)

| Prop | Type | Default |
|------|------|---------|
| `placement` | `string` (12 positions) | `'top'` |
| `trigger` | `'hover' \| 'focus' \| 'click' \| 'contextMenu' \| Array` | `'hover'` |
| `open` | `boolean` | - |
| `defaultOpen` | `boolean` | `false` |
| `onOpenChange` | `(open: boolean) => void` | - |
| `arrow` | `boolean \| { pointAtCenter }` | `true` |
| `mouseEnterDelay` | `number` | `0.1` |
| `mouseLeaveDelay` | `number` | `0.1` |
| `autoAdjustOverflow` | `boolean` | `true` |
| `getPopupContainer` | `(triggerNode) => HTMLElement` | `document.body` |
| `destroyTooltipOnHide` | `boolean` | `false` |
| `fresh` | `boolean` | `false` (v5.10.0+) |
| `zIndex` | `number` | - |

---

## Drawer

```tsx
import { Drawer } from 'antd';
import type { DrawerProps } from 'antd';
```

### Drawer Props

| Prop | Type | Default |
|------|------|---------|
| `open` | `boolean` | `false` |
| `onClose` | `(e) => void` | - |
| `afterOpenChange` | `(open: boolean) => void` | - |
| `placement` | `'top' \| 'right' \| 'bottom' \| 'left'` | `'right'` |
| `width` | `string \| number` | `378` |
| `height` | `string \| number` | `378` (top/bottom) |
| `size` | `'default' \| 'large' \| number \| string` | `'default'` (378px / 736px) |
| `title` | `ReactNode` | - |
| `footer` | `ReactNode` | - |
| `extra` | `ReactNode` | - |
| `closable` | `boolean \| { closeIcon?, disabled?, placement?: 'start' \| 'end' }` | `true` |
| `maskClosable` | `boolean` | `true` |
| `keyboard` | `boolean` | `true` |
| `destroyOnClose` | `boolean` | `false` |
| `forceRender` | `boolean` | `false` |
| `mask` | `boolean \| { enabled?, blur?, closable? }` | `true` |
| `zIndex` | `number` | `1000` |
| `loading` | `boolean` | `false` (v5.18.0+) |
| `getContainer` | `HTMLElement \| (() => HTMLElement) \| string \| false` | `document.body` |
| `push` | `boolean \| { distance: string \| number }` | `{ distance: 180 }` |
| `drawerRender` | `(node: ReactNode) => ReactNode` | - (v5.18.0+) |
| `className` | `string` | - |
| `rootClassName` | `string` | - |
| `style` | `CSSProperties` | - |
| `rootStyle` | `CSSProperties` | - |
| `classNames` | `Record<SemanticDOM, string>` | - |
| `styles` | `Record<SemanticDOM, CSSProperties>` | - |

**Semantic DOM keys:** `root`, `mask`, `section`, `header`, `title`, `extra`, `body`, `footer`, `close`

**Deprecated prop mappings:**
- `bodyStyle` -> `styles.body`
- `headerStyle` -> `styles.header`
- `footerStyle` -> `styles.footer`
- `maskStyle` -> `styles.mask`
- `contentWrapperStyle` -> `styles.wrapper`

---

## Alert

警告提示。

```tsx
import { Alert } from 'antd';
```

### Alert Props

| Prop | Type | Default |
|------|------|---------|
| `message` | `ReactNode` | - (內容) |
| `description` | `ReactNode` | - (額外內容) |
| `type` | `'success' \| 'info' \| 'warning' \| 'error'` | `'info'` (banner 模式預設 `'warning'`) |
| `showIcon` | `boolean` | `false` (banner 模式預設 `true`) |
| `icon` | `ReactNode` | - (showIcon 為 true 時生效) |
| `banner` | `boolean` | `false` (橫幅樣式) |
| `closable` | `boolean \| ({ closeIcon?: ReactNode } & React.AriaAttributes)` | `false` (aria-*: v5.15.0+) |
| `action` | `ReactNode` | - (v4.9.0+) |
| `afterClose` | `() => void` | - (關閉動畫完成時) |
| `onClose` | `(e: MouseEvent) => void` | - |

### Alert.ErrorBoundary

捕捉子元件錯誤並以 Alert 顯示：

| Prop | Type | Default |
|------|------|---------|
| `message` | `ReactNode` | `{{ error }}` |
| `description` | `ReactNode` | `{{ error stack }}` |

### Design Tokens

`defaultPadding`, `withDescriptionPadding`, `withDescriptionIconSize` (24)

---

## Progress

進度條。

```tsx
import { Progress } from 'antd';
```

### Progress Props（通用）

| Prop | Type | Default |
|------|------|---------|
| `type` | `'line' \| 'circle' \| 'dashboard'` | `'line'` |
| `percent` | `number` | `0` |
| `format` | `(percent, successPercent) => ReactNode` | `(percent) => percent + '%'` |
| `showInfo` | `boolean` | `true` (顯示數值與狀態圖示) |
| `status` | `'success' \| 'exception' \| 'normal' \| 'active'` | - (active 僅 line) |
| `strokeColor` | `string` | - |
| `strokeLinecap` | `'round' \| 'butt' \| 'square'` | `'round'` |
| `success` | `{ percent: number, strokeColor: string }` | - |
| `trailColor` | `string` | - (未填充部分顏色) |
| `size` | `number \| [number \| string, number] \| { width, height } \| 'small' \| 'default'` | `'default'` (v5.3.0+，object: v5.18.0+) |

### Line 額外 Props（type='line'）

| Prop | Type | Default |
|------|------|---------|
| `steps` | `number` | - (總步驟數) |
| `rounding` | `(step: number) => number` | `Math.round` (v5.24.0+) |
| `strokeColor` | `string \| string[] \| { from, to, direction }` | - (object 渲染漸層；string[]: v4.21.0+) |
| `percentPosition` | `{ align: string; type: string }` | `{ align: 'end', type: 'outer' }` (v5.18.0+) |

### Circle 額外 Props（type='circle'）

| Prop | Type | Default |
|------|------|---------|
| `steps` | `number \| { count, gap }` | - (v5.16.0+) |
| `strokeColor` | `string \| { [percent: string]: string }` | - (object 渲染漸層) |
| `strokeWidth` | `number` | `6` (畫布寬度百分比) |

### Dashboard 額外 Props（type='dashboard'）

| Prop | Type | Default |
|------|------|---------|
| `steps` | `number \| { count, gap }` | - (v5.16.0+) |
| `gapDegree` | `number` | `75` (半圓缺口角度，0~295) |
| `gapPosition` | `'top' \| 'bottom' \| 'left' \| 'right'` | `'bottom'` |
| `strokeWidth` | `number` | `6` |

### Design Tokens

`defaultColor` (#1677ff), `remainingColor`, `lineBorderRadius` (100), `circleTextColor`

---

## Result

結果頁。

```tsx
import { Result } from 'antd';
```

### Result Props

| Prop | Type | Default |
|------|------|---------|
| `status` | `'success' \| 'error' \| 'info' \| 'warning' \| '404' \| '403' \| '500'` | `'info'` |
| `title` | `ReactNode` | - |
| `subTitle` | `ReactNode` | - |
| `icon` | `ReactNode` | - (自訂圖示) |
| `extra` | `ReactNode` | - (操作區) |

### Design Tokens

`iconFontSize` (72), `titleFontSize` (24), `subtitleFontSize` (14), `extraMargin`

---

## Skeleton

骨架屏。

```tsx
import { Skeleton } from 'antd';
```

### Skeleton Props

| Prop | Type | Default |
|------|------|---------|
| `loading` | `boolean` | - (為 true 時顯示骨架屏) |
| `active` | `boolean` | `false` (動畫效果) |
| `avatar` | `boolean \| SkeletonAvatarProps` | `false` |
| `paragraph` | `boolean \| SkeletonParagraphProps` | `true` |
| `title` | `boolean \| SkeletonTitleProps` | `true` |
| `round` | `boolean` | `false` (段落與標題圓角) |

### SkeletonAvatarProps

| Prop | Type | Default |
|------|------|---------|
| `active` | `boolean` | `false` (僅獨立使用 avatar 時有效) |
| `shape` | `'circle' \| 'square'` | - |
| `size` | `number \| 'large' \| 'small' \| 'default'` | - |

### SkeletonTitleProps

| Prop | Type |
|------|------|
| `width` | `number \| string` |

### SkeletonParagraphProps

| Prop | Type |
|------|------|
| `rows` | `number` |
| `width` | `number \| string \| Array<number \| string>` (Array 設定各行寬度) |

### 子元件

- `Skeleton.Button` — `{ active?, block?, shape?: 'circle' \| 'round' \| 'square' \| 'default', size? }`（block: v4.17.0+）
- `Skeleton.Avatar` — 同 SkeletonAvatarProps
- `Skeleton.Input` — `{ active?, size? }`
- `Skeleton.Image` — 圖片骨架
- `Skeleton.Node` — 自訂節點骨架

### Design Tokens

`gradientFromColor`, `gradientToColor`, `titleHeight` (16), `blockRadius` (4), `paragraphLiHeight` (16)

---

## Watermark

水印。

```tsx
import { Watermark } from 'antd';
```

### Watermark Props

| Prop | Type | Default |
|------|------|---------|
| `content` | `string \| string[]` | - (文字內容) |
| `image` | `string` | - (圖片來源，建議 2x/3x，支援 base64，優先級高於 content) |
| `width` | `number` | `120` (預設值為自身寬度) |
| `height` | `number` | `64` (預設值為自身高度) |
| `rotate` | `number` | `-22` (旋轉角度 °) |
| `zIndex` | `number` | `9` |
| `gap` | `[number, number]` | `[100, 100]` (水印間距) |
| `offset` | `[number, number]` | `[gap[0]/2, gap[1]/2]` (距容器左上偏移) |
| `font` | `Font` | (見下) |
| `inherit` | `boolean` | `true` (v5.11.0+，傳遞水印到 Modal / Drawer 等彈層) |

### Font

| Prop | Type | Default |
|------|------|---------|
| `color` | `CanvasFillStrokeStyles['fillStyle']` | `rgba(0,0,0,.15)` |
| `fontSize` | `number` | `16` |
| `fontWeight` | `'normal' \| 'light' \| 'weight' \| number` | `'normal'` |
| `fontFamily` | `string` | `'sans-serif'` |
| `fontStyle` | `'none' \| 'normal' \| 'italic' \| 'oblique'` | `'normal'` |
| `textAlign` | `CanvasTextAlign` | `'center'` (v5.10.0+) |
