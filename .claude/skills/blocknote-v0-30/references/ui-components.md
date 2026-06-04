# BlockNote UI Components Reference

> Customization APIs for Slash Menu, Formatting Toolbar, Side Menu, Link Toolbar, File Panel, and Grid Suggestion Menus.

## Table of Contents

- [Slash Menu (Suggestion Menus)](#slash-menu)
- [Formatting Toolbar](#formatting-toolbar)
- [Block Side Menu](#block-side-menu)
- [Link Toolbar](#link-toolbar)
- [File Panel](#file-panel)
- [Grid Suggestion Menus (Emoji Picker)](#grid-suggestion-menus)

---

## Slash Menu

The Slash Menu opens when the user types `/` or clicks the `+` button in the Side Menu.

### Default Slash Menu Item Type

```typescript
type DefaultSuggestionItem = {
  title: string;
  onItemClick: () => void;
  subtext?: string;
  badge?: string;
  aliases?: string[];
  group?: string;
  icon?: React.ReactNode;
};
```

### Adding Custom Items

```typescript
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
import { BlockNoteView } from "@blocknote/mantine";
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

### Replacing the Slash Menu Component

```typescript
import { SuggestionMenuProps, DefaultReactSuggestionItem } from "@blocknote/react";

function CustomSlashMenu(
  props: SuggestionMenuProps<DefaultReactSuggestionItem>
) {
  return (
    <div className="slash-menu">
      {props.items.map((item, index) => (
        <div
          key={item.title}
          className={`slash-menu-item ${
            props.selectedIndex === index ? "selected" : ""
          }`}
          onClick={() => props.onItemClick?.(item)}
        >
          {item.title}
        </div>
      ))}
    </div>
  );
}

// Usage:
<BlockNoteView editor={editor} slashMenu={false}>
  <SuggestionMenuController
    triggerCharacter={"/"}
    suggestionMenuComponent={CustomSlashMenu}
  />
</BlockNoteView>
```

### SuggestionMenuController Props

| Prop | Type | Description |
|------|------|-------------|
| `triggerCharacter` | `string` | Character that opens the menu (e.g., `"/"`) |
| `getItems` | `(query: string) => Promise<Item[]>` | Filter function returning items |
| `suggestionMenuComponent` | `React.FC<SuggestionMenuProps>` | Custom menu component |
| `minQueryLength` | `number` | Min chars before showing (default: 0) |

### Creating Additional Suggestion Menus

Example: `@` mentions menu with custom inline content:

```typescript
const getMentionMenuItems = (
  editor: typeof schema.BlockNoteEditor
): DefaultReactSuggestionItem[] => {
  const users = ["Steve", "Bob", "Joe", "Mike"];
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

<BlockNoteView editor={editor}>
  <SuggestionMenuController
    triggerCharacter={"@"}
    getItems={async (query) =>
      filterSuggestionItems(getMentionMenuItems(editor), query)
    }
  />
</BlockNoteView>
```

### Programmatic Opening

```typescript
editor.openSuggestionMenu("/");
```

---

## Formatting Toolbar

Appears when users highlight text. Fully customizable.

### Key Components

| Component | Purpose |
|-----------|---------|
| `FormattingToolbarController` | Controls position/visibility |
| `FormattingToolbar` | Container for toolbar buttons |
| `BlockTypeSelect` | Dropdown for block type changes |
| `BasicTextStyleButton` | Bold, italic, underline, strike, code |
| `TextAlignButton` | Left, center, right alignment |
| `ColorStyleButton` | Text and background colors |
| `CreateLinkButton` | Insert hyperlinks |
| `FileCaptionButton` | File/image captions |
| `FileReplaceButton` | Replace file content |
| `NestBlockButton` | Increase nesting level |
| `UnnestBlockButton` | Decrease nesting level |

### Replacing the Toolbar

```typescript
import {
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

<BlockNoteView editor={editor} formattingToolbar={false}>
  <FormattingToolbarController
    formattingToolbar={() => (
      <FormattingToolbar>
        <BlockTypeSelect key="blockTypeSelect" />
        <BasicTextStyleButton basicTextStyle="bold" key="bold" />
        <BasicTextStyleButton basicTextStyle="italic" key="italic" />
        <BasicTextStyleButton basicTextStyle="underline" key="underline" />
        <BasicTextStyleButton basicTextStyle="strike" key="strike" />
        <TextAlignButton textAlignment="left" key="alignLeft" />
        <TextAlignButton textAlignment="center" key="alignCenter" />
        <TextAlignButton textAlignment="right" key="alignRight" />
        <ColorStyleButton key="colors" />
        <NestBlockButton key="nestBlock" />
        <UnnestBlockButton key="unnestBlock" />
        <CreateLinkButton key="createLink" />
      </FormattingToolbar>
    )}
  />
</BlockNoteView>
```

### Creating Custom Toolbar Buttons

```typescript
import {
  useBlockNoteEditor,
  useComponentsContext,
  useEditorState,
  useSelectedBlocks,
} from "@blocknote/react";

export function BlueButton() {
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
```

### Conditional Button Rendering

Return `null` to hide a button based on state:

```typescript
function ConditionalButton() {
  const blocks = useSelectedBlocks();
  if (blocks.filter((b) => b.content !== undefined).length === 0) {
    return null;
  }
  return <Components.FormattingToolbar.Button>...</Components.FormattingToolbar.Button>;
}
```

### Modifying Block Type Select Items

```typescript
import { blockTypeSelectItems } from "@blocknote/react";
import { RiAlertFill } from "react-icons/ri";

<FormattingToolbar
  blockTypeSelectItems={[
    ...blockTypeSelectItems(editor.dictionary),
    {
      name: "Alert",
      type: "alert",
      icon: RiAlertFill,
    },
  ]}
/>
```

### Relevant Hooks

| Hook | Purpose |
|------|---------|
| `useBlockNoteEditor()` | Access editor instance |
| `useComponentsContext()` | Access BlockNote UI components |
| `useEditorState({ editor, selector })` | Track reactive editor state |
| `useSelectedBlocks()` | Get currently selected blocks |

---

## Block Side Menu

Appears on the left when hovering a block. Contains `+` button and drag handle by default.

### Key Components

| Component | Purpose |
|-----------|---------|
| `SideMenuController` | Controls position/visibility |
| `SideMenu` | Container for side menu buttons |
| `DragHandleButton` | Default drag handle component |
| `SideMenu.Button` | Styled button for custom actions |

### Replacing the Side Menu

```typescript
import {
  SideMenu,
  SideMenuController,
  DragHandleButton,
} from "@blocknote/react";

function RemoveBlockButton() {
  const editor = useBlockNoteEditor();
  const Components = useComponentsContext()!;
  const block = useExtensionState(SideMenuExtension, {
    selector: (state) => state?.block,
  });

  return (
    <Components.SideMenu.Button
      onClick={() => block && editor.removeBlocks([block])}
    >
      Delete
    </Components.SideMenu.Button>
  );
}

<BlockNoteView editor={editor} sideMenu={false}>
  <SideMenuController
    sideMenu={(props) => (
      <SideMenu {...props}>
        <RemoveBlockButton />
        <DragHandleButton {...props} />
      </SideMenu>
    )}
  />
</BlockNoteView>
```

### Custom Drag Handle Menu Items

```typescript
// Built-in items: RemoveBlockItem, BlockColorsItem
// Add custom items via Components.Generic.Menu.Item

<SideMenu {...props} dragHandleMenu={CustomDragHandleMenu} />
```

---

## Link Toolbar

Appears when hovering a link in the editor.

### Key Components

| Component | Purpose |
|-----------|---------|
| `LinkToolbarController` | Controls position/visibility |
| `LinkToolbar` | Container for link toolbar buttons |
| `EditLinkButton` | Edit link URL and text |
| `OpenLinkButton` | Navigate to link URL |
| `DeleteLinkButton` | Remove the link |

### Custom Link Toolbar

```typescript
import {
  LinkToolbar,
  LinkToolbarController,
  EditLinkButton,
  OpenLinkButton,
  DeleteLinkButton,
} from "@blocknote/react";

<BlockNoteView editor={editor} linkToolbar={false}>
  <LinkToolbarController
    linkToolbar={(props) => (
      <LinkToolbar {...props}>
        <EditLinkButton {...props} />
        <OpenLinkButton {...props} />
        <DeleteLinkButton {...props} />
      </LinkToolbar>
    )}
  />
</BlockNoteView>
```

---

## File Panel

Appears when users select file/image blocks without URLs or click "Replace File".

### Upload Configuration

```typescript
const editor = useCreateBlockNote({
  uploadFile: async (file: File): Promise<string> => {
    const body = new FormData();
    body.append("file", file);
    const res = await fetch("https://your-api.com/upload", {
      method: "POST",
      body,
    });
    const data = await res.json();
    return data.url; // return accessible file URL
  },
});
```

### URL Resolution

For backends returning API endpoints instead of direct URLs:

```typescript
const editor = useCreateBlockNote({
  resolveFileUrl: async (url: string): Promise<string> => {
    const res = await fetch(url);
    const data = await res.json();
    return data.directUrl;
  },
});
```

---

## Grid Suggestion Menus

Grid-layout suggestion menus (default: emoji picker triggered by `:`).

### Default Emoji Picker

- Trigger: `:` character
- Requires 2 non-whitespace characters before showing
- Default 10 columns

### Customizing the Emoji Picker

```typescript
<BlockNoteView editor={editor} emojiPicker={false}>
  <GridSuggestionMenuController
    triggerCharacter={":"}
    columns={5}
    minQueryLength={2}
    gridSuggestionMenuComponent={CustomEmojiPicker}
  />
</BlockNoteView>
```

### GridSuggestionMenuController Props

| Prop | Type | Description |
|------|------|-------------|
| `triggerCharacter` | `string` | Activation character |
| `columns` | `number` | Grid column count (default: 10) |
| `minQueryLength` | `number` | Min chars before showing |
| `getItems` | `(query: string) => Promise<Item[]>` | Custom item provider |
| `gridSuggestionMenuComponent` | `React.FC` | Custom grid component |

### Custom Grid Menu Component Props

```typescript
type GridSuggestionMenuProps = {
  items: Item[];
  selectedIndex: number;
  onItemClick: (item: Item) => void;
  columns: number;
};
```
