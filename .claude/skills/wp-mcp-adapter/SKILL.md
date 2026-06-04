---
name: wp-mcp-adapter
description: >
  wordpress/mcp-adapter v0.5.0 完整 API 參考。當程式碼出現 use WP\MCP\、namespace WP\MCP\、
  McpAdapter::instance()、McpAdapter::create_server()、mcp_adapter_init、
  composer require wordpress/mcp-adapter、wp mcp-adapter serve，
  或任何涉及「將 WordPress plugin 暴露為 MCP tool / resource / prompt」、
  「接到 Claude Desktop / Cursor / VS Code via MCP」、「expose plugin as MCP」、
  「MCP server in WordPress」、「register MCP tool from ability」的任務，
  必須立即使用此 skill。
  涵蓋安裝、McpAdapter / McpServer / McpTool / McpResource / McpPrompt 完整 API 簽名、
  HttpTransport、STDIO / WP-CLI、transport permissions、error handling、
  Abilities API 整合、MCP annotations、client 端設定（Claude Desktop / Cursor）。
---

# WordPress MCP Adapter — API Reference (v0.5.0)

> **版本**: 0.5.0 | PHP >= 7.4 | WordPress >= 6.8（6.9+ 建議）
> **MCP 規格**: 2025-06-18 / 2025-11-25
> **GitHub**: https://github.com/WordPress/mcp-adapter

## 參考子檔（按需讀取）

- `references/abilities.md` — wp_register_ability() 完整欄位、tool/resource/prompt 差異、annotations、schema 格式
- `references/components.md` — McpTool / McpResource / McpPrompt fromArray() 工廠 API 與直接實例化
- `references/transports.md` — HttpTransport、STDIO、自訂 transport、transport permission callbacks
- `references/error-observability.md` — McpErrorFactory 錯誤碼、自訂 error handler、observability handler
- `references/examples.md` — 完整可執行範例（CPT CRUD、resources、prompts、client config）

---

## 安裝

```bash
# WordPress 6.9+（Abilities API 已內建）
composer require wordpress/mcp-adapter

# WordPress 6.8（需另裝 Abilities API）
composer require wordpress/abilities-api wordpress/mcp-adapter

# 強烈建議：防止多插件版本衝突
composer require automattic/jetpack-autoloader
```

**Plugin 主檔 bootstrap（Jetpack autoloader 方式）：**
```php
<?php
// 用 Jetpack autoloader 取代 vendor/autoload.php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload_packages.php';

use WP\MCP\Core\McpAdapter;

if ( class_exists( McpAdapter::class ) ) {
    McpAdapter::instance(); // 自動掛到 rest_api_init priority 15
}
```

**標準 Composer autoloader 方式：**
```php
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
McpAdapter::instance();
```

---

## 初始化流程與 Hooks

| Hook | 時機 | 用途 |
|------|------|------|
| `rest_api_init` (priority 15) | REST 請求 | McpAdapter::instance() 自動掛這裡 |
| `init` (priority 20) | WP-CLI 環境 | 同上，CLI 模式 |
| `wp_abilities_api_categories_init` | 能力系統初始化 | 註冊 ability 分類 |
| `wp_abilities_api_init` | 能力系統初始化 | **在這裡用 wp_register_ability() 註冊 abilities** |
| `mcp_adapter_init` | Adapter 初始化後 | **在這裡用 create_server() 建立自訂 MCP server** |
| `wp_mcp_init` | Plugin::instance() 後 | Plugin 層級 hook |

```php
// 唯一正確建立 server 的時機：
add_action( 'mcp_adapter_init', function ( \WP\MCP\Core\McpAdapter $adapter ): void {
    $adapter->create_server( /* ... */ );
} );
```

---

## McpAdapter（singleton）

**Namespace**: `WP\MCP\Core\McpAdapter`

```php
McpAdapter::instance(): self        // 取得/建立 singleton，自動掛 init hooks
McpAdapter::VERSION                 // '0.5.0'
```

### create_server() — 完整簽名

```php
$adapter->create_server(
    string    $server_id,                   // 唯一識別符，e.g. 'my-plugin-server'
    string    $server_route_namespace,      // REST namespace，e.g. 'my-plugin'
    string    $server_route,                // REST route，e.g. 'mcp'
    string    $server_name,                 // 人類可讀名稱
    string    $server_description,          // 描述
    string    $server_version,              // e.g. 'v1.0.0'
    array     $mcp_transports,             // [HttpTransport::class] 推薦
    ?string   $error_handler,              // class-string<McpErrorHandlerInterface>|null
    ?string   $observability_handler,      // class-string<McpObservabilityHandlerInterface>|null
    array     $tools      = [],            // list<string> ability names → tools
    array     $resources  = [],            // list<string> ability names → resources
    array     $prompts    = [],            // list<string|McpPrompt|McpPromptBuilderInterface>
    ?callable $transport_permission_callback = null  // null = is_user_logged_in()
): self|\WP_Error
```

**限制**：必須在 `mcp_adapter_init` action 中呼叫。重複 `$server_id` 回傳 `WP_Error`。

### Server 存取

```php
$adapter->get_server( string $server_id ): ?McpServer
$adapter->get_servers(): McpServer[]    // keyed by server_id
```

---

## McpServer

**Namespace**: `WP\MCP\Core\McpServer`

透過 `McpAdapter::instance()->get_server($id)` 取得。

