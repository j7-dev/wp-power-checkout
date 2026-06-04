# 完整範例

## 目錄
- [範例 1：CPT CRUD 暴露為 MCP Tools](#範例-1-cpt-crud-暴露為-mcp-tools)
- [範例 2：Post Meta / Options 暴露為 MCP Resources](#範例-2-post-meta--options-暴露為-mcp-resources)
- [範例 3：Prompt Templates](#範例-3-prompt-templates)
- [Client 端設定](#client-端設定)
- [HTTP Transport via Proxy](#http-transport-via-proxy)

---

## 範例 1：CPT CRUD 暴露為 MCP Tools

```php
<?php
/**
 * Plugin Name: Book Store MCP
 * Description: Exposes book CPT CRUD as MCP tools
 */

declare( strict_types=1 );

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

// Bootstrap
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';

if ( ! class_exists( McpAdapter::class ) ) {
    return;
}
McpAdapter::instance();

// --- Abilities ---
add_action( 'wp_abilities_api_init', function (): void {

    // LIST
    wp_register_ability( 'bookstore/list-books', [
        'label'       => 'List Books',
        'description' => 'List books with optional filtering',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'per_page'   => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 100 ],
                'post_status'=> [ 'type' => 'string', 'enum' => ['publish','draft','private'], 'default' => 'publish' ],
                'search'     => [ 'type' => 'string', 'description' => 'Search keyword' ],
            ],
        ],
        'output_schema' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'ID'         => [ 'type' => 'integer' ],
                    'post_title' => [ 'type' => 'string' ],
                    'post_status'=> [ 'type' => 'string' ],
                    'permalink'  => [ 'type' => 'string' ],
                ],
            ],
        ],
        'execute_callback' => function ( array $input ): array {
            $posts = get_posts( [
                'post_type'   => 'book',
                'numberposts' => $input['per_page'] ?? 10,
                'post_status' => $input['post_status'] ?? 'publish',
                's'           => $input['search'] ?? '',
            ] );

            return array_map( fn( $p ) => [
                'ID'          => $p->ID,
                'post_title'  => $p->post_title,
                'post_status' => $p->post_status,
                'permalink'   => get_permalink( $p->ID ),
            ], $posts );
        },
        'permission_callback' => fn() => current_user_can( 'read' ),
        'meta' => [
            'annotations' => [ 'readonly' => true, 'idempotent' => false ],
            'mcp' => [ 'public' => true ],
        ],
    ] );

    // CREATE
    wp_register_ability( 'bookstore/create-book', [
        'label'       => 'Create Book',
        'description' => 'Create a new book post',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'title'   => [ 'type' => 'string', 'description' => 'Book title' ],
                'content' => [ 'type' => 'string', 'description' => 'Book description' ],
                'status'  => [ 'type' => 'string', 'enum' => ['draft','publish'], 'default' => 'draft' ],
                'isbn'    => [ 'type' => 'string', 'description' => 'ISBN (stored as post meta)' ],
            ],
            'required' => ['title'],
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'   => [ 'type' => 'integer' ],
                'permalink' => [ 'type' => 'string' ],
            ],
        ],
        'execute_callback' => function ( array $input ): array|\WP_Error {
            $post_id = wp_insert_post( [
                'post_type'    => 'book',
                'post_title'   => $input['title'],
                'post_content' => $input['content'] ?? '',
                'post_status'  => $input['status'] ?? 'draft',
            ], true );

            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            if ( ! empty( $input['isbn'] ) ) {
                update_post_meta( $post_id, '_isbn', sanitize_text_field( $input['isbn'] ) );
            }

            return [
                'post_id'   => $post_id,
                'permalink' => get_permalink( $post_id ),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'publish_posts' ),
        'meta' => [
            'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
            'mcp' => [ 'public' => true ],
        ],
    ] );

    // UPDATE
    wp_register_ability( 'bookstore/update-book', [
        'label'       => 'Update Book',
        'description' => 'Update an existing book post',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'description' => 'Book post ID' ],
                'title'   => [ 'type' => 'string' ],
                'content' => [ 'type' => 'string' ],
                'status'  => [ 'type' => 'string', 'enum' => ['draft','publish','private'] ],
                'isbn'    => [ 'type' => 'string' ],
            ],
            'required' => ['post_id'],
        ],
        'execute_callback' => function ( array $input ): array|\WP_Error {
            $update_data = [ 'ID' => $input['post_id'] ];
            if ( isset( $input['title'] ) )   $update_data['post_title']   = $input['title'];
            if ( isset( $input['content'] ) ) $update_data['post_content'] = $input['content'];
            if ( isset( $input['status'] ) )  $update_data['post_status']  = $input['status'];

            $result = wp_update_post( $update_data, true );
            if ( is_wp_error( $result ) ) return $result;

            if ( isset( $input['isbn'] ) ) {
                update_post_meta( $input['post_id'], '_isbn', sanitize_text_field( $input['isbn'] ) );
            }

            return [ 'success' => true, 'post_id' => $input['post_id'] ];
        },
        'permission_callback' => fn( array $input ) => current_user_can( 'edit_post', $input['post_id'] ),
        'meta' => [
            'annotations' => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            'mcp' => [ 'public' => true ],
        ],
    ] );

    // DELETE
    wp_register_ability( 'bookstore/delete-book', [
        'label'       => 'Delete Book',
        'description' => 'Move a book post to trash',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'    => [ 'type' => 'integer' ],
                'force_delete'=> [ 'type' => 'boolean', 'default' => false, 'description' => 'Permanently delete (bypass trash)' ],
            ],
            'required' => ['post_id'],
        ],
        'execute_callback' => function ( array $input ): array {
            $deleted = wp_delete_post( $input['post_id'], $input['force_delete'] ?? false );
            return [ 'success' => (bool) $deleted, 'post_id' => $input['post_id'] ];
        },
        'permission_callback' => fn( array $input ) => current_user_can( 'delete_post', $input['post_id'] ),
        'meta' => [
            'annotations' => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
            'mcp' => [ 'public' => true ],
        ],
    ] );
} );

// --- Custom MCP Server ---
add_action( 'mcp_adapter_init', function ( McpAdapter $adapter ): void {
    $adapter->create_server(
        'bookstore',
        'bookstore',
        'mcp',
        'Book Store MCP Server',
        'CRUD operations for the Book CPT',
        'v1.0.0',
        [ HttpTransport::class ],
        ErrorLogMcpErrorHandler::class,
        NullMcpObservabilityHandler::class,
        [
            'bookstore/list-books',
            'bookstore/create-book',
            'bookstore/update-book',
            'bookstore/delete-book',
        ]
    );
} );
```

**REST endpoint**: `POST /wp-json/bookstore/mcp`

---

## 範例 2：Post Meta / Options 暴露為 MCP Resources

```php
add_action( 'wp_abilities_api_init', function (): void {

    // Site options as resource
    wp_register_ability( 'my-plugin/site-options', [
        'label'       => 'Site Options',
        'description' => 'Plugin configuration options',
        'execute_callback' => function (): array {
            return [
                'version'     => get_option( 'my_plugin_version', '1.0' ),
                'api_endpoint'=> get_option( 'my_plugin_api_endpoint', '' ),
                'features'    => get_option( 'my_plugin_features', [] ),
            ];
        },
        'permission_callback' => fn() => current_user_can( 'manage_options' ),
        'meta' => [
            'uri' => 'wordpress://my-plugin/options',
            'annotations' => [
                'audience'     => ['assistant'],
                'priority'     => 0.9,
                'lastModified' => get_option( 'my_plugin_last_updated', date( 'c' ) ),
            ],
            'mcp' => [ 'public' => true, 'type' => 'resource' ],
        ],
    ] );

    // Per-post meta as resource（使用動態 URI 概念，能力名稱包含 post_id 語境）
    wp_register_ability( 'my-plugin/post-metadata', [
        'label'       => 'Post Metadata',
        'description' => 'Custom metadata for a specific post',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'description' => 'Post ID' ],
            ],
            'required' => ['post_id'],
        ],
        'execute_callback' => function ( array $input ): array {
            $id = $input['post_id'];
            return [
                'post_id'     => $id,
                'custom_field'=> get_post_meta( $id, 'custom_field', true ),
                'rating'      => (int) get_post_meta( $id, 'rating', true ),
                'tags'        => wp_get_post_terms( $id, 'post_tag', [ 'fields' => 'names' ] ),
            ];
        },
        'permission_callback' => fn( array $input ) => current_user_can( 'read_post', $input['post_id'] ),
        'meta' => [
            'uri' => 'wordpress://posts/metadata',
            'annotations' => [
                'audience' => ['assistant'],
                'priority' => 0.7,
            ],
            'mcp' => [ 'public' => true, 'type' => 'resource' ],
        ],
    ] );
} );
```

---

## 範例 3：Prompt Templates

```php
add_action( 'wp_abilities_api_init', function (): void {

    // SEO meta description generator
    wp_register_ability( 'my-plugin/generate-seo-meta', [
        'label'       => 'Generate SEO Meta Description',
        'description' => 'Generate an SEO-optimized meta description for a post',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'post_id'  => [ 'type' => 'integer', 'description' => 'Post ID to generate meta for' ],
                'max_chars'=> [ 'type' => 'integer', 'default' => 160, 'maximum' => 300 ],
            ],
            'required' => ['post_id'],
        ],
        'execute_callback' => function ( array $input ): array {
            $post      = get_post( $input['post_id'] );
            $max_chars = $input['max_chars'] ?? 160;

            return [
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => implode( "\n\n", [
                                "Please generate an SEO meta description for the following WordPress post.",
                                "Requirements:",
                                "- Maximum {$max_chars} characters",
                                "- Include primary keywords naturally",
                                "- Compelling call-to-action",
                                "- Accurately describe the content",
                                "",
                                "Post Title: {$post->post_title}",
                                "Post Content (excerpt):",
                                wp_trim_words( $post->post_content, 100 ),
                            ] ),
                        ],
                    ],
                ],
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        'meta' => [
            'annotations' => [
                'audience' => ['user'],
                'priority' => 0.8,
            ],
            'mcp' => [ 'public' => true, 'type' => 'prompt' ],
        ],
    ] );

    // WordPress code review prompt
    wp_register_ability( 'my-plugin/wp-code-review', [
        'label'       => 'WordPress Code Review',
        'description' => 'Review PHP code for WordPress best practices',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'code'  => [ 'type' => 'string', 'description' => 'PHP code to review' ],
                'focus' => [
                    'type'    => 'array',
                    'items'   => [ 'type' => 'string' ],
                    'default' => ['security', 'performance', 'wpcs'],
                    'description' => 'Areas to focus: security, performance, wpcs, accessibility',
                ],
            ],
            'required' => ['code'],
        ],
        'execute_callback' => function ( array $input ): array {
            $focus = implode( ', ', $input['focus'] ?? ['security', 'performance', 'wpcs'] );
            return [
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => "Review this WordPress PHP code focusing on: {$focus}\n\n```php\n{$input['code']}\n```\n\nPlease check for WordPress Coding Standards compliance, security vulnerabilities (nonces, sanitization, escaping), and performance issues.",
                        ],
                    ],
                ],
            ];
        },
        'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        'meta' => [
            'annotations' => [ 'audience' => ['user'], 'priority' => 0.8 ],
            'mcp' => [ 'public' => true, 'type' => 'prompt' ],
        ],
    ] );
} );
```

---

## Client 端設定

### Claude Desktop / Claude Code（STDIO）

設定檔位置：
- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "wordpress-default": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress",
        "mcp-adapter",
        "serve",
        "--server=mcp-adapter-default-server",
        "--user=admin"
      ]
    },
    "wordpress-bookstore": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress",
        "mcp-adapter",
        "serve",
        "--server=bookstore",
        "--user=1"
      ]
    }
  }
}
```

### Cursor（STDIO）

`~/.cursor/mcp.json` 或專案 `.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "wordpress": {
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress",
        "mcp-adapter",
        "serve",
        "--user=admin"
      ]
    }
  }
}
```

### VS Code（STDIO，MCP extension）

`.vscode/settings.json`：

```json
{
  "mcp.servers": {
    "wordpress": {
      "type": "stdio",
      "command": "wp",
      "args": [
        "--path=/path/to/your/wordpress",
        "mcp-adapter",
        "serve",
        "--user=admin"
      ]
    }
  }
}
```

---

## HTTP Transport via Proxy

使用 `@automattic/mcp-wordpress-remote` proxy，將 STDIO MCP 通訊轉為 HTTP REST API 呼叫。認證使用 WordPress Application Passwords。

```json
{
  "mcpServers": {
    "wordpress-http": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://your-site.com/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "LOG_FILE": "/tmp/wp-mcp.log"
      }
    },
    "wordpress-http-custom": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://your-site.com/wp-json/bookstore/mcp",
        "WP_API_USERNAME": "your-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

