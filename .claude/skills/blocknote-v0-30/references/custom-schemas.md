# BlockNote Custom Schemas Reference

> APIs for creating custom blocks, custom inline content, and custom styles.

## Table of Contents

- [BlockNoteSchema](#blocknoteschema)
- [Custom Blocks (createReactBlockSpec)](#custom-blocks)
- [Custom Inline Content (createReactInlineContentSpec)](#custom-inline-content)
- [Custom Styles (createReactStyleSpec)](#custom-styles)
- [Extensions](#extensions)

---

## BlockNoteSchema

Two methods for schema creation:

### Method 1: Extend Defaults

```typescript
import { BlockNoteSchema } from "@blocknote/core";

const schema = BlockNoteSchema.create().extend({
  blockSpecs: {
    customBlock: CustomBlock,
  },
  inlineContentSpecs: {
    mention: Mention,
  },
  styleSpecs: {
    font: Font,
  },
});
```

### Method 2: Create from Scratch

Only includes what you specify. Must explicitly include defaults you want to keep:

```typescript
import {
  BlockNoteSchema,
  defaultBlockSpecs,
  defaultInlineContentSpecs,
  defaultStyleSpecs,
} from "@blocknote/core";

const schema = BlockNoteSchema.create({
  blockSpecs: {
    ...defaultBlockSpecs,
    customBlock: CustomBlock,
  },
  inlineContentSpecs: {
    ...defaultInlineContentSpecs,
    mention: Mention,
  },
  styleSpecs: {
    ...defaultStyleSpecs,
    font: Font,
  },
});
```

### Using the Schema

```typescript
const editor = useCreateBlockNote({ schema });
```

### TypeScript Support

Three approaches for type safety:

1. Optional `schema` parameter on hooks: `useBlockNoteEditor({ schema })`
2. Manual typing with schema-specific types
3. Type overrides (experimental) via custom `.d.ts` files

---

## Custom Blocks

### createReactBlockSpec

```typescript
function createReactBlockSpec(
  blockConfig: CustomBlockConfig,
  blockImplementation: ReactCustomBlockImplementation,
  extensions?: BlockNoteExtension[],
): (options?: BlockOptions) => BlockSpec;
```

### CustomBlockConfig

```typescript
type CustomBlockConfig = {
  type: string;                    // unique block identifier
  content: "inline" | "none";     // "inline" = rich text, "none" = no editable content
  propSchema: PropSchema;          // block-specific properties
};
```

### PropSchema

```typescript
type PropSchema = Record<string,
  | { default: PrimitiveType; values?: PrimitiveType[] }           // type inferred from default
  | { default: undefined; type: PrimitiveType; values?: PrimitiveType[] } // explicit type
>;
// PrimitiveType = "boolean" | "number" | "string"
```

`values` constrains the property to a predefined set of options.

### ReactCustomBlockImplementation

```typescript
type ReactCustomBlockImplementation = {
  render: React.FC<{
    block: Block;
    editor: BlockNoteEditor;
    contentRef?: (node: HTMLElement | null) => void;
  }>;

  toExternalHTML?: React.FC<{
    block: Block;
    editor: BlockNoteEditor;
    contentRef?: (node: HTMLElement | null) => void;
    context: { nestingLevel: number };
  }>;

  parse?: (element: HTMLElement) => Record<string, any> | undefined;

  meta?: {
    hardBreakShortcut?: "shift+enter" | "enter" | "none"; // default: "shift+enter"
    selectable?: boolean;           // default: true
    fileBlockAccept?: string[];     // MIME types for file blocks
    code?: boolean;                 // ProseMirror code flag
    defining?: boolean;             // ProseMirror defining flag
    isolating?: boolean;            // ProseMirror isolating flag
  };

  runsBefore?: string[];  // block types needing parsing priority
};
```

- `render`: Required. React component for editor display. Use `contentRef` on the element that should contain editable inline content (only when `content: "inline"`).
- `toExternalHTML`: Optional. Used for clipboard copy and HTML export. Renders in separate React root -- context-dependent hooks won't work.
- `parse`: Optional. Returns props if the HTML element matches this block type; `undefined` otherwise. Enables paste from HTML.

### Complete Custom Block Example

```typescript
import { createReactBlockSpec } from "@blocknote/react";
import { BlockNoteSchema, defaultBlockSpecs } from "@blocknote/core";

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
    render: ({ block, editor, contentRef }) => (
      <div
        style={{
          padding: "8px",
          borderLeft: `4px solid ${
            block.props.type === "error" ? "red" : "orange"
          }`,
        }}
      >
        <select
          value={block.props.type}
          onChange={(e) =>
            editor.updateBlock(block, {
              props: { type: e.target.value },
            })
          }
        >
          <option value="warning">Warning</option>
          <option value="error">Error</option>
          <option value="info">Info</option>
          <option value="success">Success</option>
        </select>
        <div ref={contentRef} />
      </div>
    ),
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
  return <BlockNoteView editor={editor} />;
}
```

### Configurable Blocks (with options)

```typescript
const createCustomBlock = createReactBlockSpec(
  createBlockConfig((options: CustomBlockOptions) => ({
    type: "customBlock",
    propSchema: { /* ... */ },
    content: "inline",
  })),
  (options: CustomBlockOptions) => ({
    render: (props) => { /* ... */ },
  })
);

// Usage with options:
const schema = BlockNoteSchema.create().extend({
  blockSpecs: {
    customBlock: createCustomBlock({ /* options */ }),
  },
});
```

### Adding Custom Blocks to Slash Menu

After creating a custom block, add a slash menu item for discoverability:

```typescript
import { insertOrUpdateBlockForSlashMenu } from "@blocknote/core/extensions";
import { getDefaultReactSlashMenuItems } from "@blocknote/react";

const insertAlertItem = (editor) => ({
  title: "Alert",
  onItemClick: () =>
    insertOrUpdateBlockForSlashMenu(editor, { type: "alert" }),
  aliases: ["alert", "warning", "notification"],
  group: "Other",
  icon: <AlertIcon />,
  subtext: "Insert an alert block",
});

// Combine with defaults
const getSlashMenuItems = (editor) => [
  ...getDefaultReactSlashMenuItems(editor),
  insertAlertItem(editor),
];
```

---

## Custom Inline Content

### createReactInlineContentSpec

```typescript
function createReactInlineContentSpec(
  config: CustomInlineContentConfig,
  implementation: ReactInlineContentImplementation,
): InlineContentSpec;
```

### CustomInlineContentConfig

```typescript
type CustomInlineContentConfig = {
  type: string;                    // unique identifier
  content: "styled" | "none";     // "styled" = can contain StyledText
  propSchema: PropSchema;          // same as block PropSchema
};
```

### ReactInlineContentImplementation

```typescript
type ReactInlineContentImplementation = {
  render: React.FC<{
    inlineContent: InlineContent;
    editor: BlockNoteEditor;
    contentRef?: (node: HTMLElement | null) => void;
  }>;

  toExternalHTML?: React.FC<{
    inlineContent: InlineContent;
    editor: BlockNoteEditor;
    contentRef?: (node: HTMLElement | null) => void;
  }>;

  parse?: (element: HTMLElement) => Record<string, any> | undefined;

  meta?: {
    draggable?: boolean;  // enable drag on inline content
  };
};
```

### Complete Mention Example

```typescript
import { createReactInlineContentSpec } from "@blocknote/react";
import {
  BlockNoteSchema,
  defaultInlineContentSpecs,
} from "@blocknote/core";

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
      <span style={{ backgroundColor: "#8400ff33" }}>
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
```

### Inserting Custom Inline Content

```typescript
editor.insertInlineContent([
  { type: "mention", props: { user: "Alice" } },
  " ",  // trailing space after inline content
]);
```

---

## Custom Styles

### createReactStyleSpec

```typescript
function createReactStyleSpec(
  config: CustomStyleConfig,
  implementation: ReactStyleImplementation,
): StyleSpec;
```

### CustomStyleConfig

```typescript
type CustomStyleConfig = {
  type: string;                      // unique identifier
  propSchema: "boolean" | "string";  // toggle or value-based
};
```

### ReactStyleImplementation

```typescript
type ReactStyleImplementation = {
  render: React.FC<{
    value?: string;  // present when propSchema: "string"
    contentRef: (node: HTMLElement | null) => void;
  }>;

  toExternalHTML?: React.FC<{
    value?: string;
    contentRef: (node: HTMLElement | null) => void;
  }>;

  parse?: (element: HTMLElement) => string | true | undefined;
};
```

### Complete Font Style Example

```typescript
import { createReactStyleSpec } from "@blocknote/react";
import { BlockNoteSchema, defaultStyleSpecs } from "@blocknote/core";

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

// Apply the custom style:
editor.addStyles({ font: "Arial" });
```

---

## Extensions

Create custom extensions to add keyboard shortcuts, input rules, ProseMirror plugins, or TipTap extensions.

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
  plugins?: Plugin[];          // ProseMirror plugins
  tiptapExtensions?: AnyExtension[];  // TipTap extensions
};
```

### Usage

```typescript
import { createExtension } from "@blocknote/core";

const myExtension = createExtension({
  key: "my-extension",
  keyboardShortcuts: {
    "Meta+Shift+ArrowDown": ({ editor }) => {
      editor.moveBlocksDown();
      return true; // event handled
    },
  },
});

const editor = useCreateBlockNote({
  extensions: [myExtension],
});
```

Extensions can also be attached to custom blocks as the third parameter of `createReactBlockSpec`.
