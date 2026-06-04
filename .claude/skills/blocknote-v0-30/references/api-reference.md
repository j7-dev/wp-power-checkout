# BlockNote API Reference

> Complete type definitions and prop details for all built-in blocks, inline content, styles, editor methods, and core types.

## Table of Contents

- [Default Block Props](#default-block-props)
- [Typography Blocks](#typography-blocks)
- [List Blocks](#list-blocks)
- [Code Block](#code-block)
- [Table Block](#table-block)
- [Embed Blocks](#embed-blocks)
- [Column Blocks](#column-blocks)
- [Inline Content Types](#inline-content-types)
- [Default Styles](#default-styles)
- [Editor Options](#editor-options)
- [Content Manipulation API](#content-manipulation-api)
- [Cursor and Selection API](#cursor-and-selection-api)
- [Inline Content Manipulation](#inline-content-manipulation)
- [Events API](#events-api)
- [Format Conversion API](#format-conversion-api)
- [Paste Handling API](#paste-handling-api)
- [Yjs Utilities](#yjs-utilities)
- [Server-side Processing](#server-side-processing)

---

## Default Block Props

All built-in blocks (except divider, table, and embeds where noted) include:

```typescript
type DefaultProps = {
  backgroundColor: string;     // default: "default"
  textColor: string;           // default: "default"
  textAlignment: "left" | "center" | "right" | "justify"; // default: "left"
};
```

---

## Typography Blocks

### Paragraph

```typescript
type ParagraphBlock = {
  id: string;
  type: "paragraph";
  props: DefaultProps;
  content: InlineContent[];
  children: Block[];
};
```

### Heading

```typescript
type HeadingBlock = {
  id: string;
  type: "heading";
  props: {
    level: 1 | 2 | 3 | 4 | 5 | 6; // default: 1
    isToggleable?: boolean;         // present when allowToggleHeadings enabled
  } & DefaultProps;
  content: InlineContent[];
  children: Block[];
};
```

Heading configuration options (passed via schema):

```typescript
type HeadingBlockOptions = Partial<{
  defaultLevel?: number;           // default: 1
  levels?: number[];               // default: [1, 2, 3, 4, 5, 6]
  allowToggleHeadings?: boolean;   // default: true
}>;
```

### Quote

```typescript
type QuoteBlock = {
  id: string;
  type: "quote";
  props: {
    backgroundColor: string;  // default: "default"
    textColor: string;         // default: "default"
  };
  content: InlineContent[];
  children: Block[];
};
```

### Divider

```typescript
type DividerBlock = {
  id: string;
  type: "divider";
  props: {};
  content: undefined;
  children: Block[];
};
```

Insert by typing `---` on an empty line. No props, no content.

---

## List Blocks

### Bullet List Item

```typescript
type BulletListItemBlock = {
  id: string;
  type: "bulletListItem";
  props: DefaultProps;
  content: InlineContent[];
  children: Block[];
};
```

### Numbered List Item

```typescript
type NumberedListItemBlock = {
  id: string;
  type: "numberedListItem";
  props: DefaultProps & {
    start?: number; // default: 1 or auto-incremented from previous
  };
  content: InlineContent[];
  children: Block[];
};
```

### Check List Item

```typescript
type CheckListItemBlock = {
  id: string;
  type: "checkListItem";
  props: DefaultProps & {
    checked: boolean; // default: false
  };
  content: InlineContent[];
  children: Block[];
};
```

### Toggle List Item

```typescript
type ToggleListItemBlock = {
  id: string;
  type: "toggleListItem";
  props: DefaultProps;
  content: InlineContent[];
  children: Block[];
};
```

---

## Code Block

```typescript
type CodeBlock = {
  id: string;
  type: "codeBlock";
  props: {
    language: string; // default: "text"
  };
  content: InlineContent[];
  children: Block[];
};
```

Configuration options:

```typescript
type CodeBlockOptions = {
  indentLineWithTab?: boolean;   // default: true
  defaultLanguage?: string;      // default: "text"
  supportedLanguages?: Record<string, {
    name: string;
    aliases?: string[];
  }>;
  createHighlighter?: () => Promise<HighlighterGeneric<any, any>>;
};
```

Pre-configured with syntax highlighting:

```typescript
import { codeBlockOptions } from "@blocknote/code-block";
import { createCodeBlockSpec } from "@blocknote/core";

const codeBlock = createCodeBlockSpec(codeBlockOptions);
```

Custom Shiki highlighter generation:

```bash
npx shiki-codegen --langs javascript,typescript,vue --themes light-plus,dark-plus --engine javascript --precompiled ./shiki.bundle.ts
```

---

## Table Block

```typescript
type TableBlock = {
  id: string;
  type: "table";
  props: {
    textColor: string; // default: "default"
  };
  content: TableContent;
  children: Block[];
};

type TableContent = {
  type: "tableContent";
  columnWidths: (number | undefined)[];
  headerRows?: number;
  headerCols?: number;
  rows: {
    cells: TableCell[];
  }[];
};

type TableCell = {
  type: "tableCell";
  props: {
    backgroundColor: string;   // default: "default"
    textColor: string;         // default: "default"
    textAlignment: "left" | "center" | "right" | "justify";
    colspan?: number;
    rowspan?: number;
  };
  content: InlineContent[];
};
```

Table configuration (advanced features disabled by default):

```typescript
const editor = useCreateBlockNote({
  tables: {
    splitCells: true,          // merge/divide cells
    cellBackgroundColor: true, // per-cell background
    cellTextColor: true,       // per-cell text color
    headers: true,             // header rows/columns
  },
});
```

---

## Embed Blocks

### Image Block

```typescript
type ImageBlock = {
  id: string;
  type: "image";
  props: {
    backgroundColor: string;   // default: "default"
    textAlignment: "left" | "center" | "right" | "justify"; // default: "left"
    name: string;              // default: ""
    url: string;               // default: ""
    caption: string;           // default: ""
    showPreview: boolean;      // default: true
    previewWidth: number | undefined; // default: undefined
  };
  content: undefined;
  children: Block[];
};
```

### Video Block

```typescript
type VideoBlock = {
  id: string;
  type: "video";
  props: {
    backgroundColor: string;   // default: "default"
    textAlignment: "left" | "center" | "right" | "justify"; // default: "left"
    name: string;              // default: ""
    url: string;               // default: ""
    caption: string;           // default: ""
    showPreview: boolean;      // default: true
    previewWidth: number | undefined; // default: undefined
  };
  content: undefined;
  children: Block[];
};
```

### Audio Block

```typescript
type AudioBlock = {
  id: string;
  type: "audio";
  props: {
    backgroundColor: string;   // default: "default"
    name: string;              // default: ""
    url: string;               // default: ""
    caption: string;           // default: ""
    showPreview: boolean;      // default: true
  };
  content: undefined;
  children: Block[];
};
```

### File Block

```typescript
type FileBlock = {
  id: string;
  type: "file";
  props: {
    backgroundColor: string;   // default: "default"
    name: string;              // default: ""
    url: string;               // default: ""
    caption: string;           // default: ""
  };
  content: undefined;
  children: Block[];
};
```

---

## Column Blocks

```typescript
type ColumnListBlock = {
  id: string;
  type: "columnList";
  props: {};
  content: undefined;
  children: ColumnBlock[]; // minimum 2 columns
};

type ColumnBlock = {
  id: string;
  type: "column";
  props: { width: number };
  content: undefined;
  children: Block[]; // regular blocks only
};
```

---

## Inline Content Types

```typescript
type InlineContent = StyledText | Link | CustomInlineContent;

type StyledText = {
  type: "text";
  text: string;
  styles: Styles;
};

type Link = {
  type: "link";
  content: StyledText[];
  href: string;
};

type CustomInlineContent = {
  type: string;
  content: StyledText[] | undefined;
  props: Record<string, boolean | number | string>;
};
```

---

## Default Styles

```typescript
type Styles = Partial<{
  bold: boolean;           // default: false
  italic: boolean;         // default: false
  underline: boolean;      // default: false
  strike: boolean;         // default: false
  code: boolean;           // default: false
  textColor: string;       // default: "default"
  backgroundColor: string; // default: "default"
}>;
```

---

## Editor Options

Full `BlockNoteEditorOptions` passed to `useCreateBlockNote`:

| Option | Type | Description |
|--------|------|-------------|
| `initialContent` | `PartialBlock[]` | Starting document content |
| `schema` | `BlockNoteSchema` | Custom block/inline/style specs |
| `uploadFile` | `(file: File) => Promise<string>` | Returns uploaded file URL |
| `resolveFileUrl` | `(url: string) => Promise<string>` | Resolve indirect URLs |
| `pasteHandler` | `PasteHandler` | Custom paste logic |
| `dictionary` | `Dictionary` | i18n locale object |
| `collaboration` | `CollaborationOptions` | Yjs real-time collab config |
| `domAttributes` | `Record<string, Record<string, string>>` | Custom DOM attrs per element |
| `extensions` | `Extension[]` | Custom editor extensions |
| `tables` | `TableOptions` | Advanced table features |

`domAttributes` targets: `editor`, `block`, `blockGroup`, `blockContent`, `inlineContent`.

---

## Content Manipulation API

### Reading Blocks

```typescript
// Full document snapshot
editor.document: Block[]

// Single block by ID or Block object
editor.getBlock(blockIdentifier: BlockIdentifier): Block | undefined

// Navigation
editor.getPrevBlock(blockIdentifier: BlockIdentifier): Block | undefined
editor.getNextBlock(blockIdentifier: BlockIdentifier): Block | undefined
editor.getParentBlock(blockIdentifier: BlockIdentifier): Block | undefined

// Traversal (depth-first)
editor.forEachBlock(
  callback: (block: Block) => boolean, // return false to stop
  reverse?: boolean
): void
```

### Creating Blocks

```typescript
editor.insertBlocks(
  blocksToInsert: PartialBlock[],
  referenceBlock: BlockIdentifier,
  placement?: "before" | "after" // default: "before"
): void
```

### Updating Blocks

```typescript
editor.updateBlock(
  blockToUpdate: BlockIdentifier,
  update: PartialBlock
): void
```

### Removing Blocks

```typescript
editor.removeBlocks(
  blocksToRemove: BlockIdentifier[]
): void
```

### Replacing Blocks

```typescript
editor.replaceBlocks(
  blocksToRemove: BlockIdentifier[],
  blocksToInsert: PartialBlock[]
): void
```

### Moving and Nesting

```typescript
editor.moveBlocksUp(): void
editor.moveBlocksDown(): void
editor.canNestBlock(): boolean
editor.nestBlock(): void
editor.canUnnestBlock(): boolean
editor.unnestBlock(): void
```

### Transactions

```typescript
editor.transact(() => {
  editor.insertBlocks([...], ref);
  editor.replaceBlocks([...], [...]);
}); // grouped as single undo/redo action
```

`BlockIdentifier`: string ID or any object with `id: string`.

`PartialBlock`: All fields optional (`id`, `type`, `props`, `content`, `children`).

---

## Cursor and Selection API

### TextCursorPosition

```typescript
type TextCursorPosition = {
  block: Block;
  prevBlock: Block | undefined;
  nextBlock: Block | undefined;
  parentBlock: Block | undefined;
};

editor.getTextCursorPosition(): TextCursorPosition
editor.setTextCursorPosition(
  target: BlockIdentifier,
  placement?: "start" | "end" // default: "start"
): void
```

### Selection

```typescript
type Selection = {
  blocks: Block[]; // all blocks spanned, including nested
};

editor.getSelection(): Selection | undefined
editor.setSelection(
  startBlock: BlockIdentifier,
  endBlock: BlockIdentifier
): void
```

Both blocks must have content. Throws if blocks don't exist.

---

## Inline Content Manipulation

```typescript
editor.insertInlineContent(
  content: PartialInlineContent,
  options?: { updateSelection?: boolean }
): void

editor.getSelectedText(): string
editor.getActiveStyles(): Styles
editor.getSelectedLinkUrl(): string | undefined

editor.addStyles(styles: Styles): void
editor.removeStyles(styles: Styles): void
editor.toggleStyles(styles: Styles): void

editor.createLink(url: string, text?: string): void
```

---

## Events API

All event handlers return a cleanup function.

### onChange

```typescript
editor.onChange(
  (editor, { getChanges }) => {
    const changes = getChanges();
    // change.type: "insert" | "delete" | "update" | "move"
    // change.source: "local" | "paste" | "drop" | "undo" | "redo" | "undo-redo" | "yjs-remote"
  }
): () => void
```

### onBeforeChange

```typescript
editor.onBeforeChange(
  ({ getChanges, tr }) => {
    return false; // return false to cancel
  }
): () => void
```

### Other Events

```typescript
editor.onSelectionChange((editor) => { ... }): () => void
editor.onMount(() => { ... }): () => void
editor.onUnmount(() => { ... }): () => void
```

### React Hooks for Events

```typescript
import { useEditorChange, useEditorSelectionChange } from "@blocknote/react";

useEditorChange((editor, { getChanges }) => { ... }, editor);
useEditorSelectionChange(() => { ... }, editor);
```

---

## Format Conversion API

### Export

```typescript
// Lossless (BlockNote-native HTML, preserves full structure)
editor.blocksToFullHTML(blocks?: Block[]): string

// Lossy (standard HTML, loses nesting info)
editor.blocksToHTMLLossy(blocks?: Block[]): string

// Lossy (Markdown)
editor.blocksToMarkdownLossy(blocks?: Block[]): string
```

### Import

```typescript
// HTML to Blocks
editor.tryParseHTMLToBlocks(html: string): Block[]

// Markdown to Blocks
editor.tryParseMarkdownToBlocks(markdown: string): Block[]
```

### Paste Methods

```typescript
editor.pasteHTML(html: string, raw?: boolean): void
editor.pasteText(text: string): void
editor.pasteMarkdown(markdown: string): void
```

---

## Paste Handling API

Default paste order: VS Code content -> Files -> BlockNote HTML -> Markdown -> HTML -> Plain text.

```typescript
type PasteHandler = (context: {
  event: ClipboardEvent;
  editor: BlockNoteEditor;
  defaultPasteHandler: (context?: {
    prioritizeMarkdownOverHTML?: boolean;
    plainTextAsMarkdown?: boolean;
  }) => boolean;
}) => boolean; // return true if handled, false to cancel

const editor = useCreateBlockNote({
  pasteHandler: ({ event, editor, defaultPasteHandler }) => {
    if (event.clipboardData?.types.includes("text/my-custom-format")) {
      const markdown = customToMarkdown(
        event.clipboardData.getData("text/my-custom-format")
      );
      editor.pasteMarkdown(markdown);
      return true;
    }
    return defaultPasteHandler();
  },
});
```

---

## Yjs Utilities

```typescript
import {
  blocksToYDoc,
  blocksToYXmlFragment,
  yDocToBlocks,
  yXmlFragmentToBlocks,
} from "@blocknote/core/yjs";

// Blocks -> Y.Doc
blocksToYDoc(editor, blocks, xmlFragment?: string): Y.Doc

// Blocks -> Y.XmlFragment
blocksToYXmlFragment(editor, blocks, xmlFragment?: Y.XmlFragment): Y.XmlFragment

// Y.Doc -> Blocks
yDocToBlocks(editor, ydoc, xmlFragment?: string): Block[]

// Y.XmlFragment -> Blocks
yXmlFragmentToBlocks(editor, xmlFragment): Block[]
```

---

## Server-side Processing

```typescript
import { ServerBlockNoteEditor } from "@blocknote/server-util";

const editor = ServerBlockNoteEditor.create(/* same options as client */);

// Same conversion methods available:
await editor.blocksToFullHTML(blocks);
await editor.blocksToHTMLLossy(blocks);
await editor.blocksToMarkdownLossy(blocks);
editor.tryParseHTMLToBlocks(html);
editor.tryParseMarkdownToBlocks(markdown);

// Yjs conversion:
editor.yDocToBlocks(ydoc);
editor.blocksToYDoc(blocks);

// React context support for custom blocks:
await editor.withReactContext(
  ({ children }) => <Provider>{children}</Provider>,
  async () => editor.blocksToFullHTML(blocks),
);
```