**Application Password 格式**: WordPress 後台 > 使用者 > 個人資料 > 應用程式密碼，生成 `xxxx xxxx xxxx xxxx xxxx xxxx` 格式的密碼。

---

## 除錯技巧

```bash
# 確認 server 已正確註冊
wp mcp-adapter list --format=json | jq '.[].id'

# 確認 tools 清單
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' \
  | wp mcp-adapter serve --user=admin --server=bookstore

# 測試單一 tool
echo '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"bookstore-list-books","arguments":{"per_page":5}}}' \
  | wp mcp-adapter serve --user=admin --server=bookstore

# 開啟 debug 模式
wp mcp-adapter serve --user=admin --debug

# 確認 default server 的 ability 發現功能
echo '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-adapter-discover-abilities","arguments":{}}}' \
  | wp mcp-adapter serve --user=admin
```

### 常見問題

| 症狀 | 原因 | 解法 |
|------|------|------|
| `No MCP servers available` | 未在 `mcp_adapter_init` 中建立 server | 確認 hook 正確觸發 |
| Tool 名稱與預期不符 | Ability 名稱含 `/` 被轉換 | 檢查 sanitize 規則；用 `mcp_adapter_tool_name` filter |
| `wp_register_ability not found` | WP 6.8 未安裝 Abilities API | `composer require wordpress/abilities-api` |
| Permission denied（HTTP 403） | Transport permission 或 ability permission 失敗 | 確認 `--user` 有對應 capability |
| Abilities 在 `tools/list` 看不到 | Default server 用 meta-tools 間接暴露 | 用 discover-abilities 工具，或改建自訂 server |