```php
$server->get_server_id(): string
$server->get_server_route_namespace(): string
$server->get_server_route(): string
$server->get_server_name(): string
$server->get_server_description(): string
$server->get_server_version(): string
$server->get_tools(): array<string, ToolDto>       // keyed by MCP tool name
$server->get_resources(): array<string, ResourceDto>
$server->get_prompts(): array<string, PromptDto>
$server->get_mcp_tool( string $name ): ?McpTool    // 含 execute/permission 的完整實例
$server->get_mcp_resource( string $uri ): ?McpResource
$server->get_mcp_prompt( string $name ): ?McpPrompt
$server->get_error_handler(): McpErrorHandlerInterface
$server->get_observability_handler(): McpObservabilityHandlerInterface
$server->is_mcp_validation_enabled(): bool
```

---

## Default Server

自動建立，無需手動設定。

**預設屬性：**
- `server_id`: `mcp-adapter-default-server`
- REST endpoint: `POST /wp-json/mcp/mcp-adapter-default-server`
- 內建 tools: `mcp-adapter-discover-abilities`、`mcp-adapter-get-ability-info`、`mcp-adapter-execute-ability`
- 設有 `meta.mcp.public = true` 的 abilities 透過內建 meta-tools 可被探索（**不會**直接出現在 `tools/list`）
- 設有 `meta.mcp.type = 'resource'` 或 `'prompt'` 的 abilities 會直接列在 `resources/list` 或 `prompts/list`

**控制 default server：**
```php
// 停用 default server
add_filter( 'mcp_adapter_create_default_server', '__return_false' );

// 自訂 default server 設定
add_filter( 'mcp_adapter_default_server_config', function ( array $config ): array {
    $config['tools'][] = 'my-plugin/my-ability';
    return $config;
} );
```

---

## 重要 Filters

```php
// 停用 default server
add_filter( 'mcp_adapter_create_default_server', '__return_false' );

// 覆寫 default server 完整設定（見 DefaultServerFactory keys）
add_filter( 'mcp_adapter_default_server_config', fn(array $c): array => $c );

// 開啟 MCP protocol validation（開發/除錯用；預設 false）
add_filter( 'mcp_adapter_validation_enabled', '__return_true' );
// 或針對特定 server：
add_filter( 'mcp_adapter_validation_enabled', fn(bool $e, string $id, McpServer $s) => $e, 10, 3 );

// 開啟 component 註冊的 observability tracking（預設 false）
add_filter( 'mcp_adapter_observability_record_component_registration', '__return_true' );

// 覆寫 HTTP transport 所需的 WordPress capability（預設 'read'）
add_filter( 'mcp_adapter_default_transport_permission_user_capability',
    fn(): string => 'edit_posts' );

// 自訂 MCP tool 名稱（ability 名稱經 sanitize 後的結果）
add_filter( 'mcp_adapter_tool_name', function( string $name, \WP_Ability $ability ): string {
    return $ability->get_name() === 'my-plugin/legacy' ? 'my-legacy' : $name;
}, 10, 2 );
```

---

## Tool 名稱 Sanitization

WordPress ability 名稱（e.g. `my-plugin/list-books`）會自動轉換為 MCP 合法名稱：

| Ability 名稱 | MCP Tool 名稱 |
|-------------|--------------|
| `my-plugin/my-tool` | `my-plugin-my-tool` |
| `café/résumé-tool` | `cafe-resume-tool` |

規則：`/` → `-`、移除 accent、無效字元 → `-`、合併連續 `-`、超過 128 字元則截斷並加 MD5 hash。

---

## WP-CLI 命令

```bash
# 列出所有已註冊的 MCP server
wp mcp-adapter list [--format=table|json|csv|yaml]

# 透過 STDIO transport 提供 MCP server（供 MCP client 連接）
wp mcp-adapter serve [--server=<server-id>] [--user=<id|login|email>]

# 範例
wp mcp-adapter serve --user=admin
wp mcp-adapter serve --server=my-plugin-server --user=1

# 直接測試（echo JSON-RPC into stdin）
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}' \
  | wp mcp-adapter serve --user=admin --server=mcp-adapter-default-server
```

---

## 快速開始範例

```php
<?php
// 1. 在 wp_abilities_api_init 註冊 ability
add_action( 'wp_abilities_api_init', function (): void {
    wp_register_ability( 'my-plugin/list-books', [
        'label'       => 'List Books',
        'description' => 'List book CPT entries',
        'input_schema' => [
            'type'       => 'object',
            'properties' => [
                'per_page' => [ 'type' => 'integer', 'default' => 10, 'maximum' => 100 ],
                'status'   => [ 'type' => 'string', 'enum' => ['publish','draft'], 'default' => 'publish' ],
            ],
        ],
        'execute_callback'    => function ( array $input ): array {
            return get_posts( [
                'post_type'   => 'book',
                'numberposts' => $input['per_page'] ?? 10,
                'post_status' => $input['status'] ?? 'publish',
            ] );
        },
        'permission_callback' => fn() => current_user_can( 'read' ),
        'meta' => [
            'annotations' => [ 'readonly' => true ],
            'mcp' => [ 'public' => true ],  // default server 可探索
        ],
    ] );
} );

// 2. 在 mcp_adapter_init 建立自訂 server（直接暴露為 tools/list）
add_action( 'mcp_adapter_init', function ( \WP\MCP\Core\McpAdapter $adapter ): void {
    $adapter->create_server(
        'my-plugin-books',
        'my-plugin',
        'mcp',
        'My Books MCP Server',
        'Exposes book CPT as MCP tools',
        'v1.0.0',
        [ \WP\MCP\Transport\HttpTransport::class ],
        \WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
        \WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
        [ 'my-plugin/list-books' ],   // tools
        [],                            // resources
        []                             // prompts
    );
} );
```

REST endpoint: `POST /wp-json/my-plugin/mcp`

---

詳細 API 簽名、完整範例、client 設定請閱讀 `references/` 子檔。
