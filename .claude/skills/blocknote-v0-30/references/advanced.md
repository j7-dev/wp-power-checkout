# BlockNote Advanced Features Reference

> Collaboration, extensions, server-side processing, localization, and format interoperability.

## Table of Contents

- [Real-time Collaboration (Yjs)](#real-time-collaboration)
- [Yjs Utilities](#yjs-utilities)
- [Server-side Processing](#server-side-processing)
- [Extensions System](#extensions-system)
- [Localization (i18n)](#localization)
- [Format Interoperability](#format-interoperability)
- [Paste Handling](#paste-handling)

---

## Real-time Collaboration

BlockNote uses Yjs (CRDT library) for multiplayer editing.

### Configuration

```typescript
import * as Y from "yjs";
import { WebrtcProvider } from "y-webrtc";
import { useCreateBlockNote } from "@blocknote/react";

const doc = new Y.Doc();
const provider = new WebrtcProvider("my-document-id", doc);

const editor = useCreateBlockNote({
  collaboration: {
    provider,                              // Required: Yjs provider
    fragment: doc.getXmlFragment("document-store"), // Required: XML fragment in Y.Doc
    user: {
      name: "Username",                   // Required: display name
      color: "#ff0000",                   // Required: cursor color
    },
    showCursorLabels: "activity",          // "activity" | "always"
  },
});
```

When `collaboration` is set, `initialContent` is ignored -- the document loads from the Yjs provider.

### Provider Options

**Hosted/Managed:**

| Provider | Transport | Features |
|----------|-----------|----------|
| Liveblocks | WebSocket | Persistence, webhooks, REST API, DevTools |
| PartyKit | Cloudflare | Serverless, zero-config |
| Y-Sweet | WebSocket | Open-source, managed on Jamsocket or self-hosted |

**Self-Hosted:**

| Provider | Transport | Notes |
|----------|-----------|-------|
| Hocuspocus | Node.js WebSocket | Extensible, pluggable storage |
| y-websocket | WebSocket | Custom server |
| y-indexeddb | Local IndexedDB | Offline persistence |
| y-webrtc | WebRTC / BroadcastChannel | P2P, no server needed |

### PartyKit Quick Start

```typescript
import YPartyKitProvider from "y-partykit/provider";

const doc = new Y.Doc();
const provider = new YPartyKitProvider(
  "blocknote-dev.yousefed.partykit.dev",
  "your-project-name",
  doc
);
```

### Liveblocks Quick Start

```bash
npx create-liveblocks-app@latest --example nextjs-blocknote --api-key
```

---

## Yjs Utilities

For advanced programmatic Yjs operations:

```typescript
import {
  blocksToYDoc,
  blocksToYXmlFragment,
  yDocToBlocks,
  yXmlFragmentToBlocks,
} from "@blocknote/core/yjs";
```

### blocksToYDoc

Converts blocks to a Y.Doc for collaboration initialization.

```typescript
const ydoc = blocksToYDoc(editor, blocks, xmlFragment?: string);
// xmlFragment defaults to "prosemirror"
```

### blocksToYXmlFragment

```typescript
const fragment = blocksToYXmlFragment(editor, blocks, xmlFragment?: Y.XmlFragment);
```

### yDocToBlocks

```typescript
const blocks = yDocToBlocks(editor, ydoc, xmlFragment?: string);
```

### yXmlFragmentToBlocks

```typescript
const blocks = yXmlFragmentToBlocks(editor, xmlFragment);
```

All functions support round-trip conversion (blocks -> Yjs -> blocks) without data loss.

---

## Server-side Processing

Use `@blocknote/server-util` for backend document processing.

```bash
npm install @blocknote/server-util
```

### ServerBlockNoteEditor

```typescript
import { ServerBlockNoteEditor } from "@blocknote/server-util";

// Create with same options as client-side editor
const editor = ServerBlockNoteEditor.create({
  // Optional: pass custom schema for custom blocks
  schema: myCustomSchema,
});
```

### Available Methods

```typescript
// HTML conversion
const fullHTML = await editor.blocksToFullHTML(blocks);
const lossyHTML = await editor.blocksToHTMLLossy(blocks);
const blocksFromHTML = editor.tryParseHTMLToBlocks(htmlString);

// Markdown conversion
const markdown = await editor.blocksToMarkdownLossy(blocks);
const blocksFromMD = editor.tryParseMarkdownToBlocks(mdString);

// Yjs conversion
const ydoc = editor.blocksToYDoc(blocks);
const blocks = editor.yDocToBlocks(ydoc);
const fragment = editor.blocksToYXmlFragment(blocks);
const blocks = editor.yXmlFragmentToBlocks(fragment);
```

### React Context Support

When custom blocks use React context:

```typescript
const html = await editor.withReactContext(
  ({ children }) => (
    <YourContext.Provider value={true}>{children}</YourContext.Provider>
  ),
  async () => editor.blocksToFullHTML(blocks)
);
```

---

## Extensions System

Extend the editor with keyboard shortcuts, input rules, ProseMirror plugins, or TipTap extensions.

### Extension Type

```typescript
type Extension = {
  key: string;
  keyboardShortcuts?: Record<
    string,
    (ctx: { editor: BlockNoteEditor }) => boolean
  >;
  inputRules?: {
    find: RegExp;
    replace: (props: {
      match: RegExpMatchArray;
      range: { from: number; to: number };
      editor: BlockNoteEditor;
    }) => PartialBlock | undefined;
  }[];
  plugins?: Plugin[];            // ProseMirror plugins
  tiptapExtensions?: AnyExtension[];  // TipTap extensions
};
```

### Creating an Extension

```typescript
import { createExtension } from "@blocknote/core";

const moveBlockShortcuts = createExtension({
  key: "move-blocks",
  keyboardShortcuts: {
    "Meta+Shift+ArrowDown": ({ editor }) => {
      editor.moveBlocksDown();
      return true;
    },
    "Meta+Shift+ArrowUp": ({ editor }) => {
      editor.moveBlocksUp();
      return true;
    },
  },
});

const editor = useCreateBlockNote({
  extensions: [moveBlockShortcuts],
});
```

### Input Rules

```typescript
const hrRule = createExtension({
  key: "horizontal-rule",
  inputRules: [
    {
      find: /^---$/,
      replace: ({ editor }) => ({
        type: "divider",
      }),
    },
  ],
});
```

### Attaching Extensions to Custom Blocks

Pass extensions as the third argument to `createReactBlockSpec`:

```typescript
const MyBlock = createReactBlockSpec(
  blockConfig,
  blockImplementation,
  [myExtension]  // Block-specific extensions
);
```

---

## Localization

### Built-in Locales

24 languages supported: ar, de, en, es, fr, he, hr, is, it, ja, ko, nl, no, pl, pt, ru, sk, uk, uz, vi, zh-CN, zh-TW.

### Using a Locale

```typescript
import { fr } from "@blocknote/core/locales";

const editor = useCreateBlockNote({
  dictionary: fr,
});
```

### Partial Override

```typescript
import { en } from "@blocknote/core/locales";

const editor = useCreateBlockNote({
  dictionary: {
    ...en,
    placeholders: {
      ...en.placeholders,
      default: "Start typing here...",
    },
  },
});
```

### Dictionary Structure

The dictionary object contains:

- **placeholders**: Empty block placeholder text
- **slash_menu**: Slash menu item titles, subtexts, aliases
- **side_menu**: Side menu labels
- **table_handle**: Table manipulation labels
- **color_picker**: Color picker labels

### Dynamic Language Switching

```typescript
import * as locales from "@blocknote/core/locales";

function Editor({ lang }: { lang: string }) {
  const dictionary = locales[lang] || locales.en;
  const editor = useCreateBlockNote({ dictionary }, [lang]);
  return <BlockNoteView editor={editor} />;
}
```

### Integration with i18n Libraries

Works with `react-i18next`, `next-intl`, etc. Read the current language from the i18n context and select the matching locale dictionary.

---

## Format Interoperability

### Supported Formats

| Format | Import | Export | Lossless |
|--------|--------|--------|----------|
| BlockNote JSON | Yes | Yes | Yes |
| BlockNote HTML (Full) | Yes | Yes | Yes |
| Standard HTML (Lossy) | Yes | Yes | No |
| Markdown (Lossy) | Yes | Yes | No |
| PDF | No | Yes (Pro) | N/A |
| DOCX | No | Yes (Pro) | N/A |
| ODT | No | Yes (Pro) | N/A |
| Email | No | Yes (Pro) | N/A |

### Storage Recommendation

Always store as BlockNote JSON for zero data loss:

```typescript
// Save
const content = JSON.stringify(editor.document);

// Load
const editor = useCreateBlockNote({
  initialContent: JSON.parse(savedContent),
});
```

### Lossy Conversion Caveats

**HTML (Lossy):**
- Nesting information is simplified
- Some block-specific props may be lost
- Custom blocks fall back to paragraph

**Markdown:**
- Children of non-list-item blocks are un-nested
- Some text styles (underline, text color, background color) are removed
- Tables may lose advanced features
- Custom blocks/inline content not supported

---

## Paste Handling

### Default Paste Order

1. VS Code compatible content
2. Files
3. BlockNote HTML
4. Markdown
5. HTML
6. Plain text

### Custom Paste Handler

```typescript
type PasteHandler = (context: {
  event: ClipboardEvent;
  editor: BlockNoteEditor;
  defaultPasteHandler: (context?: {
    prioritizeMarkdownOverHTML?: boolean;
    plainTextAsMarkdown?: boolean;
  }) => boolean;
}) => boolean;

const editor = useCreateBlockNote({
  pasteHandler: ({ event, editor, defaultPasteHandler }) => {
    const clipboard = event.clipboardData;

    // Handle custom format
    if (clipboard?.types.includes("text/my-custom-format")) {
      const data = clipboard.getData("text/my-custom-format");
      const markdown = convertToMarkdown(data);
      editor.pasteMarkdown(markdown);
      return true; // handled
    }

    // Prefer Markdown over HTML
    return defaultPasteHandler({ prioritizeMarkdownOverHTML: true });
  },
});
```

### Paste API Methods

```typescript
editor.pasteHTML(html: string, raw?: boolean): void
editor.pasteText(text: string): void
editor.pasteMarkdown(markdown: string): void
```

Return `true` from handler if paste was handled, `false` to cancel.
