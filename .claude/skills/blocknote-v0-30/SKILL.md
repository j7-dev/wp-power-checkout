---
name: blocknote-v0-30
user-invocable: false
description: >
  BlockNote block-based rich text editor for React. Complete API reference covering useCreateBlockNote,
  BlockNoteView, all built-in block types, custom block/inline-content/style creation, Editor manipulation
  API (insertBlocks, removeBlocks, replaceBlocks, updateBlock), format conversion (HTML/Markdown),
  Slash Menu customization, Formatting Toolbar customization, theming, and real-time collaboration.
  Use this skill whenever the user's code involves BlockNote, @blocknote/core, @blocknote/react,
  @blocknote/mantine, @blocknote/shadcn, @blocknote/ariakit, block editors built on ProseMirror/TipTap,
  useCreateBlockNote, BlockNoteView, BlockNoteEditor, BlockNoteSchema, or any rich-text block editor
  task in React. Also use when user mentions slash menus, suggestion menus, formatting toolbars,
  or block-based document structures in a React context.
  This skill covers the latest BlockNote API. Do NOT search the web for BlockNote docs; use this skill instead.
---

# BlockNote

> **Documentation source**: https://www.blocknotejs.org/docs | **Last updated**: 2026-03-15

BlockNote is a block-based rich text editor for React, built on ProseMirror and TipTap. Documents are organized into discrete blocks (paragraphs, headings, lists, tables, images, etc.) that can be nested, dragged, and programmatically manipulated.

## Installation

```bash
npm install @blocknote/core @blocknote/react @blocknote/mantine @mantine/core @mantine/hooks @mantine/utils
```

UI library alternatives: `@blocknote/shadcn` (ShadCN), `@blocknote/ariakit` (Ariakit).

Required CSS imports:

```typescript
import "@blocknote/core/fonts/inter.css";
import "@blocknote/mantine/style.css"; // or shadcn/ariakit equivalent
```

## Minimal Setup

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { useCreateBlockNote } from "@blocknote/react";

export default function App() {
  const editor = useCreateBlockNote();
  return <BlockNoteView editor={editor} />;
}
```

**Next.js**: BlockNote is client-only. Use `"use client"` directive + `dynamic(() => import("./Editor"), { ssr: false })`. Disable `reactStrictMode` for React 19 / Next 15.

## Core API Quick Reference

### useCreateBlockNote

```typescript
function useCreateBlockNote(
  options?: BlockNoteEditorOptions,
  deps?: React.DependencyList,
): BlockNoteEditor;
```

Key options:
- `initialContent: PartialBlock[]` -- starting document content
- `schema: BlockNoteSchema` -- custom blocks/inline content/styles
- `uploadFile: (file: File) => Promise<string>` -- file upload handler
- `resolveFileUrl: (url: string) => Promise<string>` -- resolve indirect file URLs
- `pasteHandler: PasteHandler` -- custom paste behavior
- `dictionary: Dictionary` -- i18n locale object
- `collaboration: { provider, fragment, user, showCursorLabels }` -- Yjs real-time collab
- `domAttributes: Record<string, Record<string, string>>` -- custom DOM attrs
- `extensions: Extension[]` -- custom extensions
- `tables: { splitCells?, cellBackgroundColor?, cellTextColor?, headers? }` -- table features

### BlockNoteView Props

| Prop | Type | Description |
|------|------|-------------|
| `editor` | `BlockNoteEditor` | **Required.** Editor instance |
| `editable` | `boolean` | Read-only toggle |
| `onChange` | `(editor) => void` | Content change callback |
| `onSelectionChange` | `(editor) => void` | Selection change callback |
| `theme` | `"light" \| "dark" \| Theme \| { light: Theme; dark: Theme }` | Theme config |
| `formattingToolbar` | `boolean` | Enable/disable default toolbar |
| `slashMenu` | `boolean` | Enable/disable default slash menu |
| `sideMenu` | `boolean` | Enable/disable default side menu |
| `linkToolbar` | `boolean` | Enable/disable default link toolbar |
| `emojiPicker` | `boolean` | Enable/disable default emoji picker |

`BlockNoteView` is **uncontrolled** -- pass initial content via `useCreateBlockNote({ initialContent })`, not as a prop.

### Document Structure

```typescript
type Block = {
  id: string;
  type: string;
  props: Record<string, boolean | number | string>;
  content: InlineContent[] | TableContent | undefined;
  children: Block[];
};

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

// Default styles
type Styles = Partial<{
  bold: boolean;       // default false
  italic: boolean;     // default false
  underline: boolean;  // default false
  strike: boolean;     // default false
  code: boolean;       // default false
  textColor: string;   // default "default"
  backgroundColor: string; // default "default"
}>;
```

All blocks share `DefaultProps`:

```typescript
type DefaultProps = {
  backgroundColor: string;     // "default"
  textColor: string;           // "default"
  textAlignment: "left" | "center" | "right" | "justify"; // "left"
};
```

### Editor Manipulation Methods

```typescript
// Reading
editor.document                              // Block[] snapshot
editor.getBlock(id): Block | undefined
editor.getPrevBlock(id): Block | undefined
editor.getNextBlock(id): Block | undefined
editor.getParentBlock(id): Block | undefined
editor.forEachBlock(cb: (block) => boolean, reverse?: boolean): void

// Creating
editor.insertBlocks(blocks: PartialBlock[], ref: BlockIdentifier, placement?: "before" | "after"): void

// Updating
editor.updateBlock(id: BlockIdentifier, update: PartialBlock): void

