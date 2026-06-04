# BlockNote Examples

> Complete, runnable code examples from official documentation.

## Table of Contents

- [Basic Editor Setup](#basic-editor-setup)
- [Editor with Initial Content](#editor-with-initial-content)
- [Next.js Integration](#nextjs-integration)
- [Save and Load Content (JSON)](#save-and-load-content)
- [HTML Conversion](#html-conversion)
- [Markdown Conversion](#markdown-conversion)
- [Custom Slash Menu Items](#custom-slash-menu-items)
- [Custom Formatting Toolbar](#custom-formatting-toolbar)
- [Custom Block: Alert](#custom-block-alert)
- [Custom Inline Content: Mention](#custom-inline-content-mention)
- [Custom Style: Font Family](#custom-style-font-family)
- [File Upload](#file-upload)
- [Real-time Collaboration](#real-time-collaboration)
- [Localization](#localization)
- [Programmatic Block Manipulation](#programmatic-block-manipulation)

---

## Basic Editor Setup

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

---

## Editor with Initial Content

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { useCreateBlockNote } from "@blocknote/react";

export default function App() {
  const editor = useCreateBlockNote({
    initialContent: [
      {
        type: "heading",
        props: { level: 1 },
        content: "Welcome to BlockNote",
      },
      {
        type: "paragraph",
        content: "This is a basic paragraph.",
      },
      {
        type: "bulletListItem",
        content: "First item",
      },
      {
        type: "bulletListItem",
        content: "Second item",
      },
      {
        type: "checkListItem",
        props: { checked: true },
        content: "Completed task",
      },
      {
        type: "checkListItem",
        props: { checked: false },
        content: "Pending task",
      },
      {
        type: "paragraph",
        content: [
          { type: "text", text: "This is ", styles: {} },
          { type: "text", text: "bold", styles: { bold: true } },
          { type: "text", text: " and ", styles: {} },
          { type: "text", text: "italic", styles: { italic: true } },
          { type: "text", text: " text.", styles: {} },
        ],
      },
      {
        type: "paragraph",
      },
    ],
  });

  return <BlockNoteView editor={editor} />;
}
```

---

## Next.js Integration

### components/Editor.tsx

```typescript
"use client";
import "@blocknote/core/fonts/inter.css";
import { useCreateBlockNote } from "@blocknote/react";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";

export default function Editor() {
  const editor = useCreateBlockNote();
  return <BlockNoteView editor={editor} />;
}
```

### components/DynamicEditor.tsx

```typescript
"use client";
import dynamic from "next/dynamic";

export const Editor = dynamic(() => import("./Editor"), { ssr: false });
```

### Page Usage

```typescript
import { Editor } from "../components/DynamicEditor";

export default function Page() {
  return (
    <div>
      <Editor />
    </div>
  );
}
```

Note: In `next.config.ts`, set `reactStrictMode: false` for React 19 / Next 15 compatibility.

---

## Save and Load Content

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { useCreateBlockNote, useEditorChange } from "@blocknote/react";
import { useState, useEffect } from "react";

const STORAGE_KEY = "blocknote-content";

export default function App() {
  const [initialContent, setInitialContent] = useState(undefined);

  // Load from storage on mount
  useEffect(() => {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (saved) {
      setInitialContent(JSON.parse(saved));
    } else {
      setInitialContent(undefined);
    }
  }, []);

  const editor = useCreateBlockNote({
    initialContent,
  }, [initialContent]);

  // Save on change
  useEditorChange((editor) => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(editor.document));
  }, editor);

  if (initialContent === undefined) return <div>Loading...</div>;

  return <BlockNoteView editor={editor} />;
}
```

---

## HTML Conversion

### Export Blocks to HTML

```typescript
// Lossless (preserves full BlockNote structure)
const fullHTML = editor.blocksToFullHTML(editor.document);

// Lossy (standard HTML, loses nesting info)
const standardHTML = await editor.blocksToHTMLLossy(editor.document);
```

### Import HTML to Blocks

```typescript
const htmlString = "<p>Hello, <strong>world!</strong></p>";
const blocks = await editor.tryParseHTMLToBlocks(htmlString);
editor.replaceBlocks(editor.document, blocks);
```

---

## Markdown Conversion

### Export Blocks to Markdown

```typescript
const markdown = editor.blocksToMarkdownLossy(editor.document);
console.log(markdown);
```

### Import Markdown to Blocks

```typescript
const markdownString = "# Hello\n\nThis is **bold** text.\n\n- Item 1\n- Item 2";
const blocks = await editor.tryParseMarkdownToBlocks(markdownString);
editor.replaceBlocks(editor.document, blocks);
```

---

## Custom Slash Menu Items

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { BlockNoteEditor } from "@blocknote/core";
import {
  filterSuggestionItems,
  insertOrUpdateBlockForSlashMenu,
} from "@blocknote/core/extensions";
import {
  DefaultReactSuggestionItem,
  getDefaultReactSlashMenuItems,
  SuggestionMenuController,
  useCreateBlockNote,
} from "@blocknote/react";
import { HiOutlineGlobeAlt } from "react-icons/hi";

const insertHelloWorldItem = (
  editor: BlockNoteEditor
): DefaultReactSuggestionItem => ({
  title: "Insert Hello World",
  onItemClick: () =>
    insertOrUpdateBlockForSlashMenu(editor, {
      type: "paragraph",
      content: [{ type: "text", text: "Hello World", styles: { bold: true } }],
    }),
  aliases: ["helloworld", "hw"],
  group: "Other",
  icon: <HiOutlineGlobeAlt size={18} />,
  subtext: "Used to insert a block with 'Hello World' below.",
});

const getCustomSlashMenuItems = (
  editor: BlockNoteEditor
): DefaultReactSuggestionItem[] => [
  ...getDefaultReactSlashMenuItems(editor),
  insertHelloWorldItem(editor),
];

export default function App() {
  const editor = useCreateBlockNote();

  return (
    <BlockNoteView editor={editor} slashMenu={false}>
      <SuggestionMenuController
        triggerCharacter={"/"}
        getItems={async (query) =>
          filterSuggestionItems(getCustomSlashMenuItems(editor), query)
        }
      />
    </BlockNoteView>
  );
}
```

---

## Custom Formatting Toolbar

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import {
  useCreateBlockNote,
  useBlockNoteEditor,
  useComponentsContext,
  useEditorState,
  FormattingToolbar,
  FormattingToolbarController,
  BasicTextStyleButton,
  BlockTypeSelect,
  TextAlignButton,
  ColorStyleButton,
  CreateLinkButton,
  NestBlockButton,
  UnnestBlockButton,
} from "@blocknote/react";

function BlueButton() {
  const editor = useBlockNoteEditor();
  const Components = useComponentsContext()!;

  const isSelected = useEditorState({
    editor,
    selector: ({ editor }) =>
      editor.getActiveStyles().textColor === "blue",
  });

  return (
    <Components.FormattingToolbar.Button
      mainTooltip="Blue Text"
      onClick={() => editor.toggleStyles({ textColor: "blue" })}
      isSelected={isSelected}
    >
      Blue
    </Components.FormattingToolbar.Button>
  );
}

export default function App() {
  const editor = useCreateBlockNote();

  return (
    <BlockNoteView editor={editor} formattingToolbar={false}>
      <FormattingToolbarController
        formattingToolbar={() => (
          <FormattingToolbar>
            <BlockTypeSelect key="blockTypeSelect" />
            <BasicTextStyleButton basicTextStyle="bold" key="bold" />
            <BasicTextStyleButton basicTextStyle="italic" key="italic" />
            <BasicTextStyleButton basicTextStyle="underline" key="underline" />
            <TextAlignButton textAlignment="left" key="left" />
            <TextAlignButton textAlignment="center" key="center" />
            <TextAlignButton textAlignment="right" key="right" />
            <ColorStyleButton key="colors" />
            <NestBlockButton key="nest" />
            <UnnestBlockButton key="unnest" />
            <CreateLinkButton key="link" />
            <BlueButton key="blue" />
          </FormattingToolbar>
        )}
      />
    </BlockNoteView>
  );
}
```

---

## Custom Block: Alert

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { createReactBlockSpec, useCreateBlockNote } from "@blocknote/react";
import {
  BlockNoteSchema,
  defaultBlockSpecs,
} from "@blocknote/core";
import {
  filterSuggestionItems,
  insertOrUpdateBlockForSlashMenu,
} from "@blocknote/core/extensions";
import {
  getDefaultReactSlashMenuItems,
  SuggestionMenuController,
} from "@blocknote/react";

const Alert = createReactBlockSpec(
  {
    type: "alert",
    propSchema: {
      type: {
        default: "warning",
        values: ["warning", "error", "info", "success"],
      },
    },
    content: "inline",
  },
  {
    render: ({ block, editor, contentRef }) => {
      const colors = {
        warning: "#fef3cd",
        error: "#f8d7da",
        info: "#cff4fc",
        success: "#d1e7dd",
      };
      return (
        <div
          style={{
            backgroundColor: colors[block.props.type],
            padding: "12px",
            borderRadius: "4px",
          }}
        >
          <select
            value={block.props.type}
            onChange={(e) =>
              editor.updateBlock(block, {
                props: { type: e.target.value },
              })
            }
            contentEditable={false}
          >
            <option value="warning">Warning</option>
            <option value="error">Error</option>
            <option value="info">Info</option>
            <option value="success">Success</option>
          </select>
          <div ref={contentRef} />
        </div>
      );
    },
  }
);

const schema = BlockNoteSchema.create({
  blockSpecs: {
    ...defaultBlockSpecs,
    alert: Alert(),
  },
});

export default function App() {
  const editor = useCreateBlockNote({ schema });

  return (
    <BlockNoteView editor={editor} slashMenu={false}>
      <SuggestionMenuController
        triggerCharacter={"/"}
        getItems={async (query) =>
          filterSuggestionItems(
            [
              ...getDefaultReactSlashMenuItems(editor),
              {
                title: "Alert",
                onItemClick: () =>
                  insertOrUpdateBlockForSlashMenu(editor, { type: "alert" }),
                aliases: ["alert", "warning", "notification"],
                group: "Other",
                subtext: "Insert an alert block",
              },
            ],
            query
          )
        }
      />
    </BlockNoteView>
  );
}
```

---

## Custom Inline Content: Mention

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { createReactInlineContentSpec, useCreateBlockNote } from "@blocknote/react";
import {
  BlockNoteSchema,
  defaultInlineContentSpecs,
} from "@blocknote/core";
import {
  filterSuggestionItems,
  SuggestionMenuController,
  DefaultReactSuggestionItem,
} from "@blocknote/react";

const Mention = createReactInlineContentSpec(
  {
    type: "mention",
    propSchema: {
      user: { default: "Unknown" },
    },
    content: "none",
  },
  {
    render: (props) => (
      <span style={{ backgroundColor: "#8400ff33", padding: "0 4px" }}>
        @{props.inlineContent.props.user}
      </span>
    ),
  }
);

const schema = BlockNoteSchema.create({
  inlineContentSpecs: {
    ...defaultInlineContentSpecs,
    mention: Mention,
  },
});

const getMentionMenuItems = (
  editor: typeof schema.BlockNoteEditor
): DefaultReactSuggestionItem[] => {
  const users = ["Alice", "Bob", "Charlie", "Diana"];
  return users.map((user) => ({
    title: user,
    onItemClick: () => {
      editor.insertInlineContent([
        { type: "mention", props: { user } },
        " ",
      ]);
    },
  }));
};

export default function App() {
  const editor = useCreateBlockNote({ schema });

  return (
    <BlockNoteView editor={editor}>
      <SuggestionMenuController
        triggerCharacter={"@"}
        getItems={async (query) =>
          filterSuggestionItems(getMentionMenuItems(editor), query)
        }
      />
    </BlockNoteView>
  );
}
```

---

## Custom Style: Font Family

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { createReactStyleSpec, useCreateBlockNote } from "@blocknote/react";
import {
  BlockNoteSchema,
  defaultStyleSpecs,
} from "@blocknote/core";

const Font = createReactStyleSpec(
  {
    type: "font",
    propSchema: "string",
  },
  {
    render: (props) => (
      <span style={{ fontFamily: props.value }} ref={props.contentRef} />
    ),
  }
);

const schema = BlockNoteSchema.create({
  styleSpecs: {
    ...defaultStyleSpecs,
    font: Font,
  },
});

export default function App() {
  const editor = useCreateBlockNote({ schema });

  return (
    <div>
      <button onClick={() => editor.addStyles({ font: "Comic Sans MS" })}>
        Comic Sans
      </button>
      <button onClick={() => editor.addStyles({ font: "Courier New" })}>
        Courier
      </button>
      <BlockNoteView editor={editor} />
    </div>
  );
}
```

---

## File Upload

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { useCreateBlockNote } from "@blocknote/react";

async function uploadFile(file: File): Promise<string> {
  const body = new FormData();
  body.append("file", file);

  const response = await fetch("https://your-api.com/upload", {
    method: "POST",
    body,
  });
  const data = await response.json();
  return data.url;
}

export default function App() {
  const editor = useCreateBlockNote({
    uploadFile,
  });

  return <BlockNoteView editor={editor} />;
}
```

---

## Real-time Collaboration

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { useCreateBlockNote } from "@blocknote/react";
import * as Y from "yjs";
import { WebrtcProvider } from "y-webrtc";

const doc = new Y.Doc();
const provider = new WebrtcProvider("my-document-id", doc);

export default function App() {
  const editor = useCreateBlockNote({
    collaboration: {
      provider,
      fragment: doc.getXmlFragment("document-store"),
      user: {
        name: "My Username",
        color: "#ff0000",
      },
      showCursorLabels: "activity",
    },
  });

  return <BlockNoteView editor={editor} />;
}
```

Supported providers: Liveblocks, PartyKit, Y-Sweet, Hocuspocus, y-websocket, y-indexeddb, y-webrtc.

---

## Localization

```typescript
import "@blocknote/core/fonts/inter.css";
import { BlockNoteView } from "@blocknote/mantine";
import "@blocknote/mantine/style.css";
import { useCreateBlockNote } from "@blocknote/react";
import { fr } from "@blocknote/core/locales";

// Full locale
export function FrenchEditor() {
  const editor = useCreateBlockNote({ dictionary: fr });
  return <BlockNoteView editor={editor} />;
}

// Partial override
import { en } from "@blocknote/core/locales";

export function CustomPlaceholderEditor() {
  const editor = useCreateBlockNote({
    dictionary: {
      ...en,
      placeholders: {
        ...en.placeholders,
        default: "Type something here...",
      },
    },
  });
  return <BlockNoteView editor={editor} />;
}
```

Supported locales (24): ar, de, en, es, fr, he, hr, is, it, ja, ko, nl, no, pl, pt, ru, sk, uk, uz, vi, zh-CN, zh-TW, and more.

---

## Programmatic Block Manipulation

```typescript
// Insert blocks after specific block
editor.insertBlocks(
  [
    { type: "heading", props: { level: 2 }, content: "New Section" },
    { type: "paragraph", content: "Some content here." },
    { type: "bulletListItem", content: "Point A" },
    { type: "bulletListItem", content: "Point B" },
  ],
  existingBlockId,
  "after"
);

// Update a block's type and content
editor.updateBlock(blockId, {
  type: "heading",
  props: { level: 3 },
  content: "Updated heading text",
});

// Remove multiple blocks
editor.removeBlocks([blockId1, blockId2, blockId3]);

// Replace blocks
editor.replaceBlocks(
  [oldBlockId],
  [
    { type: "paragraph", content: "Replacement content" },
    { type: "paragraph", content: "Additional paragraph" },
  ]
);

// Group operations into single undo/redo
editor.transact(() => {
  editor.insertBlocks([{ type: "paragraph", content: "Hello" }], refBlock);
  editor.removeBlocks([otherBlock]);
  editor.updateBlock(anotherBlock, { props: { level: 2 } });
});

// Cursor and selection
const cursor = editor.getTextCursorPosition();
editor.setTextCursorPosition(blockId, "end");

const selection = editor.getSelection();
editor.setSelection(startBlockId, endBlockId);

// Inline content
editor.addStyles({ bold: true, textColor: "red" });
editor.toggleStyles({ italic: true });
editor.createLink("https://example.com", "Click here");

// Paste programmatically
editor.pasteHTML("<p>Pasted content</p>");
editor.pasteMarkdown("# Pasted heading\n\nPasted paragraph.");
```
