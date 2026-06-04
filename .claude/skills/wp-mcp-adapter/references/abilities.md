# WordPress Abilities API 整合

> 此檔說明如何透過 `wp_register_ability()` 建立可被 MCP Adapter 使用的 abilities。
> Abilities API 自 WordPress 6.9 起內建於 core；WP 6.8 需安裝 `wordpress/abilities-api` 套件。

## 目錄
- [基本結構](#基本結構)
- [meta.mcp 欄位](#metamcp-欄位)
- [Input / Output Schema](#input--output-schema)
- [建立 Tool](#建立-tool)
- [建立 Resource](#建立-resource)
- [建立 Prompt](#建立-prompt)
- [MCP Annotations](#mcp-annotations)
- [Tool 名稱 Sanitization](#tool-名稱-sanitization)
- [Permission 兩層架構](#permission-兩層架構)

---

## 基本結構

```php
add_action( 'wp_abilities_api_init', function (): void {
    wp_register_ability( 'my-plugin/ability-name', [
        'label'               => 'Human Readable Label',
        'description'         => 'What this ability does',
        'category'            => 'my-plugin',           // 對應 wp_register_ability_category()
        'input_schema'        => [ /* JSON Schema */ ],  // tool / prompt 必填
        'output_schema'       => [ /* JSON Schema */ ],  // 選填
        'execute_callback'    => function ( array|mixed $input ) { /* ... */ },
        'permission_callback' => function ( $input ): bool { /* ... */ },
        'meta' => [
            'annotations' => [ /* MCP annotations */ ],
            'uri'         => 'wordpress://...',  // resource 必填
            'mcp' => [
                'public' => true,   // default server 可探索（必要）
                'type'   => 'tool', // 'tool'（預設）| 'resource' | 'prompt'
            ],
        ],
    ] );
} );
```

**重點**：
- `meta.mcp.public = true` 才能被 default server 的 discover/execute 工具探索
- 自訂 server 透過 `create_server()` 的 `$tools/$resources/$prompts` 參數明確指定 ability，不需要 `public = true`

---

## meta.mcp 欄位

| 欄位 | 型別 | 說明 |
|------|------|------|
| `public` | `bool` | `true` = default server 可探索；自訂 server 不需要 |
| `type` | `string` | `'tool'`（預設）、`'resource'`、`'prompt'` |

`type` 決定 default server 如何自動探索：
- `tool`（預設）：透過 discover-abilities / execute-ability meta-tools 存取
- `resource`：自動加入 `resources/list`
- `prompt`：自動加入 `prompts/list`

---

## Input / Output Schema

### Object Schema（推薦）

```php
'input_schema' => [
    'type'       => 'object',
    'properties' => [
        'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID' ],
        'status'   => [
            'type'    => 'string',
            'enum'    => ['publish', 'draft', 'private'],
            'default' => 'publish',
        ],
        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10 ],
    ],
    'required' => ['post_id'],
],
```

### Flattened Schema（單值輸入）

非 object 型別的 schema 會自動包裝為 `{ "input": value }` 格式，callback 收到的是解包後的值：

```php
// 定義
'input_schema' => [ 'type' => 'string', 'enum' => ['post', 'page'] ],
// callback 收到：'post'（字串本身，非 ['input' => 'post']）

// Output 同理，flattened output schema 自動包裝為 { "result": value }
'output_schema' => [ 'type' => 'integer', 'description' => 'Total count' ],
// callback 回傳：42（整數本身）
```

支援的 flattened 型別：`string`、`number`、`integer`、`boolean`、`array`

---

## 建立 Tool

```php
wp_register_ability( 'my-plugin/create-post', [
    'label'       => 'Create Post',
    'description' => 'Create a new WordPress post',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'title'   => [ 'type' => 'string', 'description' => 'Post title' ],
            'content' => [ 'type' => 'string', 'description' => 'Post content' ],
            'status'  => [ 'type' => 'string', 'enum' => ['draft', 'publish'], 'default' => 'draft' ],
        ],
        'required' => ['title', 'content'],
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'post_id' => [ 'type' => 'integer' ],
            'url'     => [ 'type' => 'string' ],
            'status'  => [ 'type' => 'string' ],
        ],
    ],
    'execute_callback' => function ( array $input ): array {
        $post_id = wp_insert_post( [
            'post_title'   => $input['title'],
            'post_content' => $input['content'],
            'post_status'  => $input['status'] ?? 'draft',
        ] );

        return [
            'post_id' => $post_id,
            'url'     => get_permalink( $post_id ),
            'status'  => get_post_status( $post_id ),
        ];
    },
    'permission_callback' => fn() => current_user_can( 'publish_posts' ),
    'meta' => [
        'annotations' => [
            'readonly'    => false,   // WordPress 格式 → readOnlyHint
            'destructive' => false,   // WordPress 格式 → destructiveHint
            'idempotent'  => false,   // WordPress 格式 → idempotentHint
        ],
        'mcp' => [ 'public' => true ],
    ],
] );
```

---

## 建立 Resource

Resource 必須在 `meta` 中提供 `uri`，並設定 `meta.mcp.type = 'resource'`：

```php
wp_register_ability( 'my-plugin/site-config', [
    'label'       => 'Site Configuration',
    'description' => 'WordPress site configuration and settings',
    'execute_callback' => function (): array {
        return [
            'site_name'   => get_bloginfo( 'name' ),
            'site_url'    => get_site_url(),
            'admin_email' => get_option( 'admin_email' ),
            'timezone'    => get_option( 'timezone_string' ),
        ];
    },
    'permission_callback' => fn() => current_user_can( 'manage_options' ),
    'meta' => [
        'uri' => 'wordpress://site/config',  // 必填，RFC 3986 URI
        'annotations' => [
            'audience'     => ['user', 'assistant'],
            'priority'     => 0.8,
            'lastModified' => date( 'c' ),
        ],
        'mcp' => [
            'public' => true,
            'type'   => 'resource',
        ],
    ],
] );
```

---

## 建立 Prompt

Prompt 的 `input_schema` 自動轉換為 MCP `arguments` 格式；callback 必須回傳 `{ messages: [...] }` 結構：

```php
wp_register_ability( 'my-plugin/code-review', [
    'label'       => 'Code Review Prompt',
    'description' => 'Generate a structured code review prompt',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'code'  => [ 'type' => 'string', 'description' => 'Code to review' ],
            'focus' => [
                'type'    => 'array',
                'items'   => [ 'type' => 'string' ],
                'default' => ['security', 'performance'],
            ],
        ],
        'required' => ['code'],
    ],
    'execute_callback' => function ( array $input ): array {
        $focus = implode( ', ', $input['focus'] ?? ['security', 'performance'] );

        return [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => "Please review this code focusing on: {$focus}\n\n```\n{$input['code']}\n```",
                    ],
                ],
            ],
        ];
    },
    'permission_callback' => fn() => current_user_can( 'edit_posts' ),
    'meta' => [
        'annotations' => [
            'audience' => ['user'],
            'priority' => 0.7,
        ],
        'mcp' => [
            'public' => true,
            'type'   => 'prompt',
        ],
    ],
] );
```

---

## MCP Annotations

### Tool Annotations（WordPress 格式，推薦）

```php
'meta' => [
    'annotations' => [
        'readonly'    => true,   // → readOnlyHint：tool 不修改資料
        'destructive' => false,  // → destructiveHint：tool 不刪除資料
        'idempotent'  => true,   // → idempotentHint：相同輸入產生相同輸出
        // 無 WordPress 對應，直接用 MCP 格式：
        'openWorldHint' => false,
        'title'         => 'Custom Display Title',
    ],
]
```

| WordPress 格式 | MCP 格式 | 說明 |
|---------------|---------|------|
| `readonly` | `readOnlyHint` | 不修改資料 |
| `destructive` | `destructiveHint` | 可能刪除資料 |
| `idempotent` | `idempotentHint` | 冪等操作 |
| （無）| `openWorldHint` | 可操作任意資料 |
| （無）| `title` | 客製化顯示標題 |

### Resource & Prompt Annotations（MCP 格式）

```php
'annotations' => [
    'audience'     => ['user', 'assistant'],  // 目標受眾
    'priority'     => 0.8,                   // 0.0（最低）～ 1.0（最高）
    'lastModified' => '2024-01-15T10:30:00Z', // ISO 8601
],
```

---

## Tool 名稱 Sanitization

`wp_register_ability()` 的名稱會經 `McpNameSanitizer::sanitize_name()` 處理：

1. 去除首尾空白
2. `/` → `-`
3. Transliterate accent（`é` → `e`）
4. 無效字元 → `-`
5. 合併連續 `-`
6. 超過 128 字元：截斷至 115 字元 + `-` + 12 字元 MD5 hash
7. 結果為空 → `WP_Error`

**自訂工具名稱：**
```php
add_filter( 'mcp_adapter_tool_name', function ( string $name, \WP_Ability $ability ): string {
    if ( 'my-plugin/legacy-tool' === $ability->get_name() ) {
        return 'my-legacy-tool';
    }
    return $name;
}, 10, 2 );
```

---

## Permission 兩層架構

```
[Transport Permission]  ← 整個 server 的閘門（McpAdapter::create_server() 最後參數）
        ↓
[Ability Permission]    ← 個別 ability 的 permission_callback
```

- Transport permission 封鎖的使用者無法存取該 server 上的任何 ability
- Ability permission 是針對個別操作的細粒度控制
- 建議 transport 設為最寬鬆的所需 capability，讓各 ability 自行精細控制