// Removing
editor.removeBlocks(ids: BlockIdentifier[]): void

// Replacing
editor.replaceBlocks(toRemove: BlockIdentifier[], toInsert: PartialBlock[]): void

// Reordering
editor.moveBlocksUp(): void
editor.moveBlocksDown(): void
editor.canNestBlock(): boolean
editor.nestBlock(): void
editor.canUnnestBlock(): boolean
editor.unnestBlock(): void

// Transactions (group for single undo/redo)
editor.transact(() => { /* multiple operations */ }): void

// Cursor & Selection
editor.getTextCursorPosition(): TextCursorPosition
editor.setTextCursorPosition(target: BlockIdentifier, placement?: "start" | "end"): void
editor.getSelection(): Selection | undefined
editor.setSelection(start: BlockIdentifier, end: BlockIdentifier): void

// Inline Content
editor.insertInlineContent(content: PartialInlineContent): void
editor.getSelectedText(): string
editor.getActiveStyles(): Styles
editor.getSelectedLinkUrl(): string | undefined
editor.addStyles(styles: Styles): void
editor.removeStyles(styles: Styles): void
editor.toggleStyles(styles: Styles): void
editor.createLink(url: string, text?: string): void

// Paste
editor.pasteHTML(html: string, raw?: boolean): void
editor.pasteText(text: string): void
editor.pasteMarkdown(markdown: string): void

// Misc
editor.focus(): void
editor.isFocused(): boolean
editor.undo(): void
editor.redo(): void
editor.isEditable: boolean  // set to toggle read-only
editor.openSuggestionMenu(trigger: string): void
```

### Format Conversion

```typescript
// Export (lossy)
editor.blocksToHTMLLossy(blocks?: Block[]): string
editor.blocksToMarkdownLossy(blocks?: Block[]): string

// Export (lossless, BlockNote-native HTML)
editor.blocksToFullHTML(blocks?: Block[]): string

// Import
editor.tryParseHTMLToBlocks(html: string): Block[]
editor.tryParseMarkdownToBlocks(markdown: string): Block[]
```

**Best practice**: Store documents as `JSON.stringify(editor.document)` for zero data loss.

### Events

```typescript
editor.onChange((editor, { getChanges }) => { ... }): () => void
editor.onBeforeChange(({ getChanges, tr }) => boolean | void): () => void
editor.onSelectionChange((editor) => { ... }): () => void
editor.onMount(() => { ... }): () => void
editor.onUnmount(() => { ... }): () => void
```

Change types: `"insert"`, `"delete"`, `"update"`, `"move"`. Each change has a `source`: `"local"`, `"paste"`, `"drop"`, `"undo"`, `"redo"`, `"undo-redo"`, `"yjs-remote"`.

All event callbacks return cleanup functions.

## Built-in Block Types Summary

| Type | Content | Key Props |
|------|---------|-----------|
| `paragraph` | InlineContent[] | DefaultProps |
| `heading` | InlineContent[] | `level: 1-6`, `isToggleable?: boolean` + DefaultProps |
| `quote` | InlineContent[] | `backgroundColor`, `textColor` |
| `divider` | undefined | (none) |
| `bulletListItem` | InlineContent[] | DefaultProps |
| `numberedListItem` | InlineContent[] | `start?: number` + DefaultProps |
| `checkListItem` | InlineContent[] | `checked: boolean` + DefaultProps |
| `toggleListItem` | InlineContent[] | DefaultProps |
| `codeBlock` | InlineContent[] | `language: string` |
| `table` | TableContent | `textColor` |
| `image` | undefined | `url`, `caption`, `name`, `showPreview`, `previewWidth`, `textAlignment` + bg |
| `video` | undefined | `url`, `caption`, `name`, `showPreview`, `previewWidth`, `textAlignment` + bg |
| `audio` | undefined | `url`, `caption`, `name`, `showPreview` + bg |
| `file` | undefined | `url`, `caption`, `name` + bg |
| `columnList` | undefined | children: ColumnBlock[] |
| `column` | undefined | `width: number`, children: Block[] |

For full type definitions and all prop defaults, see `references/api-reference.md`.

## Custom Schema Creation

```typescript
import { BlockNoteSchema, defaultBlockSpecs, defaultInlineContentSpecs, defaultStyleSpecs } from "@blocknote/core";

// Method 1: Extend defaults
const schema = BlockNoteSchema.create().extend({
  blockSpecs: { myBlock: MyCustomBlock },
  inlineContentSpecs: { mention: Mention },
  styleSpecs: { font: Font },
});

// Method 2: From scratch (only includes what you specify)
const schema = BlockNoteSchema.create({
  blockSpecs: { ...defaultBlockSpecs, myBlock: MyCustomBlock },
  inlineContentSpecs: { ...defaultInlineContentSpecs, mention: Mention },
  styleSpecs: { ...defaultStyleSpecs, font: Font },
});

const editor = useCreateBlockNote({ schema });
```

For custom block/inline content/style creation APIs, see `references/custom-schemas.md`.

## References Guide

| Need | File |
|------|------|
| Full block type definitions, all props and defaults | `references/api-reference.md` |
| Custom blocks, inline content, styles creation APIs | `references/custom-schemas.md` |
| Slash Menu, Formatting Toolbar, Side Menu, Link Toolbar customization | `references/ui-components.md` |
| Theming, CSS overrides, DOM attributes | `references/theming.md` |
| Complete working examples | `references/examples.md` |
| Collaboration, extensions, server-side, localization | `references/advanced.md` |
