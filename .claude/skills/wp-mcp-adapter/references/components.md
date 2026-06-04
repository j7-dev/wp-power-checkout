# McpTool / McpResource / McpPrompt — 直接實例化 API

> 此檔說明不透過 Abilities API、直接用 fromArray() 建立 MCP 元件的方式。
> 適用於：需要精細控制元件行為、不想建立 wp_ability、或有既有 handler 邏輯。

## 目錄
- [McpTool](#mcptool)
- [McpResource](#mcpresource)
- [McpPrompt](#mcpprompt)
- [McpPromptBuilder（fluent API）](#mcppromptbuilder-fluent-api)
- [Component 註冊方式比較](#component-註冊方式比較)
- [McpComponentInterface](#mcpcomponentinterface)

---

## McpTool

**Namespace**: `WP\MCP\Domain\Tools\McpTool`

### fromArray() 工廠方法

```php
$tool = McpTool::fromArray( [
    'name'        => 'uppercase-text',         // 必填，MCP 合法名稱（A-Za-z0-9_.- 最多 128）
    'title'       => 'Uppercase Text',         // 選填，顯示標題
    'description' => 'Converts text to uppercase', // 選填
    'inputSchema' => [                         // 選填，預設 ['type' => 'object']
        'type'       => 'object',
        'properties' => [
            'text' => [ 'type' => 'string', 'description' => 'Text to uppercase' ],
        ],
        'required' => ['text'],
    ],
    'outputSchema' => [                        // 選填
        'type'       => 'object',
        'properties' => [ 'result' => [ 'type' => 'string' ] ],
    ],
    'handler' => function ( array $args ): array {  // 必填，callable
        return [ 'result' => strtoupper( $args['text'] ) ];
    },
    'permission' => function (): bool {        // 選填，省略則永遠允許
        return current_user_can( 'read' );
    },
    'annotations' => [                         // 選填，直接用 MCP 格式
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
        'title'           => 'Optional display title',
    ],
    'icons' => [                               // 選填
        [ 'url' => 'https://example.com/icon.png', 'type' => 'image/png', 'size' => 64 ],
    ],
    'meta' => [ /* 任意 _meta 資料，不暴露給 MCP client */ ],
] );

if ( is_wp_error( $tool ) ) {
    // 'mcp_tool_missing_name'    — name 欄位為空
    // 'mcp_tool_missing_handler' — handler 不是 callable
    // 'mcp_tool_dto_creation_failed' — DTO 建立失敗
}
```

### fromAbility() 工廠方法

```php
$ability = wp_get_ability( 'my-plugin/my-tool' );
$tool    = McpTool::fromAbility( $ability );
// 傳回 McpTool 或 WP_Error
```

### 在 create_server() 中直接傳入 McpTool 實例

```php
$tool = McpTool::fromArray( [ /* ... */ ] );
// tools 陣列可混用 ability 名稱（string）與 McpTool 實例：
$adapter->create_server(
    // ...
    tools: [ 'my-plugin/existing-ability', $tool ],
);
```

### McpTool 實例方法（McpComponentInterface）

```php
$tool->get_protocol_dto(): \WP\McpSchema\Server\Tools\DTO\Tool  // MCP 序列化用 DTO
$tool->execute( $arguments ): mixed                               // 呼叫 handler/ability
$tool->check_permission( $arguments ): bool                      // 呼叫 permission callback
$tool->get_adapter_meta(): array                                  // 內部 metadata（非 MCP 暴露）
$tool->get_observability_context(): array                        // ['component_type', 'tool_name', 'source']
```

---

## McpResource

**Namespace**: `WP\MCP\Domain\Resources\McpResource`

### fromArray() 工廠方法

```php
$resource = McpResource::fromArray( [
    'uri'         => 'wordpress://site/readme',  // 必填，RFC 3986 URI（需有 scheme）
    'name'        => 'Site README',              // 選填，預設等於 uri
    'title'       => 'Site README',              // 選填
    'description' => 'The site README file',     // 選填
    'mimeType'    => 'text/markdown',            // 選填
    'handler' => function (): string {           // 必填，callable
        return file_get_contents( ABSPATH . 'README.md' );
    },
    'permission' => function (): bool {          // 選填
        return current_user_can( 'read' );
    },
    'annotations' => [                           // 選填，MCP Annotations 格式
        'audience'     => ['assistant'],
        'priority'     => 0.9,
        'lastModified' => date( 'c' ),
    ],
    'icons' => [ /* 同 McpTool */ ],
] );

if ( is_wp_error( $resource ) ) {
    // 'mcp_resource_missing_uri'    — uri 欄位為空
    // 'mcp_resource_invalid_uri'    — URI 不符合 RFC 3986（無 scheme）
    // 'mcp_resource_missing_name'   — name 為空字串
    // 'mcp_resource_missing_handler' — handler 不是 callable
}
```

### fromAbility()

```php
$ability  = wp_get_ability( 'my-plugin/site-config' );
$resource = McpResource::fromAbility( $ability, $error_handler );
// $error_handler: ?McpErrorHandlerInterface（選填）
```

---

## McpPrompt

**Namespace**: `WP\MCP\Domain\Prompts\McpPrompt`

### fromArray() 工廠方法

```php
$prompt = McpPrompt::fromArray( [
    'name'        => 'code-review',              // 必填，MCP 合法名稱
    'title'       => 'Code Review',              // 選填
    'description' => 'Generate a code review',  // 選填
    'arguments'   => [                           // 選填，MCP prompt arguments 格式
        [ 'name' => 'code',  'description' => 'Code to review',       'required' => true ],
        [ 'name' => 'focus', 'description' => 'Areas to focus on',    'required' => false ],
    ],
    'handler' => function ( array $args ): array {  // 必填，callable
        return [
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => "Review this code:\n\n```\n{$args['code']}\n```",
                    ],
                ],
            ],
        ];
    },
    'permission' => function (): bool {          // 選填
        return current_user_can( 'edit_posts' );
    },
    'annotations' => [                           // 選填，Prompt-level MCP Annotations
        'audience' => ['user'],
        'priority' => 0.7,
    ],
    'icons' => [ /* 同 McpTool */ ],
] );

if ( is_wp_error( $prompt ) ) {
    // 'mcp_prompt_missing_name'    — name 欄位為空
    // 'mcp_prompt_missing_handler' — handler 不是 callable
}
```

### fromAbility() / fromBuilder()

```php
$prompt = McpPrompt::fromAbility( $ability );

$builder = new MyPromptBuilder();
$prompt  = McpPrompt::fromBuilder( $builder );
```

---

## McpPromptBuilder（fluent API）

**Interface**: `WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface`
**實作類別**: `WP\MCP\Domain\Prompts\McpPromptBuilder`

```php
interface McpPromptBuilderInterface {
    public function get_name(): string;
    public function get_description(): ?string;
    public function get_arguments(): array;
    public function get_permission_callback(): ?callable;
    public function get_messages( array $arguments ): array;  // 回傳 MCP messages 陣列
}
```

**Builder 使用範例（類別方式，推薦）：**
```php
use WP\MCP\Domain\Prompts\Contracts\McpPromptBuilderInterface;

class MyCodeReviewPrompt implements McpPromptBuilderInterface {
    public function get_name(): string { return 'my-code-review'; }
    public function get_description(): ?string { return 'Generate a code review'; }
    public function get_arguments(): array {
        return [
            [ 'name' => 'code', 'required' => true, 'description' => 'Code to review' ],
        ];
    }
    public function get_permission_callback(): ?callable {
        return fn() => current_user_can( 'edit_posts' );
    }
    public function get_messages( array $arguments ): array {
        return [
            [ 'role' => 'user', 'content' => [ 'type' => 'text', 'text' => "Review:\n{$arguments['code']}" ] ],
        ];
    }
}

// 在 create_server() prompts 陣列中使用：
$adapter->create_server(
    // ...
    prompts: [
        MyCodeReviewPrompt::class,    // 類別名稱字串（自動實例化）
        new MyCodeReviewPrompt(),     // 或直接傳實例
    ]
);
```

---

## Component 註冊方式比較

| 方式 | 適用情境 | create_server() 參數 |
|------|---------|---------------------|
| ability 名稱（string） | 已有 `wp_register_ability()`，最常用 | `'my-plugin/ability'` |
| McpTool/Resource/Prompt 實例 | 需要精細控制、不建立 wp_ability | `$tool_instance` |
| Builder 類別名稱（string） | Prompt 只，有完整 class | `MyPrompt::class` |
| Builder 實例 | Prompt 只，動態建立 | `new MyPrompt()` |

---

## McpComponentInterface

所有 MCP 元件實作的共同介面（`WP\MCP\Domain\Contracts\McpComponentInterface`）：

```php
interface McpComponentInterface {
    public function get_protocol_dto(): mixed;          // Tool/Resource/Prompt DTO
    public function execute( $arguments ): mixed;       // 執行 handler 或 ability
    public function check_permission( $arguments ): bool; // 檢查 permission
    public function get_adapter_meta(): array;          // 內部 metadata，不暴露給 MCP client
    public function get_observability_context(): array; // ['component_type', 'source', ...]
}
```

---

## php-mcp-schema DTO 類別（v0.5.0 新增）

v0.5.0 引入 `wordpress/php-mcp-schema` 套件，所有 DTO 位於 `WP\McpSchema\` namespace：

```php
// Tools
\WP\McpSchema\Server\Tools\DTO\Tool
\WP\McpSchema\Server\Tools\DTO\ListToolsResult
\WP\McpSchema\Server\Tools\DTO\CallToolResult
\WP\McpSchema\Server\Tools\DTO\ToolAnnotations

// Resources
\WP\McpSchema\Server\Resources\DTO\Resource
\WP\McpSchema\Server\Resources\DTO\ListResourcesResult
\WP\McpSchema\Server\Resources\DTO\ReadResourceResult

// Prompts
\WP\McpSchema\Server\Prompts\DTO\Prompt
\WP\McpSchema\Server\Prompts\DTO\ListPromptsResult
\WP\McpSchema\Server\Prompts\DTO\GetPromptResult
\WP\McpSchema\Server\Prompts\DTO\PromptMessage
\WP\McpSchema\Server\Prompts\DTO\PromptArgument

// Content
\WP\McpSchema\Common\Content\DTO\TextContent
\WP\McpSchema\Common\Content\DTO\ImageContent

// Protocol
\WP\McpSchema\Common\Protocol\DTO\TextResourceContents
\WP\McpSchema\Common\Protocol\DTO\BlobResourceContents
```

所有 DTO 提供 `::fromArray( array $data )` 和 `->toArray()` 方法。

### ContentBlockHelper 工廠（方便建立 content block）

```php
use WP\MCP\Domain\Utils\ContentBlockHelper;

ContentBlockHelper::text( 'Hello world' )              // TextContent DTO
ContentBlockHelper::json_text( ['key' => 'value'] )    // JSON 編碼的 TextContent
ContentBlockHelper::error_text( 'Something failed' )   // 錯誤訊息 TextContent
ContentBlockHelper::image( 'base64data', 'image/png' ) // ImageContent DTO
ContentBlockHelper::embedded_text_resource( $uri, $text )  // EmbeddedResource
```
